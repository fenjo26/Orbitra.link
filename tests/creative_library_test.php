<?php
/**
 * tests/creative_library_test.php
 *
 * Библиотека креативов (этап 1): движки, синк, дедуп/семьи, агрегат.
 *
 * Без сети: HTTP-слои движков подменены их test-швами. Проверяет:
 *  1. FB: батч-запрос креативов (?ids=), оригиналы по хешам, видео-инфо;
 *  2. TikTok: /ad/get/ по списку (включая парсинг creatives[]), file/info;
 *  3. orbitraClickAdRef: настоящий id / макрос / пусто;
 *  4. CreativeSync::syncConnection: очередь загрузок + тексты объявлений
 *     плейсхолдерами, троттлинг;
 *  5. дедуп sha256 и dHash-семья («заготовка вышла в работу»);
 *  6. CreativeStats::rebuildWindow: расход+комиссия, карусель делится
 *     поровну, сейф-пейдж исключён, клик 23:30 UTC — в афинский день.
 *
 * Запуск из корня проекта:
 *
 *     php tests/creative_library_test.php
 */

require_once __DIR__ . '/../core/ClickParams.php';
require_once __DIR__ . '/../core/CurrencyRates.php';
require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/CostImporter.php';
require_once __DIR__ . '/../core/CreativeSync.php';
require_once __DIR__ . '/../core/CreativeStats.php';
require_once __DIR__ . '/../core/click_logger.php';
require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';

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

// ---- Facebook: креативы ------------------------------------------------------

echo "Facebook creatives fetch\n";

$fbCalls = [];
FacebookAdsEngine::$testHttpHandler = function (string $url, array $credentials) use (&$fbCalls): array {
    $fbCalls[] = $url;
    if (strpos($url, '/adimages') !== false) {
        $body = ['data' => [
            ['hash' => 'a1b2c3d4e5', 'uri' => 'https://lookaside.facebook.com/adimages/a1b2.png'],
            ['hash' => 'deadbeef00', 'uri' => 'https://lookaside.facebook.com/adimages/dead.png'],
        ]];
    } elseif (strpos($url, 'fields=picture') !== false) {
        // /<video_id>?fields=picture,source,length — the video-info probe.
        if (preg_match('~/60112345678901\?~', $url)) {
            $body = ['id' => '60112345678901', 'picture' => 'https://scontent.xx.fbcdn.net/cover.jpg', 'source' => 'https://video.xx.fbcdn.net/video.mp4', 'length' => 15.5];
        } else {
            $body = [];
        }
    } elseif (strpos($url, '/act_1/ads?') !== false) {
        // The ad-account edge, paged: one page is enough for the fixture.
        $body = ['data' => [
            ['id' => '60112345678901', 'name' => 'Sales Video 3', 'effective_status' => 'ACTIVE',
             'creative' => ['image_hash' => '', 'image_url' => '', 'thumbnail_url' => 'https://thumb/1.jpg', 'video_id' => '60112345678901', 'body' => 'Join now', 'title' => 'Best casino']],
            ['id' => '60112345678902', 'name' => 'Static banner', 'effective_status' => 'PAUSED',
             'creative' => ['image_hash' => 'a1b2c3d4e5', 'image_url' => 'https://thumb/2.jpg', 'thumbnail_url' => '', 'video_id' => '', 'body' => '', 'title' => '']],
        ]];
    } else {
        $body = [];
    }
    return ['body' => json_encode($body), 'error' => null];
};

$fbAds = FacebookAdsEngine::fetchAdCreatives(['token' => 'T', 'ad_account_id' => 'act_1']);
check('both ads parsed with creative fields',
    count($fbAds) === 2
    && ($fbAds[0]['video_id'] ?? '') === '60112345678901'
    && ($fbAds[0]['body'] ?? '') === 'Join now'
    && ($fbAds[1]['image_hash'] ?? '') === 'a1b2c3d4e5');
