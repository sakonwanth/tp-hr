<?php
/**
 * Audit the UI against tp-common/UI_RULES.md.
 *
 *   php scripts/qa_ui_standard_audit.php            # summary
 *   php scripts/qa_ui_standard_audit.php --detail   # every offending line
 *   php scripts/qa_ui_standard_audit.php --file=hr/leaves.php
 *
 * READ ONLY.
 *
 * The rules being checked (from UI_RULES.md):
 *   - touch target / button height  >= 48px
 *   - input height                  >= 52px
 *   - no text wrapping in buttons
 *
 * A design system already exists in native-shell.css (.tp-native-btn-primary,
 * .tp-native-input, .tp-native-card …). Anything using those is compliant by
 * construction; the findings here are places that hand-rolled Tailwind
 * instead and drifted. That mixture — not any single wrong number — is what
 * makes the UI look inconsistent.
 *
 * Compliant classes are discovered by reading the CSS, not hardcoded, so the
 * audit stays correct as the design system grows.
 */

$root = dirname(__DIR__);
$options = getopt('', ['detail', 'file::']);
$detail = isset($options['detail']);
$onlyFile = $options['file'] ?? null;

// ---------------------------------------------------- what counts as tall enough

/**
 * Classes whose CSS already guarantees a height. Parsed from the stylesheets
 * plus the inline <style> blocks in the page templates.
 */
/**
 * The design system expresses sizes as tokens — min-height:
 * var(--tp-native-touch-min). Without resolving those, every component that
 * follows the system looks like it declares no height at all.
 *
 * @return array<string,string> token name => literal value
 */
function cssTokens(array $cssSources): array
{
    $tokens = [];
    foreach ($cssSources as $css) {
        if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $t) {
                $tokens[$t[1]] = trim($t[2]);
            }
        }
    }
    return $tokens;
}

function resolveTokens(string $value, array $tokens, int $depth = 0): string
{
    if ($depth > 5 || strpos($value, 'var(') === false) return $value;

    $resolved = preg_replace_callback(
        '/var\(\s*(--[a-zA-Z0-9-]+)\s*(?:,\s*([^()]*))?\)/',
        fn($m) => $tokens[$m[1]] ?? ($m[2] ?? ''),
        $value
    ) ?? $value;

    return resolveTokens($resolved, $tokens, $depth + 1);
}

