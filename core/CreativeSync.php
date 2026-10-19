<?php
// core/CreativeSync.php
//
// Metadata half of the creative library (migration 63): which ad ran which
// file, and what still has to be downloaded.
//
// The cost sync (CostImporter) already knows the ads by id — this class asks
// the platform what THOSE ads contain (image hash / video id / ad text), binds
// the ad rows into creative_usages (so metrics can be joined without
// json_extract), and queues the actual file downloads for the worker
// (cli/creative_fetch_cron.php). The worker owns the bytes: sha256 dedup, the
// dHash "family" match that lets a manually uploaded draft pick up metrics the
// moment the platform starts running it, and the asset rows themselves.
//
// Every platform call is batched; every failure is per-connection and never
// fails the cost sync that called us.

class CreativeSync
{
    /** How often one connection re-reads its creatives (metdata pass). */
    private const SYNC_THROTTLE_SECONDS = 3 * 3600;
    /** Ads per metadata pass: a month of history plus headroom. */
    private const AD_LOOKBACK_DAYS = 40;
    private const MAX_ADS_PER_PASS = 1000;

    /**
     * Discover + bind creatives for one cost connection. Throttled; call it
     * after every cost sync — the throttle keeps it to one live pass per few
     * hours.
     *
     * @return array{ads:int, queued:int, skipped:bool, error:?string}
     */
    public static function syncConnection(PDO $pdo, int $connectionId, string $engine, array $credentials): array
    {
        $platform = strtolower(trim($engine));
        if ($platform !== 'facebook' && $platform !== 'facebook_ads' && $platform !== 'tiktok') {
            return ['ads' => 0, 'queued' => 0, 'skipped' => true, 'error' => null];
        }
        if ($platform === 'facebook_ads') {
            $platform = 'facebook';
        }

        $throttleKey = 'creative_sync_' . $connectionId;
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
            $stmt->execute([$throttleKey]);
            $last = (int) $stmt->fetchColumn();
            if ($last > 0 && time() - $last < self::SYNC_THROTTLE_SECONDS) {
                return ['ads' => 0, 'queued' => 0, 'skipped' => true, 'error' => null];
            }
        } catch (\Throwable $e) {
        }

