<?php
// core/TextTests.php
//
// Text A/B tests on local landings: one piece of text on the page (a
// headline, a paragraph, a button label) gets several variants; every visit
// is shown one of them and the winner is decided by behaviour — the share of
// visitors who went on to the offer (lp CTR), with conversions alongside.
//
// Design points:
//   - The landing files are never touched. When the tracker serves a local
//     landing it swaps the original text for the visit's variant in the
//     HTML it is about to send (orbitraApplyTextTests() in index.php's
//     landing branches). No client-side swap, so no flash of the original.
//   - The variant is a pure function of (click id, test, weights): a reload
//     of the same click shows the same text even before the hit row exists.
//   - text_test_hits remembers which variant a click saw; stats join it to
//     clicks (offer_at = went on to the offer) and conversions.
//   - 'auto' tests re-weigh themselves (Thompson sampling over lp CTR, from
//     the rotation cron): the likely winner gets more traffic, every variant
//     keeps at least a 5% floor so a slow starter can still recover.
//   - Only text nodes are matched first (">Original<"), then the first plain
//     occurrence, so attributes and scripts are left alone in the usual case.

require_once __DIR__ . '/ReportMetrics.php';

class TextTests
{
    public const FLOOR_PCT = 5;
    public const MIN_VIEWS_TO_MOVE = 50;     // per variant, before weights leave equal
    public const WIN_VIEWS = 100;            // per variant, before a winner is named
    public const WIN_PROBABILITY = 0.95;
    public const MAX_VARIANTS = 8;
    public const REWEIGH_EVERY_MIN = 30;

    // ---- storage ----------------------------------------------------------