check('the account edge is used, no removed ?ids= and no per-ad calls',
    strpos($fbCalls[0] ?? '', '/act_1/ads?') !== false && strpos($fbCalls[0] ?? '', 'ids=') === false, $fbCalls[0] ?? '');

// A fields mistake fails loudly (the reviewer's silent-empty-library trap).
$deadHandler = function (string $url, array $credentials): array {
    // Graph answers a bad `fields` with HTTP 400 + an error envelope.
    return ['body' => json_encode(['error' => ['message' => '(#100) fields are invalid', 'code' => 100]]), 'error' => 'HTTP 400: (#100) fields are invalid'];
};
FacebookAdsEngine::$testHttpHandler = $deadHandler;
$threw = false;
try {
    FacebookAdsEngine::fetchAdCreatives(['token' => 'T', 'ad_account_id' => 'act_1']);
} catch (RuntimeException $e) {
    $threw = strpos($e->getMessage(), 'invalid') !== false;
}
check('a fields error throws instead of an empty library', $threw);
FacebookAdsEngine::$testHttpHandler = null; // restored to the edge mock below

// Re-arm the edge mock: the hash/video probes below still need it.
FacebookAdsEngine::$testHttpHandler = function (string $url, array $credentials) use (&$fbCalls): array {
    $fbCalls[] = $url;
    if (strpos($url, '/adimages') !== false) {
        return ['body' => json_encode(['data' => [
            ['hash' => 'a1b2c3d4e5', 'uri' => 'https://lookaside.facebook.com/adimages/a1b2.png'],
            ['hash' => 'deadbeef00', 'uri' => 'https://lookaside.facebook.com/adimages/dead.png'],
        ]]), 'error' => null];
    }
    if (strpos($url, 'fields=picture') !== false && preg_match('~/60112345678901\?~', $url)) {
        return ['body' => json_encode(['id' => '60112345678901', 'picture' => 'https://scontent.xx.fbcdn.net/cover.jpg', 'source' => 'https://video.xx.fbcdn.net/video.mp4', 'length' => 15.5]), 'error' => null];
    }
    return ['body' => json_encode([]), 'error' => null];
};

$fbHashes = FacebookAdsEngine::fetchImageUrlsByHashes(['token' => 'T', 'ad_account_id' => 'act_1'], ['a1b2c3d4e5', 'deadbeef00']);
check('adimages resolves hashes to full-size URIs', count($fbHashes) === 2 && strpos($fbHashes['a1b2c3d4e5'], 'adimages') !== false);

$fbVideo = FacebookAdsEngine::fetchVideoInfo(['token' => 'T'], '60112345678901');
check('video info: cover + source + length',
    $fbVideo !== null && strpos($fbVideo['source'], '.mp4') !== false && strpos($fbVideo['picture'], 'cover') !== false);

FacebookAdsEngine::$testHttpHandler = null;

// ---- TikTok: креативы --------------------------------------------------------

echo "TikTok creatives fetch\n";

$ttCalls = [];
TikTokAdsEngine::$testHttpHandler = function (string $method, string $url, ?array $payload, array $credentials) use (&$ttCalls): array {
    $ttCalls[] = $url;
    if (strpos($url, '/ad/get/') !== false) {
        // Форма «creatives[]», как в свежих версиях Business API.
        $data = ['list' => [
            ['ad_id' => '1780000000000000001', 'ad_name' => 'Hook 12s', 'status' => 'ENABLE',
             'adgroup_id' => '1795000000000000001', 'campaign_id' => '1790000000000000001',
             'creatives' => [['video_id' => '3400000000000000001', 'image_ids' => []]], 'ad_text' => 'Play now'],
            ['ad_id' => '1780000000000000002', 'ad_name' => 'Carousel', 'status' => 'DISABLE',
             'adgroup_id' => '1795000000000000001', 'campaign_id' => '1790000000000000001',
             'creatives' => [['video_id' => '', 'image_ids' => ['3500000000000000001', '3500000000000000002']]]],
        ], 'page_info' => ['total_page' => 1]];
    } elseif (strpos($url, '/file/video/ad/info/') !== false) {
        $data = ['list' => [['video_id' => '3400000000000000001', 'image_url' => 'https://p16-sign.tiktokcdn.com/cover.webp', 'preview_url' => '', 'video_name' => 'hook12s.mp4']]];
    } elseif (strpos($url, '/file/image/ad/info/') !== false) {
        $data = ['list' => [['image_id' => '3500000000000000001', 'image_url' => 'https://p16-sign.tiktokcdn.com/img1.webp', 'preview_url' => '']]];
    } else {
        $data = ['list' => []];
    }
    return ['body' => json_encode(['code' => 0, 'message' => 'OK', 'data' => $data + (strpos($url, '/file/') === false ? ['page_info' => ['total_page' => 1]] : [])]), 'error' => null];
};

