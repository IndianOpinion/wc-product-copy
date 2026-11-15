<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 33: soft integration with spend-meter, active only when
 * `SPEND_METER_API` is defined and equal to 1.
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
            'estimated_input_tokens' => $estimatedInput,
            'max_output_tokens' => $outputCap,
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
        do_action('spend_meter_record', [
            'reservation_id' => $reservation,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $tokensIn,
            'output_tokens' => $tokensOut,
            'cost' => $cost,
            'status' => $status,
        ]);
    }

    public function release(?string $reservation): void
    {
        if (!self::present() || $reservation === null) {
            return;
        }
        do_action('spend_meter_release', $reservation);
    }
}
