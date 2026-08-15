<?php
// core/CurrencyRates.php
//
// Ad platforms report spend in the *ad account's* currency, which is frequently not
// the tracker's currency. Writing that number straight into clicks.cost silently
// mixes EUR spend with USD revenue and makes ROI wrong by whatever the pair happens
// to be that week. Everything that lands in cost_records goes through here first.
//
// Rates are fetched once and cached in `settings` (open.er-api.com, no key required).
// A manual override is honoured first so an operator can pin rates on an air-gapped
// install or when the upstream is unreachable:
//
//     settings['fx_rates_manual_json'] = {"EUR": 0.92, "RUB": 92.5}   // per 1 USD
//
// If no rate is known for a pair the amount is returned unchanged rather than
// zeroed — an unconverted number is wrong by a few percent, a zeroed one destroys
// the report.

class CurrencyRates
{
    private const ENDPOINT = 'https://open.er-api.com/v6/latest/USD';
    private const TTL_SECONDS = 43200; // 12h — FX moves slower than ad spend syncs
    private const HTTP_TIMEOUT = 8;

    /** @var array<string,float>|null in-process memo, keyed by currency, per 1 USD */
    private static $memo = null;

    /**
     * Convert an amount between two ISO-4217 codes.
     * Unknown pair or unreachable rate source => amount is returned unchanged.
     */
    public static function convert(PDO $pdo, float $amount, string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to   = strtoupper(trim($to));

        if ($amount == 0.0 || $from === '' || $to === '' || $from === $to) {
            return $amount;
        }

        $rates = self::table($pdo);
        $fromRate = $rates[$from] ?? null; // units of $from per 1 USD
        $toRate   = $rates[$to] ?? null;

        if ($fromRate === null || $toRate === null || $fromRate <= 0) {
            return $amount;
        }

        return round(($amount / $fromRate) * $toRate, 6);
    }

    /** The tracker's own reporting currency (settings.currency), defaulting to USD. */
    public static function trackerCurrency(PDO $pdo): string
    {
        try {
            $value = $pdo->query("SELECT value FROM settings WHERE key = 'currency' LIMIT 1")->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        } catch (\Throwable $e) {
            // Fall through to the default.
        }
        return 'USD';
    }

    /**
     * USD-based rate table. Manual overrides win over the cached remote table so a
     * pinned rate is never silently replaced by the next refresh.
     *
     * @return array<string,float>
     */
    public static function table(PDO $pdo): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $rates = ['USD' => 1.0];

        $cached = self::getSetting($pdo, 'fx_rates_json');
        $updatedAt = (int) self::getSetting($pdo, 'fx_rates_updated_at');
        $isStale = (time() - $updatedAt) > self::TTL_SECONDS;

        if (is_string($cached) && $cached !== '' && !$isStale) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $rates = array_merge($rates, self::sanitize($decoded));
            }
        } else {
            $fetched = self::fetchRemote();
            if ($fetched !== null) {
                $rates = array_merge($rates, $fetched);
                self::setSetting($pdo, 'fx_rates_json', json_encode($rates));
                self::setSetting($pdo, 'fx_rates_updated_at', (string) time());
            } elseif (is_string($cached) && $cached !== '') {
                // Upstream is down — a stale table still beats no conversion at all.
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $rates = array_merge($rates, self::sanitize($decoded));
                }
            }
        }

        $manual = self::getSetting($pdo, 'fx_rates_manual_json');
        if (is_string($manual) && trim($manual) !== '') {
            $decoded = json_decode($manual, true);
            if (is_array($decoded)) {
                $rates = array_merge($rates, self::sanitize($decoded));
            }
        }

        self::$memo = $rates;
        return $rates;
    }

    /** Drop the in-process memo (tests, long-running CLI loops). */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** @return array<string,float>|null */
    private static function fetchRemote(): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        $rates = $decoded['rates'] ?? null;
        if (!is_array($rates) || empty($rates)) {
            return null;
        }

        return self::sanitize($rates);
    }

    /** @return array<string,float> */
    private static function sanitize(array $raw): array
    {
        $clean = [];
        foreach ($raw as $code => $rate) {
            if (!is_string($code) || !preg_match('/^[A-Za-z]{3}$/', $code)) {
                continue;
            }
            $rate = (float) $rate;
            if ($rate > 0) {
                $clean[strtoupper($code)] = $rate;
            }
        }
        return $clean;
    }

    private static function getSetting(PDO $pdo, string $key)
    {
        try {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ? LIMIT 1");
            $stmt->execute([$key]);
            return $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function setSetting(PDO $pdo, string $key, string $value): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                                   ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $stmt->execute([$key, $value]);
        } catch (\Throwable $e) {
            // Caching is an optimisation; a failure here must not break a sync.
        }
    }
}
