<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 57: once a day, strip the text from records past the retention
 * period, keeping state, hashes and times. At most 500 products per run.
 */
final class Housekeeping
{
    public const HOOK = 'product_copy_housekeeping';
    public const LIMIT = 500;

    public function __construct(
        private readonly DraftStore $drafts,
        private readonly SettingsRepository $settings
    ) {
    }

    public function run(): int
    {
        /** @var mixed $days */
        $days = apply_filters('product_copy_retention_days', $this->settings->get()->retentionDays());
        $days = is_numeric($days) ? max(1, (int) $days) : 90;
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => self::LIMIT,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => Meta::PURGE_AFTER,
                'value' => Clock::now(),
                'compare' => '<=',
                'type' => 'NUMERIC',
            ]],
        ]);
        $done = 0;
        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = (int) (is_object($id) && isset($id->ID) ? $id->ID : $id);
            $draft = $this->drafts->get($id);
            if ($draft !== null) {
                $since = $draft->decidedAt ?? $draft->createdAt;
                if ($since + $days * 86400 > Clock::now()) {
                    // The filter lengthened retention: move the purge time.
                    update_post_meta($id, Meta::PURGE_AFTER, $since + $days * 86400);
                    continue;
                }
                $stripped = $draft->with([
                    'fields' => [],
                    'note' => '',
                    'facts' => [],
                    'flags' => array_map(static fn (array $f): array => ['code' => $f['code'], 'field' => $f['field'], 'detail' => []], $draft->flags),
                    'extra' => ['purged' => true, 'purged_at' => Clock::now(), 'replaced' => [], 'error' => null],
                ]);
                $json = wp_json_encode($stripped->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($json)) {
                    update_post_meta($id, Meta::DRAFT, wp_slash($json));
                }
            }
            delete_post_meta($id, Meta::PURGE_AFTER);
            $done++;
        }

        return $done;
    }
}
