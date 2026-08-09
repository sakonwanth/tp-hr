<?php
/**
 * Find CSS classes the markup uses that no stylesheet defines.
 *
 *   php scripts/qa_dead_classes_audit.php
 *   php scripts/qa_dead_classes_audit.php --detail
 *
 * READ ONLY.
 *
 * A class that does not exist fails silently — the element just renders
 * without it. assets/css/app.css went stale and 354 utilities written across
 * the app did nothing at all: an opacity step Tailwind does not generate left
 * the default border, so the net-pay box looked wrong; self-start let a status
 * pill stretch the full
 * width; spacing utilities vanished, which is most of why every card read as
 * cramped. Nothing in the other audits could see it, and it took a screenshot
 * from a real user to notice.
 *
 * This is the check that would have caught it.
 *
 * KNOWN LIMITS
 *
 * Classes assembled in PHP are invisible here, as are ones only ever added by
 * JavaScript; both are skipped rather than guessed at. Font Awesome and other
 * CDN stylesheets are not local files, so their prefixes are ignored.
 */

$root = dirname(__DIR__);
$options = getopt('', ['detail']);
$detail = isset($options['detail']);

/** Prefixes owned by stylesheets that are not on disk here. */
const EXTERNAL_PREFIXES = ['fa-', 'fas', 'far', 'fab', 'fal', 'fad', 'swal2-', 'flatpickr'];

/** Utility families Tailwind generates on demand; a miss here is meaningful. */
function isIgnorable(string $class): bool
{
    if ($class === '') return true;
    foreach (EXTERNAL_PREFIXES as $p) {
        if ($class === $p || strpos($class, $p) === 0) return true;
    }
    // State prefixes resolve to their own selectors; check the base instead.
    if (strpos($class, ':') !== false) return true;
    // Arbitrary values with dynamic content are not worth guessing.
    if (strpos($class, '[') !== false && strpos($class, '$') !== false) return true;
    return false;
}

// ------------------------------------------------------- what CSS defines

$cssFiles = [];
foreach (['assets/css/app.css', 'assets/css/native-shell.css'] as $f) {
    $p = $root . '/' . $f;
    if (is_file($p)) $cssFiles[$p] = (string)file_get_contents($p);
}

// Inline <style> blocks in templates define plenty too.
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    $rel = '/' . ltrim(str_replace($root, '', $p), '/');
    foreach (['/.claude/', '/vendor/', '/node_modules/', '/ios-app/', '/scripts/', '/tests/', '/cron/'] as $skip) {
        if (strpos($rel, $skip) !== false) continue 2;
    }
    $phpFiles[$p] = (string)file_get_contents($p);
}

$defined = [];

function collectDefined(string $css, array &$defined): void
{
    // .foo, .foo\:bar, .foo\/50, .max-w-\[min\(960px\2c 100\%\)\]
    //
    // The hex form matters: CSS writes a comma as '\2c ' — backslash, hex,
    // trailing space. A character class that stops at whitespace truncates the
    // selector there, and every arbitrary value containing a comma then looks
    // undefined. Match that form explicitly, before the generic escape.
    $pattern = '/\.((?:\\\\[0-9a-fA-F]{1,6} |\\\\.|[a-zA-Z0-9_-])+)/';
    if (!preg_match_all($pattern, $css, $m)) return;

    foreach ($m[1] as $sel) {
        $name = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6}) ?/',
            fn($h) => chr(hexdec($h[1])),
            $sel
        );
        $defined[str_replace('\\', '', $name)] = true;
    }
}

foreach ($cssFiles as $css) {
    collectDefined($css, $defined);
}
foreach ($phpFiles as $php) {
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $php, $sm)) {
        foreach ($sm[1] as $block) collectDefined($block, $defined);
    }
}

// ------------------------------------------------------- what markup uses

$used = [];

