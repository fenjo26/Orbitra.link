<?php
/**
 * LeadForge 2.0 — Analyze → Build engine.
 *
 * Three integration modes over one pipeline:
 *   auto           detect the source network and route to the configured
 *                  target, keeping the bundle's own structure intact;
 *   cross-network  cut the old network's handlers out entirely and re-seat the
 *                  landing on the chosen network;
 *   raw            clone patch — strip foreign counters/hostile scripts,
 *                  inject the ClickID bridge and {offer} macros, no backend.
 *
 * The generated order.php is dual-logging: CPA network first, then the full
 * raw snapshot into the CRM vault (in-process when served by this tracker,
 * over /crm-ingest when the ZIP is deployed elsewhere). Every stage appends
 * plain log lines — they are what the panel's execution console renders.
 */

require_once __DIR__ . '/landing_path.php';
require_once __DIR__ . '/CrmVault.php';

class LeadForge
{
    /** Networks the engine can detect and speak. Signatures are lowercase. */
    public static function networks(): array
    {
        return [
            'drcash'      => ['label' => 'Dr.Cash',      'sigs' => ['dr.cash', 'drcash']],
            'lemonad'     => ['label' => 'LemonAD',      'sigs' => ['lemonad']],
            'webvork'     => ['label' => 'Webvork',      'sigs' => ['webvork']],
            'leadbit'     => ['label' => 'Leadbit',      'sigs' => ['leadbit']],
            'everad'      => ['label' => 'Everad',       'sigs' => ['everad']],
            'kma'         => ['label' => 'KMA.biz',      'sigs' => ['kma.biz']],
            'terraleads'  => ['label' => 'TerraLeads',   'sigs' => ['terraleads']],
            'luckyonline' => ['label' => 'Lucky.online', 'sigs' => ['lucky.online']],
            'ezaff'       => ['label' => 'Ezaff',        'sigs' => ['ezaff']],
            'offercify'   => ['label' => 'Offercify',    'sigs' => ['offercify']],
            'adcombo'     => ['label' => 'AdCombo',      'sigs' => ['adcombo']],
            'm1'          => ['label' => 'M1-Shop',      'sigs' => ['m1-shop', 'm1shop']],
            'monsterleads'=> ['label' => 'MonsterLeads', 'sigs' => ['monsterleads']],
            'custom'      => ['label' => 'Custom API / Webhook', 'sigs' => []],
        ];
    }

    /** Third-party counters and hostile snippets Raw mode strips. */
    public static function foreignScriptSignatures(): array
    {
        return [
            'facebook_pixel'   => ['connect.facebook.net', 'fbq(', 'facebook.com/tr?'],
            'tiktok_pixel'     => ['analytics.tiktok.com', 'ttq.load', 'ttq.page'],
            'google_analytics' => ['googletagmanager.com/gtag/js', 'google-analytics.com', 'gtag(\'config\'', 'gtag("config"'],
            'yandex_metrika'   => ['mc.yandex.ru', 'ym('],
            'devtools_blocker' => ['oncontextmenu', 'devtools-detect', 'disable-devtool'],
            'back_redirect'    => ['history.pushstate(-1', 'history.back(-2', 'location.replace(history'],
        ];
    }

    /** Legacy order-handler filenames Cross mode removes from the bundle root. */
    public const LEGACY_HANDLERS = ['order.php', 'send.php', 'api.php', 'sender.php', 'submit.php', 'order_ajax.php', 'ajax.php'];

    /** GEO phone masks shared by the adapter generator and Auto QA. */
    public static function geoMasks(): array
    {
        return [
            'IT' => ['code' => '+39', 'pattern' => '+39 3## ### ####', 'min' => 9, 'max' => 11],
            'ES' => ['code' => '+34', 'pattern' => '+34 6## ### ###', 'min' => 9, 'max' => 9],
            'DE' => ['code' => '+49', 'pattern' => '+49 1## #######', 'min' => 10, 'max' => 12],
            'FR' => ['code' => '+33', 'pattern' => '+33 6 ## ## ## ##', 'min' => 9, 'max' => 9],
            'PL' => ['code' => '+48', 'pattern' => '+48 ### ### ###', 'min' => 9, 'max' => 9],
            'RO' => ['code' => '+40', 'pattern' => '+40 7## ### ###', 'min' => 9, 'max' => 9],
            'GR' => ['code' => '+30', 'pattern' => '+30 69# ### ####', 'min' => 10, 'max' => 10],
            'RU' => ['code' => '+7',  'pattern' => '+7 (9##) ###-##-##', 'min' => 10, 'max' => 10],
            'UA' => ['code' => '+380', 'pattern' => '+380 (##) ###-##-##', 'min' => 9, 'max' => 9],
            'KZ' => ['code' => '+7',  'pattern' => '+7 (7##) ###-##-##', 'min' => 10, 'max' => 10],
            'US' => ['code' => '+1',  'pattern' => '+1 (###) ###-####', 'min' => 10, 'max' => 10],
            'MX' => ['code' => '+52', 'pattern' => '+52 1 ### ### ####', 'min' => 10, 'max' => 11],
            'CO' => ['code' => '+57', 'pattern' => '+57 3## ### ####', 'min' => 10, 'max' => 10],
        ];
    }

    // ==================================================================
    // Analyze
    // ==================================================================

    /**
     * Static inspection of one uploaded archive — nothing is modified.
     * Accepts ZIP archives and bare HTML/PHP files (treated as one-file
     * bundles).
     *
     * @return array analysis card; ['error'=>...] on a broken archive
     */
    public static function analyzeUploaded(string $tmpPath, string $origName): array
    {
        $card = [
            'file_name'     => $origName,
            'size_bytes'    => (int) @filesize($tmpPath),
            'files_count'   => 0,
            'has_index'     => false,
            'detected'      => false,
            'network'       => null,
            'suggested_profile_id' => null,
            'forms_count'   => 0,
            'detected_geo'  => '',
            'encoding'      => 'UTF-8',
            'has_phone_mask' => false,
            'detected_inputs' => [],
            'foreign_scripts_detected' => [],
            'cta_links_count' => 0,
            'ready_for_build' => false,
            'warnings'      => [],
        ];
        $isZip = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'zip'
            || (@mime_content_type($tmpPath) === 'application/zip');
        $dir = sys_get_temp_dir() . '/lf_analyze_' . uniqid();
        @mkdir($dir, 0775, true);

        if ($isZip) {
            if (!class_exists('ZipArchive')) {
                self::rrmdir($dir);
                return ['error' => 'missing_ext_zip'];
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                self::rrmdir($dir);
                return ['error' => 'Invalid or corrupted ZIP archive'];
            }
            $zip->extractTo($dir);
            $zip->close();
            orbitraFlattenSingleNestedDir($dir);
        } else {
            // A bare HTML/PHP file is a one-file bundle.
            $base = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'php' ? 'index.php' : 'index.html';
            copy($tmpPath, $dir . '/' . $base);
        }

        $card = array_merge($card, self::analyzeDir($dir));
        self::rrmdir($dir);

        $card['ready_for_build'] = $card['has_index'] && $card['forms_count'] > 0
            && in_array($card['encoding'], ['UTF-8', 'ASCII'], true);
        if (!$card['has_index']) {
            $card['warnings'][] = 'no_index_page';
        }
        if ($card['forms_count'] === 0) {
            $card['warnings'][] = 'no_forms';
        }
        if (!in_array($card['encoding'], ['UTF-8', 'ASCII'], true)) {
            $card['warnings'][] = 'encoding_not_utf8';
        }
        return $card;
    }