$ttAds = TikTokAdsEngine::fetchAdCreatives(['access_token' => 'T', 'advertiser_id' => '1'], ['1780000000000000001', '1780000000000000002']);
check('ad/get filters by the requested ad ids', strpos($ttCalls[0] ?? '', '/ad/get/') !== false);
check('video_id parsed from creatives[]',
    ($ttAds[0]['video_id'] ?? '') === '3400000000000000001' && ($ttAds[0]['ad_text'] ?? '') === 'Play now');
check('image_ids parsed from creatives[]',
    ($ttAds[1]['image_ids'] ?? []) === ['3500000000000000001', '3500000000000000002']);

$ttVideo = TikTokAdsEngine::fetchVideoInfo(['access_token' => 'T', 'advertiser_id' => '1'], ['3400000000000000001']);
check('file/video/ad/info returns the cover', strpos($ttVideo['3400000000000000001']['image_url'] ?? '', 'cover') !== false);
$ttImage = TikTokAdsEngine::fetchImageInfo(['access_token' => 'T', 'advertiser_id' => '1'], ['3500000000000000001']);
check('file/image/ad/info returns the image', strpos($ttImage['3500000000000000001']['image_url'] ?? '', 'img1') !== false);

TikTokAdsEngine::$testHttpHandler = null;

// ---- clicks.ad_ref -----------------------------------------------------------

echo "clicks.ad_ref\n";

check('real ad_id extracted', orbitraClickAdRef('{"ad_id":"1780000000000000001","sub_id_1":"x"}') === '1780000000000000001');
check('macro skipped, creative fallback used', orbitraClickAdRef('{"ad_id":"__CID__","creative":"1203_456"}') === '1203_456');
check('unreplaced {{ad.id}} skipped', orbitraClickAdRef('{"creative_id":"{{ad.id}}"}') === '');
check('empty and absent parameters give empty string', orbitraClickAdRef('{"ad_id":""}') === '' && orbitraClickAdRef('{}') === '' && orbitraClickAdRef('') === '');

// ---- CreativeSync::syncConnection --------------------------------------------

echo "CreativeSync metadata pass\n";

