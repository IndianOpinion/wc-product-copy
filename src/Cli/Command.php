<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Cli;

use IndianOpinion\WcProductCopy\Admin\Messages;
use IndianOpinion\WcProductCopy\Admin\StatusPanel;
use IndianOpinion\WcProductCopy\Clock;
use IndianOpinion\WcProductCopy\Draft;
use IndianOpinion\WcProductCopy\Exception\ProductCopyException;
use IndianOpinion\WcProductCopy\Fields;
use IndianOpinion\WcProductCopy\JobOutcome;
use IndianOpinion\WcProductCopy\Meta;
use IndianOpinion\WcProductCopy\Plan;
use IndianOpinion\WcProductCopy\Plugin;
use IndianOpinion\WcProductCopy\Prompt\Prompt;

/**
 * Write product descriptions as drafts for review.
 *
 * Exit codes: 0 success, 1 error, 2 nothing to do, 3 paused, 4 cap reached.
 */
final class Command
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_NOTHING = 2;
    public const EXIT_PAUSED = 3;
    public const EXIT_CAP = 4;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Plan, confirm and queue drafts.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Product ids.
     *
     * [--category=<slug>]
     * : Products in this category.
     *
     * [--status=<status>]
     * : publish, draft, pending, private or future (comma-separated).
     *
     * [--type=<type>]
     * : simple, variable, grouped or external (comma-separated).
     *
     * [--missing=<which>]
     * : Only products missing: description, short, both or any.
     *
     * [--fields=<fields>]
     * : description, short or both. Default from settings.
     *
     * [--all]
     * : Every product. There is no implicit "all".
     *
     * [--limit=<n>]
     * : Stop after this many products.
     *
     * [--regenerate-owned]
     * : Also rewrite fields filled from accepted drafts.
     *
     * [--refresh-drafts]
     * : Replace pending drafts whose input changed.
     *
     * [--include-discarded]
     * : Include products whose last draft was discarded.
     *
     * [--dry-run]
     * : Report only. No call, no write, no counter change.
     *
     * [--show-prompt=<id>]
     * : Print the prompt for one product and exit. No call.
     *
     * [--now]
     * : Run in the foreground instead of queueing. Still capped.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function generate(array $positional, array $assoc): void
    {
        $args = [
            'fields' => isset($assoc['fields']) ? (string) $assoc['fields'] : '',
            'regenerate_owned' => self::flag($assoc, 'regenerate-owned'),
            'refresh_drafts' => self::flag($assoc, 'refresh-drafts'),
            'include_discarded' => self::flag($assoc, 'include-discarded'),
        ];
        if (isset($assoc['show-prompt'])) {
            $this->showPrompt((int) $assoc['show-prompt'], $args);

            return;
        }
        $selection = [
            'ids' => array_map('intval', $positional),
            'category' => $assoc['category'] ?? null,
            'status' => $assoc['status'] ?? null,
            'type' => $assoc['type'] ?? null,
            'missing' => $assoc['missing'] ?? null,
            'all' => self::flag($assoc, 'all'),
            'limit' => $assoc['limit'] ?? null,
        ];
        try {
            $plan = $this->plugin->planner->plan($selection, $args);
        } catch (ProductCopyException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        $this->report($plan, (string) ($assoc['format'] ?? 'table'));
        $runnable = $plan->runnable();
        if (self::flag($assoc, 'dry-run')) {
            \WP_CLI::halt($runnable === [] ? self::EXIT_NOTHING : self::EXIT_OK);
        }
        if ($runnable === []) {
            \WP_CLI::log('Nothing to do.');
            \WP_CLI::halt(self::EXIT_NOTHING);
        }
        $now = self::flag($assoc, 'now');
        if (!self::flag($assoc, 'yes')) {
            \WP_CLI::confirm(sprintf($now ? 'Write %d drafts now?' : 'Queue %d products?', count($runnable)));
        }
        if ($now) {
            $this->runNow($plan);

            return;
        }
        try {
            $queued = $this->plugin->queuer->queue($plan);
        } catch (ProductCopyException $e) {
            \WP_CLI::error($e->getMessage() . ' (' . $e->reason() . ')');

            return;
        }
        if (!$this->plugin->switch->isEnabled()) {
            \WP_CLI::warning('Generation is paused. Jobs wait until `wp product-copy resume`.');
        }
        \WP_CLI::success(sprintf('%d queued with %s.', $queued, $this->plugin->runner->name()));
        \WP_CLI::halt($queued === 0 ? self::EXIT_NOTHING : self::EXIT_OK);
    }

    /**
     * List drafts.
     *
     * ## OPTIONS
     *
     * [--state=<state>]
     * : pending, accepted, discarded or failed.
     *
     * [--flagged]
     * : Only drafts with flags.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function drafts(array $positional, array $assoc): void
    {
        $state = isset($assoc['state']) ? (string) $assoc['state'] : '';
        if ($state !== '' && !in_array($state, Draft::STATES, true)) {
            \WP_CLI::error('Unknown state. Use pending, accepted, discarded or failed.');

            return;
        }
        $meta = [['key' => Meta::DRAFT, 'compare' => 'EXISTS']];
        if ($state !== '') {
            $meta[] = ['key' => Meta::DRAFT, 'value' => '"state":"' . $state . '"', 'compare' => 'LIKE'];
        }
        if (self::flag($assoc, 'flagged')) {
            $meta[] = ['key' => Meta::DRAFT, 'value' => '"flags":[{', 'compare' => 'LIKE'];
        }
        $rows = [];
        for ($page = 1;; $page++) {
            $query = new \WP_Query([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_query' => $meta,
            ]);
            foreach ($query->posts as $id) {
                $id = (int) (is_object($id) ? $id->ID : $id);
                $draft = $this->plugin->drafts->get($id);
                if ($draft === null) {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'title' => get_the_title($id),
                    'state' => $draft->state,
                    'fields' => implode(',', array_keys($draft->fields)),
                    'flags' => implode(',', array_values(array_unique(array_map(static fn (array $f): string => $f['code'], $draft->flags)))),
                    'age_days' => (int) floor((Clock::now() - $draft->createdAt) / 86400),
                    'model' => $draft->model,
                ];
            }
            if (count($query->posts) < 200) {
                break;
            }
        }
        if ($rows === []) {
            \WP_CLI::log('No drafts.');
            \WP_CLI::halt(self::EXIT_NOTHING);
        }
        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['id', 'title', 'state', 'fields', 'flags', 'age_days', 'model']);
    }

    /**
     * Show one draft with its flags and facts.
     *
     * ## OPTIONS
     *
     * <id>
     * : Product id.
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function show(array $positional, array $assoc): void
    {
        $id = (int) ($positional[0] ?? 0);
        $draft = $this->plugin->acceptor->draft($id);
        if ($draft === null) {
            \WP_CLI::log('No draft for product ' . $id . '.');
            \WP_CLI::halt(self::EXIT_NOTHING);
        }
        \WP_CLI::log(sprintf(
            'Product %d: %s%s, model %s, created %s',
            $id,
            $draft->state,
            $draft->isStale() ? ' (stale)' : '',
            $draft->model === '' ? '-' : $draft->model,
            Clock::iso($draft->createdAt)
        ));
        foreach ($draft->fields as $field => $text) {
            \WP_CLI::log('');
            \WP_CLI::log('== ' . Fields::label($field) . ' ==');
            \WP_CLI::log($text);
        }
        if ($draft->note !== '') {
            \WP_CLI::log('');
            \WP_CLI::log('Note: ' . $draft->note);
        }
        \WP_CLI::log('');
        \WP_CLI::log('Flags:' . ($draft->flags === [] ? ' none' : ''));
        foreach ($draft->flags as $flag) {
            \WP_CLI::log(sprintf('  %s %s: %s', $flag['code'], $flag['field'], implode(', ', $flag['detail'])));
        }
        \WP_CLI::log('Facts (* used):');
        foreach ($draft->facts as $fact) {
            \WP_CLI::log(sprintf('  %s%s. %s: %s', in_array($fact['id'], $draft->usedFacts, true) ? '*' : ' ', $fact['id'], $fact['label'], $fact['value']));
        }
        if (is_array($draft->extra['error'] ?? null)) {
            \WP_CLI::log('Error: ' . (string) ($draft->extra['error']['class'] ?? '') . ' ' . (string) ($draft->extra['error']['message'] ?? ''));
        }
    }

    /**
     * Accept drafts, as the user given with --user.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Product ids.
     *
     * [--field=<field>]
     * : description, short or both.
     * ---
     * default: both
     * ---
     *
     * [--accept-stale]
     * : Accept drafts written from older product data.
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function accept(array $positional, array $assoc): void
    {
        if (get_current_user_id() === 0) {
            \WP_CLI::error('Run as a user who can edit the products, with --user=<login>.');

            return;
        }
        $field = (string) ($assoc['field'] ?? 'both');
        if (!Fields::isValid($field)) {
            \WP_CLI::error('--field must be description, short or both.');

            return;
        }
        $ok = 0;
        foreach ($positional as $id) {
            $draft = $this->plugin->drafts->get((int) $id);
            $which = $field;
            if ($draft !== null && $field === 'both' && count($draft->fields) === 1) {
                $which = (string) array_key_first($draft->fields);
            }
            $result = $this->plugin->acceptor->accept((int) $id, $which, ['accept_stale' => self::flag($assoc, 'accept-stale')]);
            if ($result->ok) {
                $ok++;
                \WP_CLI::log(sprintf('%d: accepted %s', (int) $id, implode(', ', $result->fields)));
            } else {
                \WP_CLI::warning(sprintf('%d: %s (%s)', (int) $id, $result->reason, Messages::reason($result->reason)));
            }
        }
        \WP_CLI::halt($ok === 0 ? self::EXIT_NOTHING : self::EXIT_OK);
    }

    /**
     * Discard drafts.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Product ids.
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function discard(array $positional, array $assoc): void
    {
        $ok = 0;
        foreach ($positional as $id) {
            if ($this->plugin->acceptor->discard((int) $id)) {
                $ok++;
                \WP_CLI::log((int) $id . ': discarded');
            } else {
                \WP_CLI::warning((int) $id . ': nothing to discard, or not allowed (use --user)');
            }
        }
        \WP_CLI::halt($ok === 0 ? self::EXIT_NOTHING : self::EXIT_OK);
    }

    /**
     * Switch, caps, usage today, queued, pending and failed.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function status(array $positional, array $assoc): void
    {
        $data = (new StatusPanel($this->plugin))->data();
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode($data));

            return;
        }
        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = ['key' => $key, 'value' => is_bool($value) ? ($value ? 'yes' : 'no') : ($value === null ? '-' : (string) $value)];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * Turn generation off. Queued jobs wait.
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function pause(array $positional, array $assoc): void
    {
        $this->plugin->switch->turnOff();
        $this->plugin->log->add('paused', 0, 'switched_off');
        do_action('product_copy_paused', 'switched_off');
        \WP_CLI::success('Generation is off.');
    }

    /**
     * Turn generation on and clear a pause.
     *
     * @param list<string> $positional
     * @param array<string, mixed> $assoc
     */
    public function resume(array $positional, array $assoc): void
    {
        $this->plugin->switch->turnOn();
        $this->plugin->log->add('resumed');
        if (\IndianOpinion\WcProductCopy\KillSwitch::forcedOff()) {
            \WP_CLI::warning('PRODUCT_COPY_DISABLED is true; the constant keeps generation off.');
            \WP_CLI::halt(self::EXIT_PAUSED);
        }
        \WP_CLI::success('Generation is on.');
    }

    /**
     * @param array{fields: string, regenerate_owned: bool, refresh_drafts: bool, include_discarded: bool} $args
     */
    private function showPrompt(int $productId, array $args): void
    {
        try {
            $item = $this->plugin->planner->evaluate($productId, $this->plugin->planner->normaliseArgs($args));
        } catch (ProductCopyException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        if (!$item->runs() || $item->prompt === null) {
            \WP_CLI::log(sprintf('Product %d would be skipped: %s (%s).', $productId, $item->reason, Messages::reason($item->reason)));
            \WP_CLI::halt(self::EXIT_NOTHING);
        }
        \WP_CLI::log('--- system ---');
        \WP_CLI::log($item->prompt->system);
        \WP_CLI::log('--- user ---');
        \WP_CLI::log($item->prompt->user);
        if ($item->image) {
            \WP_CLI::log(Prompt::IMAGE_PLACEHOLDER);
        }
        \WP_CLI::log(sprintf('--- %s, prompt %s, input hash %s ---', implode('+', $item->fields), $item->prompt->version, substr($item->inputHash, 0, 16)));
    }

    private function report(Plan $plan, string $format): void
    {
        $rows = array_map(static fn ($i): array => [
            'id' => $i->productId,
            'title' => $i->title,
            'fields' => implode(',', $i->fields),
            'decision' => $i->decision,
            'reason' => $i->reason,
            'facts' => $i->factCount,
            'image' => $i->image ? 'yes' : 'no',
            'est_tokens' => $i->estTokens,
        ], $plan->items);
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($plan->toArray()));

            return;
        }
        if ($rows !== []) {
            \WP_CLI\Utils\format_items($format === 'csv' ? 'csv' : 'table', $rows, ['id', 'title', 'fields', 'decision', 'reason', 'facts', 'image', 'est_tokens']);
        }
        if ($format === 'csv') {
            return;
        }
        $totals = $plan->totals();
        $headroom = $plan->headroom();
        $reasons = [];
        foreach ($totals['by_reason'] as $reason => $n) {
            $reasons[] = $reason . '=' . $n;
        }
        \WP_CLI::log(sprintf(
            'Products: %d. Run: %d. Skip: %d (%s). Calls: %d. Estimated input tokens: %d.',
            $totals['products'],
            $totals['run'],
            $totals['skip'],
            implode(', ', $reasons),
            $totals['calls'],
            $totals['est_tokens']
        ));
        \WP_CLI::log(sprintf('Cap headroom: %d calls left today; %d today, %d later.', $headroom['calls_left_today'], $headroom['today'], $headroom['later']));
    }

    private function runNow(Plan $plan): void
    {
        $saved = 0;
        foreach ($plan->runnable() as $item) {
            $outcome = $this->plugin->generator->run($item->productId, $plan->args, 'cli');
            \WP_CLI::log(sprintf('%d: %s %s', $item->productId, $outcome->status, $outcome->reason));
            if ($outcome->status === JobOutcome::SAVED) {
                $saved++;
            } elseif ($outcome->status === JobOutcome::WAIT_DAY) {
                \WP_CLI::warning('A daily cap is reached (' . $outcome->reason . '). Stopping.');
                \WP_CLI::halt(self::EXIT_CAP);
            } elseif ($outcome->status === JobOutcome::WAIT_SWITCH) {
                \WP_CLI::warning('Generation is paused (' . $outcome->reason . '). Stopping.');
                \WP_CLI::halt(self::EXIT_PAUSED);
            }
        }
        \WP_CLI::success(sprintf('%d drafts saved.', $saved));
        \WP_CLI::halt($saved === 0 ? self::EXIT_NOTHING : self::EXIT_OK);
    }

    /**
     * @param array<string, mixed> $assoc
     */
    private static function flag(array $assoc, string $name): bool
    {
        if (!array_key_exists($name, $assoc)) {
            return false;
        }
        $value = $assoc[$name];

        return $value === true || in_array(is_scalar($value) ? strtolower((string) $value) : '', ['1', 'true', 'yes', ''], true);
    }
}
