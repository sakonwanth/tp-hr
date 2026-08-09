<?php
/**
 * Find cards nested inside other cards.
 *
 *   php scripts/qa_nested_cards_audit.php
 *   php scripts/qa_nested_cards_audit.php --file=payslip.php
 *
 * READ ONLY.
 *
 * Each card contributes its own padding, so a card inside a card inside a
 * card squeezes the content into a narrow column while the page around it
 * sits empty. On payslip.php that stack left roughly 190px of usable width on
 * a 375px screen, and text wrapped that had no need to.
 *
 * WHAT IT MEASURES
 *
 * Nesting on its own is not the defect. A small stat tile inside a row card
 * is good composition, and hr/reports.php is full of them — its tiles carry
 * py-2 and no horizontal padding at all, so they cost the content nothing.
 * Counting nestings flagged 110 of those and buried the real problem.
 *
 * What hurts is horizontal padding ACCUMULATING down the chain. Every
 * container that adds px eats the same width twice, and the text ends up in a
 * narrow column with the page empty on both sides. So this sums left/right
 * padding along each chain and reports the total.
 *
 * ACCURACY
 *
 * PHP branches mean the div tags in a template do not balance as written:
 * both arms of an if/else appear in the source. The stack is therefore
 * tolerant — an unmatched close just pops what it can — and the output is a
 * list of candidates to read, not a verdict. Check each one before changing
 * it.
 */

$root = dirname(__DIR__);

/** Horizontal padding budget per side. Beyond this the content column gets
 * visibly narrow on a 375px phone — payslip.php was at 92px. */
define('PADDING_BUDGET_PX', 56);
$options = getopt('', ['file::']);
$onlyFile = $options['file'] ?? null;

/** Container surfaces. Deliberately not derived from CSS — see the header. */
const CARD_CLASSES = [
    'native-card',
    'tp-native-card',
    'tp-native-data-card',
    'tp-ios-attendance-panel',
    'tp-holidays-main-card',
    'glass-card',
    'stat-card',
    'dashboard-hero-summary',
];

/** A hand-rolled card: the card radius token plus a background and padding. */
function looksLikeInlineCard(string $classAttr): bool
{
    $hasRadius = strpos($classAttr, 'rounded-[var(--tp-ios-card-radius)]') !== false
        || strpos($classAttr, 'rounded-[var(--tp-radius-card)]') !== false;
    $hasBg = preg_match('/\bbg-[a-z]/', $classAttr) === 1;
    $hasPad = preg_match('/\bp-\d|\bpx-\d|\bpy-\d/', $classAttr) === 1;

    return $hasRadius && $hasBg && $hasPad;
}

function cardKind(string $classAttr): ?string
{
    foreach (preg_split('/\s+/', trim($classAttr)) as $c) {
        if ($c !== '' && in_array($c, CARD_CLASSES, true)) return $c;
    }
    return looksLikeInlineCard($classAttr) ? 'inline-card' : null;
}

/** Horizontal padding a class list contributes, in px (mobile / unprefixed). */
function horizontalPadding(string $classAttr, array $cssPadding): int
{
    $px = 0;

    foreach (preg_split('/\s+/', trim($classAttr)) as $c) {
        if ($c === '') continue;
        if (isset($cssPadding[$c])) $px = max($px, $cssPadding[$c]);
    }

    // Responsive prefixes describe wider screens; mobile is what gets squeezed.
    if (preg_match_all('/(?<![a-z:-])(p|px)-(\d+(?:\.\d+)?)\b/', $classAttr, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $px = max($px, (int)round((float)$hit[2] * 4));
        }
    }
    if (preg_match_all('/(?<![a-z:-])(p|px)-\[(\d+)px\]/', $classAttr, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $px = max($px, (int)$hit[2]);
        }
    }

    return $px;
}

/** @return array<string,int> class => horizontal padding px, from the CSS */
function cssHorizontalPadding(array $cssSources, array $tokens): array
{
    $out = [];
    foreach ($cssSources as $css) {
        if (!preg_match_all('/\.([a-zA-Z0-9_-]+)\s*(?:,[^{]*)?\{([^}]*)\}/s', $css, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $rule) {
            $body = $rule[2];
            $value = null;
            if (preg_match('/padding-(?:left|right)\s*:\s*([^;]+)/i', $body, $p)) {
                $value = $p[1];
            } elseif (preg_match('/(?<!-)padding\s*:\s*([^;]+)/i', $body, $p)) {
                $parts = preg_split('/\s+/', trim($p[1]));
                $value = count($parts) === 1 ? $parts[0] : ($parts[1] ?? $parts[0]);
            }
            if ($value === null) continue;

            $resolved = $value;
            for ($i = 0; $i < 5 && strpos($resolved, 'var(') !== false; $i++) {
                $resolved = preg_replace_callback(
                    '/var\(\s*(--[a-zA-Z0-9-]+)\s*(?:,\s*([^()]*))?\)/',
                    fn($v) => $tokens[$v[1]] ?? ($v[2] ?? ''),
                    $resolved
                ) ?? $resolved;
            }
            if (preg_match('/([0-9.]+)\s*(px|rem)/i', $resolved, $n)) {
                $out[$rule[1]] = (int)round((float)$n[1] * (strtolower($n[2]) === 'rem' ? 16 : 1));
            }
        }
    }
    return $out;
}

$cssSources = [];
foreach (['assets/css/native-shell.css', 'templates/header.php'] as $f) {
    $p = $root . '/' . $f;
    if (is_file($p)) $cssSources[] = (string)file_get_contents($p);
}
$tokens = [];
foreach ($cssSources as $c) {
    if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $c, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) $tokens[$t[1]] = trim($t[2]);
    }
}
$cssPadding = cssHorizontalPadding($cssSources, $tokens);