function creativeFixtureDb(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME)");
    $pdo->exec("CREATE TABLE ad_entities (
        id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT, level TEXT, external_id TEXT,
        name TEXT, status TEXT, parent_id TEXT, connection_id INTEGER,
        first_seen_at DATETIME, last_seen_at DATETIME, UNIQUE(platform, level, external_id)
    )");
    $pdo->exec("CREATE TABLE creative_fetch_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, platform TEXT, kind TEXT, url TEXT,
        ref_type TEXT, ref_id TEXT, ad_id TEXT, adset_id TEXT, campaign_id TEXT,
        status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, error TEXT,
        created_at DATETIME DEFAULT (datetime('now')), updated_at DATETIME DEFAULT (datetime('now')),
        UNIQUE(platform, ref_type, ref_id, kind)
    )");
    $pdo->exec("CREATE TABLE creative_usages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, asset_id INTEGER, platform TEXT, ad_id TEXT,
        adset_id TEXT, campaign_id TEXT, connection_id INTEGER,
        ad_text TEXT NOT NULL DEFAULT '', ad_title TEXT NOT NULL DEFAULT '',
        first_seen_at DATETIME, last_seen_at DATETIME, UNIQUE(asset_id, platform, ad_id)
    )");
    $pdo->exec("CREATE TABLE media_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER, folder_id INTEGER,
        orig_name TEXT, stored_name TEXT, sha256 TEXT, mime TEXT, size INTEGER, width INTEGER, height INTEGER,
        is_active INTEGER DEFAULT 1, created_at DATETIME, deleted_at DATETIME,
        source TEXT NOT NULL DEFAULT 'upload', kind TEXT NOT NULL DEFAULT 'image',
        thumb_name TEXT, phash TEXT, first_seen_at DATETIME, last_seen_at DATETIME, ai_status TEXT,
        tags TEXT NOT NULL DEFAULT '[]'
    )");
    $pdo->exec("CREATE TABLE creative_refs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, asset_id INTEGER, platform TEXT, ref_type TEXT, ref_id TEXT,
        created_at DATETIME, UNIQUE(platform, ref_type, ref_id)
    )");
    $pdo->exec("CREATE TABLE cost_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INTEGER, external_id TEXT,
        source_campaign_id TEXT, ad_id TEXT, adset_id TEXT, amount REAL, fee_amount REAL NOT NULL DEFAULT 0,
        currency TEXT, click_date DATE, impressions INTEGER, platform_clicks INTEGER,
        raw_json TEXT, is_matched INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE creative_daily (
        asset_id INTEGER NOT NULL, day TEXT NOT NULL, spend REAL NOT NULL DEFAULT 0, fee REAL NOT NULL DEFAULT 0,
        impressions INTEGER NOT NULL DEFAULT 0, platform_clicks INTEGER NOT NULL DEFAULT 0,
        tracker_clicks INTEGER NOT NULL DEFAULT 0, visitors INTEGER NOT NULL DEFAULT 0,
        installs INTEGER NOT NULL DEFAULT 0, registrations INTEGER NOT NULL DEFAULT 0,
        ftd INTEGER NOT NULL DEFAULT 0, revenue REAL NOT NULL DEFAULT 0, updated_at DATETIME,
        PRIMARY KEY (asset_id, day)
    )");
    $pdo->exec("CREATE TABLE aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, engine TEXT, credentials_json TEXT,
        field_mapping_json TEXT, agency_id INTEGER, is_active INTEGER DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE clicks (
        id TEXT PRIMARY KEY, campaign_id INTEGER, ip TEXT, cost REAL DEFAULT 0, fee REAL NOT NULL DEFAULT 0,
        revenue REAL DEFAULT 0, parameters_json TEXT, created_at DATETIME,
        ad_ref TEXT, offer_id INTEGER, landing_id INTEGER, offer_at DATETIME, direct_offer INTEGER DEFAULT 0,
        is_bot INTEGER DEFAULT 0, is_safe_page INTEGER DEFAULT 0, pwa_install_at DATETIME
    )");
    $pdo->exec("CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, status TEXT, value REAL, revenue REAL, created_at DATETIME)");
    $pdo->exec("CREATE TABLE revenue_records (id INTEGER PRIMARY KEY AUTOINCREMENT, click_id TEXT, amount REAL, created_at DATETIME)");
    $pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY, name TEXT, is_archived INTEGER DEFAULT 0)");
    $pdo->exec("CREATE TABLE streams (id INTEGER PRIMARY KEY, campaign_id INTEGER, schema_type TEXT, schema_custom_json TEXT)");
    return $pdo;
}

