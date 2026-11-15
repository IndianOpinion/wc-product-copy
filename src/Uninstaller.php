<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

use IndianOpinion\WcProductCopy\Caller\LlmJsonCaller;
use IndianOpinion\WcProductCopy\Queue\ActionSchedulerRunner;
use IndianOpinion\WcProductCopy\Queue\GenQueueRunner;

/**
 * Removes everything section 6 lists, on every site of a network, except
 * accepted descriptions (store content).
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        foreach (Meta::options() as $option) {
            delete_option($option);
        }
        foreach (Meta::postMetaKeys() as $key) {
            delete_post_meta_by_key($key);
        }
        // Lock transients (product_copy_lock_<id>, the runner lock) and the
        // library's cooldown transients under this plugin's own prefix.
        foreach ([Meta::LOCK_PREFIX, LlmJsonCaller::STORE_PREFIX] as $prefix) {
            $like = $wpdb->esc_like('_transient_' . $prefix) . '%';
            $timeout = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
            $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout));
            foreach (is_array($names) ? $names : [] as $name) {
                delete_option((string) $name);
            }
        }
        wp_clear_scheduled_hook(Housekeeping::HOOK);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(ActionSchedulerRunner::HOOK, [], ActionSchedulerRunner::GROUP);
        }
        if (function_exists('gen_queue_cancel_where')) {
            gen_queue_cancel_where(['type' => GenQueueRunner::TYPE]);
        }
    }
}
