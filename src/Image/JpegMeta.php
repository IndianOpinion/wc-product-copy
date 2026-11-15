<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy\Image;

/**
 * Removes every APPn (except the JFIF APP0) and comment segment from a
 * JPEG, so no location, camera or author data leaves the site even when an
 * image editor keeps it on re-encode.
 */
final class JpegMeta
{
    public static function strip(string $jpeg): string
    {
        if (strlen($jpeg) < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return $jpeg;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $length = strlen($jpeg);
        while ($pos + 4 <= $length) {
            if ($jpeg[$pos] !== "\xFF") {
                return $jpeg;
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xDA) {
                return $out . substr($jpeg, $pos);
            }
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($jpeg, $pos, 2);
                $pos += 2;
                continue;
            }
            $segLength = unpack('n', substr($jpeg, $pos + 2, 2));
            $segLength = is_array($segLength) ? (int) $segLength[1] : 0;
            if ($segLength < 2 || $pos + 2 + $segLength > $length) {
                return $jpeg;
            }
            $isJfif = $marker === 0xE0 && substr($jpeg, $pos + 4, 5) === "JFIF\0";
            $isMeta = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE || ($marker === 0xE0 && !$isJfif);
            if (!$isMeta) {
                $out .= substr($jpeg, $pos, 2 + $segLength);
            }
            $pos += 2 + $segLength;
        }

        return $jpeg;
    }
}
