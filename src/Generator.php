<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Caller\ModelRequest;
use IndianOpinion\WcProductCopy\Caller\ModelResponse;
use IndianOpinion\WcProductCopy\Exception\GenerationException;
use IndianOpinion\WcProductCopy\Exception\NotConfiguredException;
use IndianOpinion\WcProductCopy\Image\ImageChoice;
use IndianOpinion\WcProductCopy\Image\ImagePreparer;
use IndianOpinion\WcProductCopy\Prompt\PromptBuilder;

/**
 * One job: cases 29 to 47. Reads the switch and caps afresh, locks the
 * product, re-checks eligibility, makes at most one call through the
 * caller, cleans and checks the answer, and saves a pending draft.
 */
final class Generator
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly KillSwitch $switch,
        private readonly Usage $usage,
        private readonly Planner $planner,
        private readonly CallerFactory $callers,
        private readonly DraftStore $drafts,
        private readonly PromptBuilder $prompts,
        private readonly ImagePreparer $images,
        private readonly HtmlCleaner $cleaner,
        private readonly FactCheck $check,
        private readonly SpendMeter $meter,
        private readonly Lock $lock,
        private readonly Log $log
    ) {
    }

    /**
     * @param array<mixed> $args Run arguments carried by the job.
     * @param bool $finalAttempt When false, a retryable failure is handed back to the runner without a failed record.
     */
    public function run(int $productId, array $args, string $jobRef = '', bool $finalAttempt = true): JobOutcome
    {
        // Case 29 and 30: fresh read of the switch at the start of each job.
        $blocked = $this->switch->blockedReason();
        if ($blocked !== null) {
            return JobOutcome::waitSwitch($blocked);
        }
        $status = (string) get_post_status($productId);
        if (!wc_get_product($productId) instanceof \WC_Product || in_array($status, ['', 'trash', 'auto-draft'], true)) {
            return $this->skip($productId, 'gone');
        }
        if (!$this->lock->acquire((string) $productId)) {
            return $this->skip($productId, 'locked');
        }
        try {
            return $this->locked($productId, $this->planner->normaliseArgs($args), $jobRef, $finalAttempt);
        } finally {
            $this->lock->release((string) $productId);
        }
    }

    /**
     * @param array{fields: string, regenerate_owned: bool, refresh_drafts: bool, include_discarded: bool} $args
     */
    private function locked(int $productId, array $args, string $jobRef, bool $finalAttempt): JobOutcome
    {
        $item = $this->planner->evaluate($productId, $args);
        if (!$item->runs() || $item->facts === null || $item->imageChoice === null || $item->prompt === null) {
            return $this->skip($productId, $item->reason);
        }
        $settings = $this->settings->get();

        try {
            $caller = $this->callers->get();
        } catch (NotConfiguredException $e) {
            $this->log->add('waiting', $productId, 'not_configured');

            return JobOutcome::waitSwitch('not_configured');
        }

        // Cases 31 and 32, early: no image work when a cap is already reached.
        // They are checked again atomically when the attempt is counted.
        if ($this->usage->callsLeft($settings) <= 0) {
            return $this->capReached('calls');
        }
        $costCap = $settings->costCap();
        if ($costCap !== null && $this->usage->today()['cost'] + ($settings->assumedCost() ?? 0.0) > $costCap + 1e-9) {
            return $this->capReached('cost');
        }

        // Cases 9 to 12: image, prepared once per job and not kept.
        $imageStatus = $this->imageStatus($item->imageChoice);
        $dataUrl = null;
        $prompt = $item->prompt;
        if ($item->imageChoice->willSend() && $caller->supportsImages()) {
            $prepared = $this->images->prepare($item->imageChoice->attachmentId);
            if ($prepared->sent()) {
                $dataUrl = $prepared->dataUrl;
                $imageStatus = ['status' => 'sent', 'detail' => $prepared->width . 'x' . $prepared->height];
            } else {
                $imageStatus = ['status' => 'image_unavailable', 'detail' => $prepared->detail];
                if ($item->facts->count() < $settings->minFacts()) {
                    return $this->skip($productId, 'insufficient_data');
                }
                $prompt = $this->prompts->build($item->facts, $item->fields, $settings, false, $productId);
            }
        } elseif ($item->imageChoice->willSend()) {
            $imageStatus = ['status' => 'not_sent', 'detail' => ImageChoice::TEXT_ONLY_CALLER];
            $prompt = $this->prompts->build($item->facts, $item->fields, $settings, false, $productId);
        }

        // Case 33: spend-meter has the last word before a call.
        $estimate = $prompt->estimatedTokens() + ($dataUrl !== null ? $settings->imageTokens() : 0);
        $decision = $this->meter->preSpend($caller->provider(), $caller->model(), $estimate, $settings->outputCap());
        if (!$decision['allowed']) {
            $this->meter->release($decision['reservation']);
            if (str_starts_with($decision['reason'], 'kill_switch')) {
                $this->switch->pause('spend_meter_kill_switch');
                $this->log->add('paused', $productId, 'spend_meter_kill_switch');

                return JobOutcome::waitSwitch('spend_meter_kill_switch');
            }
            $this->log->add('waiting', $productId, 'spend_meter:' . $decision['reason']);

            return JobOutcome::waitDay('spend_meter');
        }
        $metered = SpendMeter::present();

        $request = new ModelRequest(
            SpendMeter::PURPOSE,
            $prompt->system,
            $prompt->user,
            PromptBuilder::schema($item->fields),
            $item->fields,
            $settings->outputCap(),
            $dataUrl
        );

        // Count the attempt before the call: a failed call still costs money.
        // Cap check and count are one locked step, so two workers cannot
        // both take the last call of the day.
        $capped = $this->usage->reserveCall($settings->callCap(), $costCap, $settings->assumedCost() ?? 0.0);
        if ($capped !== null) {
            $this->meter->release($decision['reservation']);

            return $this->capReached($capped);
        }
        try {
            $response = $caller->call($request);
        } catch (GenerationException $e) {
            return $this->failure($productId, $item, $e, $settings, $decision['reservation'], $caller->provider(), $caller->model(), $finalAttempt, $metered);
        } catch (\Throwable $e) {
            $wrapped = new GenerationException('The caller threw an unexpected error.', GenerationException::PERMANENT, 1, null, $e);

            return $this->failure($productId, $item, $wrapped, $settings, $decision['reservation'], $caller->provider(), $caller->model(), $finalAttempt, $metered);
        }

        $this->usage->addCalls(max(0, $response->billedCalls - 1));
        [$cost, $costSource] = $this->recordCost($response->cost, $response->costSource, $settings);
        $reported = $response->costSource === 'reported' ? $response->cost : null;
        $this->meter->record($decision['reservation'], $caller->provider(), $caller->model(), $response->tokensIn, $response->tokensOut, $reported, 'ok');

        // Case 40, for callers that do not enforce the schema themselves.
        $texts = [];
        foreach ($item->fields as $field) {
            $key = $field === Fields::SHORT ? 'short_html' : 'description_html';
            $text = $response->data[$key] ?? null;
            if (!is_string($text) || Text::isEmpty($text)) {
                $error = new GenerationException('The answer is missing a requested field.', GenerationException::RETRYABLE, 0, null, null, 'invalid_output');

                return $this->failure($productId, $item, $error, $settings, null, $caller->provider(), $caller->model(), $finalAttempt, $metered, true);
            }
            $texts[$field] = $text;
        }

        return $this->saveDraft($productId, $item, $prompt, $response, $texts, $imageStatus, $cost, $costSource, $jobRef, $settings, $metered);
    }

    /**
     * @param array<string, string> $texts
     * @param array{status: string, detail: string} $imageStatus
     */
    private function saveDraft(
        int $productId,
        PlanItem $item,
        Prompt\Prompt $prompt,
        ModelResponse $response,
        array $texts,
        array $imageStatus,
        ?float $cost,
        string $costSource,
        string $jobRef,
        Settings $settings,
        bool $metered
    ): JobOutcome {
        assert($item->facts !== null);
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $fields = [];
        $flags = [];
        foreach ($texts as $field => $text) {
            $clean = $this->cleaner->clean($text);
            $fields[$field] = $clean['html'];
            $flags = array_merge($flags, $this->check->field($field, $clean['html'], $item->facts, $settings, $clean['removed'], $currency));
        }
        $used = [];
        foreach (is_array($response->data['used_facts'] ?? null) ? $response->data['used_facts'] : [] as $id) {
            if (is_scalar($id) && !in_array((string) $id, $used, true)) {
                $used[] = mb_substr((string) $id, 0, 20);
            }
        }
        $flags = array_merge($flags, $this->check->references($used, $item->facts));
        /** @var mixed $filtered */
        $filtered = apply_filters('product_copy_flags', $flags, $productId, $fields, $item->facts->toArray());
        if (is_array($filtered)) {
            $flags = self::validFlags($filtered);
        }
        $note = is_string($response->data['note'] ?? null) ? Text::cut(Text::plain($response->data['note']), 300) : '';

        $draft = new Draft(
            $productId,
            Draft::PENDING,
            $fields,
            $flags,
            $note,
            $used,
            $item->facts->toArray(),
            $item->inputHash,
            $prompt->version,
            $prompt->hash,
            $response->model,
            $response->tokensIn,
            $response->tokensOut,
            $cost,
            $costSource,
            Clock::now(),
            null,
            null,
            [
                'image' => $imageStatus,
                'facts_dropped' => $item->facts->dropped,
                'job' => mb_substr($jobRef, 0, 100),
                'prompt_source' => $prompt->source,
                'metered' => $metered,
            ]
        );
        $this->drafts->save($draft, $settings->retentionDays());
        $this->log->add('saved', $productId, count($flags) . ' flags');
        do_action('product_copy_draft_saved', $productId, $draft);

        return JobOutcome::saved($draft, $metered);
    }

    private function failure(
        int $productId,
        PlanItem $item,
        GenerationException $e,
        Settings $settings,
        ?string $reservation,
        string $provider,
        string $model,
        bool $finalAttempt,
        bool $metered,
        bool $alreadyCounted = false
    ): JobOutcome {
        $cost = null;
        if (!$alreadyCounted) {
            $this->usage->addCalls(max(0, $e->billedCalls() - 1));
            if ($e->billedCalls() > 0) {
                [$cost] = $this->recordCost($e->cost(), $e->cost() !== null ? 'reported' : 'unknown', $settings);
            }
            $this->meter->record($reservation, $provider, $model, null, null, $e->cost(), $e->billedCalls() > 0 ? 'error_billed' : 'error_unbilled');
        }

        if ($e->isAccountProblem()) {
            $this->switch->pause('account_error');
            $this->log->add('paused', $productId, 'account_error ' . $e->errorClass());
            $this->writeFailed($productId, $item, $e, $settings);
            do_action('product_copy_failed', $productId, $e);

            return JobOutcome::failed('account_error', $e, $cost, $metered);
        }
        if ($e->kind() === GenerationException::RETRYABLE && !$finalAttempt) {
            $this->log->add('retry', $productId, $e->errorClass());

            return JobOutcome::retry($e, $cost, $metered);
        }
        $this->writeFailed($productId, $item, $e, $settings);
        $this->log->add('failed', $productId, $e->errorClass());
        do_action('product_copy_failed', $productId, $e);

        return JobOutcome::failed($e->reason(), $e, $cost, $metered);
    }

    /**
     * Case 46: state failed with the exception class and a short message;
     * no raw output, no prompt. A pending draft from an earlier run is kept.
     */
    private function writeFailed(int $productId, PlanItem $item, GenerationException $e, Settings $settings): void
    {
        $existing = $this->drafts->get($productId);
        if ($existing !== null && $existing->state === Draft::PENDING) {
            return;
        }
        $this->drafts->save(new Draft(
            $productId,
            Draft::FAILED,
            [],
            [],
            '',
            [],
            $item->facts !== null ? $item->facts->toArray() : [],
            $item->inputHash,
            $item->prompt !== null ? $item->prompt->version : '',
            $item->prompt !== null ? $item->prompt->hash : '',
            '',
            null,
            null,
            $e->cost(),
            $e->cost() !== null ? 'reported' : 'unknown',
            Clock::now(),
            null,
            null,
            ['error' => ['class' => $e->errorClass(), 'kind' => $e->kind(), 'message' => mb_substr($e->getMessage(), 0, 160)]]
        ), $settings->retentionDays());
    }

    /**
     * Case 32: record cost; unknown cost counts at the assumed cost.
     *
     * @return array{0: ?float, 1: string}
     */
    private function recordCost(?float $cost, string $source, Settings $settings): array
    {
        if ($cost !== null) {
            $this->usage->addCost($cost, false);

            return [$cost, $source];
        }
        $assumed = $settings->assumedCost();
        $this->usage->addCost($assumed ?? 0.0, true);

        return [$assumed, $assumed !== null ? 'assumed' : 'unknown'];
    }

    private function capReached(string $which): JobOutcome
    {
        $day = Clock::day();
        $this->log->add('cap_reached', 0, $which);
        do_action('product_copy_cap_reached', $which, $day);

        return JobOutcome::waitDay('cap_' . $which);
    }

    private function skip(int $productId, string $reason): JobOutcome
    {
        $this->log->add('skipped', $productId, $reason);
        do_action('product_copy_skipped', $productId, $reason);

        return JobOutcome::skipped($reason);
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function imageStatus(ImageChoice $choice): array
    {
        return match ($choice->status) {
            ImageChoice::NONE => ['status' => 'none', 'detail' => ''],
            ImageChoice::DISABLED => ['status' => 'not_sent', 'detail' => 'disabled'],
            ImageChoice::SVG => ['status' => 'image_unavailable', 'detail' => 'svg'],
            ImageChoice::TEXT_ONLY_CALLER => ['status' => 'not_sent', 'detail' => ImageChoice::TEXT_ONLY_CALLER],
            default => ['status' => 'pending', 'detail' => ''],
        };
    }

    /**
     * @param array<mixed> $flags
     *
     * @return list<array{code: string, field: string, detail: list<string>}>
     */
    private static function validFlags(array $flags): array
    {
        $out = [];
        foreach ($flags as $flag) {
            if (!is_array($flag) || !is_string($flag['code'] ?? null) || preg_match('/^[a-z0-9_]{1,40}$/', $flag['code']) !== 1) {
                continue;
            }
            $detail = [];
            foreach (is_array($flag['detail'] ?? null) ? $flag['detail'] : [] as $d) {
                if (is_scalar($d)) {
                    $detail[] = mb_substr((string) $d, 0, 200);
                }
            }
            $out[] = ['code' => $flag['code'], 'field' => is_string($flag['field'] ?? null) ? $flag['field'] : '', 'detail' => $detail];
        }

        return $out;
    }
}