    /** Scan an extracted bundle directory. */
    public static function analyzeDir(string $dir): array
    {
        $out = [
            'files_count' => 0, 'has_index' => false, 'detected' => false,
            'network' => null, 'forms_count' => 0, 'detected_geo' => '',
            'encoding' => 'UTF-8', 'has_phone_mask' => false,
            'detected_inputs' => [], 'foreign_scripts_detected' => [],
            'cta_links_count' => 0,
        ];
        $inputs = [];
        $geoVotes = [];
        $networkHits = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $out['files_count']++;
            $rel = ltrim(str_replace($dir, '', $f->getPathname()), '/');
            if ($rel === 'index.html' || $rel === 'index.htm' || $rel === 'index.php') {
                $out['has_index'] = true;
            }
            $ext = strtolower(pathinfo($f->getPathname(), PATHINFO_EXTENSION));
            if (!in_array($ext, ['html', 'htm', 'php', 'js'], true)) {
                continue;
            }
            $content = (string) @file_get_contents($f->getPathname());
            if ($content === '') {
                continue;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($content, 'UTF-8') : strtolower($content);

            // Encoding: a file that is valid UTF-8 (or plain ASCII) is fine;
            // anything else (cp1251 et al) warns.
            if (!mb_check_encoding($content, 'UTF-8')) {
                $out['encoding'] = 'non-UTF-8';
            }

            // Network signatures.
            foreach (self::networks() as $key => $net) {
                if ($key === 'custom') {
                    continue;
                }
                foreach ($net['sigs'] as $sig) {
                    if (strpos($lower, $sig) !== false) {
                        $networkHits[$key] = ($networkHits[$key] ?? 0) + 1;
                        break;
                    }
                }
            }

            // Foreign scripts / counters.
            foreach (self::foreignScriptSignatures() as $fk => $sigs) {
                foreach ($sigs as $sig) {
                    if (strpos($lower, $sig) !== false) {
                        $out['foreign_scripts_detected'][$fk] = $fk;
                        break;
                    }
                }
            }

            // GEO: the html lang attribute gets one vote per file.
            if (in_array($ext, ['html', 'htm', 'php'], true) && preg_match('/<html[^>]+lang=["\']([a-zA-Z]{2})/i', $content, $m)) {
                $geoKey = strtoupper($m[1]);
                $geoVotes[$geoKey] = ($geoVotes[$geoKey] ?? 0) + 1;
            }

            if (in_array($ext, ['html', 'htm', 'php'], true)) {
                // Forms + input names.
                if (preg_match_all('/<form\b[^>]*>/i', $content, $fm)) {
                    $out['forms_count'] += count($fm[0]);
                }
                if (preg_match_all('/<input[^>]+name=["\']([^"\']+)["\']/i', $content, $im)) {
                    foreach ($im[1] as $name) {
                        $name = strtolower(trim($name));
                        if ($name !== '' && !in_array($name, $inputs, true) && count($inputs) < 12) {
                            $inputs[] = $name;
                        }
                    }
                }
                // CTA anchors: "#order" style jumps and links to form pages.
                if (preg_match_all('/<a[^>]+href=["\']#[a-zA-Z][^"\']*["\']/i', $content, $am)) {
                    $out['cta_links_count'] += count($am[0]);
                }
                if (preg_match_all('/<a[^>]+href=["\'](?:order|form|zamov|zamovlennya|checkout)[^"\']*["\']/i', $lower, $am2)) {
                    $out['cta_links_count'] += count($am2[0]);
                }
            }

            // A phone mask heuristic: masking libs or permissive patterns near
            // the word phone.
            if (strpos($lower, 'phone') !== false || strpos($lower, 'tel') !== false) {
                if (strpos($lower, 'mask(') !== false || strpos($lower, 'inputmask') !== false || strpos($lower, 'imask') !== false) {
                    $out['has_phone_mask'] = true;
                }
            }
        }

        if ($networkHits) {
            arsort($networkHits);
            $out['network'] = array_key_first($networkHits);
            $out['detected'] = true;
        }
        if ($geoVotes) {
            arsort($geoVotes);
            $geo = array_key_first($geoVotes);
            if (array_key_exists($geo, self::geoMasks())) {
                $out['detected_geo'] = $geo;
            }
        }
        $out['detected_inputs'] = $inputs;
        $out['foreign_scripts_detected'] = array_values($out['foreign_scripts_detected']);
        return $out;
    }

    // ==================================================================
    // Build
    // ==================================================================

