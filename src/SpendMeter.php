<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 33: soft integration with spend-meter, active only when
 * `SPEND_METER_API` is defined and equal to 1. Only its contract-1 hooks
 * are used: the filter `spend_meter_pre_spend` and the actions
 * `spend_meter_record` and `spend_meter_release`. Money crosses the
 * boundary as integer nanos.
 */
final class SpendMeter
{
    public const PURPOSE = 'product-copy.generate';
    public const CALLER = 'wc-product-copy';

    public static function present(): bool
    {
        return defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') === 1;
    }

    /**
     * Ask before a call.
     *
     * @return array{allowed: bool, reason: string, reservation: ?string}
     */
    public function preSpend(string $provider, string $model, int $estimatedInput, int $outputCap): array
    {
        if (!self::present()) {
            return ['allowed' => true, 'reason' => '', 'reservation' => null];
        }
        /** @var mixed $answer */
        $answer = apply_filters('spend_meter_pre_spend', null, [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'est_input_tokens' => max(0, $estimatedInput),
            'max_output_tokens' => max(0, $outputCap),
        ]);
        $answer = is_array($answer) ? $answer : [];
        $reservation = $answer['reservation_id'] ?? null;

        return [
            'allowed' => ($answer['allowed'] ?? null) === true,
            'reason' => is_string($answer['reason'] ?? null) ? $answer['reason'] : 'no_answer',
            'reservation' => is_scalar($reservation) && (string) $reservation !== '' ? (string) $reservation : null,
        ];
    }

    /**
     * @param string $status `ok`, `error_billed` or `error_unbilled`.
     */
    public function record(?string $reservation, string $provider, string $model, ?int $tokensIn, ?int $tokensOut, ?float $cost, string $status): void
    {
        if (!self::present()) {
            return;
        }
        $call = [
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => max(0, (int) $tokensIn),
            'output_tokens' => max(0, (int) $tokensOut),
            'status' => $status,
        ];
        if ($reservation !== null) {
            $call['reservation_id'] = $reservation;
        }
        if ($cost !== null && $cost >= 0.0) {
            // Provider-reported cost, in nanos as the contract requires.
            $call['provider_cost'] = self::nanos($cost);
        }
        do_action('spend_meter_record', $call);
    }

    /** Decimal currency units to integer nanos, rounded half up. */
    public static function nanos(float $amount): int
    {
        return (int) round($amount * 1000000000, 0, PHP_ROUND_HALF_UP);
    }

    public function release(?string $reservation): void
    {
        if (!self::present() || $reservation === null) {
            return;
        }
        do_action('spend_meter_release', $reservation);
    }
}
