<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Image;

/**
 * The result of preparing an image: a JPEG data URL, or a reason it is
 * unavailable. Never kept after the job.
 */
final class PreparedImage
{
    private function __construct(
        public readonly ?string $dataUrl,
        public readonly string $status,
        public readonly string $detail,
        public readonly int $width = 0,
        public readonly int $height = 0
    ) {
    }

    public static function ok(string $jpeg, int $width, int $height): self
    {
        return new self('data:image/jpeg;base64,' . base64_encode($jpeg), 'sent', '', $width, $height);
    }

    public static function unavailable(string $detail): self
    {
        return new self(null, 'image_unavailable', $detail);
    }

    public function sent(): bool
    {
        return $this->dataUrl !== null;
    }
}
