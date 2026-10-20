<?php
// Delivery goes through the landing push base's sender. Load it here, not at
// the call sites: api.php (admin_push_test), postback.php and TelegramFunnel
// only require this file, and without these lines every real send died on
// "Class PushSender not found" (a 500 on the test button; swallowed by the
// postback's try/catch, so conversion pushes silently never left).
require_once __DIR__ . "/PushBase.php";
require_once __DIR__ . "/PushMacros.php";
require_once __DIR__ . "/PushSender.php";

/**
 * AdminPushNotify — web-push alerts to the operator's own devices.
 *
 * The Telegram twin of this lives in TelegramNotifyRules + the admin bot;
 * this one rides the browser: the panel PWA (installed to the home screen or
 * a desktop browser) subscribes through the panel service worker, and the
 * tracker pushes to it with the same VAPID keypair the landing push base
 * uses (core/PushBase.php) — one keypair, two audiences.
 *
 * Audiences are kept strictly apart: push_subscriptions rows are landing
 * visitors being re-engaged; admin_push_subs rows are the tracker's own
 * users being notified. Same RFC 8291/8292 wire format, different tables,
 * different triggers.
 *
 * Delivery is synchronous (like the Telegram alerts): a conversion postback
 * sends to every matching device inline, one short-timeout HTTPS call each.
 * Dead endpoints (404/410) age out on the spot. Digests are sent by
 * cli/push_cron.php, which calls runDigests() on its per-minute tick; the
 * sent-marker table makes a double-fire impossible even when cron overlaps.
 */


class AdminPushNotify
{
    /**
     * Status filter keys — the same vocabulary TelegramNotifyRules uses, so
     * both channels can be filtered identically. 'all' is a UI shorthand
     * stored as an empty CSV.
     */
    public static function statusKeys(): array
    {
        return ['sale', 'deposit', 'registration', 'hold', 'rejected', 'trash', 'tg_join'];
    }