function classesTallerThan(int $px, array $cssSources, array $tokens = []): array
{
    $ok = [];
    foreach ($cssSources as $css) {
        // .foo { ... min-height: 56px ... }  /  height: 3.5rem
        if (!preg_match_all('/\.([a-zA-Z0-9_-]+)\s*(?:,[^{]*)?\{([^}]*)\}/s', $css, $m, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($m as $rule) {
            if (!preg_match('/(?:min-height|height)\s*:\s*([^;]+)/i', $rule[2], $h)) continue;
            $declared = resolveTokens(trim($h[1]), $tokens);
            if (!preg_match('/([0-9.]+)\s*(px|rem)/i', $declared, $v)) continue;
            $value = (float)$v[1] * (strtolower($v[2]) === 'rem' ? 16 : 1);
            if ($value >= $px) $ok[$rule[1]] = true;
        }
    }
    return $ok;
}

$css = [];
foreach (['assets/css/native-shell.css', 'templates/header.php', 'login.php'] as $f) {
    $p = $root . '/' . $f;
    if (is_file($p)) $css[] = (string)file_get_contents($p);
}

$tokens = cssTokens($css);
$tallEnoughButton = classesTallerThan(48, $css, $tokens);
$tallEnoughInput  = classesTallerThan(52, $css, $tokens);

/** Tailwind arbitrary values: min-h-[56px], h-[3.5rem]. */
function tailwindHeightOk(string $classAttr, int $px): bool
{
    if (preg_match_all('/(?:min-)?h-\[([0-9.]+)(px|rem)\]/', $classAttr, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $value = (float)$hit[1] * (strtolower($hit[2]) === 'rem' ? 16 : 1);
            if ($value >= $px) return true;
        }
    }
    // Tailwind scale: h-12 = 3rem = 48px, h-14 = 56px …
    if (preg_match_all('/(?:min-)?h-(\d+)(?![\w-])/', $classAttr, $m)) {
        foreach ($m[1] as $step) {
            if (((int)$step) * 4 >= $px) return true;
        }
    }
    return false;
}

function usesCompliantClass(string $classAttr, array $known): bool
{
    foreach (preg_split('/\s+/', trim($classAttr)) as $c) {
        if ($c !== '' && isset($known[$c])) return true;
    }
    return false;
}

// ------------------------------------------------------------------ scan

$files = [];
if ($onlyFile !== null) {
    $files[] = $root . '/' . ltrim((string)$onlyFile, '/');
} else {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (substr($p, -4) !== '.php') continue;

        // Match against the path RELATIVE to the repo. Matching the absolute
        // path skips everything when the checkout itself lives under one of
        // these names — a git worktree under .claude/ does exactly that.
        $rel = '/' . ltrim(str_replace($root, '', $p), '/');
        foreach (['/.claude/', '/vendor/', '/node_modules/', '/ios-app/', '/scripts/', '/tests/', '/cron/', '/api/'] as $skip) {
            if (strpos($rel, $skip) !== false) continue 2;
        }
        $files[] = $p;
    }
}
sort($files);

$findings = [];

function add(array &$findings, string $rule, string $file, int $line, string $snippet): void
{
    $findings[$rule][] = ['file' => $file, 'line' => $line, 'snippet' => mb_substr(trim($snippet), 0, 120)];
}

$skipped = 0;

foreach ($files as $path) {
    if (!is_file($path)) continue;
    $rel = str_replace($root . '/', '', $path);
    $source = (string)file_get_contents($path);

    // Inline PHP inside a tag closes with '>', which stops any [^>]* tag
    // match halfway and hides the class attribute that follows — every such
    // button then looks like it has no height at all. Blank the PHP blocks
    // first, keeping newline count intact so reported line numbers stay true.
    $source = preg_replace_callback(
        '/<\?(?:php|=)?.*?\?' . '>/s',
        fn($m) => str_repeat("\n", substr_count($m[0], "\n")) . 'PHPEXPR',
        $source
    ) ?? $source;

    // A page's own inline <style> defines classes like .tb-btn and .btn-print.
    // Without reading it, every use of those looks like a bare, height-less
    // button — which is how the first run produced a pile of false positives.
    $localButton = $tallEnoughButton;
    $localInput = $tallEnoughInput;
    if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $source, $sm)) {
        $localTokens = array_merge($tokens, cssTokens($sm[1]));
        $localButton = array_merge($localButton, classesTallerThan(48, $sm[1], $localTokens));
        $localInput = array_merge($localInput, classesTallerThan(52, $sm[1], $localTokens));
    }

    // Tags routinely span lines and embed inline PHP echo blocks, so match
    // across newlines rather than per line. (Writing a literal PHP close tag
    // in this comment would end the script here — even inside a // comment.)
    if (!preg_match_all('/<(button|input|select|textarea)\b[^>]*>/is', $source, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }

    foreach ($m as $hit) {
        $tag = $hit[0][0];
        $tagName = strtolower($hit[1][0]);
        $lineNo = substr_count(substr($source, 0, $hit[0][1]), "\n") + 1;

        preg_match('/class\s*=\s*"([^"]*)"/i', $tag, $c);
        $classAttr = $c[1] ?? '';

        // A class built from a PHP variable cannot be judged from source.
        // Counting it as a violation would be a guess, so it is set aside and
        // reported separately.
        if (strpos($classAttr, '<?') !== false || strpos($classAttr, '$') !== false
            || preg_match('/class\s*=\s*[\'"]?\s*\.\s*\$/', $tag)) {
            $skipped++;
            continue;
        }

        if ($tagName === 'button') {
            if (preg_match('/type\s*=\s*"(submit|button|reset)"/i', $tag) === 0 && $classAttr === '') {
                // opening tag captured without its attributes — not judgeable
                $skipped++;
                continue;
            }
            if (!usesCompliantClass($classAttr, $localButton) && !tailwindHeightOk($classAttr, 48)) {
                add($findings, 'button height < 48px (UI_RULES: button minimum 48px)', $rel, $lineNo, $tag);
            }
            if ($classAttr !== '' && strpos($classAttr, 'whitespace-nowrap') === false
                && !usesCompliantClass($classAttr, $localButton)) {
                add($findings, 'button may wrap text (UI_RULES: no text wrapping in buttons)', $rel, $lineNo, $tag);
            }
            continue;
        }

        if (preg_match('/type\s*=\s*"(checkbox|radio|hidden|submit|button|file)"/i', $tag)) continue;
        if ($classAttr === '') { $skipped++; continue; }

        if (!usesCompliantClass($classAttr, $localInput) && !tailwindHeightOk($classAttr, 52)) {
            add($findings, 'input height < 52px (UI_RULES: input minimum 52px)', $rel, $lineNo, $tag);
        }
    }
}

// ---------------------------------------------------------------- report

echo "TP-HR — UI standard audit (read only)\n";
echo "against tp-common/UI_RULES.md\n";
printf("files scanned: %d\n", count($files));
printf("design-system classes recognised: %d button-height, %d input-height\n",
    count($tallEnoughButton), count($tallEnoughInput));
echo str_repeat('=', 72) . "\n\n";

$total = 0;
foreach ($findings as $rule => $hits) {
    $total += count($hits);
    printf("%-62s %4d\n", $rule, count($hits));

    $byFile = [];
    foreach ($hits as $h) {
        $byFile[$h['file']] = ($byFile[$h['file']] ?? 0) + 1;
    }
    arsort($byFile);
    foreach (array_slice($byFile, 0, $detail ? 100 : 6, true) as $file => $n) {
        printf("    %-56s %3d\n", $file, $n);
    }
    if (!$detail && count($byFile) > 6) {
        printf("    ... and %d more file(s)\n", count($byFile) - 6);
    }
    echo "\n";

    if ($detail) {
        foreach (array_slice($hits, 0, 40) as $h) {
            printf("      %s:%d\n        %s\n", $h['file'], $h['line'], $h['snippet']);
        }
        echo "\n";
    }
}

echo str_repeat('=', 72) . "\n";
printf("total findings: %d\n", $total);
printf("not judgeable from source (class built in PHP): %d — not counted\n", $skipped);

if ($total > 0) {
    echo "\nThese are places that hand-rolled Tailwind instead of using the\n";
    echo "design system in native-shell.css. Fixing means swapping in\n";
    echo ".tp-native-btn-* / .tp-native-input, not adding more one-off classes.\n";
}

exit($total > 0 ? 1 : 0);
