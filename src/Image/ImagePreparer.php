<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Image;

/**
 * Cases 10 to 12: read the image from local disk (or fetch it from the
 * site's own hosts), resize so the longest side is at most 1,024 pixels,
 * re-encode as JPEG at quality 82 and strip every metadata segment.
 */
final class ImagePreparer
{
    public const QUALITY = 82;
    public const FETCH_TIMEOUT = 10;
    public const FETCH_LIMIT = 10485760;
    private const MAX_REDIRECTS = 2;

    public function prepare(int $attachmentId): PreparedImage
    {
        /** @var mixed $maxSide */
        $maxSide = apply_filters('product_copy_image_max_side', 1024);
        $maxSide = is_numeric($maxSide) ? max(64, min(4096, (int) $maxSide)) : 1024;

        $file = (string) get_attached_file($attachmentId);
        $temp = null;
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            $url = (string) wp_get_attachment_url($attachmentId);
            if ($url === '') {
                return PreparedImage::unavailable('no_file');
            }
            [$path, $reason] = $this->fetch($url);
            if ($path === null) {
                return PreparedImage::unavailable($reason);
            }
            $file = $temp = $path;
        }

        try {
            return $this->encode($file, $maxSide);
        } finally {
            if ($temp !== null && is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * Fetch an offloaded image. Only the site's own hosts, no redirects to
     * other hosts, 10 seconds, 10 MB, image content types only.
     *
     * @return array{0: ?string, 1: string} A local temp file path, or null and a reason.
     */
    public function fetch(string $url): array
    {
        $allowed = self::allowedHosts();
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if ($host === '' || !in_array($scheme, ['http', 'https'], true) || !in_array($host, $allowed, true)) {
                return [null, $hop === 0 ? 'foreign_host' : 'redirect_off_host'];
            }
            $response = wp_safe_remote_get($url, [
                'timeout' => self::FETCH_TIMEOUT,
                'redirection' => 0,
                'limit_response_size' => self::FETCH_LIMIT + 1,
            ]);
            if (is_wp_error($response)) {
                return [null, 'fetch_error'];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                $location = self::header($response, 'location');
                if ($location === '') {
                    return [null, 'fetch_error'];
                }
                if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
                    $location = $scheme . '://' . $host . $location;
                }
                $url = $location;
                continue;
            }
            if ($code !== 200) {
                return [null, 'http_' . $code];
            }
            $type = strtolower(self::header($response, 'content-type'));
            if (!str_starts_with($type, 'image/') || str_contains($type, 'svg')) {
                return [null, 'not_an_image'];
            }
            $length = self::header($response, 'content-length');
            $body = (string) wp_remote_retrieve_body($response);
            if ((is_numeric($length) && (int) $length > self::FETCH_LIMIT) || strlen($body) > self::FETCH_LIMIT) {
                return [null, 'too_large'];
            }
            if ($body === '') {
                return [null, 'fetch_error'];
            }
            $temp = wp_tempnam('product-copy-image');
            if (!is_string($temp) || $temp === '' || file_put_contents($temp, $body) === false) {
                return [null, 'temp_unwritable'];
            }

            return [$temp, 'ok'];
        }

        return [null, 'too_many_redirects'];
    }

    /**
     * @param array<mixed> $response
     */
    private static function header(array $response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);
        if (is_array($value)) {
            $value = end($value);
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = [
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
        ];
        $uploads = wp_upload_dir(null, false);
        if (is_array($uploads) && isset($uploads['baseurl'])) {
            $hosts[] = wp_parse_url((string) $uploads['baseurl'], PHP_URL_HOST);
        }
        $out = [];
        foreach ($hosts as $host) {
            if (is_string($host) && $host !== '') {
                $out[] = strtolower($host);
            }
        }

        return array_values(array_unique($out));
    }

    private function encode(string $file, int $maxSide): PreparedImage
    {
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return PreparedImage::unavailable('editor_cannot_open');
        }
        $size = $editor->get_size();
        $width = (int) ($size['width'] ?? 0);
        $height = (int) ($size['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return PreparedImage::unavailable('no_size');
        }
        if (max($width, $height) > $maxSide) {
            $resized = $editor->resize($maxSide, $maxSide, false);
            if (is_wp_error($resized)) {
                return PreparedImage::unavailable('resize_failed');
            }
            $size = $editor->get_size();
            $width = (int) ($size['width'] ?? 0);
            $height = (int) ($size['height'] ?? 0);
        }
        $editor->set_quality(self::QUALITY);
        $temp = wp_tempnam('product-copy-jpeg');
        if (!is_string($temp) || $temp === '') {
            return PreparedImage::unavailable('temp_unwritable');
        }
        try {
            $saved = $editor->save($temp . '.jpg', 'image/jpeg');
            if (is_wp_error($saved) || !is_array($saved) || !isset($saved['path']) || !is_file((string) $saved['path'])) {
                return PreparedImage::unavailable('encode_failed');
            }
            $bytes = (string) file_get_contents((string) $saved['path']);
            unlink((string) $saved['path']);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        if ($bytes === '') {
            return PreparedImage::unavailable('encode_failed');
        }

        return PreparedImage::ok(JpegMeta::strip($bytes), $width, $height);
    }
}