$pdo = creativeFixtureDb();
// Кабинет TikTok: два объявления, у каждого creative (видео + картинка).
$pdo->exec("INSERT INTO ad_entities (platform, level, external_id, name, status, last_seen_at) VALUES
    ('tiktok','ad','1780000000000000001','Hook 12s','ACTIVE', datetime('now')),
    ('tiktok','ad','1780000000000000002','Carousel','ACTIVE', datetime('now')),
    ('tiktok','ad','1780999999999999999','Ancient','ACTIVE', datetime('now','-90 days'))");
$pdo->exec("INSERT INTO aggregator_connections (name, engine, credentials_json, field_mapping_json) VALUES ('tt', 'tiktok', '{}', '{}')");
$connId = (int) $pdo->lastInsertId();

TikTokAdsEngine::$testHttpHandler = function (string $method, string $url, ?array $payload, array $credentials): array {
    if (strpos($url, '/ad/get/') !== false) {
        $data = ['list' => [
            ['ad_id' => '1780000000000000001', 'ad_name' => 'Hook 12s', 'adgroup_id' => '1795000000000000001', 'campaign_id' => '1790000000000000001',
             'creatives' => [['video_id' => '3400000000000000001']], 'ad_text' => 'Play now'],
            ['ad_id' => '1780000000000000002', 'ad_name' => 'Carousel', 'adgroup_id' => '1795000000000000001', 'campaign_id' => '1790000000000000001',
             'creatives' => [['video_id' => '', 'image_ids' => ['3500000000000000001']]]],
        ], 'page_info' => ['total_page' => 1]];
        return ['body' => json_encode(['code' => 0, 'data' => $data]), 'error' => null];
    }
    return ['body' => json_encode(['code' => 0, 'data' => ['list' => []]]), 'error' => null];
};

$sync = CreativeSync::syncConnection($pdo, $connId, 'tiktok', ['access_token' => 'T', 'advertiser_id' => '1']);
check('sync pass processed two ads', ($sync['ads'] ?? 0) === 2 && empty($sync['error']), json_encode($sync));

$queue = $pdo->query("SELECT ref_type, ref_id, kind, ad_id FROM creative_fetch_queue ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('three downloads queued: video cover + carousel image (+ nothing for the 90-day-old ad)',
    count($queue) === 2
    && ($queue[0]['ref_type'] ?? '') === 'tt_video_id' && ($queue[0]['kind'] ?? '') === 'image'
    && ($queue[1]['ref_type'] ?? '') === 'tt_image_id');

$usage = $pdo->query("SELECT ad_text, adset_id, campaign_id FROM creative_usages WHERE ad_id = '1780000000000000001'")->fetch(PDO::FETCH_ASSOC);
check('ad text bound as a placeholder usage', ($usage['ad_text'] ?? '') === 'Play now' && ($usage['adset_id'] ?? '') === '1795000000000000001');

$throttled = CreativeSync::syncConnection($pdo, $connId, 'tiktok', ['access_token' => 'T', 'advertiser_id' => '1']);
check('second pass inside the throttle window is skipped', $throttled['skipped'] === true);

TikTokAdsEngine::$testHttpHandler = null;

// ---- Дедуп и семьи -------------------------------------------------------------

echo "dedup and families\n";

$tmpA = tempnam(sys_get_temp_dir(), 'ocr');
$tmpB = tempnam(sys_get_temp_dir(), 'ocr');
$img = imagecreatetruecolor(64, 64);
for ($x = 0; $x < 64; $x++) {
    $gray = (int) ($x * 4); // горизонтальный градиент
    imagefilledrectangle($img, $x, 0, $x, 63, imagecolorallocate($img, $gray, $gray, $gray));
}
imagepng($img, $tmpA);
// «Почти тот же»: один столбец сдвинут — dHash меняется в паре бит.
$imgB = imagecreatetruecolor(64, 64);
for ($x = 0; $x < 64; $x++) {
    $gray = (int) ($x * 4);
    if ($x === 30) { $gray = 255 - $gray; }
    imagefilledrectangle($imgB, $x, 0, $x, 63, imagecolorallocate($imgB, $gray, $gray, $gray));
}
imagepng($imgB, $tmpB);

$hashA = CreativeSync::dHashFile($tmpA);
$hashB = CreativeSync::dHashFile($tmpB);
check('dHash produced for both images', $hashA !== null && strlen($hashA) === 16 && $hashB !== null);
$dist = CreativeSync::hamming($hashA, $hashB);
check('near-identical images are a family (hamming ≤ 6)', $dist >= 0 && $dist <= 6, "distance=$dist");

$imgC = imagecreatetruecolor(64, 64);
for ($x = 0; $x < 64; $x++) {
    $gray = 255 - (int) ($x * 4); // инверсия
    imagefilledrectangle($imgC, $x, 0, $x, 63, imagecolorallocate($imgC, $gray, $gray, $gray));
}
$tmpC = tempnam(sys_get_temp_dir(), 'ocr');
imagepng($imgC, $tmpC);
$distC = CreativeSync::hamming($hashA, CreativeSync::dHashFile($tmpC));
check('an inverted image is far away', $distC > 40, "distance=$distC");

$sniffImg = CreativeSync::sniffFile($tmpA, 'image');
check('sniffFile reads a real PNG', ($sniffImg['ext'] ?? '') === 'png' && ($sniffImg['width'] ?? 0) === 64);
$tmpMp4 = tempnam(sys_get_temp_dir(), 'ocr');
file_put_contents($tmpMp4, str_repeat('x', 4) . 'ftypisom' . str_repeat('y', 64));
check('sniffFile recognizes mp4 by signature', CreativeSync::sniffFile($tmpMp4, 'video')['ext'] === 'mp4');
file_put_contents($tmpMp4, '<html>not a video</html>');
check('sniffFile rejects a text file posing as video', CreativeSync::sniffFile($tmpMp4, 'video') === null);

// Заготовка: upload-asset с phash; платформа качает тот же кадр (близкий хэш).
$pdo->exec("INSERT INTO media_assets (orig_name, stored_name, sha256, mime, size, source, phash)
    VALUES ('draft.png', 'ab/draft.png', '" . hash_file('sha256', $tmpA) . "', 'image/png', 100, 'upload', '$hashA')");
$draftId = (int) $pdo->lastInsertId();

$found = CreativeSync::findExistingAsset($pdo, 'fresh-sha-no-exact-match', $hashB);
check('the draft went live: family match binds the upload asset', $found !== null && (int) $found['id'] === $draftId);
$exactHit = CreativeSync::findExistingAsset($pdo, hash_file('sha256', $tmpA), null);
check('an exact sha256 duplicate wins before the family pass', $exactHit !== null && (int) $exactHit['id'] === $draftId);
check('a distant hash binds nothing', CreativeSync::findExistingAsset($pdo, 'no-match-sha', CreativeSync::dHashFile($tmpC)) === null);

@unlink($tmpA); @unlink($tmpB); @unlink($tmpC); @unlink($tmpMp4);

// ---- CreativeStats::rebuildWindow ---------------------------------------------

echo "creative_daily aggregate\n";

$pdo = creativeFixtureDb();
// Кабинет в Афинах: расход дня 2026-10-11; клик в 23:30 UTC 10-го — уже 11-е.
$pdo->exec("INSERT INTO aggregator_connections (name, engine, credentials_json, field_mapping_json) VALUES ('tt', 'tiktok', '{\"timezone\":\"Europe/Athens\"}', '{}')");
$statsConnId = (int) $pdo->lastInsertId();
// Ассет 7 — единственный файл у объявления TT9 (не карусель).
$pdo->exec("INSERT INTO creative_usages (asset_id, platform, ad_id, adset_id, campaign_id, connection_id, ad_text) VALUES
    (7, 'tiktok', 'TT9', '1795', '1790', $statsConnId, 'Play now')");
$pdo->exec("INSERT INTO cost_records (connection_id, external_id, ad_id, amount, fee_amount, currency, click_date, impressions, platform_clicks, is_matched)
    VALUES ($statsConnId, 'e1', 'TT9', 100.0, 10.0, 'USD', '2026-10-11', 5000, 120, 1)");

// The safe-page predicate excludes safe clicks only for campaigns that run a
// cloak stream with reporting exclusion on — mirror a real cloak campaign.
$pdo->exec("INSERT INTO campaigns (id, name) VALUES (1, 'Cloaked')");
$pdo->exec("INSERT INTO streams (id, campaign_id, schema_type, schema_custom_json) VALUES (1, 1, 'cloak', '{\"exclude_safe_from_reports\":true}')");
$ins = $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, revenue, parameters_json, created_at, ad_ref, offer_id, direct_offer, is_bot, is_safe_page, pwa_install_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
// Деньги: 23:30 UTC 10-го = 11-е Афины; рега + депозит, инстолл.
$ins->execute(['M1', 1, '1.1.1.1', 0, '{"ad_id":"TT9"}', '2026-10-10 23:30:00', 'TT9', 5, 1, 0, 0, '2026-10-10 23:31:00']);
$ins->execute(['M2', 1, '1.1.1.1', 0, '{"ad_id":"TT9"}', '2026-10-11 09:00:00', 'TT9', 5, 1, 0, 0, null]);
// Сейф-пейдж: исключён из метрик.
$ins->execute(['S1', 1, '1.1.1.1', 0, '{"ad_id":"TT9"}', '2026-10-11 10:00:00', 'TT9', null, 0, 0, 1, null]);
// Чужое объявление — не в этом ассете.
$ins->execute(['X1', 1, '1.1.1.1', 0, '{"ad_id":"OTHER"}', '2026-10-11 11:00:00', 'OTHER', null, 0, 0, 0, null]);

$conv = $pdo->prepare("INSERT INTO conversions (click_id, status, value) VALUES (?,?,?)");
$conv->execute(['M1', 'registration', 2.0]);
$conv->execute(['M1', 'deposit', 80.0]);
$conv->execute(['M2', 'registration', 2.0]);
$pdo->exec("INSERT INTO revenue_records (click_id, amount) VALUES ('M1', 82.0), ('M2', 2.0)");

$result = CreativeStats::rebuildWindow($pdo, '2026-10-11', '2026-10-11');
$cell = $pdo->query("SELECT * FROM creative_daily WHERE asset_id = 7 AND day = '2026-10-11'")->fetch(PDO::FETCH_ASSOC);
check('one asset-day cell rebuilt', ($result['days'] ?? 0) === 1 && $cell !== false, json_encode($result));
check('spend + fee from cost_records', abs((float) $cell['spend'] - 100.0) < 0.0001 && abs((float) $cell['fee'] - 10.0) < 0.0001, json_encode($cell));
check('the 23:30 UTC click belongs to the Athens day (UTC+3)', (int) $cell['visitors'] === 2, json_encode($cell));
check('safe-page click excluded, other ad excluded', (int) $cell['visitors'] === 2 && (int) $cell['tracker_clicks'] === 2);
check('installs / registrations / ftd counted', (int) $cell['installs'] === 1 && (int) $cell['registrations'] === 2 && (int) $cell['ftd'] === 1);
check('real revenue from revenue_records', abs((float) $cell['revenue'] - 84.0) < 0.0001);
check('platform counters carried', (int) $cell['impressions'] === 5000 && (int) $cell['platform_clicks'] === 120);

// Карусель: два ассета на одном объявлении — расход делится поровну.
$pdo->exec("INSERT INTO creative_usages (asset_id, platform, ad_id, connection_id) VALUES (8, 'tiktok', 'TT9', $statsConnId), (9, 'tiktok', 'TT9', $statsConnId)");
CreativeStats::rebuildWindow($pdo, '2026-10-11', '2026-10-11');
$shares = $pdo->query("SELECT asset_id, spend FROM creative_daily WHERE day = '2026-10-11' AND asset_id IN (7,8,9) ORDER BY asset_id")->fetchAll(PDO::FETCH_ASSOC);
check('carousel: three assets share the day spend equally (33.33 each)',
    count($shares) === 3
    && abs((float) $shares[0]['spend'] - 100 / 3) < 0.01
    && abs((float) $shares[1]['spend'] - 100 / 3) < 0.01
    && abs((float) $shares[2]['spend'] - 100 / 3) < 0.01, json_encode($shares));

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