    /**
     * Full build pipeline for one bundle.
     *
     * @param array $card  the analysis card (from staging), may be null for a
     *                    blind one-shot build
     * @param array $opts mode/network/api_key/offer_id/geo/payout/currency/
     *                    group_id/inject flags/generate flags/auto_save_tracker/
     *                    auto_create_offer/crm_enabled/auto_qa/base_url/name
     * @return array ['ok'=>bool,'message'=>string,'logs'=>[],'result'=>[],'qa'=>[]]
     */
    public static function buildBundle(PDO $pdo, string $zipPath, ?array $card, array $opts): array
    {
        $logs = [];
        $log = function (string $m) use (&$logs) {
            $logs[] = $m;
        };
        $mode = in_array(($opts['mode'] ?? 'auto'), ['auto', 'cross', 'cross-network', 'raw'], true) ? ($opts['mode'] ?? 'auto') : 'auto';
        if ($mode === 'cross-network') {
            $mode = 'cross';
        }
        $geo = strtoupper(trim((string) ($opts['geo'] ?? 'IT')));
        $network = strtolower(trim((string) ($opts['network'] ?? 'drcash')));
        $generateOrder = ($opts['generate_order_php'] ?? true) && $mode !== 'raw';
        $generateThankYou = ($opts['generate_thank_you'] ?? true) && $mode !== 'raw';
        $crmEnabled = !empty($opts['crm_enabled']) && $generateOrder;

        // Auto mode routes to the detected network when one was recognized.
        if ($mode === 'auto' && !empty($card['detected']) && !empty($card['network'])
            && array_key_exists($card['network'], self::networks()) && $card['network'] !== 'custom') {
            $network = $card['network'];
            $log("Auto: source network detected as '{$network}' — routing to it");
        } elseif ($mode === 'cross' && !empty($card['network'])) {
            $log("Cross: replacing source network '{$card['network']}' with target '{$network}'");
        }

        if (($generateOrder || $generateThankYou)) {
            require_once __DIR__ . '/PhpLanding.php';
            if (!PhpLanding::enabled($pdo)) {
                return ['ok' => false, 'message' => 'php_landings_disabled', 'logs' => $logs,
                        'detail' => ['hint' => 'Order handler and Thank You page are PHP — turn on "Allow PHP landings" in Settings -> General']];
            }
        }

        $tempDir = sys_get_temp_dir() . '/lf_build_' . uniqid();
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true)) {
            return ['ok' => false, 'message' => 'Cannot create temp directory for forging', 'logs' => $logs];
        }

        if (strtolower(pathinfo($zipPath, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                self::rrmdir($tempDir);
                return ['ok' => false, 'message' => 'Invalid or corrupted ZIP archive', 'logs' => $logs];
            }
            $zip->extractTo($tempDir);
            $zip->close();
            orbitraFlattenSingleNestedDir($tempDir);
        } else {
            copy($zipPath, $tempDir . '/index.html');
        }

        // --- Cross: cut the old network's handlers out -----------------
        if ($mode === 'cross') {
            $removed = self::removeLegacyHandlers($tempDir);
            foreach ($removed as $r) {
                $log("Cross: removed legacy handler {$r}");
            }
            if (!$removed) {
                $log('Cross: no legacy order handlers found in bundle root');
            }
        }

        // --- HTML pass: strip / inject / rewrite ------------------------
        $injectAdapter = !empty($opts['inject_js_adapter']);
        $injectMacro = !empty($opts['inject_offer_macro']);
        $rewriteForms = $generateOrder;
        $stripForeign = in_array($mode, ['cross', 'raw'], true);
        $formsDetected = 0;
        $filesProcessed = 0;
        $strippedCount = 0;

        $adapterInline = $injectAdapter ? self::adapterJs($geo, !empty($opts['add_phone_mask'])) : '';

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
            if (!in_array($ext, ['html', 'htm', 'php'], true)) {
                continue;
            }
            // Never touch the handlers we ourselves generate.
            $base = strtolower($file->getFilename());
            if (in_array($base, ['order.php', 'thank_you.php'], true)) {
                continue;
            }
            $content = (string) @file_get_contents($file->getPathname());
            if ($content === false || $content === '') {
                continue;
            }
            $filesProcessed++;

            if (preg_match_all('/<form\b[^>]*>/i', $content, $matches)) {
                $formsDetected += count($matches[0]);
            }

            if ($stripForeign) {
                [$content, $removedHere] = self::stripForeignScripts($content);
                foreach ($removedHere as $label) {
                    $log(($mode === 'raw' ? 'Raw' : 'Cross') . ": stripped {$label} from {$file->getFilename()}");
                }
                $strippedCount += count($removedHere);
            }

            if ($injectAdapter && strpos($content, 'orbitra_adapter.js') === false) {
                if (stripos($content, '</head>') !== false) {
                    $content = str_ireplace('</head>', "    <script src=\"orbitra_adapter.js\"></script>\n</head>", $content);
                } elseif (stripos($content, '</body>') !== false) {
                    $content = str_ireplace('</body>', "    <script src=\"orbitra_adapter.js\"></script>\n</body>", $content);
                }
            }

            if ($rewriteForms) {
                $content = preg_replace('/<form([^>]*?)action=["\'][^"\']*?["\']([^>]*?)>/i', '<form$1action="order.php"$2>', $content);
                $content = preg_replace('/<form(?![^>]*\baction\b)([^>]*?)>/i', '<form action="order.php"$1>', $content);
            }

            if ($injectMacro) {
                $content = preg_replace('/<a([^>]*?)href=["\'](?:#order|order\.html|#form|#popup|https?:\/\/[^"\']+)["\']([^>]*?)>/i', '<a$1href="{offer}"$2>', $content);
            }

            @file_put_contents($file->getPathname(), $content);
        }
        $log("Processed {$filesProcessed} page file(s), {$formsDetected} form(s) found");

        if ($adapterInline !== '') {
            @file_put_contents($tempDir . '/orbitra_adapter.js', $adapterInline);
            $log('Injected orbitra_adapter.js (ClickID bridge' . (!empty($opts['add_phone_mask']) ? " + {$geo} phone mask" : '') . ')');
        }
        if ($injectMacro) {
            $log('Replaced CTA links with {offer} macro');
        }
        if ($stripForeign && $strippedCount === 0) {
            $log('No foreign counters or hostile scripts matched the strip list');
        }

        // --- Generate handlers -----------------------------------------
        if ($generateOrder) {
            $orderSrc = self::orderPhp([
                'network' => $network,
                'api_key' => (string) ($opts['api_key'] ?? ''),
                'offer_id' => (string) ($opts['offer_id'] ?? ''),
                'geo' => $geo,
                'payout' => (float) ($opts['payout'] ?? 0),
                'currency' => (string) ($opts['currency'] ?? 'USD'),
                'crm_enabled' => $crmEnabled,
                'base_url' => (string) ($opts['base_url'] ?? ''),
                'landing_name' => (string) ($opts['name'] ?? 'Landing'),
            ]);
            $scan = PhpLanding::scan($orderSrc);
            if ($scan) {
                self::rrmdir($tempDir);
                return ['ok' => false, 'message' => 'Generated order.php failed the PHP landing scan: ' . implode(', ', $scan), 'logs' => $logs];
            }
            @file_put_contents($tempDir . '/order.php', $orderSrc);
            $netLabel = self::networks()[$network]['label'] ?? $network;
            $log("Generated order.php bridge for {$netLabel}" . ($crmEnabled ? ' + CRM vault sync' : ''));
        } elseif ($mode === 'raw') {
            $log('Raw mode: no order.php generated (clone patch only)');
        }

        if ($generateThankYou) {
            $thankSrc = self::thankYouPhp($geo, (float) ($opts['payout'] ?? 0), (string) ($opts['currency'] ?? 'USD'), $crmEnabled);
            $scanTy = PhpLanding::scan($thankSrc);
            if ($scanTy) {
                self::rrmdir($tempDir);
                return ['ok' => false, 'message' => 'Generated thank_you.php failed the PHP landing scan: ' . implode(', ', $scanTy), 'logs' => $logs];
            }
            @file_put_contents($tempDir . '/thank_you.php', $thankSrc);
            $log("Generated localized thank_you.php for [{$geo}]");
        }

        // --- Repack + persist -------------------------------------------
        $downloadToken = md5(uniqid('lf_', true));
        $downloadsDir = __DIR__ . '/../data/leadforge_downloads';
        if (!is_dir($downloadsDir)) {
            @mkdir($downloadsDir, 0775, true);
        }
        $reZip = new ZipArchive();
        if ($reZip->open($downloadsDir . '/' . $downloadToken . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $ri = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($ri as $file) {
                $localPath = str_replace($tempDir . '/', '', $file->getPathname());
                $file->isDir() ? $reZip->addEmptyDir($localPath) : $reZip->addFile($file->getPathname(), $localPath);
            }
            $reZip->close();
        }

        $landingId = null;
        $slug = '';
        $offerId = null;
        if (!empty($opts['auto_save_tracker'])) {
            $landingName = trim((string) ($opts['name'] ?? '')) ?: ('LeadForge ' . date('Ymd His'));
            $derivedSlug = orbitraSlugify($landingName);
            $slugCheck = orbitraValidateLandingSlug($pdo, $derivedSlug, null);
            if (!$slugCheck['ok']) {
                $base = rtrim(substr($derivedSlug, 0, 60), '-_');
                for ($n = 2; $n <= 50; $n++) {
                    $cand = orbitraValidateLandingSlug($pdo, $base . '-' . $n, null);
                    if ($cand['ok']) {
                        $slugCheck = $cand;
                        break;
                    }
                }
                if (!$slugCheck['ok']) {
                    $slugCheck = ['ok' => true, 'value' => '', 'error' => ''];
                }
            }
            $slug = $slugCheck['value'];

            $stmt = $pdo->prepare("INSERT INTO landings (name, group_id, type, url, action_payload, action_type, state, slug, redirect_type) VALUES (?, ?, 'local', '', NULL, '', 'active', ?, 'redirect')");
            $stmt->execute([$landingName, !empty($opts['group_id']) ? (int) $opts['group_id'] : null, $slug]);
            $landingId = (int) $pdo->lastInsertId();

            $targetLandingDir = orbitraLandingDir($pdo, $landingId);
            if (!is_dir($targetLandingDir)) {
                @mkdir($targetLandingDir, 0775, true);
            }
            $ci = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($ci as $item) {
                $destPath = $targetLandingDir . '/' . $ci->getSubPathName();
                $item->isDir() ? (!is_dir($destPath) && @mkdir($destPath, 0775, true)) : @copy($item->getPathname(), $destPath);
            }
            $log("Saved landing '{$landingName}' → /lander/{$slug}/ (ID #{$landingId})");

            // A matching local offer. offers has no payout_currency column —
            // the conversion carries the currency, not the offer.
            if (!empty($opts['auto_create_offer'])) {
                $stmtOff = $pdo->prepare("INSERT INTO offers (name, group_id, affiliate_network_id, payout_value, is_local, state) VALUES (?, ?, NULL, ?, 0, 'active')");
                $stmtOff->execute([$landingName . ' [Offer]', !empty($opts['group_id']) ? (int) $opts['group_id'] : null, (float) ($opts['payout'] ?? 0)]);
                $offerId = (int) $pdo->lastInsertId();
                $log("Created matching offer #{$offerId}");
            }
        }

        // --- Auto QA ------------------------------------------------------
        $qa = ['performed' => false];
        if (!empty($opts['auto_qa']) && $generateOrder && $landingId) {
            $qa = self::runQa($pdo, $landingId, $slug, [
                'geo' => $geo,
                'crm_enabled' => $crmEnabled,
                'base_url' => (string) ($opts['base_url'] ?? ''),
                'forms_count' => $formsDetected,
                'network' => $network,
            ]);
            foreach (($qa['log'] ?? []) as $line) {
                $log($line);
            }
            $log($qa['passed']
                ? "[QA PASS] confidence {$qa['confidence']}% (" . implode(', ', array_keys(array_filter(array_map(fn($c) => $c['passed'], $qa['checks'])))) . ')'
                : "[QA FAIL: " . ($qa['fail_reason'] ?? 'checks failed') . "] confidence {$qa['confidence']}%");
        } elseif (!empty($opts['auto_qa'])) {
            $log('Auto QA skipped: needs order.php and auto-save to tracker');
        }

        self::rrmdir($tempDir);

        return [
            'ok' => true,
            'message' => 'built',
            'logs' => $logs,
            'qa' => $qa,
            'result' => [
                'landing_id' => $landingId,
                'landing_name' => ($opts['name'] ?? ''),
                'slug' => $slug,
                'offer_id' => $offerId,
                'download_token' => $downloadToken,
                'download_url' => '/api.php?action=leadforge_download&token=' . $downloadToken,
                'forms_detected' => $formsDetected,
                'files_processed' => $filesProcessed,
                'geo' => $geo,
                'network' => $network,
                'mode' => $mode,
            ],
        ];
    }

    /**
     * Live Auto QA: POST a QA lead through the real /order.php route (the
     * in-process bridge index.php exposes for orbitra_lp cookies), verify the
     * CRM vault row / conversion and the thank-you redirect, and score it.
     *
     * Confidence: 25 points per passed check — HTML/form structure, order.php
     * bridge response, dual logging (vault or pixel), thank-you redirect.
     */
    public static function runQa(PDO $pdo, int $landingId, string $slug, array $opts): array
    {
        require_once __DIR__ . '/PhpLanding.php';
        $qa = [
            'performed' => true,
            'job_id' => 'job_lf_' . bin2hex(random_bytes(4)),
            'confidence' => 0,
            'passed' => false,
            'fail_reason' => '',
            'hosted_preview_url' => $slug !== '' ? '/lander/' . $slug . '/' : null,
            'checks' => [
                'form_elements' => ['passed' => false, 'details' => ''],
                'network_bridge' => ['passed' => false, 'details' => ''],
                'crm_dual_log' => ['passed' => false, 'details' => ''],
                'thank_you_redirect' => ['passed' => false, 'details' => ''],
            ],
            'warnings' => [],
            'log' => [],
        ];
        $say = function (string $m) use (&$qa) {
            $qa['log'][] = $m;
        };

        if (!PhpLanding::enabled($pdo)) {
            $qa['fail_reason'] = 'php_landings_disabled';
            $say('QA: PHP landings are disabled in Settings');
            return $qa;
        }

        $formsCount = (int) ($opts['forms_count'] ?? 0);
        $qa['checks']['form_elements']['passed'] = $formsCount > 0;
        $qa['checks']['form_elements']['details'] = $formsCount > 0
            ? "Found {$formsCount} form(s) wired to order.php"
            : 'No forms detected in the built pages';
        if ($formsCount <= 0) {
            $qa['fail_reason'] = 'no_forms';
            $qa['confidence'] = 0;
            $say('QA FAIL: no forms to submit');
            return $qa;
        }

        // A real click row so postback-based verification can attach to it.
        // Removed afterwards: QA traffic must never show up in reports.
        $geo = strtoupper((string) ($opts['geo'] ?? 'IT'));
        $mask = self::geoMasks()[$geo] ?? ['code' => '+1', 'pattern' => '', 'min' => 9, 'max' => 15];
        $dial = ltrim($mask['code'], '+');
        $qaPhone = '+' . $dial . '3330001122';   // IT → +393330001122
        $qaClick = 'qa_test_' . time() . '_' . bin2hex(random_bytes(3));

        $campaignId = 0;
        try {
            $campaignId = (int) $pdo->query("SELECT id FROM campaigns ORDER BY id LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {
        }
        if ($campaignId <= 0) {
            $qa['fail_reason'] = 'no_campaign_for_qa';
            $qa['warnings'][] = 'Create at least one campaign to stage QA clicks';
            $say('QA SKIP: no campaign exists to stage the test click');
            return $qa;
        }
        try {
            $pdo->prepare("INSERT INTO clicks (id, campaign_id, ip, user_agent, country, country_code, device_type, os, browser, language, accept_language_raw, parameters_json)
                           VALUES (?, ?, '127.0.0.1', 'Orbitra Auto QA', 'Local', 'QA', 'desktop', 'QA', 'QA', 'en', 'en', '{}')")
                ->execute([$qaClick, $campaignId]);
        } catch (\Throwable $e) {
            $qa['fail_reason'] = 'qa_click_failed';
            $say('QA FAIL: could not stage test click — ' . $e->getMessage());
            return $qa;
        }

        // The loopback request: same host the panel runs on, cookie steering
        // the /order.php bridge to the landing we just saved.
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $base = (string) ($opts['base_url'] ?? '');
        if ($base !== '' && strpos($base, '://') !== false) {
            $qaUrl = rtrim($base, '/') . '/order.php';
        } elseif ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $qaUrl = $scheme . '://' . $host . '/order.php';
        } else {
            $qaUrl = 'http://127.0.0.1/order.php';
        }
        $say("QA: posting test lead to {$qaUrl} (subid {$qaClick})");

        $post = http_build_query([
            'name' => 'QA-Test-Lead',
            'phone' => $qaPhone,
            'subid' => $qaClick,
            'orbitra_qa' => '1',
            'sub1' => 'qa',
            'pixel' => 'qa',
        ]);
        $httpCode = 0;
        $responseHeader = '';
        $responseBody = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($qaUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_COOKIE => 'orbitra_lp=' . $landingId,
                CURLOPT_SSL_VERIFYPEER => false, // loopback self-signed installs
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($curlErr !== '') {
                $say("QA: cURL error: {$curlErr}");
            }
            if (is_string($raw)) {
                $responseHeader = substr($raw, 0, $hSize);
                $responseBody = substr($raw, $hSize);
            }
        } else {
            // Live QA drives the real HTTP bridge; without curl it cannot see
            // the redirect, so it reports a warning instead of a fake pass.
            $qa['warnings'][] = 'PHP cURL is required for Live QA';
            $say('QA SKIP: cURL extension is not available');
            try {
                $pdo->prepare("DELETE FROM clicks WHERE id = ?")->execute([$qaClick]);
            } catch (\Throwable $e) {
            }
            return $qa;
        }

        $bridgeOk = $httpCode >= 200 && $httpCode < 500 && strpos($responseBody, 'Fatal error') === false;
        $qa['checks']['network_bridge']['passed'] = $bridgeOk;
        $qa['checks']['network_bridge']['details'] = "order.php answered HTTP {$httpCode}";
        $say("QA: order.php → HTTP {$httpCode}");
        if (!$bridgeOk || $httpCode !== 302) {
            $say('QA: response head: ' . str_replace("\r\n", ' | ', substr($responseHeader . '>>BODY<<' . $responseBody, 0, 300)));
        }
        if (!$bridgeOk) {
            $qa['fail_reason'] = 'order_php_http_' . $httpCode;
        }

        // Dual logging: CRM vault row when CRM sync is on, pixel conversion
        // when it is off. QA rows never pollute analytics — the click and any
        // conversion it produced are deleted below.
        $dualOk = false;
        if (!empty($opts['crm_enabled'])) {
            try {
                $row = $pdo->prepare("SELECT id, clean_phone, is_qa_test FROM crm_leads WHERE click_id = ? AND is_qa_test = 1 ORDER BY id DESC LIMIT 1");
                $row->execute([$qaClick]);
                $crmRow = $row->fetch(PDO::FETCH_ASSOC);
                if ($crmRow) {
                    $dualOk = true;
                    $qa['checks']['crm_dual_log']['details'] = 'Lead saved to vault: raw ' . $qaPhone . ' → ' . $crmRow['clean_phone'];
                } else {
                    $qa['checks']['crm_dual_log']['details'] = 'No QA row in crm_leads — CRM sync did not reach the vault';
                }
            } catch (\Throwable $e) {
                $qa['checks']['crm_dual_log']['details'] = 'Vault check failed: ' . $e->getMessage();
            }
        } else {
            try {
                $conv = $pdo->prepare("SELECT COUNT(*) FROM conversions WHERE click_id = ?");
                $conv->execute([$qaClick]);
                $dualOk = ((int) $conv->fetchColumn()) > 0;
                $qa['checks']['crm_dual_log']['details'] = $dualOk
                    ? 'Conversion pixel registered the lead on the test click'
                    : 'No conversion on the test click — pixel path broken';
            } catch (\Throwable $e) {
                $qa['checks']['crm_dual_log']['details'] = 'Conversion check failed: ' . $e->getMessage();
            }
        }
        $qa['checks']['crm_dual_log']['passed'] = $dualOk;
        if (!$dualOk && $qa['fail_reason'] === '') {
            $qa['fail_reason'] = 'dual_log_missing';
        }
        $say('QA: dual log ' . ($dualOk ? 'OK' : 'MISSING'));

        $redirectOk = (bool) preg_match('/location:\s*[^\r\n]*thank_you\.php/im', $responseHeader);
        $qa['checks']['thank_you_redirect']['passed'] = $redirectOk;
        $qa['checks']['thank_you_redirect']['details'] = $redirectOk
            ? 'HTTP redirect to thank_you.php with subid'
            : 'No redirect to thank_you.php in the response';
        if (!$redirectOk && $qa['fail_reason'] === '') {
            $qa['fail_reason'] = 'no_thank_you_redirect';
        }
        $say('QA: thank_you redirect ' . ($redirectOk ? 'OK' : 'MISSING'));

        $passedCount = count(array_filter(array_map(fn($c) => $c['passed'], $qa['checks'])));
        $qa['confidence'] = 25 * $passedCount;
        $qa['passed'] = $passedCount === 4;

        // Cleanup: analytics stays pristine; the QA evidence lives in the vault.
        try {
            $pdo->prepare("DELETE FROM conversions WHERE click_id = ?")->execute([$qaClick]);
            $pdo->prepare("DELETE FROM clicks WHERE id = ?")->execute([$qaClick]);
        } catch (\Throwable $e) {
        }
        return $qa;
    }

    // ==================================================================
    // Generators
    // ==================================================================

    /** The ClickID bridge adapter. */
    public static function adapterJs(string $geo, bool $withMask): string
    {
        $mask = self::geoMasks()[$geo] ?? ['code' => '', 'pattern' => '', 'min' => 7, 'max' => 15];
        $js = <<<'JS'
/**
 * Orbitra LeadForge 2.0 — ClickID Bridge & JS Adapter
 * Captures tracking params from the URL (with sessionStorage/cookie fallbacks),
 * re-injects them as hidden fields into every form, and enforces the GEO
 * phone mask on tel inputs.
 */
(function() {
    var GEO = '@@GEO@@';
    var PHONE_CODE = '@@PHONE_CODE@@';
    var PHONE_PATTERN = '@@PHONE_PATTERN@@';
    var PHONE_MIN = @@PHONE_MIN@@;
    var PHONE_MAX = @@PHONE_MAX@@;
    var MASK_ENABLED = @@MASK_ENABLED@@;

    function getQueryParam(name) {
        var match = RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
    }
    function getCookie(name) {
        var m = RegExp('(^|;\\s*)' + name + '=([^;]*)').exec(document.cookie);
        return m ? decodeURIComponent(m[2]) : '';
    }
    function store(key, value) {
        try { sessionStorage.setItem('orbitra_' + key, value); } catch (e) {}
        try {
            document.cookie = 'orbitra_lf_' + key + '=' + encodeURIComponent(value) +
                '; path=/; max-age=2592000; SameSite=Lax';
        } catch (e) {}
    }
    function recall(key) {
        var v = '';
        try { v = sessionStorage.getItem('orbitra_' + key) || ''; } catch (e) {}
        if (!v) v = getCookie('orbitra_lf_' + key);
        return v;
    }

    var PARAMS = ['subid', 'sub_id', 'click_id', 'clickid', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5',
                  'sub6', 'sub7', 'sub8', 'sub9', 'sub10', 'pixel',
                  'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term', 'utm_placement',
                  'fbclid', 'fbp', 'fbc', 'ttclid', 'gclid', 'adset_id', 'ad_id'];
    var captured = {};
    PARAMS.forEach(function(p) {
        var v = getQueryParam(p);
        if (v) {
            captured[p] = v;
            store(p, v);
        } else {
            var r = recall(p);
            if (r) captured[p] = r;
        }
    });

    // The tracker's own click cookie is the last-resort subid: it is set when
    // the campaign served this page, even with no URL parameters at all.
    if (!captured.subid && !captured.sub_id && !captured.click_id && !captured.clickid) {
        var ck = getCookie('orbitra_click') || getCookie('subid');
        if (ck) captured.subid = ck;
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Hidden-field injection into every form.
        var forms = document.querySelectorAll('form');
        Array.prototype.forEach.call(forms, function(form) {
            for (var key in captured) {
                if (!captured[key] || form.querySelector('input[name="' + key + '"]')) continue;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = captured[key];
                form.appendChild(input);
            }
        });

        if (!MASK_ENABLED) return;

        // Phone mask + validation for the target GEO.
        var phoneInputs = document.querySelectorAll(
            'input[type="tel"], input[name*="phone"], input[name*="tel"], input[name="phone_number"]'
        );
        var digitsOf = function(s) { return (s || '').replace(/\D+/g, ''); };

        Array.prototype.forEach.call(phoneInputs, function(input) {
            input.setAttribute('autocomplete', 'tel');
            if (PHONE_PATTERN) input.setAttribute('placeholder', PHONE_PATTERN);
            input.addEventListener('input', function() {
                // Allow the mask's punctuation only; letters are dropped.
                this.value = this.value.replace(/[^0-9+()\- ]/g, '');
            });
            input.addEventListener('blur', function() {
                var d = digitsOf(this.value).length;
                var bad = d !== 0 && (d < PHONE_MIN || d > PHONE_MAX);
                this.classList.toggle('orbitra-phone-invalid', bad);
            });
            var form = input.closest ? input.closest('form') : input.form;
            if (form && !form.getAttribute('data-orbitra-mask')) {
                form.setAttribute('data-orbitra-mask', '1');
                form.addEventListener('submit', function(e) {
                    var bad = Array.prototype.some.call(
                        this.querySelectorAll('input[type="tel"], input[name*="phone"], input[name*="tel"], input[name="phone_number"]'),
                        function(el) {
                            var d = digitsOf(el.value).length;
                            if (d === 0 || d < PHONE_MIN || d > PHONE_MAX) {
                                el.classList.add('orbitra-phone-invalid');
                                return true;
                            }
                            return false;
                        }
                    );
                    if (bad) {
                        e.preventDefault();
                        if (!document.getElementById('orbitra-phone-err')) {
                            var div = document.createElement('div');
                            div.id = 'orbitra-phone-err';
                            div.style.cssText = 'color:#e11d48;font-size:12px;margin-top:6px';
                            div.textContent = 'Please enter a valid phone number for your country (' + PHONE_PATTERN + ')';
                            (this.parentNode || document.body).appendChild(div);
                        }
                    }
                });
            }
        });

        var style = document.createElement('style');
        style.textContent = '.orbitra-phone-invalid{border-color:#e11d48 !important;box-shadow:0 0 0 1px #e11d48}';
        document.head.appendChild(style);
    });
})();
JS;
        $replacements = [
            '@@GEO@@' => $geo,
            '@@PHONE_CODE@@' => $mask['code'],
            '@@PHONE_PATTERN@@' => $mask['pattern'],
            '@@PHONE_MIN@@' => (int) $mask['min'],
            '@@PHONE_MAX@@' => (int) $mask['max'],
            '@@MASK_ENABLED@@' => $withMask ? 'true' : 'false',
        ];
        return strtr($js, $replacements);
    }

    /**
     * The generated order.php. @@KEY@@ markers are substituted with
     * var_export()-safe PHP literals — an API key containing quotes or
     * backslashes cannot break out of the string.
     */
    public static function orderPhp(array $o): string
    {
        $geo = strtoupper((string) ($o['geo'] ?? 'IT'));
        $mask = self::geoMasks()[$geo] ?? ['code' => '', 'min' => 7, 'max' => 15];
        $dial = ltrim($mask['code'], '+');
        $tpl = <<<'PHP'
<?php
/**
 * Orbitra LeadForge 2.0 — Universal CPA Order Bridge + CRM Vault Sync
 * Network: @@NETWORK@@ | Offer: @@OFFER_ID@@ | Target GEO: @@GEO@@
 *
 * Dual logging: the CPA network first, then the full raw lead snapshot into
 * the Orbitra CRM vault (in-process here, or /crm-ingest when this bundle is
 * hosted elsewhere). QA-flagged submissions never call the real network.
 */
session_start();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

$LF = array(
    'network'      => @@NETWORK@@,
    'api_key'      => @@API_KEY@@,
    'offer_id'     => @@OFFER_ID@@,
    'geo'          => @@GEO@@,
    'payout'       => @@PAYOUT@@,
    'currency'     => @@CURRENCY@@,
    'crm_enabled'  => @@CRM_ENABLED@@,
    'tracker_base' => @@BASE_URL@@,
    'landing_name' => @@LANDING_NAME@@,
    'dial_code'    => @@DIAL_CODE@@,
);

$name = trim($_POST['name'] ?? $_POST['fio'] ?? $_POST['client'] ?? 'Customer');
$rawPhone = trim($_POST['phone'] ?? $_POST['tel'] ?? $_POST['phone_number'] ?? '');
$product = trim($_POST['product'] ?? '') ?: $LF['landing_name'];
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : 0.0;
// The click id reaches the form as a hidden field (JS adapter) or, failing
// that, lives in the cookie the tracker set when it served this page.
$subid = trim($_POST['subid'] ?? $_POST['sub_id'] ?? $_POST['click_id'] ?? $_POST['clickid'] ?? $_COOKIE['orbitra_click'] ?? '');
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($rawPhone === '') {
    die('Error: Phone number is required.');
}

$isQa = (($_POST['orbitra_qa'] ?? '') === '1') || (strpos($subid, 'qa_test_') === 0);

// E.164 normalization — the raw input is preserved untouched alongside it.
$cleanPhone = '';
$lfDigits = preg_replace('/\D+/', '', $rawPhone);
if ($lfDigits !== '' && strlen($lfDigits) >= 4) {
    if (strncmp($rawPhone, '+', 1) === 0) {
        $cleanPhone = '+' . substr($lfDigits, 0, 15);
    } elseif (strncmp($lfDigits, '00', 2) === 0) {
        $cleanPhone = '+' . substr($lfDigits, 2, 15);
    } elseif ($LF['dial_code'] !== '' && strncmp($lfDigits, $LF['dial_code'], strlen($LF['dial_code'])) === 0 && strlen($lfDigits) > strlen($LF['dial_code'])) {
        $cleanPhone = '+' . substr($lfDigits, 0, 15);
    } else {
        $cleanPhone = '+' . ($LF['dial_code'] !== '' ? $LF['dial_code'] . $lfDigits : $lfDigits);
    }
}

// Sub/attribution params carried by the adapter's hidden fields.
$lfSubKeys = array('sub1','sub2','sub3','sub4','sub5','sub6','sub7','sub8','sub9','sub10',
                   'pixel','utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_placement',
                   'fbclid','fbp','fbc','ttclid','gclid','adset_id','ad_id');
$subParams = array();
foreach ($lfSubKeys as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') {
        $subParams[$k] = substr((string) $_POST[$k], 0, 255);
    }
}

