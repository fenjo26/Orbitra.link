<?php
/**
 * tests/social_preview_test.php
 *
 * Social link preview: the bot list (messengers yes, search crawlers no), the
 * serve decision (enabled × bot × non-empty card) and the og:* card itself —
 * tags present, URL built from the request, HTML escaped.
 *
 *     php tests/social_preview_test.php
 */

require_once __DIR__ . '/../core/SocialPreview.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

// ---- Bot list ----------------------------------------------------------------

echo "\nbot detection\n";

check('facebookexternalhit is a preview bot', orbitraIsSocialPreviewBot('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'));
check('TelegramBot is a preview bot', orbitraIsSocialPreviewBot('TelegramBot (like TwitterBot)'));
check('WhatsApp is a preview bot', orbitraIsSocialPreviewBot('WhatsApp/2.23.20.0'));
check('Twitterbot is a preview bot', orbitraIsSocialPreviewBot('Twitterbot/1.0'));
check('googlebot is NOT — search bots keep the real answer', !orbitraIsSocialPreviewBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
check('curl is NOT — tools are CloakDetector\'s job', !orbitraIsSocialPreviewBot('curl/8.4.0'));
check('a browser is NOT', !orbitraIsSocialPreviewBot('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'));
check('empty UA is NOT', !orbitraIsSocialPreviewBot(''));

// ---- Serve decision ----------------------------------------------------------

echo "\nserve decision\n";

$campaign = [
    'id' => 1,
    'name' => 'Camp',
    'preview_enabled' => 1,
    'preview_title' => 'Big <Bonus> & Wins',
    'preview_description' => 'Card "description"',
    'preview_image' => '/uploads/preview.png',
];

$serve = function (array $campaign, string $ua) {
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'track.example.com';
    $_SERVER['REQUEST_URI'] = '/abc123?utm=x';
    ob_start();
    $answered = orbitraSocialPreviewServe(new PDO('sqlite::memory:'), $campaign, $ua);
    $html = (string) ob_get_clean();
    return [$answered, $html];
};

[$answered, $html] = $serve($campaign, 'facebookexternalhit/1.1');
check('enabled + bot → card served', $answered === true, $html);
check('og:title present and HTML-escaped', str_contains($html, 'property="og:title" content="Big &lt;Bonus&gt; &amp; Wins"'), $html);
check('og:image resolves relative path against the host', str_contains($html, 'content="https://track.example.com/uploads/preview.png"'), $html);
check('og:url is the requested campaign path', str_contains($html, 'property="og:url" content="https://track.example.com/abc123"'), $html);
check('og:description present', str_contains($html, 'property="og:description"'));
check('twitter:title mirror tag', str_contains($html, 'name="twitter:title"'));
check('noindex — a preview card must not rank', str_contains($html, 'noindex'));
check('empty body — no script, no redirect, no delay', str_contains($html, '<body></body>'));

// Absolute image URL passes through untouched.
[$answered2, $html2] = $serve(array_replace($campaign, ['preview_image' => 'https://cdn.example.com/card.png']), 'TelegramBot (like TwitterBot)');
check('absolute image URL untouched', $answered2 && str_contains($html2, 'content="https://cdn.example.com/card.png"'));

// A human never sees the card — the router continues.
[$answered3] = $serve($campaign, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15');
check('enabled + human → router continues', $answered3 === false);

// Disabled → nothing, even for bots.
[$answered4] = $serve(array_replace($campaign, ['preview_enabled' => 0]), 'facebookexternalhit/1.1');
check('disabled → router continues', $answered4 === false);

// Enabled but an empty card → degrade to the normal flow, not a blank page.
[$answered5] = $serve(array_replace($campaign, ['preview_title' => '', 'preview_image' => '']), 'facebookexternalhit/1.1');
check('empty card degrades to the normal flow', $answered5 === false);

// ---- Summary -----------------------------------------------------------------

echo "\n" . str_repeat('-', 60) . "\n";
echo "passed: $passed   failed: $failed\n";
exit($failed === 0 ? 0 : 1);