// ------------------------------------------------------------------ files

$files = [];
if ($onlyFile !== null) {
    $files[] = $root . '/' . ltrim((string)$onlyFile, '/');
} else {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (substr($p, -4) !== '.php') continue;
        $rel = '/' . ltrim(str_replace($root, '', $p), '/');
        foreach (['/.claude/', '/vendor/', '/node_modules/', '/ios-app/', '/scripts/', '/tests/', '/cron/', '/api/'] as $skip) {
            if (strpos($rel, $skip) !== false) continue 2;
        }
        $files[] = $p;
    }
}
sort($files);

// ------------------------------------------------------------------- scan

$results = [];

foreach ($files as $path) {
    if (!is_file($path)) continue;
    $rel = str_replace($root . '/', '', $path);
    $source = (string)file_get_contents($path);

    // Blank PHP blocks, preserving line count, so tag matching is not cut
    // short by a '>' inside an echo.
    $source = preg_replace_callback(
        '/<\?(?:php|=)?.*?\?' . '>/s',
        fn($m) => str_repeat("\n", substr_count($m[0], "\n")),
        $source
    ) ?? $source;

    if (!preg_match_all('/<div\b[^>]*>|<\/div>/is', $source, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $stack = [];       // open divs; each entry is a card name or null
    $openCards = [];   // currently open cards, outermost first

    foreach ($m[0] as $hit) {
        $tag = $hit[0];
        $offset = $hit[1];

        if ($tag[1] === '/') {
            $popped = array_pop($stack);
            if (is_array($popped)) {
                array_pop($openCards);
            }
            continue;
        }

        preg_match('/class\s*=\s*"([^"]*)"/i', $tag, $c);
        $classAttr = $c[1] ?? '';
        $pad = horizontalPadding($classAttr, $cssPadding);
        $kind = cardKind($classAttr);

        // Any padded container counts toward the squeeze, card or not — the
        // plain `p-5` div that wrapped the payslip list cost as much as a card.
        if ($kind === null && $pad === 0) {
            $stack[] = null;
            continue;
        }

        $lineNo = substr_count(substr($source, 0, $offset), "\n") + 1;
        $entry = ['kind' => $kind ?? 'padded-div', 'pad' => $pad, 'line' => $lineNo];

        $chainPad = $pad;
        foreach ($openCards as $o) {
            $chainPad += $o['pad'];
        }

        if ($kind !== null && $chainPad >= PADDING_BUDGET_PX) {
            $chain = [];
            foreach ($openCards as $o) {
                $chain[] = sprintf('%s(%dpx)', $o['kind'], $o['pad']);
            }
            $chain[] = sprintf('%s(%dpx)', $entry['kind'], $pad);
            $results[$rel][] = [
                'line'     => $lineNo,
                'chainPad' => $chainPad,
                'chain'    => implode(' > ', $chain),
            ];
        }

        $stack[] = $entry;
        $openCards[] = $entry;
    }
}

// ----------------------------------------------------------------- report

echo "TP-HR — nested card audit (read only)\n";
printf("files scanned: %d\n", count($files));
echo str_repeat('=', 74) . "\n\n";

if ($results === []) {
    echo "No card is nested inside another.\n";
    exit(0);
}

uasort($results, fn($a, $b) => count($b) <=> count($a));

$total = 0;
foreach ($results as $file => $hits) {
    $total += count($hits);
    $deepest = max(array_column($hits, "chainPad"));
    printf("%s — %d chain(s), worst %dpx of side padding\n", $file, count($hits), $deepest);
    foreach (array_slice($hits, 0, 6) as $h) {
        printf("    line %-6d %3dpx   %s\n", $h['line'], $h['chainPad'], $h['chain']);
    }
    if (count($hits) > 6) {
        printf("    ... and %d more\n", count($hits) - 6);
    }
    echo "\n";
}

echo str_repeat('=', 74) . "\n";
printf("%d nesting(s) across %d file(s).\n\n", $total, count($results));
echo "Read each one before changing it — PHP branches make the div structure\n";
echo "in source ambiguous, so this lists candidates rather than defects.\n";

exit(1);
