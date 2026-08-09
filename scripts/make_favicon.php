<?php
/**
 * Build a multi-size favicon.ico from the brand icon.
 *
 * GD cannot write ICO, but the format allows each entry to hold a whole PNG
 * (browsers have supported that since IE6-era tooling), so the container is
 * assembled by hand around GD-rendered PNGs.
 */
$srcPath = $argv[1];
$outPath = $argv[2];
$sizes = [16, 32, 48];

$src = imagecreatefrompng($srcPath);
$pngs = [];

foreach ($sizes as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, 255, 255, 255));
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

    ob_start();
    imagepng($canvas, null, 9);
    $pngs[$size] = ob_get_clean();
    imagedestroy($canvas);
}

// ICONDIR: reserved(2) type(2)=1 count(2)
$ico = pack('vvv', 0, 1, count($pngs));
$offset = 6 + 16 * count($pngs);

foreach ($pngs as $size => $data) {
    // ICONDIRENTRY: w(1) h(1) colours(1) reserved(1) planes(2) bpp(2) bytes(4) offset(4)
    $ico .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($data), $offset);
    $offset += strlen($data);
}
foreach ($pngs as $data) {
    $ico .= $data;
}

file_put_contents($outPath, $ico);
printf("wrote %s — %d sizes, %d bytes\n", basename($outPath), count($pngs), strlen($ico));