/**
 * Send the lead to the CPA network. Returns [httpCode, responseBody].
 * The QA guard short-circuits the real call — a test lead must never reach
 * the advertiser.
 */
function lf_send($url, $payload, $headers, $asJson)
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(array('http' => array(
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $asJson ? json_encode($payload) : http_build_query($payload),
            'timeout' => 15,
            'ignore_errors' => true,
        )));
        $body = @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header) ? (int) (explode(' ', $http_response_header[0])[1] ?? 0) : 0;
        return array($code, is_string($body) ? $body : '');
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $asJson ? json_encode($payload) : http_build_query($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, is_string($body) ? $body : '');
}

$netRequest = array('endpoint' => '', 'method' => 'POST', 'timestamp' => date('Y-m-d H:i:s'));
$netResponse = array('http_code' => 0, 'body' => '');
$networkLeadId = '';

if ($isQa) {
    $netResponse = array('http_code' => 200, 'body' => '{"qa":true,"simulated":true}', 'network_lead_id' => 'qa_simulated');
    $networkLeadId = 'qa_simulated';
} else {
    try {
        if ($LF['network'] === 'drcash') {
            $netRequest['endpoint'] = 'https://affiliate.dr.cash/api/order/create';
            $payload = array(
                'stream_code' => $LF['offer_id'],
                'client' => array('name' => $name, 'phone' => $cleanPhone ?: $rawPhone, 'address' => $_POST['address'] ?? ''),
                'sub1' => $subid,
                'sub2' => $subParams['sub1'] ?? '',
                'sub3' => $subParams['sub2'] ?? '',
                'sub4' => $subParams['sub3'] ?? '',
                'sub5' => $subParams['sub4'] ?? '',
            );
            $netRequest['payload'] = $payload;
            list($code, $body) = lf_send($netRequest['endpoint'], $payload,
                array('Content-Type: application/json', 'Authorization: Bearer ' . $LF['api_key']), true);
            $json = json_decode($body, true);
            if (!empty($json['uuid'])) $networkLeadId = (string) $json['uuid'];
        } elseif ($LF['network'] === 'lemonad') {
            $netRequest['endpoint'] = 'https://lemonad.com/api/v2/lead/create';
            $payload = array(
                'api_token' => $LF['api_key'], 'offer_id' => $LF['offer_id'],
                'name' => $name, 'phone' => $cleanPhone ?: $rawPhone,
                'ip' => $ip, 'country' => $LF['geo'], 'click_id' => $subid,
            );
            $netRequest['payload'] = $payload;
            list($code, $body) = lf_send($netRequest['endpoint'], $payload, array('Content-Type: application/x-www-form-urlencoded'), false);
            $json = json_decode($body, true);
            if (!empty($json['lead_id'])) $networkLeadId = (string) $json['lead_id'];
        } elseif ($LF['network'] === 'webvork') {
            $netRequest['endpoint'] = 'https://api.webvork.com/v1/lead';
            $payload = array(
                'token' => $LF['api_key'], 'offer_id' => $LF['offer_id'],
                'name' => $name, 'phone' => $cleanPhone ?: $rawPhone,
                'country' => $LF['geo'], 'ip' => $ip, 'utm_campaign' => $subid,
            );
            $netRequest['payload'] = $payload;
            list($code, $body) = lf_send($netRequest['endpoint'], $payload, array('Content-Type: application/x-www-form-urlencoded'), false);
            $json = json_decode($body, true);
            if (!empty($json['lead_id'])) $networkLeadId = (string) $json['lead_id'];
        } elseif ($LF['network'] === 'leadbit') {
            $netRequest['endpoint'] = 'http://leadbit.com/api/new-order';
            $payload = array(
                'flow_hash' => $LF['offer_id'], 'api_key' => $LF['api_key'],
                'country' => $LF['geo'], 'name' => $name,
                'phone' => $cleanPhone ?: $rawPhone, 'sub1' => $subid, 'ip' => $ip,
            );
            $netRequest['payload'] = $payload;
            list($code, $body) = lf_send($netRequest['endpoint'], $payload, array('Content-Type: application/x-www-form-urlencoded'), false);
        } elseif ($LF['network'] === 'everad') {
            $netRequest['endpoint'] = 'https://api.everad.com/campaigns/' . $LF['offer_id'] . '/order';
            $payload = array(
                'campaign_id' => $LF['offer_id'], 'name' => $name,
                'phone' => $cleanPhone ?: $rawPhone, 'ip' => $ip, 'sid1' => $subid,
            );
            $netRequest['payload'] = $payload;
            list($code, $body) = lf_send($netRequest['endpoint'], $payload,
                array('Content-Type: application/json', 'X-Api-Key: ' . $LF['api_key']), true);
        } else {
            // Custom or generic webhook: the offer id field carries the URL.
            if (!empty($LF['offer_id']) && filter_var($LF['offer_id'], FILTER_VALIDATE_URL)) {
                $netRequest['endpoint'] = $LF['offer_id'];
                $payload = $_POST;
                $payload['subid'] = $subid;
                $payload['phone'] = $cleanPhone ?: $rawPhone;
                $netRequest['payload'] = 'form passthrough';
                list($code, $body) = lf_send($netRequest['endpoint'], $payload, array('Content-Type: application/x-www-form-urlencoded'), false);
            } else {
                $code = 0;
                $body = '';
            }
        }
        $netResponse['http_code'] = isset($code) ? (int) $code : 0;
        $netResponse['body'] = substr((string) $body, 0, 4096);
        if ($networkLeadId === '') {
            $json = json_decode((string) $body, true);
            foreach (array('uuid', 'lead_id', 'id', 'order_id') as $jk) {
                if (!empty($json[$jk])) {
                    $networkLeadId = (string) $json[$jk];
                    break;
                }
            }
            if ($networkLeadId === '' && !empty($json['data']['id'])) {
                $networkLeadId = (string) $json['data']['id'];
            }
        }
        $netResponse['network_lead_id'] = $networkLeadId;
    } catch (Throwable $e) {
        $netResponse['http_code'] = 0;
        $netResponse['body'] = 'exception: ' . $e->getMessage();
    }
}

