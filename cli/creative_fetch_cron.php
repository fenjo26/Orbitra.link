<?php
// Orbitra — Creative fetch worker (библиотека креативов, этап 1).
//
// Скачивает файлы, поставленные в очередь метаданным-синком (core/CreativeSync
// .php): картинки-оригиналы Facebook, обложки и превью TikTok, видео Facebook.
// Всё тяжёлое живёт здесь, а не в запросе синка: лимит времени (--time-limit),
// лимит объёма на файл, валидация по сигнатуре (getimagesize для картинок,
// ftyp/EBML для видео), дедуп по sha256 и семья по dHash — вручную загруженная
// заготовка получает метрики, как только площадка начала крутить её же файл.
//
// Crontab (install.sh добавляет с маркером):
//   */5 * * * * php /var/www/orbitra/cli/creative_fetch_cron.php --quiet \
//     >> /var/www/orbitra/var/logs/creative_fetch.log 2>&1 # orbitra-creative-fetch

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/CreativeSync.php';
require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php'; // applyProxy(): общая прокси-логика движков

$options = getopt('', ['quiet', 'time-limit::']);
$isQuiet = isset($options['quiet']);
$timeLimit = max(30, min(1800, (int) ($options['time-limit'] ?? 240)));
$startedAt = time();

// Only one fetch worker at a time: downloads are slow, and a second copy
// would race the same queue rows to the same files.
$lockDir = __DIR__ . '/../var/locks';
@mkdir($lockDir, 0775, true);
$lockFp = @fopen($lockDir . '/creative_fetch_cron.lock', 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0); // уже работает — тихо уходим
}

function cron_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

/** Размерные шапки: картинка 10 МБ, видео 100 МБ, обложка 5 МБ. */
function fetch_cap_bytes(string $kind): int
{
    if ($kind === 'video') {
        return 100 * 1024 * 1024;
    }
    return 10 * 1024 * 1024;
}

/**
 * Скачивание с жёстким лимитом объёма: WRITEFUNCTION обрывает передачу, как
 * только байт стало больше cap — Content-Length лжёт, MAXFILESIZE лишь hint.
 * Прокси подключения применяется тем же правилом, что у движков: указан
 * невалидный — ошибка, а не «поедем с серверного IP».
 */
function creative_download(string $url, array $credentials, int $capBytes, string $tmpPath): ?string
{
    $ch = curl_init($url);
    $fh = @fopen($tmpPath, 'wb');
    if ($fh === false) {
        return 'cannot open temp file';
    }
    $bytes = 0;
    $overCap = false;
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        // Bound reader: the transfer dies the moment the cap is exceeded.
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$bytes, $capBytes, &$overCap) {
            $bytes += strlen($chunk);
            if ($bytes > $capBytes) {
                $overCap = true;
                return -1; // прерывает передачу
            }
            return strlen($chunk);
        },
    ]);
    try {
        TikTokAdsEngine::applyProxy($ch, $credentials);
    } catch (\Throwable $e) {
        fclose($fh);
        curl_close($ch);
        @unlink($tmpPath);
        return 'proxy: ' . $e->getMessage();
    }
    curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($overCap) {
        @unlink($tmpPath);
        return 'file exceeds the size cap (' . (int) ($capBytes / 1048576) . ' MB)';
    }
    if ($curlErr !== '') {
        @unlink($tmpPath);
        return 'download: ' . $curlErr;
    }
    if ($bytes === 0) {
        @unlink($tmpPath);
        return 'empty response';
    }
    return null;
}

// Счётчики прогона.
$done = 0;
$failed = 0;

