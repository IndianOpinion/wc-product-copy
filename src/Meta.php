<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Post meta keys, transient prefixes and option names (section 6). All
 * meta keys start with `_` so they stay out of the custom fields box.
 */
final class Meta
{
    public const DRAFT = '_product_copy_draft';
    public const OWNED = '_product_copy_owned';
    public const UNDO = '_product_copy_undo';
    public const NOTES = '_product_copy_notes';
    public const EXCLUDE = '_product_copy_exclude';
    /** Unix time after which housekeeping strips the draft text (case 57). */
    public const PURGE_AFTER = '_product_copy_purge_after';

    public const LOCK_PREFIX = 'product_copy_lock_';

    /**
     * @return list<string>
     */
    public static function postMetaKeys(): array
    {
        return [self::DRAFT, self::OWNED, self::UNDO, self::NOTES, self::EXCLUDE, self::PURGE_AFTER];
    }

    /**
     * @return list<string>
     */
    public static function options(): array
    {
        return [Settings::OPTION, Credentials::OPTION, KillSwitch::OPTION, Usage::OPTION, Log::OPTION];
    }
}