    public static function listForLanding(PDO $pdo, int $landingId, bool $withStats = true): array
    {
        $st = $pdo->prepare('SELECT * FROM text_tests WHERE landing_id = ? ORDER BY id');
        $st->execute([$landingId]);
        $tests = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $tests[] = self::hydrate($pdo, $t, $withStats);
        }
        return $tests;
    }

    public static function get(PDO $pdo, int $id, bool $withStats = true): ?array
    {
        $st = $pdo->prepare('SELECT * FROM text_tests WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        return $t ? self::hydrate($pdo, $t, $withStats) : null;
    }

    private static function hydrate(PDO $pdo, array $t, bool $withStats): array
    {
        foreach (['id', 'landing_id', 'is_active', 'winner_variant_id'] as $k) {
            $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        }
        $st = $pdo->prepare('SELECT * FROM text_test_variants WHERE test_id = ? ORDER BY is_original DESC, id');
        $st->execute([$t['id']]);
        $t['variants'] = array_map(static function ($v) {
            foreach (['id', 'test_id', 'weight', 'is_active', 'is_original'] as $k) {
                $v[$k] = (int) $v[$k];
            }
            return $v;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        if ($withStats) {
            $stats = self::stats($pdo, (int) $t['id']);
            $prob = self::probabilityBest($stats, 4000, crc32('tt' . $t['id']));
            foreach ($t['variants'] as &$v) {
                $v['stats'] = $stats[$v['id']] ?? self::zeroStats();
                $v['prob_best'] = $prob[$v['id']] ?? null;
            }
            unset($v);
        }
        return $t;
    }

    /**
     * Create or update a test with its variants. $in: landing_id, name,
     * original_text, is_active, mode ('auto'|'equal'), variants: [{id?, text,
     * is_active, source?}]. The original text is always variant #1 (control).
     * Returns [test, error].
     */
    public static function save(PDO $pdo, array $in, ?int $id = null): array
    {
        $landingId = (int) ($in['landing_id'] ?? 0);
        $original = trim((string) ($in['original_text'] ?? ''));
        if ($landingId <= 0) {
            return [null, 'landing_required'];
        }
        if ($original === '' || mb_strlen($original) > 2000) {
            return [null, 'original_required'];
        }
        $variantsIn = [];
        $seen = [mb_strtolower($original) => true];
        foreach (is_array($in['variants'] ?? null) ? $in['variants'] : [] as $v) {
            $text = trim((string) (is_array($v) ? ($v['text'] ?? '') : $v));
            if ($text === '' || mb_strlen($text) > 2000 || isset($seen[mb_strtolower($text)])) {
                continue;
            }
            $seen[mb_strtolower($text)] = true;
            $variantsIn[] = [
                'id' => is_array($v) && !empty($v['id']) ? (int) $v['id'] : null,
                'text' => $text,
                'is_active' => !is_array($v) || !isset($v['is_active']) || !empty($v['is_active']) ? 1 : 0,
                'source' => is_array($v) && ($v['source'] ?? '') === 'ai' ? 'ai' : 'manual',
            ];
            if (count($variantsIn) >= self::MAX_VARIANTS - 1) {
                break;
            }
        }
        if (!$variantsIn) {
            return [null, 'variants_required'];
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') {
            $name = mb_substr($original, 0, 60);
        }
        $active = !isset($in['is_active']) || (!empty($in['is_active']) && !in_array($in['is_active'], ['0', 'false'], true)) ? 1 : 0;
        $mode = ($in['mode'] ?? 'auto') === 'equal' ? 'equal' : 'auto';

        $pdo->beginTransaction();
        try {
            if ($id) {
                $cur = self::get($pdo, $id, false);
                if (!$cur || (int) $cur['landing_id'] !== $landingId) {
                    $pdo->rollBack();
                    return [null, 'not_found'];
                }
                $pdo->prepare('UPDATE text_tests SET name = ?, original_text = ?, is_active = ?, mode = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$name, $original, $active, $mode, $id]);
                // A changed control text is a different test: stats would lie.
                if ($cur['original_text'] !== $original) {
                    $pdo->prepare('DELETE FROM text_test_hits WHERE test_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE text_tests SET winner_variant_id = NULL WHERE id = ?')->execute([$id]);
                }
                $pdo->prepare('UPDATE text_test_variants SET text = ? WHERE test_id = ? AND is_original = 1')->execute([$original, $id]);
                $keep = [];
                foreach ($variantsIn as $v) {
                    if ($v['id']) {
                        $up = $pdo->prepare('UPDATE text_test_variants SET text = ?, is_active = ? WHERE id = ? AND test_id = ? AND is_original = 0');
                        $up->execute([$v['text'], $v['is_active'], $v['id'], $id]);
                        if ($up->rowCount() > 0) {
                            $keep[] = $v['id'];
                            continue;
                        }
                    }
                    $pdo->prepare('INSERT INTO text_test_variants (test_id, text, is_active, is_original, source, weight) VALUES (?,?,?,0,?,0)')
                        ->execute([$id, $v['text'], $v['is_active'], $v['source']]);
                    $keep[] = (int) $pdo->lastInsertId();
                }
                $ph = implode(',', array_fill(0, count($keep), '?'));
                $pdo->prepare("DELETE FROM text_test_variants WHERE test_id = ? AND is_original = 0 AND id NOT IN ($ph)")
                    ->execute(array_merge([$id], $keep));
            } else {
                $pdo->prepare('INSERT INTO text_tests (landing_id, name, original_text, is_active, mode) VALUES (?,?,?,?,?)')
                    ->execute([$landingId, $name, $original, $active, $mode]);
                $id = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO text_test_variants (test_id, text, is_active, is_original, source, weight) VALUES (?,?,1,1,\'manual\',0)')
                    ->execute([$id, $original]);
                foreach ($variantsIn as $v) {
                    $pdo->prepare('INSERT INTO text_test_variants (test_id, text, is_active, is_original, source, weight) VALUES (?,?,?,0,?,0)')
                        ->execute([$id, $v['text'], $v['is_active'], $v['source']]);
                }
            }
            self::resetWeightsIfNeeded($pdo, $id);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return [self::get($pdo, $id), null];
    }

    public static function delete(PDO $pdo, int $id): bool
    {
        $pdo->prepare('DELETE FROM text_test_hits WHERE test_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM text_test_variants WHERE test_id = ?')->execute([$id]);
        $st = $pdo->prepare('DELETE FROM text_tests WHERE id = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /** New or edited variant sets start from an equal split. */
    private static function resetWeightsIfNeeded(PDO $pdo, int $testId): void
    {
        $st = $pdo->prepare('SELECT id, weight FROM text_test_variants WHERE test_id = ? AND is_active = 1');
        $st->execute([$testId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sum = array_sum(array_map(static fn($r) => (int) $r['weight'], $rows));
        $zero = array_filter($rows, static fn($r) => (int) $r['weight'] === 0);
        if ($rows && ($sum !== 100 || $zero)) {
            self::writeWeights($pdo, self::equalWeights(array_map(static fn($r) => (int) $r['id'], $rows)));
        }
    }

    private static function equalWeights(array $ids): array
    {
        $n = count($ids);
        if ($n === 0) {
            return [];
        }
        $base = intdiv(100, $n);
        $out = [];
        foreach (array_values($ids) as $i => $id) {
            $out[$id] = $base + ($i === 0 ? 100 - $base * $n : 0);
        }
        return $out;
    }

    private static function writeWeights(PDO $pdo, array $weights): void
    {
        $up = $pdo->prepare('UPDATE text_test_variants SET weight = ? WHERE id = ?');
        foreach ($weights as $id => $w) {
            $up->execute([(int) $w, (int) $id]);
        }
    }

    // ---- serving ------------------------------------------------------------

    /** Active tests with ≥ 2 active variants for a landing (one small query). */
    public static function activeForLanding(PDO $pdo, int $landingId): array
    {
        $st = $pdo->prepare("SELECT t.id AS test_id, t.original_text, t.mode, v.id AS variant_id, v.text, v.weight, v.is_original
                             FROM text_tests t JOIN text_test_variants v ON v.test_id = t.id
                             WHERE t.landing_id = ? AND t.is_active = 1 AND v.is_active = 1
                             ORDER BY t.id, v.is_original DESC, v.id");
        $st->execute([$landingId]);
        $tests = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (int) $r['test_id'];
            $tests[$tid]['id'] = $tid;
            $tests[$tid]['original_text'] = (string) $r['original_text'];
            $tests[$tid]['mode'] = (string) $r['mode'];
            $tests[$tid]['variants'][] = [
                'id' => (int) $r['variant_id'], 'text' => (string) $r['text'],
                'weight' => (int) $r['weight'], 'is_original' => (int) $r['is_original'],
            ];
        }
        return array_values(array_filter($tests, static fn($t) => count($t['variants']) >= 2));
    }

    /** Deterministic weighted pick: the same click always lands on the same variant. */
    public static function pickVariant(array $test, string $clickId): array
    {
        $variants = $test['variants'];
        $weights = [];
        $sum = 0;
        foreach ($variants as $v) {
            $w = $test['mode'] === 'equal' ? 1 : max(0, (int) $v['weight']);
            $weights[] = $w;
            $sum += $w;
        }
        if ($sum <= 0) {
            $weights = array_fill(0, count($variants), 1);
            $sum = count($variants);
        }
        $roll = (crc32($clickId . ':' . $test['id']) % 1000000) / 1000000 * $sum;
        $acc = 0;
        foreach ($variants as $i => $v) {
            $acc += $weights[$i];
            if ($roll < $acc) {
                return $v;
            }
        }
        return $variants[count($variants) - 1];
    }

    /** Replace the original text with the variant; null when the original is not on the page. */
    public static function replaceText(string $html, string $original, string $replacement): ?string
    {
        $candidates = array_unique([$original, htmlspecialchars($original, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($original, ENT_NOQUOTES, 'UTF-8')]);
        $safe = htmlspecialchars($replacement, ENT_NOQUOTES, 'UTF-8');
        // Earliest text-node match across the spellings (raw, &amp;-escaped),
        // so the swap hits the first visible occurrence on the page.
        $best = null;
        foreach ($candidates as $needle) {
            $re = '/(>\s*)' . preg_quote($needle, '/') . '(\s*<)/u';
            if (@preg_match($re, $html, $m, PREG_OFFSET_CAPTURE) === 1) {
                $at = $m[0][1] + strlen($m[1][0]);
                if ($best === null || $at < $best[0]) {
                    $best = [$at, strlen($needle)];
                }
            }
        }
        if ($best === null) {
            foreach ($candidates as $needle) {
                $pos = strpos($html, $needle);
                if ($pos !== false && ($best === null || $pos < $best[0])) {
                    $best = [$pos, strlen($needle)];
                }
            }
        }
        if ($best !== null) {
            return substr($html, 0, $best[0]) . $safe . substr($html, $best[0] + $best[1]);
        }
        return null;
    }

    /**
     * Swap every active test's text for this click's variant and remember
     * the assignment. Never throws: a broken test must not break a landing.
     */
    public static function apply(PDO $pdo, string $html, int $landingId, string $clickId): string
    {
        if ($landingId <= 0 || $clickId === '') {
            return $html;
        }
        try {
            $tests = self::activeForLanding($pdo, $landingId);
        } catch (\Throwable $e) {
            return $html; // tables missing on a half-migrated install
        }
        foreach ($tests as $t) {
            $v = self::pickVariant($t, $clickId);
            if (!$v['is_original']) {
                $out = self::replaceText($html, $t['original_text'], $v['text']);
                if ($out === null) {
                    continue; // the control text is not on this page — no hit, no data
                }
                $html = $out;
            } elseif (self::replaceText($html, $t['original_text'], $t['original_text']) === null) {
                continue;
            }
            try {
                $pdo->prepare('INSERT OR IGNORE INTO text_test_hits (click_id, test_id, variant_id) VALUES (?,?,?)')
                    ->execute([$clickId, $t['id'], $v['id']]);
            } catch (\Throwable $e) {
                // A locked DB costs this one data point, never the page.
            }
        }
        return $html;
    }

    // ---- stats and weights ----------------------------------------------------

    public static function zeroStats(): array
    {
        return ['views' => 0, 'lp_clicks' => 0, 'ctr' => null, 'conversions' => 0, 'sales' => 0, 'revenue' => 0.0];
    }

    /** variant id => views (hits), lp_clicks (went to the offer), conversions, sales, revenue, ctr %. */
    public static function stats(PDO $pdo, int $testId): array
    {
        $agg = orbitraConversionAggregateSql('payout');
        $st = $pdo->prepare("SELECT h.variant_id,
                    COUNT(*) AS views,
                    COALESCE(SUM(CASE WHEN cl.offer_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS lp_clicks,
                    COALESCE(SUM(cva.cnt_any), 0) AS conversions,
                    COALESCE(SUM(cva.cnt_sale), 0) AS sales,
                    COALESCE(SUM(cva.rev_all), 0) AS revenue
                FROM text_test_hits h
                LEFT JOIN clicks cl ON cl.id = h.click_id
                LEFT JOIN $agg cva ON cva.click_id = h.click_id
                WHERE h.test_id = ?
                GROUP BY h.variant_id");
        $st->execute([$testId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $views = (int) $r['views'];
            $lp = (int) $r['lp_clicks'];
            $out[(int) $r['variant_id']] = [
                'views' => $views,
                'lp_clicks' => $lp,
                'ctr' => $views > 0 ? round($lp / $views * 100, 2) : null,
                'conversions' => (int) $r['conversions'],
                'sales' => (int) $r['sales'],
                'revenue' => round((float) $r['revenue'], 2),
            ];
        }
        return $out;
    }

    /** P(variant has the highest lp CTR), Beta(1+clicks, 1+views−clicks) posteriors. */
    public static function probabilityBest(array $stats, int $draws = 4000, int $seed = 1): array
    {
        if (count($stats) < 2) {
            return [];
        }
        mt_srand($seed);
        $wins = array_fill_keys(array_keys($stats), 0);
        for ($i = 0; $i < $draws; $i++) {
            $best = null;
            $bestVal = -1.0;
            foreach ($stats as $id => $s) {
                $x = self::betaSample(1 + $s['lp_clicks'], 1 + max(0, $s['views'] - $s['lp_clicks']));
                if ($x > $bestVal) {
                    $bestVal = $x;
                    $best = $id;
                }
            }
            $wins[$best]++;
        }
        mt_srand();
        return array_map(static fn($w) => round($w / $draws, 4), $wins);
    }

    private static function betaSample(float $a, float $b): float
    {
        $x = self::gammaSample($a);
        $y = self::gammaSample($b);
        return $x + $y > 0 ? $x / ($x + $y) : 0.5;
    }

    /** Marsaglia–Tsang, shape ≥ 1 (our shapes are always ≥ 1). */
    private static function gammaSample(float $k): float
    {
        $d = $k - 1 / 3;
        $c = 1 / sqrt(9 * $d);
        while (true) {
            do {
                $u1 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $u2 = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
                $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
                $v = (1 + $c * $z) ** 3;
            } while ($v <= 0);
            $u = mt_rand(1, mt_getrandmax()) / mt_getrandmax();
            if (log($u) < 0.5 * $z * $z + $d - $d * $v + $d * log($v)) {
                return $d * $v;
            }
        }
    }

    /**
     * Re-weigh one auto test: P(best) → weights with a floor, while every
     * variant has MIN_VIEWS_TO_MOVE views; name a winner once it is clear.
     * Returns ['changed' => bool, 'winner' => ?int].
     */
    public static function reweigh(PDO $pdo, int $testId, ?int $now = null): array
    {
        $now = $now ?? time();
        $test = self::get($pdo, $testId, false);
        $res = ['changed' => false, 'winner' => null];
        if (!$test || !$test['is_active'] || $test['mode'] !== 'auto') {
            return $res;
        }
        $active = array_values(array_filter($test['variants'], static fn($v) => $v['is_active'] === 1));
        if (count($active) < 2) {
            return $res;
        }
        $stats = self::stats($pdo, $testId);
        $sub = [];
        foreach ($active as $v) {
            $sub[$v['id']] = $stats[$v['id']] ?? self::zeroStats();
        }
        $minViews = min(array_map(static fn($s) => $s['views'], $sub));
        $pdo->prepare('UPDATE text_tests SET last_run_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now), $testId]);
        if ($minViews < self::MIN_VIEWS_TO_MOVE) {
            return $res;
        }
        $prob = self::probabilityBest($sub, 4000, crc32($testId . ':' . intdiv($now, 3600)));
        $n = count($sub);
        $floor = self::FLOOR_PCT;
        $pool = 100 - $floor * $n;
        $weights = [];
        foreach ($prob as $id => $p) {
            $weights[$id] = $floor + $pool * $p;
        }
        // Integers summing to 100, remainder to the strongest.
        $int = array_map(static fn($w) => (int) floor($w), $weights);
        arsort($weights);
        $int[array_key_first($weights)] += 100 - array_sum($int);
        $changed = false;
        foreach ($active as $v) {
            if ((int) $v['weight'] !== (int) $int[$v['id']]) {
                $changed = true;
            }
        }
        if ($changed) {
            self::writeWeights($pdo, $int);
        }
        $res['changed'] = $changed;
        $top = array_key_first($weights);
        if ($minViews >= self::WIN_VIEWS && ($prob[$top] ?? 0) >= self::WIN_PROBABILITY) {
            if ((int) $test['winner_variant_id'] !== (int) $top) {
                $pdo->prepare('UPDATE text_tests SET winner_variant_id = ? WHERE id = ?')->execute([$top, $testId]);
                $res['winner'] = (int) $top;
            }
        }
        return $res;
    }

    /** Cron entry: every active auto test not re-weighed in the last half hour. */
    public static function reweighAll(PDO $pdo, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = ['tests' => 0, 'changed' => 0, 'winners' => []];
        try {
            $st = $pdo->prepare("SELECT id FROM text_tests WHERE is_active = 1 AND mode = 'auto'
                                 AND (last_run_at IS NULL OR last_run_at <= ?)");
            $st->execute([gmdate('Y-m-d H:i:s', $now - self::REWEIGH_EVERY_MIN * 60)]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return $out;
        }
        foreach ($ids as $id) {
            $out['tests']++;
            $r = self::reweigh($pdo, (int) $id, $now);
            if ($r['changed']) {
                $out['changed']++;
            }
            if ($r['winner']) {
                $out['winners'][(int) $id] = $r['winner'];
            }
        }
        return $out;
    }

    // ---- candidates ------------------------------------------------------------

    /**
     * Texts on a local landing worth testing: title, headings, paragraphs,
     * buttons and links, 3–300 characters, deduplicated, in page order.
     */
    public static function candidates(string $html, int $limit = 60): array
    {
        if (trim($html) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $out = [];
        $seen = [];
        $nodes = $xp->query('//title|//h1|//h2|//h3|//h4|//p|//button|//a|//li|//span|//div[not(*)]|//label');
        foreach ($nodes as $node) {
            // Only elements whose text is their own (no nested block markup).
            $hasElementChild = false;
            foreach ($node->childNodes as $ch) {
                if ($ch->nodeType === XML_ELEMENT_NODE && !in_array(strtolower($ch->nodeName), ['br'], true)) {
                    $hasElementChild = true;
                    break;
                }
            }
            if ($hasElementChild) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if (mb_strlen($text) < 3 || mb_strlen($text) > 300 || isset($seen[$text]) || strpos($text, '<?') !== false) {
                continue;
            }
            if (self::replaceText($html, $text, $text) === null) {
                continue; // whitespace/markup inside: the server could not find it to swap
            }
            $seen[$text] = true;
            $out[] = ['tag' => strtolower($node->nodeName), 'text' => $text];
            if (count($out) >= $limit) {
                break;
            }
        }
        // Headings first: they are what people test.
        $rank = ['title' => 0, 'h1' => 1, 'h2' => 2, 'button' => 3, 'h3' => 4, 'a' => 5, 'h4' => 6, 'p' => 7];
        usort($out, static fn($a, $b) => ($rank[$a['tag']] ?? 9) <=> ($rank[$b['tag']] ?? 9));
        return $out;
    }

    /** The landing's main page source (index.html, else index.php as text). */
    public static function landingSource(PDO $pdo, int $landingId): string
    {
        if (!function_exists('orbitraLandingDir')) {
            require_once __DIR__ . '/landing_path.php';
        }
        try {
            $dir = orbitraLandingContentDir(orbitraLandingDir($pdo, $landingId));
        } catch (\Throwable $e) {
            return '';
        }
        foreach (['index.html', 'index.php'] as $f) {
            if (is_file($dir . '/' . $f)) {
                return (string) file_get_contents($dir . '/' . $f);
            }
        }
        return '';
    }

    /** Ask the configured model for headline/text variants. Returns string[]. */
    public static function generateVariants(AiClient $client, string $original, int $count, string $lang, string $instructions = '', string $context = ''): array
    {
        $count = max(1, min(self::MAX_VARIANTS - 1, $count));
        $system = 'You write conversion copy for landing pages of performance-marketing campaigns. '
            . 'Return ONLY a JSON array of strings, no commentary. Keep the language of the original text. '
            . 'Keep roughly the same length and the same facts (numbers, brand, bonus amounts); never invent prices, '
            . 'guarantees, medical or financial promises. Each variant must take a clearly different angle '
            . '(urgency, benefit, curiosity, social proof, simplicity…).';
        $prompt = "Original text:\n" . $original . "\n\nWrite {$count} alternative versions.";
        if ($context !== '') {
            $prompt .= "\n\nOther text on the same page, for context:\n" . mb_substr($context, 0, 1500);
        }
        if (trim($instructions) !== '') {
            $prompt .= "\n\nExtra instructions from the user:\n" . mb_substr($instructions, 0, 1000);
        }
        $raw = $client->complete($system, $prompt, 1500);
        if (preg_match('/\[[\s\S]*\]/u', $raw, $m)) {
            $list = json_decode($m[0], true);
        } else {
            $list = null;
        }
        if (!is_array($list)) {
            // Fallback: one variant per non-empty line, bullets stripped.
            $list = array_filter(array_map(static fn($l) => trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $l), " \t\"'"),
                preg_split('/\R/u', $raw)));
        }
        $out = [];
        foreach ($list as $t) {
            if (!is_string($t)) {
                continue;
            }
            $t = trim($t);
            if ($t !== '' && mb_strtolower($t) !== mb_strtolower($original) && !in_array($t, $out, true)) {
                $out[] = mb_substr($t, 0, 2000);
            }
        }
        return array_slice($out, 0, $count);
    }
}

if (!function_exists('orbitraApplyTextTests')) {
    /** index.php hook for local landings. */
    function orbitraApplyTextTests(PDO $pdo, string $html, $landingId, $clickId): string
    {
        return TextTests::apply($pdo, $html, (int) $landingId, (string) $clickId);
    }
}