while (time() - $startedAt < $timeLimit) {
    // Пачка pending-строк; failed после 5 попыток уходит в failed навсегда.
    $rows = $pdo->query("
        SELECT * FROM creative_fetch_queue
        WHERE status = 'pending' AND attempts < 5
        ORDER BY id ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        if (time() - $startedAt >= $timeLimit) {
            break 2;
        }
        $queueId = (int) $row['id'];
        $platform = (string) $row['platform'];
        $kind = (string) $row['kind'];
        $refType = (string) $row['ref_type'];
        $refId = (string) $row['ref_id'];

        // Уже готово (успешный прошлый прогон)? Пропускаем с меткой done.
        $already = $pdo->prepare("SELECT asset_id FROM creative_refs WHERE platform = ? AND ref_type = ? AND ref_id = ? LIMIT 1");
        $already->execute([$platform, $refType, $refId]);
        $existingAssetId = $already->fetchColumn();
        if ($existingAssetId !== false && (int) $existingAssetId > 0) {
            $pdo->prepare("UPDATE creative_fetch_queue SET status = 'done', updated_at = datetime('now') WHERE id = ?")->execute([$queueId]);
            $done++;
            continue;
        }

        $credentials = [];
        try {
            $stmt = $pdo->prepare("SELECT credentials_json FROM aggregator_connections WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $row['connection_id']]);
            $credentials = json_decode((string) $stmt->fetchColumn(), true) ?: [];
        } catch (\Throwable $e) {
        }

        $error = null;
        try {
            // 1. Разрешить URL, если очередь принесла только ref.
            $url = (string) $row['url'];
            $coverTmpPath = null;
            if ($url === '') {
                if ($platform === 'facebook') {
                    require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
                    if ($refType === 'fb_image_hash') {
                        $urls = FacebookAdsEngine::fetchImageUrlsByHashes($credentials, [$refId]);
                        $url = $urls[$refId] ?? '';
                    } elseif ($refType === 'fb_video_id') {
                        $info = FacebookAdsEngine::fetchVideoInfo($credentials, $refId);
                        if ($info === null) {
                            throw new RuntimeException('video info unavailable');
                        }
                        $url = $info['source'];
                        // Обложку качаем сразу сюда: у видео asset.thumb_name.
                        if ($info['picture'] !== '') {
                            $coverTmpPath = tempnam(sys_get_temp_dir(), 'ocr');
                            $coverErr = creative_download($info['picture'], $credentials, 5 * 1024 * 1024, $coverTmpPath);
                            if ($coverErr !== null) {
                                @unlink($coverTmpPath);
                                $coverTmpPath = null;
                            }
                        }
                    }
                } else {
                    require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';
                    if ($refType === 'tt_video_id') {
                        $info = TikTokAdsEngine::fetchVideoInfo($credentials, [$refId]);
                        $url = $info[$refId]['image_url'] ?? ($info[$refId]['preview_url'] ?? '');
                    } elseif ($refType === 'tt_image_id') {
                        $info = TikTokAdsEngine::fetchImageInfo($credentials, [$refId]);
                        $url = $info[$refId]['image_url'] ?? ($info[$refId]['preview_url'] ?? '');
                    }
                }
            }
            if ($url === '' || !preg_match('~^https?://~i', $url)) {
                throw new RuntimeException('no downloadable URL for ' . $refType);
            }

            // 2. Скачать и провалидировать.
            $tmpPath = tempnam(sys_get_temp_dir(), 'ocr');
            $downloadError = creative_download($url, $credentials, fetch_cap_bytes($kind), $tmpPath);
            if ($downloadError !== null) {
                @unlink($tmpPath);
                if ($coverTmpPath !== null) { @unlink($coverTmpPath); }
                throw new RuntimeException($downloadError);
            }
            $sniff = CreativeSync::sniffFile($tmpPath, $kind);
            if ($sniff === null) {
                @unlink($tmpPath);
                if ($coverTmpPath !== null) { @unlink($coverTmpPath); }
                throw new RuntimeException('content failed the signature check');
            }
            $isImage = ($kind !== 'video');
            $sha256 = hash_file('sha256', $tmpPath);
            $phash = $isImage ? CreativeSync::dHashFile($tmpPath) : null;

            // 3. Точный дубль / семья → переиспользовать asset.
            $existing = CreativeSync::findExistingAsset($pdo, $sha256, $phash);
            if ($existing !== null) {
                $assetId = (int) $existing['id'];
                @unlink($tmpPath);
                if ($coverTmpPath !== null) { @unlink($coverTmpPath); }
                // Заготовка вышла «в работу»: платформенный источник не
                // перезаписывает upload, только отмечает время.
                $pdo->prepare("UPDATE media_assets SET last_seen_at = datetime('now'), kind = CASE WHEN kind = 'image' AND ? = 'video' THEN 'video' ELSE kind END WHERE id = ?")
                    ->execute([$kind, $assetId]);
            } else {
                $stored = CreativeSync::storeFile($tmpPath, $sniff['ext']);
                @unlink($tmpPath);
                if ($stored === null) {
                    if ($coverTmpPath !== null) { @unlink($coverTmpPath); }
                    throw new RuntimeException('could not store the file');
                }
                $thumbName = null;
                if ($coverTmpPath !== null) {
                    $coverSniff = CreativeSync::sniffFile($coverTmpPath, 'image');
                    if ($coverSniff !== null) {
                        $thumbName = CreativeSync::storeFile($coverTmpPath, $coverSniff['ext']);
                    }
                    @unlink($coverTmpPath);
                }
                $pdo->prepare("
                    INSERT INTO media_assets
                        (owner_user_id, folder_id, orig_name, stored_name, sha256, mime, size, width, height, source, kind, thumb_name, phash, first_seen_at, last_seen_at)
                    VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ")->execute([
                    ($platform === 'facebook' ? 'fb ' : 'tt ') . $refId . '.' . $sniff['ext'],
                    $stored, $sha256, $sniff['mime'],
                    (int) @filesize(__DIR__ . '/../uploads/media/' . $stored),
                    $sniff['width'] ?? null, $sniff['height'] ?? null,
                    $platform, $kind, $thumbName, $phash,
                ]);
                $assetId = (int) $pdo->lastInsertId();
            }

            // 4. Связки: ref → asset, usage placeholder (asset 0) → asset.
            $pdo->prepare("
                INSERT INTO creative_refs (asset_id, platform, ref_type, ref_id)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(platform, ref_type, ref_id) DO UPDATE SET asset_id = excluded.asset_id
            ")->execute([$assetId, $platform, $refType, $refId]);

            if ((string) $row['ad_id'] !== '') {
                // Поднять текст из плейсхолдера и перенести на реальный asset.
                $placeholder = $pdo->prepare("SELECT ad_text, ad_title, adset_id, campaign_id, connection_id FROM creative_usages WHERE asset_id = 0 AND platform = ? AND ad_id = ? LIMIT 1");
                $placeholder->execute([$platform, (string) $row['ad_id']]);
                $ph = $placeholder->fetch(PDO::FETCH_ASSOC) ?: [];
                $pdo->prepare("
                    INSERT INTO creative_usages (asset_id, platform, ad_id, adset_id, campaign_id, connection_id, ad_text, ad_title, first_seen_at, last_seen_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                    ON CONFLICT(asset_id, platform, ad_id) DO UPDATE SET last_seen_at = datetime('now')
                ")->execute([
                    $assetId, $platform, (string) $row['ad_id'],
                    (string) ($ph['adset_id'] ?? $row['adset_id'] ?? ''),
                    (string) ($ph['campaign_id'] ?? $row['campaign_id'] ?? ''),
                    (int) ($ph['connection_id'] ?? $row['connection_id']),
                    (string) ($ph['ad_text'] ?? ''), (string) ($ph['ad_title'] ?? ''),
                ]);
                $pdo->prepare("DELETE FROM creative_usages WHERE asset_id = 0 AND platform = ? AND ad_id = ?")->execute([$platform, (string) $row['ad_id']]);
            }

            $pdo->prepare("UPDATE creative_fetch_queue SET status = 'done', updated_at = datetime('now') WHERE id = ?")->execute([$queueId]);
            $done++;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $attempts = (int) $row['attempts'] + 1;
            $status = $attempts >= 5 ? 'failed' : 'pending';
            $pdo->prepare("UPDATE creative_fetch_queue SET status = ?, attempts = ?, error = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$status, $attempts, $error, $queueId]);
            $failed++;
            cron_log("[queue#$queueId] ✗ $refType $refId: $error");
        }
    }
}

// Heartbeat — по нему баннер в панели решает, что воркер не запущен.
try {
    $pdo->prepare("
        INSERT INTO settings (key, value, updated_at) VALUES ('creative_fetch_last_run', ?, datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
    ")->execute([gmdate('c')]);
} catch (\Throwable $e) {
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
cron_log("creative fetch pass: done=$done failed=$failed (" . (time() - $startedAt) . "s)");
