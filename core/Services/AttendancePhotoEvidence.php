<?php
declare(strict_types=1);

/**
 * HR-owned photo admission policy, shared by the HR and Check-in capture routes.
 * This rejects unusable frames; it does not identify a person or prove liveness.
 */
final class AttendancePhotoEvidence
{
    public const MIN_SIDE = 120;
    public const MIN_PIXELS = 19200;
    public const MAX_SIDE = 8192;
    public const MAX_PIXELS = 16000000;
    public const SAMPLE_SIDE = 64;
    public const CHANNEL_TOLERANCE = 3;
    public const MIN_DETAIL_FRACTION = 0.005;

    /** Caller owns the returned GD image and must destroy it after use. */
    public static function decode(string $encoded, int $maxBytes = 10485760): ?GdImage
    {
        if ($maxBytes < 1 || strlen($encoded) > (int) ceil($maxBytes / 3) * 4 + 128) {
            return null;
        }
        $declared = null;
        if (str_starts_with($encoded, 'data:')) {
            if (!preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,#i', $encoded, $match)) {
                return null;
            }
            $declared = strtolower($match[1]);
            $declared = $declared === 'jpg' ? 'jpeg' : $declared;
            $encoded = substr($encoded, strlen($match[0]));
        }
        if (!self::fitsMemory(0, (int) floor(strlen($encoded) * 3 / 4))) {
            return null;
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '' || strlen($binary) > $maxBytes) {
            return null;
        }
        return self::decodeBinary($binary, $maxBytes, $declared);
    }

    /** For a server-controlled, already stored image. Never accepts a path. */
    public static function decodeBinary(string $binary, int $maxBytes = 10485760, ?string $declaredSubtype = null): ?GdImage
    {
        if ($binary === '' || $maxBytes < 1 || strlen($binary) > $maxBytes || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($binary);
        if (!is_array($info)) {
            return null;
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = (string) ($info['mime'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            || ($declaredSubtype !== null && $mime !== 'image/' . $declaredSubtype)
            || min($width, $height) < self::MIN_SIDE
            || max($width, $height) > self::MAX_SIDE
            || $width * $height < self::MIN_PIXELS
            || $width * $height > self::MAX_PIXELS
            || !self::fitsMemory($width * $height, strlen($binary))) {
            return null;
        }
        try {
            $image = @imagecreatefromstring($binary);
        } catch (Throwable $e) {
            return null;
        }
        if (!$image instanceof GdImage) {
            return null;
        }
        if (!self::hasDetail($image)) {
            imagedestroy($image);
            return null;
        }
        return $image;
    }

    public static function isValid(string $encoded, int $maxBytes = 10485760): bool
    {
        $image = self::decode($encoded, $maxBytes);
        if ($image === null) {
            return false;
        }
        imagedestroy($image);
        return true;
    }

    public static function isValidBinary(string $binary, int $maxBytes = 10485760): bool
    {
        $image = self::decodeBinary($binary, $maxBytes);
        if ($image === null) return false;
        imagedestroy($image);
        return true;
    }

    /**
     * No minimum brightness: dark frames with actual detail must remain usable.
     * Median-relative coverage ignores isolated hot pixels and compression noise.
     */
    public static function hasDetail(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (min($width, $height) < self::MIN_SIDE || $width * $height < self::MIN_PIXELS) {
            return false;
        }
        $samples = [];
        $channels = [[], [], []];
        $columns = min(self::SAMPLE_SIDE, $width);
        $rows = min(self::SAMPLE_SIDE, $height);
        for ($y = 0; $y < $rows; $y++) {
            $sy = min($height - 1, (int) floor(($y + 0.5) * $height / $rows));
            for ($x = 0; $x < $columns; $x++) {
                $sx = min($width - 1, (int) floor(($x + 0.5) * $width / $columns));
                $c = imagecolorsforindex($image, imagecolorat($image, $sx, $sy));
                // JPEG persistence composites transparency against black.
                $opacity = (127 - (int) ($c['alpha'] ?? 0)) / 127;
                $rgb = [(int) round($c['red'] * $opacity), (int) round($c['green'] * $opacity), (int) round($c['blue'] * $opacity)];
                $samples[] = $rgb;
                foreach ($rgb as $channel => $value) {
                    $channels[$channel][] = $value;
                }
            }
        }
        $count = count($samples);
        $median = [];
        $range = 0;
        foreach ($channels as $i => $values) {
            sort($values, SORT_NUMERIC);
            $median[$i] = $values[intdiv($count, 2)];
            $range = max($range, $values[$count - 1] - $values[0]);
        }
        if ($range <= self::CHANNEL_TOLERANCE) {
            return false;
        }
        $required = max(8, (int) ceil($count * self::MIN_DETAIL_FRACTION));
        $detail = 0;
        foreach ($samples as $rgb) {
            if (max(abs($rgb[0] - $median[0]), abs($rgb[1] - $median[1]), abs($rgb[2] - $median[2])) > self::CHANNEL_TOLERANCE
                && ++$detail >= $required) {
                return true;
            }
        }
        return false;
    }

    private static function fitsMemory(int $pixels, int $bytes): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $value = (float) $limit;
        $suffix = strtolower(substr($limit, -1));
        $multiplier = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$suffix] ?? 1;
        // Decoded source + resize scratch + compressed bytes + modest PHP overhead.
        return memory_get_usage(true) + $pixels * 8 + $bytes * 2 + 8388608 < $value * $multiplier;
    }
}