// === CRM Vault Sync =====================================================
// Full-fidelity snapshot: what the visitor typed, what we delivered, what the
// network answered. Never blocks the redirect on failure.
$crmOk = false;
if ($LF['crm_enabled']) {
    $crmPayload = array(
        'click_id'      => $subid,
        'campaign_id'   => 0,
        'lander_id'     => 0,
        'offer_id'      => $LF['offer_id'],
        'network'       => $LF['network'],
        'network_lead_id' => $networkLeadId,
        'product'       => $product,
        'price'         => $price,
        'customer_name' => $name,
        'raw_phone'     => $rawPhone,
        'clean_phone'   => $cleanPhone,
        'geo'           => $LF['geo'],
        'ip'            => $ip,
        'user_agent'    => $userAgent,
        'status'        => 'lead',
        'payout'        => $LF['payout'],
        'currency'      => $LF['currency'],
        'is_qa_test'    => $isQa ? 1 : 0,
        'status_source' => 'form_submit',
        'sub_data'      => $subParams,
        'network_request'  => $netRequest,
        'network_response' => $netResponse,
    );
    if (isset($subParams['utm_source']))     $crmPayload['utm_source']    = $subParams['utm_source'];
    if (isset($subParams['utm_campaign']))   $crmPayload['utm_campaign']  = $subParams['utm_campaign'];
    if (isset($subParams['utm_placement']))  $crmPayload['utm_placement'] = $subParams['utm_placement'];
    if (isset($subParams['adset_id']))       $crmPayload['adset_id']      = $subParams['adset_id'];
    if (isset($subParams['ad_id']))          $crmPayload['ad_id']         = $subParams['ad_id'];

    if (function_exists('orbitraCrmRecordLead') && isset($pdo) && ($pdo instanceof PDO)) {
        // In-process: this file runs inside the tracker's index.php, which
        // keeps the connection in $pdo at the top-level require scope.
        $crmRes = orbitraCrmRecordLead($pdo, $crmPayload, false);
        $crmOk = !empty($crmRes['ok']);
    } elseif ($LF['tracker_base'] !== '') {
        $ch = curl_init(rtrim($LF['tracker_base'], '/') . '/crm-ingest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($crmPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $crmRaw = curl_exec($ch);
        curl_close($ch);
        $crmJson = json_decode((string) $crmRaw, true);
        $crmOk = !empty($crmJson['status']) && $crmJson['status'] === 'success';
    }
}

// === Failsafe local backup =============================================
// .log on purpose: the asset server whitelists .json/.txt, and a backup full
// of names and phone numbers must not be downloadable from the landing URL.
$leadLog = array(
    'time' => date('Y-m-d H:i:s'),
    'network' => $LF['network'],
    'network_lead_id' => $networkLeadId,
    'http_code' => $netResponse['http_code'],
    'name' => $name,
    'phone' => $rawPhone,
    'phone_e164' => $cleanPhone,
    'subid' => $subid,
    'ip' => $ip,
    'geo' => $LF['geo'],
    'crm_synced' => $crmOk,
    'crm_error' => isset($crmRes['message']) ? $crmRes['message'] : '',
    'qa' => $isQa,
);
@file_put_contents(__DIR__ . '/orbitra_leads_backup.log', json_encode($leadLog) . PHP_EOL, FILE_APPEND | LOCK_EX);

// === Tracker conversion =================================================
// When CRM sync is on it already upserted the conversion; the pixel here is
// the fallback for when it failed (and the only path when sync is off).
if ($subid !== '' && (!$LF['crm_enabled'] || !$crmOk) && !$isQa) {
    $trackerHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $postbackUrl = $proto . '://' . $trackerHost . '/pixel.gif?action=conversion&subid=' . urlencode($subid)
        . '&status=lead&payout=' . rawurlencode((string) $LF['payout']) . '&currency=' . rawurlencode($LF['currency']);
    @file_get_contents($postbackUrl, false, stream_context_create(array('http' => array('timeout' => 2))));
}

// === Redirect ============================================================
$_SESSION['order_name'] = $name;
$_SESSION['order_phone'] = $rawPhone;
$_SESSION['order_id'] = $networkLeadId !== '' ? $networkLeadId : ('LF-' . date('YmdHis') . '-' . rand(1000, 9999));

header('Location: thank_you.php?name=' . urlencode($name) . '&phone=' . urlencode($rawPhone)
    . '&order_id=' . urlencode($_SESSION['order_id']) . '&subid=' . urlencode($subid)
    . ($isQa ? '&qa=1' : ''));
exit;
PHP;
        $replacements = [
            '@@NETWORK@@'      => var_export((string) ($o['network'] ?? 'custom'), true),
            '@@API_KEY@@'      => var_export((string) ($o['api_key'] ?? ''), true),
            '@@OFFER_ID@@'     => var_export((string) ($o['offer_id'] ?? ''), true),
            '@@GEO@@'          => var_export($geo, true),
            '@@PAYOUT@@'       => var_export((float) ($o['payout'] ?? 0), true),
            '@@CURRENCY@@'     => var_export(strtoupper((string) ($o['currency'] ?? 'USD')), true),
            '@@CRM_ENABLED@@'  => !empty($o['crm_enabled']) ? 'true' : 'false',
            '@@BASE_URL@@'     => var_export(rtrim((string) ($o['base_url'] ?? ''), '/'), true),
            '@@LANDING_NAME@@' => var_export((string) ($o['landing_name'] ?? 'Landing'), true),
            '@@DIAL_CODE@@'    => var_export($dial, true),
        ];
        return strtr($tpl, $replacements);
    }

    /** The localized thank-you page. Conversion pixel only when CRM sync is off. */
    public static function thankYouPhp(string $geo, float $payout, string $currency, bool $crmEnabled): string
    {
        $titles = [
            'IT' => ['title' => 'Grazie per il tuo ordine!', 'subtitle' => 'Il tuo ordine è stato registrato con successo.', 'call' => 'Il nostro operatore ti contatterà a breve per confermare la spedizione.', 'details' => 'Dettagli dell\'ordine:', 'name' => 'Nome:', 'phone' => 'Telefono:', 'order' => 'Numero ordine:'],
            'ES' => ['title' => '¡Gracias por su pedido!', 'subtitle' => 'Su pedido ha sido registrado con éxito.', 'call' => 'Nuestro especialista se comunicará con usted en breve para confirmar el envío.', 'details' => 'Detalles del pedido:', 'name' => 'Nombre:', 'phone' => 'Teléfono:', 'order' => 'Número de pedido:'],
            'DE' => ['title' => 'Vielen Dank für Ihre Bestellung!', 'subtitle' => 'Ihre Bestellung wurde erfolgreich erfasst.', 'call' => 'Unser Berater wird Sie in Kürze kontaktieren, um die Details zu bestätigen.', 'details' => 'Bestelldetails:', 'name' => 'Name:', 'phone' => 'Telefon:', 'order' => 'Bestellnummer:'],
            'FR' => ['title' => 'Merci pour votre commande !', 'subtitle' => 'Votre commande a été enregistrée avec succès.', 'call' => 'Notre conseiller vous contactera sous peu pour confirmer les détails.', 'details' => 'Détails de la commande :', 'name' => 'Nom :', 'phone' => 'Téléphone :', 'order' => 'Numéro de commande :'],
            'PL' => ['title' => 'Dziękujemy za zamówienie!', 'subtitle' => 'Twoje zamówienie zostało pomyślnie przyjęte.', 'call' => 'Nasz konsultant skontaktuje się z Tobą wkrótce w celu potwierdzenia adresu.', 'details' => 'Szczegóły zamówienia:', 'name' => 'Imię:', 'phone' => 'Telefon:', 'order' => 'Numer zamówienia:'],
            'RO' => ['title' => 'Vă mulțumim pentru comandă!', 'subtitle' => 'Comanda dumneavoastră a fost înregistrată cu succes.', 'call' => 'Operatorul nostru vă va contacta în scurt timp pentru confirmare.', 'details' => 'Detalii comandă:', 'name' => 'Nume:', 'phone' => 'Telefon:', 'order' => 'Număr comandă:'],
            'RU' => ['title' => 'Спасибо за ваш заказ!', 'subtitle' => 'Ваша заявка успешно принята в обработку.', 'call' => 'Оператор свяжется с вами в течение 10-15 минут для подтверждения адреса доставки.', 'details' => 'Данные заказа:', 'name' => 'Имя:', 'phone' => 'Телефон:', 'order' => 'Номер заявки:'],
            'EN' => ['title' => 'Thank you for your order!', 'subtitle' => 'Your order has been placed successfully.', 'call' => 'Our representative will call you shortly to verify delivery details.', 'details' => 'Order details:', 'name' => 'Name:', 'phone' => 'Phone:', 'order' => 'Order ID:'],
        ];
        $t = $titles[$geo] ?? $titles['EN'];
        $tpl = <<<'PHP'
<?php
session_start();
$name = htmlspecialchars($_GET['name'] ?? $_SESSION['order_name'] ?? 'Cliente');
$phone = htmlspecialchars($_GET['phone'] ?? $_SESSION['order_phone'] ?? '');
$orderId = htmlspecialchars($_GET['order_id'] ?? $_SESSION['order_id'] ?? ('#' . rand(100000, 999999)));
$subid = htmlspecialchars($_GET['subid'] ?? '');
$isQa = (($_GET['qa'] ?? '') === '1');
?>
<!DOCTYPE html>
<html lang="@@GEO@@">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@@TITLE@@</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #ffffff; border-radius: 20px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06), 0 8px 10px -6px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; max-width: 500px; width: 100%; padding: 36px 28px; text-align: center; }
        .icon-box { width: 72px; height: 72px; background: #ecfdf5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: #10b981; }
        h1 { font-size: 24px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
        p.sub { font-size: 15px; color: #64748b; margin-bottom: 24px; line-height: 1.5; }
        .info-box { background: #f1f5f9; border-radius: 14px; padding: 18px; text-align: left; margin-bottom: 24px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .info-row:last-child { margin-bottom: 0; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 600; color: #0f172a; }
        .notice { font-size: 13px; color: #059669; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px; margin-bottom: 24px; }
        .btn { display: inline-block; background: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 12px; font-weight: 600; font-size: 14px; transition: background 0.2s; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-box">
            <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
        </div>
        <h1>@@TITLE@@</h1>
        <p class="sub">@@SUBTITLE@@</p>

        <div class="info-box">
            <div class="info-row">
                <span class="info-label">@@ORDER@@</span>
                <span class="info-value"><?php echo $orderId; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">@@NAME@@</span>
                <span class="info-value"><?php echo $name; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">@@PHONE@@</span>
                <span class="info-value"><?php echo $phone; ?></span>
            </div>
        </div>

        <div class="notice">
            ⚡ @@CALL@@
        </div>

        <a href="/" class="btn">← @@BACK@@</a>
    </div>

    <?php if ($subid !== '' && !@$isQa && @@PIXEL@@): ?>
    <!-- Orbitra Conversion Pixel -->
    <img src="/pixel.gif?action=conversion&subid=<?php echo urlencode($subid); ?>&status=lead&payout=@@PAYOUT@@&currency=@@CURRENCY@@" width="1" height="1" style="display:none;" alt="">
    <?php endif; ?>
</body>
</html>
PHP;
        return strtr($tpl, [
            '@@GEO@@' => htmlspecialchars($geo, ENT_QUOTES),
            '@@TITLE@@' => htmlspecialchars($t['title'], ENT_QUOTES),
            '@@SUBTITLE@@' => htmlspecialchars($t['subtitle'], ENT_QUOTES),
            '@@CALL@@' => htmlspecialchars($t['call'], ENT_QUOTES),
            '@@ORDER@@' => htmlspecialchars($t['order'], ENT_QUOTES),
            '@@NAME@@' => htmlspecialchars($t['name'], ENT_QUOTES),
            '@@PHONE@@' => htmlspecialchars($t['phone'], ENT_QUOTES),
            '@@BACK@@' => 'Back to Site',
            '@@PAYOUT@@' => rawurlencode((string) $payout),
            '@@CURRENCY@@' => rawurlencode(strtoupper($currency)),
            '@@PIXEL@@' => $crmEnabled ? 'false' : 'true',
        ]);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Remove third-party counters and hostile snippets from an HTML/PHP page.
     * Only <script> blocks and tracking <img> pixels with unambiguous
     * signatures are touched — conservative by design, every removal logged.
     *
     * @return array [newContent, removedLabels]
     */
    public static function stripForeignScripts(string $html): array
    {
        $removed = [];
        $sigs = self::foreignScriptSignatures();

        $html = preg_replace_callback(
            '/<script\b[^>]*>.*?<\/script\s*>/is',
            function ($m) use ($sigs, &$removed) {
                $low = function_exists('mb_strtolower') ? mb_strtolower($m[0], 'UTF-8') : strtolower($m[0]);
                foreach ($sigs as $label => $needles) {
                    foreach ($needles as $needle) {
                        if (strpos($low, $needle) !== false) {
                            // Never strip our own adapter.
                            if (strpos($low, 'orbitra_adapter') !== false) {
                                return $m[0];
                            }
                            $removed[$label] = $label;
                            return '';
                        }
                    }
                }
                return $m[0];
            },
            $html
        );

        // Tracking pixels: <img src="https://www.facebook.com/tr?...">
        $html = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ($m) use (&$removed) {
                if (preg_match('#src=["\']https?://(www\.)?(facebook\.com/tr\?|analytics\.tiktok\.com|mc\.yandex\.ru|www\.google-analytics\.com|googleads\.g\.doubleclick\.net)#i', $m[0])) {
                    $removed['tracking_pixel_img'] = 'tracking_pixel_img';
                    return '';
                }
                return $m[0];
            },
            $html
        );

        return [$html, array_values($removed)];
    }

    /** Delete the old network's form handlers from the bundle root. */
    public static function removeLegacyHandlers(string $dir): array
    {
        $removed = [];
        foreach (self::LEGACY_HANDLERS as $name) {
            $p = $dir . '/' . $name;
            if (is_file($p)) {
                @unlink($p);
                $removed[] = $name;
            }
        }
        return $removed;
    }

    /** Recursively remove a directory tree. */
    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $e) {
            $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
        }
        @rmdir($dir);
    }
}
