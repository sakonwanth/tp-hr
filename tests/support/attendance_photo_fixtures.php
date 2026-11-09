<?php
/** Deterministic generated fixtures; no real employee photographs. */
function photoFixture(int $width = 320, int $height = 240, string $kind = 'detail', string $format = 'png'): string
{
    $im = imagecreatetruecolor($width, $height);
    if ($kind === 'transparent') {
        imagealphablending($im, false);
        imagesavealpha($im, true);
    }
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $v = match ($kind) {
                'black', 'hot_pixel' => [0, 0, 0],
                'white' => [255, 255, 255],
                'color' => [180, 30, 90],
                'noise' => [50 + ($x + $y) % 4, 50 + ($x * 2 + $y) % 4, 50 + ($x + $y * 2) % 4],
                'dark_detail' => [($x + $y) % 13, ($x * 3 + $y) % 13, ($x + $y * 2) % 13],
                default => [($x * 3 + $y) % 256, ($x + $y * 2) % 256, ($x * 2 + $y * 3) % 256],
            };
            $c = $kind === 'transparent' ? imagecolorallocatealpha($im, $v[0], $v[1], $v[2], 127) : imagecolorallocate($im, $v[0], $v[1], $v[2]);
            imagesetpixel($im, $x, $y, $c);
        }
    }
    if ($kind === 'hot_pixel') imagesetpixel($im, (int) floor($width / 128), (int) floor($height / 128), 0xffffff);
    ob_start();
    $format === 'jpeg' ? imagejpeg($im, null, 90) : imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);
    return $bytes;
}
function photoDataUrl(string $binary, string $mime = 'png'): string { return 'data:image/' . $mime . ';base64,' . base64_encode($binary); }
function photoWithPngDimensions(string $png, int $width, int $height): string
{
    $png = substr_replace($png, pack('NN', $width, $height), 16, 8);
    return substr_replace($png, pack('N', crc32(substr($png, 12, 17))), 29, 4);
}