        // Facebook: the /act_X/ads edge is scoped to THIS cabinet's own ads —
        // no id list needed (a shared-by-platform list would make every
        // connection ask for other cabinets' ads and silently skip the
        // errors). TikTok's /ad/get/ is advertiser-scoped too, but its
        // filtering wants explicit ids — those come from ad_entities, and a
        // foreign id there simply returns nothing.
        $adIds = [];
        if ($platform !== 'facebook') {
            try {
                $adStmt = $pdo->prepare("
                    SELECT external_id FROM ad_entities
                    WHERE platform = ? AND level = 'ad'
                      AND last_seen_at >= datetime('now', '-" . (int) self::AD_LOOKBACK_DAYS . " days')
                    ORDER BY last_seen_at DESC
                    LIMIT " . self::MAX_ADS_PER_PASS . "
                ");
                $adStmt->execute([$platform]);
                $adIds = array_map('strval', $adStmt->fetchAll(PDO::FETCH_COLUMN));
            } catch (\Throwable $e) {
                $adIds = [];
            }
            if (empty($adIds)) {
                self::stampThrottle($pdo, $throttleKey);
                return ['ads' => 0, 'queued' => 0, 'skipped' => false, 'error' => null];
            }
        }

        try {
            if ($platform === 'facebook') {
                require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';
                $ads = FacebookAdsEngine::fetchAdCreatives($credentials);
            } else {
                require_once __DIR__ . '/../aggregator_engines/TikTokAdsEngine.php';
                $ads = TikTokAdsEngine::fetchAdCreatives($credentials, $adIds);
            }
        } catch (\Throwable $e) {
            // A loud failure (fields drift, dead token) must reach the cron
            // log — an empty library with no error is the silent-loss trap.
            self::stampThrottle($pdo, $throttleKey);
            return ['ads' => 0, 'queued' => 0, 'skipped' => false, 'error' => $e->getMessage()];
        }

        $queued = 0;
        try {
            $enqueue = $pdo->prepare("
                INSERT INTO creative_fetch_queue
                    (connection_id, platform, kind, url, ref_type, ref_id, ad_id, adset_id, campaign_id)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON CONFLICT(platform, ref_type, ref_id, kind) DO UPDATE SET
                    url = excluded.url,
                    ad_id = CASE WHEN excluded.ad_id != '' THEN excluded.ad_id ELSE creative_fetch_queue.ad_id END,
                    adset_id = CASE WHEN excluded.adset_id != '' THEN excluded.adset_id ELSE creative_fetch_queue.adset_id END,
                    campaign_id = CASE WHEN excluded.campaign_id != '' THEN excluded.campaign_id ELSE creative_fetch_queue.campaign_id END,
                    -- a re-enqueued ref retried even after a failure
                    status = CASE WHEN creative_fetch_queue.status = 'done' THEN 'done' ELSE 'pending' END
            ");

            foreach ($ads as $ad) {
                $adId = (string) $ad['ad_id'];
                $adsetId = (string) ($ad['adset_id'] ?? '');
                $campaignId = (string) ($ad['campaign_id'] ?? '');
                $common = [$connectionId, $platform];

                // Ad posts to watch for comments (stage 3): the story id is
                // "<pageId>_<postId>"; the page prefix doubles as the page id.
                $storyId = trim((string) ($ad['story_id'] ?? ''));
                if ($platform === 'facebook' && $storyId !== '') {
                    try {
                        $postStmt = $pdo->prepare("
                            INSERT INTO comment_posts (connection_id, ad_id, post_id, page_id, ad_active)
                            VALUES (?, ?, ?, ?, ?)
                            ON CONFLICT(connection_id, post_id) DO UPDATE SET
                                ad_id = excluded.ad_id,
                                page_id = CASE WHEN excluded.page_id != '' THEN excluded.page_id ELSE comment_posts.page_id END
                        ");
                        $postStmt->execute([
                            $connectionId, $adId, $storyId,
                            strtok($storyId, '_') ?: '',
                            in_array((string) ($ad['status'] ?? 'ACTIVE'), ['ACTIVE', ''], true) ? 1 : 0,
                        ]);
                    } catch (\Throwable $e) {
                        // No comment_posts table yet (pre-migration DB): the
                        // creative sync itself must not fail over it.
                    }
                }

                if ($platform === 'facebook') {
                    if (!empty($ad['video_id'])) {
                        // The worker resolves video → source+cover with
                        // fetchVideoInfo (one call per video) before fetching.
                        $enqueue->execute(array_merge($common, ['video', '', 'fb_video_id', (string) $ad['video_id'], $adId, $adsetId, $campaignId]));
                        $queued++;
                    }
                    if (!empty($ad['image_hash'])) {
                        // Resolve the hash to a full-size URL lazily in the
                        // worker (fetchImageUrlsByHashes batches 100); the
                        // creative's inline image_url is the fallback.
                        $fallback = $ad['image_url'] !== '' ? $ad['image_url'] : $ad['thumbnail_url'];
                        $enqueue->execute(array_merge($common, ['image', $fallback, 'fb_image_hash', (string) $ad['image_hash'], $adId, $adsetId, $campaignId]));
                        $queued++;
                    }
                } else {
                    if (!empty($ad['video_id'])) {
                        $enqueue->execute(array_merge($common, ['image', '', 'tt_video_id', (string) $ad['video_id'], $adId, $adsetId, $campaignId]));
                        $queued++;
                    }
                    foreach (($ad['image_ids'] ?? []) as $imageId) {
                        if ((string) $imageId === '') {
                            continue;
                        }
                        $enqueue->execute(array_merge($common, ['image', '', 'tt_image_id', (string) $imageId, $adId, $adsetId, $campaignId]));
                        $queued++;
                    }
                }
            }

            // Bind the ad text right here — it does not need the file. Written
            // as a pending usage against asset 0; the worker rewrites the row
            // with the real asset_id when the download lands.
            $usage = $pdo->prepare("
                INSERT INTO creative_usages (asset_id, platform, ad_id, adset_id, campaign_id, connection_id, ad_text, ad_title, first_seen_at, last_seen_at)
                VALUES (0, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ON CONFLICT(asset_id, platform, ad_id) DO UPDATE SET
                    adset_id = CASE WHEN excluded.adset_id != '' THEN excluded.adset_id ELSE creative_usages.adset_id END,
                    campaign_id = CASE WHEN excluded.campaign_id != '' THEN excluded.campaign_id ELSE creative_usages.campaign_id END,
                    ad_text = CASE WHEN excluded.ad_text != '' THEN excluded.ad_text ELSE creative_usages.ad_text END,
                    ad_title = CASE WHEN excluded.ad_title != '' THEN excluded.ad_title ELSE creative_usages.ad_title END,
                    connection_id = excluded.connection_id,
                    last_seen_at = datetime('now')
            ");
            foreach ($ads as $ad) {
                $usage->execute([
                    $platform,
                    (string) $ad['ad_id'],
                    (string) ($ad['adset_id'] ?? ''), (string) ($ad['campaign_id'] ?? ''),
                    $connectionId,
                    (string) ($ad['body'] ?? ($ad['ad_text'] ?? '')),
                    (string) ($ad['title'] ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            self::stampThrottle($pdo, $throttleKey);
            return ['ads' => count($ads), 'queued' => $queued, 'skipped' => false, 'error' => $e->getMessage()];
        }

        self::stampThrottle($pdo, $throttleKey);
        return ['ads' => count($ads), 'queued' => $queued, 'skipped' => false, 'error' => null];
    }

    private static function stampThrottle(PDO $pdo, string $throttleKey): void
    {
        try {
            $pdo->prepare("
                INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
            ")->execute([$throttleKey, (string) time()]);
        } catch (\Throwable $e) {
        }
    }

    // ---- dHash / families ---------------------------------------------------

    /**
     * 64-bit difference hash (dHash) of an image file: the perceptual
     * fingerprint behind "almost the same creative". GD only — no external
     * dependencies; a missing GD returns null and families simply do not
     * match (sha256 exact dedup still works).
     *
     * 9x8 grayscale, each bit = pixel brighter than its right neighbour.
     * Returned as 16 hex chars.
     */
    public static function dHashFile(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            imagedestroy($img);
            return null;
        }
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        imagedestroy($img);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y) & 0xFF;
                $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                $bits .= ($left > $right) ? '1' : '0';
            }
        }
        imagedestroy($small);
        // Two 32-bit halves: base_convert() loses precision on a full 64-bit
        // binary string (all-ones came back as a 17-char hex), and each half
        // fits the float mantissa exactly.
        $hex = '';
        foreach ([0, 32] as $offset) {
            $hex .= str_pad(base_convert(substr($bits, $offset, 32), 2, 16), 8, '0', STR_PAD_LEFT);
        }
        return $hex;
    }

    /**
     * Hamming distance between two hex hashes (64 for full mismatch, -1 when
     * either side is missing/malformed).
     */
    public static function hamming(?string $a, ?string $b): int
    {
        if ($a === null || $b === null || strlen($a) !== 16 || strlen($b) !== 16) {
            return -1;
        }
        $distance = 0;
        for ($i = 0; $i < 16; $i++) {
            $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
            for ($m = 1; $m <= 8; $m <<= 1) {
                if ($xor & $m) {
                    $distance++;
                }
            }
        }
        return $distance;
    }

    /** Family threshold: ≤ 6 differing bits of 64 reads as "same creative". */
    public const FAMILY_HAMMING_THRESHOLD = 6;

    /**
     * Content sniff for a downloaded file: images via getimagesize, videos via
     * the mp4 'ftyp' / webm EBML signatures. Returns the extension+mime (and
     * dimensions for images) or null when the bytes are neither — a text file
     * wearing .jpg dies here.
     *
     * @return array{ext:string,mime:string,width?:int,height?:int}|null
     */
    public static function sniffFile(string $path, string $kind): ?array
    {
        if ($kind === 'video') {
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return null;
            }
            $head = (string) fread($fh, 12);
            fclose($fh);
            if (substr($head, 4, 4) === 'ftyp') {
                return ['ext' => 'mp4', 'mime' => 'video/mp4'];
            }
            if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
                return ['ext' => 'webm', 'mime' => 'video/webm'];
            }
            return null;
        }
        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1]) || strpos((string) ($info['mime'] ?? ''), 'image/') !== 0) {
            return null;
        }
        $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $mime = (string) $info['mime'];
        return ['ext' => $extByMime[$mime] ?? 'jpg', 'mime' => $mime, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * Store a downloaded temp file into uploads/media under its content-hash
     * name (the same sharding scheme orbitraMediaStoreUpload() uses). The
     * caller owns the temp file and unlinks it on every path.
     */
    public static function storeFile(string $tmpPath, string $ext): ?string
    {
        $sha = hash_file('sha256', $tmpPath);
        if ($sha === false) {
            return null;
        }
        $name = substr($sha, 0, 2) . '/' . substr($sha, 0, 12) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = __DIR__ . '/../uploads/media';
        @mkdir($dir . '/' . substr($name, 0, 2), 0775, true);
        if (!@rename($tmpPath, $dir . '/' . $name)) {
            return null;
        }
        @chmod($dir . '/' . $name, 0644);
        return $name;
    }

    /**
     * The asset a downloaded file belongs to: an exact sha256 duplicate, or —
     * images only — a dHash family member among the operator's own uploads.
     * The family branch is the "draft went live" link: a manually uploaded
     * draft picks up metrics instead of spawning a near-duplicate row.
     *
     * @return array|null media_assets row
     */
    public static function findExistingAsset(PDO $pdo, string $sha256, ?string $phash): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM media_assets WHERE sha256 = ? AND COALESCE(deleted_at, '') = '' LIMIT 1");
        $stmt->execute([$sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        if ($phash === null || $phash === '') {
            return null;
        }
        // Семья — только против своих загрузок (source='upload'): точность
        // перцептивного хэша не тянет на глобальную дедупликацию между
        // площадками.
        try {
            $candidates = $pdo->query("SELECT id, phash FROM media_assets WHERE phash IS NOT NULL AND phash != '' AND source = 'upload' AND COALESCE(deleted_at, '') = '' LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        $best = null;
        $bestDistance = self::FAMILY_HAMMING_THRESHOLD + 1;
        foreach ($candidates as $cand) {
            $distance = self::hamming($phash, (string) $cand['phash']);
            if ($distance >= 0 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $cand;
            }
        }
        if ($best === null) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM media_assets WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $best['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
