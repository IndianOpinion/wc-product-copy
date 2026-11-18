<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Image;

/**
 * Which image a product would send (case 9), decided without reading the
 * file. Used by the dry run and the input hash.
 */
final class ImageChoice
{
    public const NONE = 'none';
    public const DISABLED = 'disabled';
    public const SVG = 'svg';
    public const TEXT_ONLY_CALLER = 'caller_text_only';
    public const CANDIDATE = 'candidate';

    public function __construct(
        public readonly int $attachmentId,
        public readonly string $status,
        public readonly ?int $mtime = null
    ) {
    }

    public function willSend(): bool
    {
        return $this->status === self::CANDIDATE;
    }

    /**
     * @return array{id: int, mtime: ?int}|null
     */
    public function hashable(): ?array
    {
        return $this->willSend() ? ['id' => $this->attachmentId, 'mtime' => $this->mtime] : null;
    }

    /**
     * Featured image, else the first gallery image, else none. SVG never.
     */
    public static function pick(\WC_Product $product, bool $sendingOn, bool $callerTakesImages): self
    {
        $id = (int) $product->get_image_id();
        if ($id <= 0) {
            $gallery = $product->get_gallery_image_ids();
            $id = isset($gallery[0]) ? (int) $gallery[0] : 0;
        }
        if ($id <= 0) {
            return new self(0, self::NONE);
        }
        if (!$sendingOn) {
            return new self($id, self::DISABLED);
        }
        $mime = (string) get_post_mime_type($id);
        $file = (string) get_attached_file($id);
        if ($mime === 'image/svg+xml' || preg_match('/\.svgz?$/i', $file) === 1) {
            return new self($id, self::SVG);
        }
        if (!$callerTakesImages) {
            return new self($id, self::TEXT_ONLY_CALLER);
        }
        $mtime = null;
        if ($file !== '' && is_file($file)) {
            $time = filemtime($file);
            $mtime = $time === false ? null : $time;
        } else {
            $meta = wp_get_attachment_metadata($id);
            $mtime = is_array($meta) && isset($meta['filesize']) ? (int) $meta['filesize'] : null;
        }

        return new self($id, self::CANDIDATE, $mtime);
    }
}