foreach ($phpFiles as $path => $php) {
    $rel = str_replace($root . '/', '', $path);

    if (!preg_match_all('/class\s*=\s*"([^"]*)"/i', $php, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }

    foreach ($m as $hit) {
        $value = $hit[1][0];
        // Built in PHP — cannot be judged from source.
        if (strpos($value, '<?') !== false || strpos($value, '$') !== false) continue;

        $line = substr_count(substr($php, 0, $hit[0][1]), "\n") + 1;
        foreach (preg_split('/\s+/', trim($value)) as $class) {
            if (isIgnorable($class)) continue;
            if (isset($defined[$class])) continue;
            $used[$class][] = $rel . ':' . $line;
        }
    }
}

// ----------------------------------------------------------------- report

echo "TP-HR — dead class audit (read only)\n";
printf("stylesheets: %d, templates: %d\n", count($cssFiles), count($phpFiles));
printf("classes defined: %d\n", count($defined));
echo str_repeat('=', 70) . "\n\n";

if ($used === []) {
    echo "Every class the markup uses exists in CSS.\n";
    exit(0);
}

uasort($used, fn($a, $b) => count($b) <=> count($a));

/**
 * A missing Tailwind utility is always a defect — it was written to do
 * something and does nothing. A missing custom name usually is not: the app
 * uses unstyled classes as hooks, for JavaScript (education-row, tab-panel)
 * and for the contract checks in scripts/verify-*.sh (tp-ios-master-screen).
 * Reporting both as failures buries the ones that matter.
 */
function looksLikeUtility(string $class): bool
{
    if (!preg_match('/^[a-z][a-z0-9-]*(?:\/\d+)?(?:\[.*\])?$/', $class)) return false;

    static $families = [
        'p', 'px', 'py', 'pt', 'pb', 'pl', 'pr', 'm', 'mx', 'my', 'mt', 'mb', 'ml', 'mr',
        'w', 'h', 'min-w', 'min-h', 'max-w', 'max-h', 'gap', 'space',
        'text', 'bg', 'border', 'rounded', 'shadow', 'ring', 'opacity',
        'flex', 'grid', 'items', 'justify', 'self', 'col', 'row', 'order',
        'font', 'leading', 'tracking', 'align', 'whitespace', 'truncate',
        'overflow', 'z', 'top', 'bottom', 'left', 'right', 'inset',
        'accent', 'line-clamp', 'aspect', 'object', 'translate', 'scale', 'rotate',
    ];

    foreach ($families as $f) {
        if ($class === $f || strpos($class, $f . '-') === 0) return true;
    }
    return false;
}

$utilities = [];
$custom = [];
foreach ($used as $class => $where) {
    // Stray punctuation from a class attribute spliced together in PHP.
    if (!preg_match('/^[a-zA-Z]/', $class)) continue;
    if (looksLikeUtility($class)) $utilities[$class] = $where;
    else $custom[$class] = $where;
}

if ($utilities !== []) {
    echo "DEAD UTILITIES — written but generating no CSS:\n\n";
    foreach ($utilities as $class => $where) {
        printf("  %-42s %3d use(s)\n", $class, count($where));
        if ($detail) foreach (array_slice($where, 0, 6) as $w) echo "        $w\n";
    }
    echo "\n";
}

if ($custom !== []) {
    printf("Unstyled custom classes (%d) — usually JS or test hooks, not defects:\n  ", count($custom));
    echo implode(', ', array_slice(array_keys($custom), 0, 14));
    if (count($custom) > 14) printf(' … +%d', count($custom) - 14);
    echo "\n\n";
}

echo str_repeat('=', 70) . "\n";

if ($utilities === []) {
    echo "No dead utilities. Every Tailwind class in the markup generates CSS.\n";
    exit(0);
}

printf("%d dead utility class(es).\n\n", count($utilities));
echo "Check the value is one Tailwind actually generates — the opacity scale is\n";
echo "5/10/20/25/30…, so border-white/8 produces nothing. If the value is valid,\n";
echo "rebuild: npx tailwindcss -i assets/css/input.css -o assets/css/app.css\n";
echo "--minify — then bump the ?v= on BOTH stylesheets together.\n";

exit(1);