    /** Settings row for a user, defaults merged in. Never returns null. */
    public static function settingsFor(PDO $pdo, int $userId): array
    {
        $defaults = [
            'conv_enabled' => 1,
            'conv_statuses' => '',
            'conv_campaigns' => '',
            'digest_daily' => 0,
            'digest_weekly' => 0,
            'digest_monthly' => 0,
            'digest_time' => '21:00',
        ];
        try {
            $stmt = $pdo->prepare("SELECT * FROM admin_push_settings WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $defaults; // pre-migration database: read as "not configured"
        }
        if (!$row) {
            return $defaults;
        }
        // Stringify: SQLite gives native types, the API answers JSON.
        foreach ($defaults as $k => $v) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $defaults[$k] = is_int($v) ? (int) $row[$k] : (string) $row[$k];
            } elseif (is_int($v)) {
                $defaults[$k] = 0;
            }
        }
        return $defaults;
    }

    /**
     * Validate + persist a settings patch. Only the posted keys move; absent
     * keys keep their stored value (the "absent = keep" convention of
     * save_user).
     *
     * @return array the stored settings after the save
     */
    public static function saveSettings(PDO $pdo, int $userId, array $patch): array
    {
        $current = self::settingsFor($pdo, $userId);
        $next = $current;

        if (array_key_exists('conv_enabled', $patch)) {
            $next['conv_enabled'] = !empty($patch['conv_enabled']) ? 1 : 0;
        }
        if (array_key_exists('conv_statuses', $patch)) {
            $list = array_values(array_intersect(
                self::statusKeys(),
                array_map('strval', (array) $patch['conv_statuses'])
            ));
            $next['conv_statuses'] = implode(',', $list);
        }
        if (array_key_exists('conv_campaigns', $patch)) {
            // The UI posts a CSV string; the API may post an array of ids.
            $raw = is_array($patch['conv_campaigns']) ? $patch['conv_campaigns'] : explode(',', (string) $patch['conv_campaigns']);
            $ids = [];
            foreach ($raw as $id) {
                $id = trim((string) $id);
                if ($id !== '' && ctype_digit($id)) {
                    $ids[] = $id;
                }
            }
            $next['conv_campaigns'] = implode(',', array_values(array_unique($ids)));
        }
        foreach (['digest_daily', 'digest_weekly', 'digest_monthly'] as $flag) {
            if (array_key_exists($flag, $patch)) {
                $next[$flag] = !empty($patch[$flag]) ? 1 : 0;
            }
        }
        if (array_key_exists('digest_time', $patch)) {
            $time = trim((string) $patch['digest_time']);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
                $next['digest_time'] = $time;
            }
        }

        $pdo->prepare(
            "INSERT INTO admin_push_settings
                (user_id, conv_enabled, conv_statuses, conv_campaigns,
                 digest_daily, digest_weekly, digest_monthly, digest_time, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
             ON CONFLICT(user_id) DO UPDATE SET
                conv_enabled = excluded.conv_enabled,
                conv_statuses = excluded.conv_statuses,
                conv_campaigns = excluded.conv_campaigns,
                digest_daily = excluded.digest_daily,
                digest_weekly = excluded.digest_weekly,
                digest_monthly = excluded.digest_monthly,
                digest_time = excluded.digest_time,
                updated_at = excluded.updated_at"
        )->execute([
            $userId,
            (int) $next['conv_enabled'],
            $next['conv_statuses'],
            $next['conv_campaigns'],
            (int) $next['digest_daily'],
            (int) $next['digest_weekly'],
            (int) $next['digest_monthly'],
            $next['digest_time'],
        ]);
        return $next;
    }

    /**
     * Store (or refresh) one browser subscription. Upsert keyed on the
     * endpoint: re-subscribing the same browser after a key rotation or a
     * login as another user rebinds the row instead of duplicating it.
     *
     * @return array{id:int}|array{error:string}
     */
    public static function subscribe(PDO $pdo, int $userId, array $sub): array
    {
        $endpoint = trim((string) ($sub['endpoint'] ?? ''));
        $p256dh = trim((string) ($sub['keys']['p256dh'] ?? ($sub['p256dh'] ?? '')));
        $auth = trim((string) ($sub['keys']['auth'] ?? ($sub['auth'] ?? '')));
        $ua = mb_substr(trim((string) ($sub['user_agent'] ?? '')), 0, 250);

        if (strpos($endpoint, 'https://') !== 0) {
            return ['error' => 'endpoint must be an https push endpoint'];
        }
        if ($p256dh === '' || $auth === '') {
            return ['error' => 'p256dh and auth keys are required'];
        }

        $pdo->prepare(
            "INSERT INTO admin_push_subs (user_id, endpoint, p256dh, auth, user_agent, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON CONFLICT(endpoint) DO UPDATE SET
                user_id = excluded.user_id,
                p256dh = excluded.p256dh,
                auth = excluded.auth,
                user_agent = excluded.user_agent,
                is_active = 1"
        )->execute([$userId, $endpoint, $p256dh, $auth, $ua]);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM admin_push_subs WHERE endpoint = ?");
            $stmt->execute([$endpoint]);
            $id = (int) $stmt->fetchColumn();
        }
        return ['id' => $id];
    }

    public static function unsubscribe(PDO $pdo, int $userId, int $id): void
    {
        // Scoped by user_id: one account cannot retire another account's
        // device by guessing ids.
        $pdo->prepare("DELETE FROM admin_push_subs WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    }

    /** @return array active + dead subscriptions of one user, newest first */
    public static function devicesFor(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, endpoint, user_agent, is_active, created_at, last_seen
                 FROM admin_push_subs WHERE user_id = ? ORDER BY id DESC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Conversion dispatch
    // ------------------------------------------------------------------

    /**
     * The conversion hook — called right next to TelegramNotifyRules::dispatch
     * (postback.php, and the Telegram funnel's tg_join). Fans the event out
     * to every user whose filters match, on each of their active devices.
     *
     * A user with an active device and NO settings row gets the defaults
     * (everything, unfiltered): subscribing a device is opting in, and a
     * fresh subscriber must not stay silent until someone opens the settings
     * card and saves it once.
     *
     * @return int devices notified
     */
    public static function dispatch(PDO $pdo, string $clickId, string $status, float $payout, string $currency, int $campaignId): int
    {
        try {
            $rows = $pdo->query(
                "SELECT u.id AS user_id, u.language, s.conv_statuses, s.conv_campaigns
                 FROM users u
                 JOIN (SELECT DISTINCT user_id FROM admin_push_subs WHERE is_active = 1) d
                     ON d.user_id = u.id
                 LEFT JOIN admin_push_settings s ON s.user_id = u.id
                 WHERE (s.conv_enabled IS NULL OR s.conv_enabled = 1)"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return 0; // pre-migration database
        }
        if (!$rows) {
            return 0;
        }

        $notified = 0;
        foreach ($rows as $row) {
            $statuses = array_filter(explode(',', (string) $row['conv_statuses']));
            if ($statuses && !in_array($status, $statuses, true)) {
                continue;
            }
            $campaigns = array_filter(explode(',', (string) $row['conv_campaigns']));
            if ($campaigns && !in_array((string) $campaignId, $campaigns, true)) {
                continue;
            }

            $devices = self::activeDevices($pdo, (int) $row['user_id']);
            if (!$devices) {
                continue;
            }
            $text = self::conversionText($pdo, $clickId, $status, $payout, $currency, $campaignId, (string) ($row['language'] ?? ''));
            // A tag per conversion: with one shared tag each new push would
            // replace the previous one in the tray — five conversions read as
            // one. The tag rides PushSender::buildPayload's explicit-tag
            // branch; link is a panel-relative query the service worker
            // resolves against the panel path (it knows where the panel
            // lives; the server here might not).
            $message = [
                'id' => 2,
                'tag' => 'orbitra-conv-' . preg_replace('/[^A-Za-z0-9_-]/', '', $clickId) . '-' . $status,
                'title' => $text['title'],
                'text' => $text['body'],
                'link_url' => '?tab=conversions',
                'icon_url' => '/frontend/dist/icons/icon-192.png',
                'image_url' => '',
            ];
            foreach ($devices as $device) {
                $res = static::deliver($pdo, $device, $message);
                if (!empty($res['ok'])) {
                    $notified++;
                    self::touchDevice($pdo, (int) $device['id']);
                } elseif (!empty($res['dead'])) {
                    self::retireDevice($pdo, (int) $device['id']);
                }
            }
        }
        return $notified;
    }

    /**
     * One conversion's notification text. Plain words, no {macros}: this is
     * not the landing funnel copy, it is an operator alert. Payout carries
     * its own currency; the flag emoji doubles as the country for a glance.
     */
    private static function conversionText(PDO $pdo, string $clickId, string $status, float $payout, string $currency, int $campaignId, string $lang): array
    {
        $one = function (string $sql, array $args) use ($pdo): string {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($args);
                $v = $stmt->fetchColumn();
                return $v === false || $v === null ? '' : (string) $v;
            } catch (\Throwable $e) {
                return '';
            }
        };
        $campaign = $one("SELECT name FROM campaigns WHERE id = ?", [$campaignId]);
        $click = [];
        try {
            $stmt = $pdo->prepare("SELECT offer_id, country_code FROM clicks WHERE id = ? LIMIT 1");
            $stmt->execute([$clickId]);
            $click = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
        }
        $offer = !empty($click['offer_id'])
            ? $one("SELECT name FROM offers WHERE id = ?", [(int) $click['offer_id']])
            : '';
        $country = strtoupper((string) ($click['country_code'] ?? ''));
        $flag = '';
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $flag = mb_chr(0x1F1E6 + ord($country[0]) - 65) . mb_chr(0x1F1E6 + ord($country[1]) - 65) . ' ';
        }

        $money = $payout > 0
            ? number_format($payout, 2, '.', ' ') . ' ' . $currency
            : '';
        $title = strtoupper($status) . ($money !== '' ? ' · ' . $money : '');
        $body = trim(($campaign !== '' ? $campaign : '#' . $campaignId)
            . ($offer !== '' ? ' · ' . $offer : '')
            . ' · ' . date('H:i'));
        return ['title' => $flag . $title, 'body' => $body];
    }

    // ------------------------------------------------------------------
    // Digests
    // ------------------------------------------------------------------

    /**
     * Digest scheduler — one call per push_cron tick. A digest goes out when
     * the user's wall clock has passed digest_time and no digest of that
     * period was sent today (the unique (user, period, period_key) row is
     * both the marker and the lock: a concurrent tick's INSERT fails and the
     * loser skips).
     *
     * Period windows are tracker days (UTC, like the clicks table and the
     * Telegram daily summary); digest_time is the user's local clock, from
     * their profile timezone.
     *
     * @return int digests sent
     */
    public static function runDigests(PDO $pdo, ?int $now = null): int
    {
        try {
            $rows = $pdo->query(
                "SELECT s.*, u.language, u.timezone FROM admin_push_settings s
                 JOIN users u ON u.id = s.user_id
                 WHERE s.digest_daily = 1 OR s.digest_weekly = 1 OR s.digest_monthly = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return 0;
        }
        if (!$rows) {
            return 0;
        }
        $now = $now ?? time();

        $sent = 0;
        foreach ($rows as $row) {
            if (!self::activeDevices($pdo, (int) $row['user_id'])) {
                continue;
            }
            $zone = (string) ($row['timezone'] ?? '');
            if (!in_array($zone, timezone_identifiers_list(), true)) {
                $zone = 'UTC';
            }
            // Derived from $now (not 'now') so the test can pin the clock.
            $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($zone));
            $dueMinute = (int) substr((string) $row['digest_time'], 0, 2) * 60
                + (int) substr((string) $row['digest_time'], 3, 2);
            $localMinute = (int) $local->format('H') * 60 + (int) $local->format('i');
            $beforeTime = $localMinute < $dueMinute;

            foreach (['daily', 'weekly', 'monthly'] as $period) {
                if ((int) ($row['digest_' . $period] ?? 0) !== 1) {
                    continue;
                }
                // Daily waits for the time every day. Weekly/monthly cover
                // the last COMPLETE week/month: on its first day (Monday /
                // the 1st) they wait for the time too; on any later day the
                // window is still unsent (cron was down, or the digest was
                // just switched on), so they catch up right away.
                if ($beforeTime) {
                    $firstDay = $period === 'daily'
                        || ($period === 'weekly' && (int) $local->format('N') === 1)
                        || ($period === 'monthly' && (int) $local->format('j') === 1);
                    if ($firstDay) {
                        continue;
                    }
                }
                // period_key pins the digest to one window: the local day for
                // daily, the ISO week for weekly, the local month for monthly.
                $periodKey = $period === 'daily'
                    ? $local->format('Y-m-d')
                    : ($period === 'weekly' ? $local->format('o-W') : $local->format('Y-m'));

                // Claim first (INSERT OR IGNORE): the unique key decides the
                // race, and a claimed digest must not double-send on error.
                try {
                    $claim = $pdo->prepare(
                        "INSERT OR IGNORE INTO admin_push_digest_log (user_id, period, period_key) VALUES (?, ?, ?)"
                    );
                    $claim->execute([(int) $row['user_id'], $period, $periodKey]);
                    if ((int) $pdo->query("SELECT changes()")->fetchColumn() === 0) {
                        continue; // already sent in this window
                    }
                } catch (\Throwable $e) {
                    continue;
                }

                $text = self::digestText($pdo, $period, $local, (string) ($row['language'] ?? ''));
                $message = [
                    'id' => 1,
                    // One tray slot per digest window, renotify alerts the
                    // replacement; tap opens the dashboard.
                    'tag' => 'orbitra-digest-' . $period . '-' . $periodKey,
                    'title' => $text['title'],
                    'text' => $text['body'],
                    'link_url' => '?tab=dashboard',
                    'icon_url' => '/frontend/dist/icons/icon-192.png',
                    'image_url' => '',
                ];
                foreach (self::activeDevices($pdo, (int) $row['user_id']) as $device) {
                    $res = static::deliver($pdo, $device, $message);
                    if (!empty($res['ok'])) {
                        $sent++;
                        self::touchDevice($pdo, (int) $device['id']);
                    } elseif (!empty($res['dead'])) {
                        self::retireDevice($pdo, (int) $device['id']);
                    }
                }
            }
        }
        return $sent;
    }

    /**
     * Window for a digest period, in the reader's own timezone. Public
     * because the Telegram digests compute over the SAME windows — one
     * function, matching numbers in both channels.
     *
     *  - daily:   today, local midnight to local midnight (sent in the evening);
     *  - weekly:  the LAST COMPLETE ISO week, Monday to Monday — a digest that
     *             goes out on Monday must summarise the week that just ended,
     *             not the few hours of the new one;
     *  - monthly: the LAST COMPLETE calendar month, for the same reason.
     *
     * Bounds are converted to UTC strings because clicks.created_at is stored
     * in UTC — plain comparisons keep the created_at index usable. DST is
     * handled by doing the calendar arithmetic in the local zone.
     *
     * @return array{from:string, to:string, label:string}
     */
    public static function digestWindow(string $period, int $now, string $zone = 'UTC'): array
    {
        if (!in_array($zone, timezone_identifiers_list(), true)) {
            $zone = 'UTC';
        }
        $tz = new DateTimeZone($zone);
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $today = $local->setTime(0, 0, 0);
        if ($period === 'weekly') {
            // 'monday this week' is ISO: on a Sunday it is the Monday before.
            $to = $today->modify('monday this week');
            $from = $to->modify('-7 days');
            $last = $to->modify('-1 day');
            $label = $from->format('d.m') . '–' . $last->format('d.m.Y');
        } elseif ($period === 'monthly') {
            $to = $today->modify('first day of this month');
            $from = $to->modify('first day of previous month');
            $label = $from->format('m.Y');
        } else {
            $from = $today;
            $to = $today->modify('+1 day');
            $label = $from->format('d.m.Y');
        }
        $utc = new DateTimeZone('UTC');
        return [
            'from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            'to' => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            'label' => $label,
        ];
    }

    /**
     * The safe-page predicate the dashboard applies (core/ReportMetrics.php),
     * so digest numbers match the panel for cloaked campaigns. Falls back to
     * no filter only if ReportMetrics cannot be loaded.
     */
    public static function safePageSql(string $prefix = ''): string
    {
        if (!function_exists('orbitraSafePagePredicate')) {
            $file = __DIR__ . '/ReportMetrics.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        return function_exists('orbitraSafePagePredicate') ? orbitraSafePagePredicate($prefix) : '1=1';
    }

    /**
     * Clicks/conversions/revenue/cost for a [from, to) UTC window. Shared by
     * the push digests and the Telegram digests so the two never disagree.
     *
     * @return array{clicks:int, conversions:int, revenue:float, cost:float}
     */
    public static function windowStats(PDO $pdo, string $from, string $to): array
    {
        $stats = ['clicks' => 0, 'conversions' => 0, 'revenue' => 0.0, 'cost' => 0.0];
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS clicks,
                        COALESCE(SUM(is_conversion), 0) AS conversions,
                        COALESCE(SUM(revenue), 0) AS revenue,
                        COALESCE(SUM(cost), 0) AS cost
                 FROM clicks
                 WHERE created_at >= ? AND created_at < ? AND " . self::safePageSql()
            );
            $stmt->execute([$from, $to]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stats = [
                    'clicks' => (int) $row['clicks'],
                    'conversions' => (int) $row['conversions'],
                    'revenue' => (float) $row['revenue'],
                    'cost' => (float) $row['cost'],
                ];
            }
        } catch (\Throwable $e) {
        }
        return $stats;
    }

    /**
     * Timezone for install-wide (Telegram) digests: the panel owner's profile
     * timezone — the first admin — so "21:00" in the panel means 21:00 for the
     * person who set it, not server time. Falls back to PHP's default.
     */
    public static function panelTimezone(PDO $pdo): string
    {
        try {
            $tz = (string) $pdo->query(
                "SELECT timezone FROM users WHERE role = 'admin' AND COALESCE(timezone, '') != '' ORDER BY id LIMIT 1"
            )->fetchColumn();
            if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        } catch (\Throwable $e) {
        }
        return date_default_timezone_get();
    }

    /** Totals for one digest window, in the reader's timezone. */
    private static function digestText(PDO $pdo, string $period, DateTimeImmutable $local, string $lang): array
    {
        // $local is derived from the caller's $now; window and label come
        // from the same instant and zone (a pinned test clock moves both).
        $w = self::digestWindow($period, (int) $local->getTimestamp(), $local->getTimezone()->getName());
        $label = $w['label'];

        $stats = self::windowStats($pdo, $w['from'], $w['to']);

        $ru = str_starts_with($lang, 'ru');
        $titles = $ru
            ? ['daily' => 'Итоги дня', 'weekly' => 'Итоги недели', 'monthly' => 'Итоги месяца']
            : ['daily' => 'Daily summary', 'weekly' => 'Weekly summary', 'monthly' => 'Monthly summary'];
        $body = sprintf(
            "🖱 %s\n🎯 %s\n💰 %s\n📈 %s",
            number_format($stats['clicks'], 0, '.', ' '),
            number_format($stats['conversions'], 0, '.', ' '),
            number_format($stats['revenue'], 2, '.', ' '),
            number_format($stats['revenue'] - $stats['cost'], 2, '.', ' ')
        );
        return ['title' => $titles[$period] . ' · ' . $label, 'body' => $body];
    }

    // ------------------------------------------------------------------
    // Test send
    // ------------------------------------------------------------------

    /** Test push to every active device of a user; the UI shows per-device codes. */
    public static function sendTest(PDO $pdo, int $userId): array
    {
        $devices = self::activeDevices($pdo, $userId);
        if (!$devices) {
            return ['results' => [], 'error' => 'no active devices'];
        }
        $message = [
            'id' => 3,
            // Unique per press: a repeated tag only updates the old entry
            // silently on macOS, so the second test looked like a failure.
            'tag' => 'orbitra-test-' . time(),
            'title' => 'Orbitra · test',
            'text' => 'Push pipe is alive',
            'link_url' => '',
            'icon_url' => '/frontend/dist/icons/icon-192.png',
            'image_url' => '',
        ];
        $results = [];
        foreach ($devices as $device) {
            $res = static::deliver($pdo, $device, $message);
            if (!empty($res['ok'])) {
                self::touchDevice($pdo, (int) $device['id']);
            } elseif (!empty($res['dead'])) {
                self::retireDevice($pdo, (int) $device['id']);
            }
            $results[] = [
                'id' => (int) $device['id'],
                'ok' => !empty($res['ok']),
                'code' => $res['code'] ?? null,
                'error' => $res['error'] ?? null,
            ];
        }
        return ['results' => $results];
    }

    // ------------------------------------------------------------------
    // Delivery plumbing
    // ------------------------------------------------------------------

    /** @return array active admin_push_subs rows (endpoint/p256dh/auth shape) */
    private static function activeDevices(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, endpoint, p256dh, auth FROM admin_push_subs
                 WHERE user_id = ? AND is_active = 1 ORDER BY id"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function touchDevice(PDO $pdo, int $id): void
    {
        try {
            $pdo->prepare("UPDATE admin_push_subs SET last_seen = datetime('now') WHERE id = ?")->execute([$id]);
        } catch (\Throwable $e) {
        }
    }

    private static function retireDevice(PDO $pdo, int $id): void
    {
        try {
            $pdo->prepare("UPDATE admin_push_subs SET is_active = 0 WHERE id = ?")->execute([$id]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * One device, one push. Routed through PushSender::send with synthetic
     * rows (the message carries no landing macros, so expansion is a no-op).
     * Protected seam: the test subclass intercepts here instead of touching
     * the network.
     *
     * @param array $device admin_push_subs row
     * @param array $message synthetic push_messages row
     * @return array{ok:bool, code:?int, dead:bool, error:?string}
     */
    protected static function deliver(PDO $pdo, array $device, array $message): array
    {
        // click_id/country_code are absent: PushSender resolves no offer
        // context for them and the payload carries our texts verbatim.
        return PushSender::send($pdo, $device, $message);
    }
}
