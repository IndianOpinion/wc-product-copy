<?php

/**
 * Case 64: remove options, post meta, locks and queued jobs. Accepted
 * descriptions are store content and stay. Per site on multisite.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\WcProductCopy\Uninstaller::run();
