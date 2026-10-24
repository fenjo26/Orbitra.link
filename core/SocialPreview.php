<?php
// core/SocialPreview.php
//
// Social link preview ("Превью ссылки для соцсетей"): when a campaign has the
// preview enabled, a SOCIAL preview bot hitting the campaign URL gets a
// minimal og:* card instead of the click flow; every human is routed exactly
// as always — no delay, no different page. This is how "custom social
// preview" works at link shorteners (Bitly/Rebrandly).
//
// Deliberately NOT the full CloakDetector bot list: search crawlers
// (googlebot & co) must keep seeing the real campaign answer, and Orbitra's
// own cloaking already exists for that job. This is only the messengers'
// link-preview fetchers.
//
// The feature is organic-positioning (posts, groups, messengers). Ad platforms
// check the final URL of an ad — the editor shows a one-time disclaimer
// (preview_ack_at) saying platform rules are the operator's responsibility.

/**
 * UA signatures of social/preview bots that get the og:* card.
 *
 * @return string[]
 */
function orbitraSocialPreviewBots(): array
{
    return [
        // Meta
        'facebookexternalhit', 'facebot', 'facebookcatalog',
        'meta-externalagent', 'meta-externalfetcher',
        // Messengers
        'telegrambot', 'whatsapp', 'skypeuripreviewer',
        'discord-bot', 'slack', 'slackbot', 'slack-imgproxy',
        // Social networks
        'twitterbot', 'linkedinbot', 'vkshare', 'vkrobot',
        'pinterestbot', 'tumblr',
        // Messengers regional
        'kakaotalk-scrap', 'kakaostory-og-reader', 'line-poker',
        'remindpreview', 'blueno', 'bandscraper',
    ];
}

/**
 * Does this User-Agent belong to a social preview bot?
 */
function orbitraIsSocialPreviewBot(string $ua): bool
{
    if ($ua === '') {
        return false;
    }
    $uaLower = strtolower($ua);
    foreach (orbitraSocialPreviewBots() as $sig) {
        if (strpos($uaLower, $sig) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Serve the og:* card for a campaign hit, if that is the right answer.
 *
 * Called from the campaign router (index.php) after the campaign is resolved,
 * active and unarchived — before any click logging or redirect. Returns true
 * when it answered (caller exits); false when the normal flow must continue.
 * Never logs a click: a preview fetch is not a visitor.
 */
function orbitraSocialPreviewServe(PDO $pdo, array $campaign, string $ua): bool
{
    if ((int) ($campaign['preview_enabled'] ?? 0) !== 1) {
        return false;
    }
    if (!orbitraIsSocialPreviewBot($ua)) {
        return false;
    }

    $title = trim((string) ($campaign['preview_title'] ?? ''));
    $description = trim((string) ($campaign['preview_description'] ?? ''));
    $image = trim((string) ($campaign['preview_image'] ?? ''));
    if ($title === '' && $image === '') {
        // Enabled but nothing to show — the card would be empty. Degrade to
        // the normal flow rather than serve a blank page to the bot.
        return false;
    }

    // The card URL is the campaign URL exactly as the bot requested it, so
    // og:url never disagrees with the link being previewed.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
    $pageUrl = $host !== '' ? $scheme . '://' . $host . $path : $path;

    // A relative image path resolves against the campaign host; an absolute
    // URL passes through untouched.
    $imageUrl = '';
    if ($image !== '') {
        if (preg_match('#^https?://#i', $image)) {
            $imageUrl = $image;
        } elseif ($host !== '') {
            $imageUrl = $scheme . '://' . $host . '/' . ltrim($image, '/');
        }
    }

    $e = static function (string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
    echo '<title>' . $e($title !== '' ? $title : ($campaign['name'] ?? '')) . '</title>';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:url" content="' . $e($pageUrl) . '">';
    if ($title !== '') {
        echo '<meta property="og:title" content="' . $e($title) . '">';
        echo '<meta name="twitter:title" content="' . $e($title) . '">';
    }
    if ($description !== '') {
        echo '<meta property="og:description" content="' . $e($description) . '">';
        echo '<meta name="twitter:description" content="' . $e($description) . '">';
    }
    if ($imageUrl !== '') {
        echo '<meta property="og:image" content="' . $e($imageUrl) . '">';
        echo '<meta name="twitter:image" content="' . $e($imageUrl) . '">';
    }
    echo '<meta name="robots" content="noindex">';
    echo '</head><body></body></html>';
    return true;
}
