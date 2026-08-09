<?php
/**
 * Recolour the compiled Tailwind CSS in place.
 *
 * Rebuilding from source dropped 98 classes that the shipped build had —
 * including layout ones like grid-cols-5 and gap-8 that PHP files demonstrably
 * use — which broke the desktop layout. Substituting colour values inside the
 * known-good build keeps every selector byte-for-byte identical and changes
 * only what it renders.
 *
 * Handles both notations Tailwind emits: #hex in gradients/shadows and the
 * space-separated `rgb(R G B / var(--tw-...))` used by colour utilities.
 */

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
    exit("usage: php retint.php <app.css>\n");
}

// Tailwind's violet and purple scales -> the TP-HR brand scale.
$brand = [
    50 => '#f9f6f3', 100 => '#f3ede7', 200 => '#e8dccf', 300 => '#d8c4ad',
    400 => '#c7a989', 500 => '#b79168', 600 => '#a3805b', 700 => '#8c6d4d',
    800 => '#745a3f', 900 => '#5d4730', 950 => '#3d2e1f',
];

$violet = [
    50 => '#f5f3ff', 100 => '#ede9fe', 200 => '#ddd6fe', 300 => '#c4b5fd',
    400 => '#a78bfa', 500 => '#8b5cf6', 600 => '#7c3aed', 700 => '#6d28d9',
    800 => '#5b21b6', 900 => '#4c1d95', 950 => '#2e1065',
];

$purple = [
    50 => '#faf5ff', 100 => '#f3e8ff', 200 => '#e9d5ff', 300 => '#d8b4fe',
    400 => '#c084fc', 500 => '#a855f7', 600 => '#9333ea', 700 => '#7e22ce',
    800 => '#6b21a8', 900 => '#581c87', 950 => '#3b0764',
];

function hexToRgbTriple(string $hex): string
{
    return sprintf('%d %d %d', hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

$css = file_get_contents($file);
$before = $css;
$replaced = 0;

foreach ([$violet, $purple] as $scale) {
    foreach ($scale as $step => $from) {
        $to = $brand[$step];

        // #hex, either case
        $count = 0;
        $css = str_ireplace($from, $to, $css, $count);
        $replaced += $count;

        // rgb(R G B ...) space-separated form
        $count = 0;
        $css = str_replace(hexToRgbTriple($from), hexToRgbTriple($to), $css, $count);
        $replaced += $count;

        // rgb(R,G,B) comma form, just in case
        $count = 0;
        $css = str_replace(str_replace(' ', ',', hexToRgbTriple($from)), str_replace(' ', ',', hexToRgbTriple($to)), $css, $count);
        $replaced += $count;
    }
}

file_put_contents($file, $css);

printf("replaced %d colour occurrence(s)\n", $replaced);
printf("size before/after: %d / %d bytes\n", strlen($before), strlen($css));
