<?php
// tests/language_tables_parity_test.php
//
// The interface language is picked in the panel, but it is spoken by several
// server-side tables that are maintained by hand: the Telegram bot, the
// automation notifications, the funnel bot, the public push-unsubscribe page
// and the name of the language the AI assistant is told to answer in. None of
// them follows the panel by itself. Korean was added to the panel and the
// assistant kept answering in English, and five of the older bot languages
// turned out to have been missing the /allow strings since they shipped.
//
// This pins every table to the panel's own list and to each other:
//
//   1. the four tables carry exactly the panel's languages, every language
//      has every key, and every key keeps the English placeholders;
//   2. the assistant, the funnel and the bot's /lang picker name the same
//      languages as the panel;
//   3. the bot's /lang help and its "unknown language" reply list them all.
//
// Static only: the tables are read with the PHP tokenizer, so no file is
// executed, no database opened and nothing is sent.
//
// Run: php tests/language_tables_parity_test.php

$root = realpath(__DIR__ . '/..');
$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "✓ $name\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAILED: $name" . ($detail !== '' ? " — $detail" : '') . "\n");
    }
}

/** Language codes the panel ships: the keys of `translations` in LanguageContext.jsx. */
function panelLanguages(string $root): array
{
    $src = (string) file_get_contents($root . '/frontend/src/contexts/LanguageContext.jsx');
    if (!preg_match('/export const translations = \{([^}]*)\};/', $src, $m)) {
        return [];
    }
    preg_match_all('/\b([a-z]{2})\b/', $m[1], $keys);
    return $keys[1];
}

/**
 * One hand-maintained table as language => key => raw value literal, read
 * from the `[` that follows $arrayMarker after $afterMarker.
 */
function scanTable(string $file, string $afterMarker, string $arrayMarker): array
{
    $src = (string) file_get_contents($file);
    $after = strpos($src, $afterMarker);
    if ($after === false) {
        return [];
    }
    $at = strpos($src, $arrayMarker, $after);
    if ($at === false) {
        return [];
    }
    // The slice starts mid-file, so it needs an opening tag to be PHP at all —
    // without one the tokenizer returns it as one blob of inline HTML.
    $tokens = token_get_all('<?php ' . substr($src, $at, 600000));
    $table = [];
    $depth = 0;
    $lang = null;
    $key = null;       // a key waiting for its value
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t === '[') {
            $depth++;
            $key = null;
            continue;
        }
        if ($t === ']') {
            $depth--;
            $key = null;
            if ($depth === 0) {
                break;
            }
            continue;
        }
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $literal = substr($t[1], 1, -1);
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
            // A key. At depth 1 it names a language, deeper it names a string.
            if ($depth === 1) {
                $lang = $literal;
                $table[$lang] = $table[$lang] ?? [];
            } else {
                $key = $literal;
            }
            continue;
        }
        if ($key !== null && $lang !== null && $depth === 2) {
            $table[$lang][$key] = $literal;
            $key = null;
        }
    }
    return $table;
}

function placeholders(string $raw): array
{
    preg_match_all('/\{[A-Za-z_0-9]+\}/', $raw, $m);
    $list = $m[0];
    sort($list);
    return $list;
}

function sameSet(array $a, array $b): bool
{
    sort($a);
    sort($b);
    return $a === $b;
}

function missingList(array $expected, array $actual): string
{
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));
    return 'missing [' . implode(',', $missing) . '] extra [' . implode(',', $extra) . ']';
}

// --- 0. The panel's own list -------------------------------------------------
$ui = panelLanguages($root);
check('panel language list read from LanguageContext.jsx', count($ui) >= 2 && in_array('en', $ui, true), implode(',', $ui));

// --- 1. The four text tables --------------------------------------------------
$tables = [
    'Telegram bot (telegram_bot.php botText)' => scanTable($root . '/telegram_bot.php', 'function botText(', '$texts = ['),
    'Automation notifications (core/AutomationNotify.php)' => scanTable($root . '/core/AutomationNotify.php', 'function orbitraAutomationStrings(', 'return ['),
    'Funnel bot (core/TelegramFunnel.php)' => scanTable($root . '/core/TelegramFunnel.php', 'public static function text(', 'static $texts = ['),
    'Push unsubscribe page (index.php)' => scanTable($root . '/index.php', "\$unsubPageDict = [", '['),
];
$en = [];
foreach ($tables as $name => $table) {
    $langs = array_keys($table);
    check("$name: table found with English", isset($table['en']) && count($table['en']) >= 4, count($table['en'] ?? []) . ' en keys');
    $en[$name] = array_keys($table['en'] ?? []);
    check("$name: carries exactly the panel's languages", sameSet($langs, $ui), missingList($ui, $langs));
    foreach ($table as $lang => $strings) {
        if ($lang === 'en') {
            continue;
        }
        $keys = array_keys($strings);
        check("$name / $lang: every key", sameSet($keys, $en[$name]), missingList($en[$name], $keys));
        foreach ($strings as $key => $raw) {
            if (!isset($table['en'][$key])) {
                continue;
            }
            $want = placeholders($table['en'][$key]);
            $have = placeholders($raw);
            if ($want !== $have) {
                check("$name / $lang / $key: placeholders as in English", false, '{' . implode('}, {', $have) . '} vs {' . implode('}, {', $want) . '}');
            }
            if (trim($raw) === "''" || trim($raw) === '""') {
                check("$name / $lang / $key: not empty", false);
            }
        }
    }
}

// --- 2. The lists that name the languages -------------------------------------
require_once $root . '/core/TelegramFunnel.php';
require_once $root . '/core/AiAgent.php';

$assistant = array_keys(AiAgent::LANGUAGE_NAMES);
check('AI assistant names every panel language (it is told to answer in it)', sameSet($assistant, $ui), missingList($ui, $assistant));
check('AI assistant language names are non-empty strings', count(array_filter(AiAgent::LANGUAGE_NAMES, fn($v) => !is_string($v) || trim($v) === '')) === 0);

$funnel = TelegramFunnel::languages();
check('funnel bot supports every panel language', sameSet($funnel, $ui), missingList($ui, $funnel));
$funnelTable = $tables['Funnel bot (core/TelegramFunnel.php)'];
foreach ($ui as $lang) {
    $mapped = TelegramFunnel::langOf(['language_code' => strtoupper($lang) . '-' . strtoupper($lang)]);
    check("funnel maps a visitor's $lang Telegram UI onto $lang", $mapped === $lang, "got $mapped");
    foreach (array_keys($funnelTable['en'] ?? []) as $key) {
        $text = TelegramFunnel::text($lang, $key);
        // The last fallback of text() is the key itself; an unknown language reads English.
        $resolved = $text !== $key && trim($text) !== '' && ($lang === 'en' || $text !== TelegramFunnel::text('en', $key));
        check("funnel text $lang/$key is its own", $resolved, var_export($text, true));
    }
}

$botSrc = (string) file_get_contents($root . '/telegram_bot.php');
preg_match('/function orbitraTelegramLanguages\(\): array\s*\{\s*return \[(.*?)\];/s', $botSrc, $m);
preg_match_all("/'([a-z]{2})' => '([^']+)'/", $m[1] ?? '', $picker);
$pickerLangs = $picker[1];
check('bot /lang picker offers every panel language', sameSet($pickerLangs, $ui), missingList($ui, $pickerLangs));
check('bot /lang picker names are unique (a picker reads in the language it selects)', count(array_unique($picker[2])) === count($picker[2]));

// --- 3. The bot tells its users which languages exist -------------------------
$bot = $tables['Telegram bot (telegram_bot.php botText)'];
foreach ($bot as $lang => $strings) {
    if (preg_match('/\/lang ((?:[a-z]{2}\|)+[a-z]{2})\s/', $strings['help'] ?? '', $hm)) {
        $listed = explode('|', $hm[1]);
        check("bot help in $lang lists every language of /lang", sameSet($listed, $ui), missingList($ui, $listed));
    } else {
        check("bot help in $lang has a /lang line", false);
    }
    // "Unknown language: xx." and then, on the next line, the codes. The line
    // break is a real one in some languages' source and an \n escape in others.
    $lines = preg_split('/\r?\n|\\\\n/', $strings['lang_invalid'] ?? '');
    $secondLine = $lines[1] ?? '';
    preg_match_all('/\b([a-z]{2})\b/', preg_replace('/^[^:]*:/u', '', $secondLine), $lm);
    check("bot $lang replies to an unknown language with the full list", sameSet($lm[1], $ui), missingList($ui, $lm[1]));
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
