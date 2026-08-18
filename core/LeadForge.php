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
    private static ?array $allGeoRulesCache = null;

    /** Networks the engine can detect and speak. Signatures are lowercase. */
    public static function networks(): array
    {
        return [
            'drcash'      => ['label' => 'Dr.Cash',      'sigs' => ['dr.cash', 'drcash']],
            'lemonad'     => ['label' => 'LemonAD',      'sigs' => ['lemonad']],
            'webvork'     => ['label' => 'Webvork',      'sigs' => ['webvork']],
            'leadbit'     => ['label' => 'Leadbit',      'sigs' => ['leadbit']],
            'everad'      => ['label' => 'Everad',       'sigs' => ['everad']],
            'kma'         => ['label' => 'KMA.biz',      'sigs' => ['kma.biz', 'kma']],
            'terraleads'  => ['label' => 'TerraLeads',   'sigs' => ['terraleads', 't-api.org']],
            'luckyonline' => ['label' => 'Lucky.online', 'sigs' => ['lucky.online', 'lucky']],
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

    /**
     * Load full ALL_GEO_RULES dataset (146 countries).
     * @return array<string, array>
     */
    public static function allGeoRules(): array
    {
        if (self::$allGeoRulesCache !== null) {
            return self::$allGeoRulesCache;
        }
        $file = __DIR__ . '/data/leadforge_geo_rules.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && !empty($data)) {
                self::$allGeoRulesCache = $data;
                return self::$allGeoRulesCache;
            }
        }
        return [];
    }

    /**
     * GEO phone masks shared by the adapter generator and Auto QA.
     * Derived dynamically from the 146-GEO rules database.
     */
    public static function geoMasks(): array
    {
        $builtIn = [
            // --- Europe -------------------------------------------------
            'IT' => ['code' => '+39', 'pattern' => '+39 3## ### ####', 'min' => 9, 'max' => 11],
            'ES' => ['code' => '+34', 'pattern' => '+34 6## ### ###', 'min' => 9, 'max' => 9],
            'DE' => ['code' => '+49', 'pattern' => '+49 1## #######', 'min' => 10, 'max' => 12],
            'FR' => ['code' => '+33', 'pattern' => '+33 6 ## ## ## ##', 'min' => 9, 'max' => 9],
            'PL' => ['code' => '+48', 'pattern' => '+48 ### ### ###', 'min' => 9, 'max' => 9],
            'RO' => ['code' => '+40', 'pattern' => '+40 7## ### ###', 'min' => 9, 'max' => 9],
            'GR' => ['code' => '+30', 'pattern' => '+30 69# ### ####', 'min' => 10, 'max' => 10],
            'GB' => ['code' => '+44', 'pattern' => '+44 7### ######', 'min' => 10, 'max' => 10],
            'PT' => ['code' => '+351', 'pattern' => '+351 9## ### ###', 'min' => 9, 'max' => 9],
            'NL' => ['code' => '+31', 'pattern' => '+31 6 ## ## ## ##', 'min' => 9, 'max' => 9],
            'BE' => ['code' => '+32', 'pattern' => '+32 4## ## ## ##', 'min' => 9, 'max' => 9],
            'AT' => ['code' => '+43', 'pattern' => '+43 6## #######', 'min' => 10, 'max' => 11],
            'CH' => ['code' => '+41', 'pattern' => '+41 7# ### ## ##', 'min' => 9, 'max' => 9],
            'CZ' => ['code' => '+420', 'pattern' => '+420 ### ### ###', 'min' => 9, 'max' => 9],
            'SK' => ['code' => '+421', 'pattern' => '+421 ### ### ###', 'min' => 9, 'max' => 9],
            'HU' => ['code' => '+36', 'pattern' => '+36 ## ### ####', 'min' => 8, 'max' => 9],
            'BG' => ['code' => '+359', 'pattern' => '+359 ### ### ###', 'min' => 8, 'max' => 9],
            'RS' => ['code' => '+381', 'pattern' => '+381 6# ### ####', 'min' => 8, 'max' => 9],
            'HR' => ['code' => '+385', 'pattern' => '+385 9# ### ####', 'min' => 8, 'max' => 8],
            'SI' => ['code' => '+386', 'pattern' => '+386 3# ### ###', 'min' => 8, 'max' => 8],
            'LT' => ['code' => '+370', 'pattern' => '+370 6## #####', 'min' => 8, 'max' => 8],
            'LV' => ['code' => '+371', 'pattern' => '+371 2### ####', 'min' => 8, 'max' => 8],
            'EE' => ['code' => '+372', 'pattern' => '+372 5### ####', 'min' => 7, 'max' => 8],
            'DK' => ['code' => '+45', 'pattern' => '+45 ## ## ## ##', 'min' => 8, 'max' => 8],
            'SE' => ['code' => '+46', 'pattern' => '+46 7# ### ## ##', 'min' => 9, 'max' => 9],
            'NO' => ['code' => '+47', 'pattern' => '+47 ### ## ###', 'min' => 8, 'max' => 8],
            'FI' => ['code' => '+358', 'pattern' => '+358 4# ### ####', 'min' => 9, 'max' => 10],
            'IE' => ['code' => '+353', 'pattern' => '+353 8# ### ####', 'min' => 9, 'max' => 9],
            'CY' => ['code' => '+357', 'pattern' => '+357 9# ######', 'min' => 8, 'max' => 8],
            'MD' => ['code' => '+373', 'pattern' => '+373 6## #####', 'min' => 8, 'max' => 8],
            'BY' => ['code' => '+375', 'pattern' => '+375 2# ### ## ##', 'min' => 9, 'max' => 9],
            'TR' => ['code' => '+90', 'pattern' => '+90 5## ### ## ##', 'min' => 10, 'max' => 10],
            // --- Americas ------------------------------------------------
            'US' => ['code' => '+1',  'pattern' => '+1 (###) ###-####', 'min' => 10, 'max' => 10],
            'MX' => ['code' => '+52', 'pattern' => '+52 1 ### ### ####', 'min' => 10, 'max' => 11],
            'CO' => ['code' => '+57', 'pattern' => '+57 3## ### ####', 'min' => 10, 'max' => 10],
            'BR' => ['code' => '+55', 'pattern' => '+55 (##) 9 ####-####', 'min' => 10, 'max' => 11],
            'AR' => ['code' => '+54', 'pattern' => '+54 9 ## ####-####', 'min' => 10, 'max' => 11],
            'CL' => ['code' => '+56', 'pattern' => '+56 9 #### ####', 'min' => 9, 'max' => 9],
            'PE' => ['code' => '+51', 'pattern' => '+51 ### ### ###', 'min' => 9, 'max' => 9],
            'EC' => ['code' => '+593', 'pattern' => '+593 ## ### ####', 'min' => 9, 'max' => 9],
            'VE' => ['code' => '+58', 'pattern' => '+58 4## ### ####', 'min' => 10, 'max' => 10],
            'UY' => ['code' => '+598', 'pattern' => '+598 9# ### ###', 'min' => 8, 'max' => 8],
            'PY' => ['code' => '+595', 'pattern' => '+595 9## ### ###', 'min' => 9, 'max' => 9],
            'BO' => ['code' => '+591', 'pattern' => '+591 # ### ####', 'min' => 7, 'max' => 8],
            'CR' => ['code' => '+506', 'pattern' => '+506 #### ####', 'min' => 8, 'max' => 8],
            'PA' => ['code' => '+507', 'pattern' => '+507 6### ####', 'min' => 7, 'max' => 8],
            'GT' => ['code' => '+502', 'pattern' => '+502 # ### ####', 'min' => 8, 'max' => 8],
            'DO' => ['code' => '+1',  'pattern' => '+1 (###) ###-####', 'min' => 10, 'max' => 10],
            'SV' => ['code' => '+503', 'pattern' => '+503 ####-####', 'min' => 7, 'max' => 8],
            'HN' => ['code' => '+504', 'pattern' => '+504 ####-####', 'min' => 8, 'max' => 8],
            'NI' => ['code' => '+505', 'pattern' => '+505 #### ####', 'min' => 8, 'max' => 8],
            // --- Asia ----------------------------------------------------
            'ID' => ['code' => '+62', 'pattern' => '+62 8## #### ####', 'min' => 9, 'max' => 12],
            'TH' => ['code' => '+66', 'pattern' => '+66 # #### ####', 'min' => 9, 'max' => 9],
            'VN' => ['code' => '+84', 'pattern' => '+84 3# ### ####', 'min' => 9, 'max' => 10],
            'MY' => ['code' => '+60', 'pattern' => '+60 1#-### ####', 'min' => 9, 'max' => 10],
            'PH' => ['code' => '+63', 'pattern' => '+63 9## ### ####', 'min' => 10, 'max' => 10],
            'IN' => ['code' => '+91', 'pattern' => '+91 ##### #####', 'min' => 10, 'max' => 10],
            'KH' => ['code' => '+855', 'pattern' => '+855 ## ### ###', 'min' => 8, 'max' => 9],
            'JP' => ['code' => '+81', 'pattern' => '+81 9# #### ####', 'min' => 10, 'max' => 10],
            'KR' => ['code' => '+82', 'pattern' => '+82 1# ### ####', 'min' => 9, 'max' => 10],
            'CN' => ['code' => '+86', 'pattern' => '+86 1## #### ####', 'min' => 11, 'max' => 11],
            'PK' => ['code' => '+92', 'pattern' => '+92 3## ### ####', 'min' => 10, 'max' => 10],
            'BD' => ['code' => '+880', 'pattern' => '+880 1### ######', 'min' => 10, 'max' => 10],
            // --- MENA & Africa -------------------------------------------
            'MA' => ['code' => '+212', 'pattern' => '+212 6## ### ###', 'min' => 9, 'max' => 9],
            'DZ' => ['code' => '+213', 'pattern' => '+213 5## ## ## ##', 'min' => 9, 'max' => 9],
            'TN' => ['code' => '+216', 'pattern' => '+216 ## ### ###', 'min' => 8, 'max' => 8],
            'EG' => ['code' => '+20', 'pattern' => '+20 1# ### ####', 'min' => 10, 'max' => 11],
            'ZA' => ['code' => '+27', 'pattern' => '+27 8# ### ####', 'min' => 9, 'max' => 9],
            'NG' => ['code' => '+234', 'pattern' => '+234 7## ### ####', 'min' => 10, 'max' => 10],
            'KE' => ['code' => '+254', 'pattern' => '+254 7## ### ###', 'min' => 9, 'max' => 9],
            'GH' => ['code' => '+233', 'pattern' => '+233 2# ### ####', 'min' => 9, 'max' => 9],
            'SN' => ['code' => '+221', 'pattern' => '+221 7# ### ## ##', 'min' => 9, 'max' => 9],
            'CI' => ['code' => '+225', 'pattern' => '+225 0# ## ## ## ##', 'min' => 8, 'max' => 10],
            'SA' => ['code' => '+966', 'pattern' => '+966 5# ### ####', 'min' => 9, 'max' => 9],
            'AE' => ['code' => '+971', 'pattern' => '+971 5# ### ####', 'min' => 9, 'max' => 9],
            'IL' => ['code' => '+972', 'pattern' => '+972 5#-###-####', 'min' => 9, 'max' => 9],
            // --- CIS ----------------------------------------------------
            'RU' => ['code' => '+7',  'pattern' => '+7 (9##) ###-##-##', 'min' => 10, 'max' => 10],
            'UA' => ['code' => '+380', 'pattern' => '+380 (##) ###-##-##', 'min' => 9, 'max' => 9],
            'KZ' => ['code' => '+7',  'pattern' => '+7 (7##) ###-##-##', 'min' => 10, 'max' => 10],
        ];

        $rules = self::allGeoRules();
        foreach ($rules as $iso => $r) {
            if (!isset($builtIn[$iso])) {
                $code = (string) ($r['country_prefix'] ?? '');
                $example = (string) ($r['example_local'] ?? '');
                $pattern = $example !== '' ? ($code . ' ' . $example) : ($code . ' ### ### ###');
                $builtIn[$iso] = [
                    'code' => $code,
                    'pattern' => $pattern,
                    'min' => (int) ($r['minlength'] ?? 7),
                    'max' => (int) ($r['maxlength'] ?? 15),
                ];
            }
        }
        return $builtIn;
    }

    private const LANG_GEO = [
        'HI' => 'IN', 'TA' => 'IN', 'TE' => 'IN', 'ML' => 'IN', 'KN' => 'IN', 'MR' => 'IN', 'GU' => 'IN', 'PA' => 'IN', 'AS' => 'IN', 'OR' => 'IN',
        'BN' => 'BD', 'UR' => 'PK',
        'UK' => 'UA', 'EL' => 'GR', 'CS' => 'CZ', 'HE' => 'IL', 'JA' => 'JP', 'KO' => 'KR',
        'ZH' => 'CN', 'VI' => 'VN', 'MS' => 'MY', 'TL' => 'PH', 'SW' => 'KE',
        'SV' => 'SE', 'DA' => 'DK', 'NO' => 'NO', 'NB' => 'NO', 'NN' => 'NO',
        'ET' => 'EE', 'SL' => 'SI', 'SR' => 'RS', 'KK' => 'KZ',
    ];

    // ==================================================================
    // Analyze
    // ==================================================================

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

            if (!mb_check_encoding($content, 'UTF-8')) {
                $out['encoding'] = 'non-UTF-8';
            }

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

            foreach (self::foreignScriptSignatures() as $fk => $sigs) {
                foreach ($sigs as $sig) {
                    if (strpos($lower, $sig) !== false) {
                        $out['foreign_scripts_detected'][$fk] = $fk;
                        break;
                    }
                }
            }

            if (in_array($ext, ['html', 'htm', 'php'], true) && preg_match('/<html[^>]+lang=["\']([a-zA-Z]{2})/i', $content, $m)) {
                $geoKey = strtoupper($m[1]);
                $geoKey = self::LANG_GEO[$geoKey] ?? $geoKey;
                $geoVotes[$geoKey] = ($geoVotes[$geoKey] ?? 0) + 1;
            }
            if (in_array($ext, ['html', 'htm', 'php'], true) && preg_match('/[\x{0900}-\x{097F}]/u', $content)) {
                $geoVotes['IN'] = ($geoVotes['IN'] ?? 0) + 1;
            }

            if (in_array($ext, ['html', 'htm', 'php'], true)) {
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
                if (preg_match_all('/<a[^>]+href=["\']#[a-zA-Z][^"\']*["\']/i', $content, $am)) {
                    $out['cta_links_count'] += count($am[0]);
                }
                if (preg_match_all('/<a[^>]+href=["\'](?:order|form|zamov|zamovlennya|checkout)[^"\']*["\']/i', $lower, $am2)) {
                    $out['cta_links_count'] += count($am2[0]);
                }
            }

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
            if (array_key_exists($geo, self::geoMasks()) || array_key_exists($geo, self::allGeoRules())) {
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

        if ($mode === 'cross') {
            $removed = self::removeLegacyHandlers($tempDir);
            foreach ($removed as $r) {
                $log("Cross: removed legacy handler {$r}");
            }
            if (!$removed) {
                $log('Cross: no legacy order handlers found in bundle root');
            }
        }

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
            $base = strtolower($file->getFilename());
            if (in_array($base, ['order.php', 'thank_you.php', 'success.php'], true)) {
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
            $log('Injected orbitra_adapter.js (ClickID bridge' . (!empty($opts['add_phone_mask']) ? " + {$geo} reference phone validator & counter" : '') . ')');
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
            $log("Generated universal order.php bridge for {$netLabel}" . ($crmEnabled ? ' + CRM vault sync' : ''));
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
        $targetType = in_array($opts['target_type'] ?? '', ['lander', 'offer', 'both'], true) ? $opts['target_type'] : '';
        $autoSave = $targetType !== '' ? in_array($targetType, ['lander', 'both'], true) : !empty($opts['auto_save_tracker']);
        $autoCreateOffer = $targetType !== '' ? $targetType === 'both' : !empty($opts['auto_create_offer']);

        if ($targetType === 'offer') {
            $offerName = trim((string) ($opts['name'] ?? '')) ?: ('LeadForge ' . date('Ymd His'));
            $stmtOff = $pdo->prepare("INSERT INTO offers (name, group_id, affiliate_network_id, payout_value, is_local, state) VALUES (?, ?, NULL, ?, 1, 'active')");
            $stmtOff->execute([$offerName, !empty($opts['group_id']) ? (int) $opts['group_id'] : null, (float) ($opts['payout'] ?? 0)]);
            $offerId = (int) $pdo->lastInsertId();

            $targetOfferDir = dirname(__DIR__) . '/offers/' . $offerId;
            if (!is_dir($targetOfferDir)) {
                @mkdir($targetOfferDir, 0775, true);
            }
            $ci = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($ci as $item) {
                $destPath = $targetOfferDir . '/' . $ci->getSubPathName();
                $item->isDir() ? (!is_dir($destPath) && @mkdir($destPath, 0775, true)) : @copy($item->getPathname(), $destPath);
            }
            $log("Saved local offer '{$offerName}' (ID #{$offerId}) — files served from /offers/{$offerId}/");
        } elseif ($autoSave) {
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

            if ($autoCreateOffer) {
                $offerGroupId = null;
                if (!empty($opts['group_id'])) {
                    $stmtGN = $pdo->prepare(
                        "SELECT og.id FROM offer_groups og JOIN landing_groups lg ON lg.name = og.name WHERE lg.id = ?"
                    );
                    $stmtGN->execute([(int) $opts['group_id']]);
                    $offerGroupId = $stmtGN->fetchColumn() ?: null;
                }
                $stmtOff = $pdo->prepare("INSERT INTO offers (name, group_id, affiliate_network_id, payout_value, is_local, state) VALUES (?, ?, NULL, ?, 0, 'active')");
                $stmtOff->execute([$landingName . ' [Offer]', $offerGroupId, (float) ($opts['payout'] ?? 0)]);
                $offerId = (int) $pdo->lastInsertId();
                $log("Created matching offer #{$offerId}" . ($offerGroupId ? ' (linked to the same-named offer group)' : ''));
            }
        }

        // --- Auto QA ------------------------------------------------------
        $qa = ['performed' => false];
        $qaTargetId = (int) ($landingId ?: $offerId);
        if (!empty($opts['auto_qa']) && $generateOrder && $qaTargetId > 0) {
            $qa = self::runQa($pdo, (int) ($landingId ?? 0), $slug, [
                'type' => $landingId ? 'landing' : 'offer',
                'offer_id' => (int) $offerId,
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
            $log('Auto QA skipped: needs order.php and a save target (lander or local offer)');
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
                'target_type' => $targetType !== '' ? $targetType : null,
            ],
        ];
    }

    public static function runQa(PDO $pdo, int $landingId, string $slug, array $opts): array
    {
        require_once __DIR__ . '/PhpLanding.php';
        $isOffer = (($opts['type'] ?? 'landing') === 'offer') && (int) ($opts['offer_id'] ?? 0) > 0;
        $entityId = $isOffer ? (int) $opts['offer_id'] : $landingId;
        $entityLabel = $isOffer ? "local offer #{$entityId}" : "landing #{$landingId}";
        $qa = [
            'performed' => true,
            'job_id' => 'job_lf_' . bin2hex(random_bytes(4)),
            'confidence' => 0,
            'passed' => false,
            'fail_reason' => '',
            'hosted_preview_url' => $isOffer
                ? ($entityId > 0 ? '/offers/' . $entityId . '/' : null)
                : ($slug !== '' ? '/lander/' . $slug . '/' : null),
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

        $geo = strtoupper((string) ($opts['geo'] ?? 'IT'));
        $rule = self::allGeoRules()[$geo] ?? null;
        $mask = self::geoMasks()[$geo] ?? ['code' => '+1', 'pattern' => '', 'min' => 9, 'max' => 15];
        $dial = ltrim($mask['code'] ?: ($rule['country_prefix'] ?? '+1'), '+');
        $qaLen = max((int) ($mask['min'] ?? 9), min((int) ($mask['max'] ?? 10), 10));
        $prefixDigits = !empty($rule['allowed_prefixes'][0]) ? (string)$rule['allowed_prefixes'][0] : '3';
        $fillDigits = substr(str_pad($prefixDigits . '330001122', $qaLen, '2'), 0, $qaLen);
        $qaPhone = '+' . $dial . $fillDigits;
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
        $say("QA: posting test lead to {$qaUrl} for {$entityLabel} (subid {$qaClick})");

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
                CURLOPT_COOKIE => $isOffer ? 'orbitra_lo=' . $entityId : 'orbitra_lp=' . $landingId,
                CURLOPT_SSL_VERIFYPEER => false,
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

    /**
     * Generate the orbitra_adapter.js ClickID & Phone Validation engine.
     * Uses the full reference template with 146-GEO rules, live counter badge,
     * adaptive background theme, vibration feedback, dynamic country switching,
     * strict name checks, and ClickID bridge.
     */
    public static function adapterJs(string $geo, bool $withMask): string
    {
        $geo = strtoupper(trim($geo));
        $allRules = self::allGeoRules();
        $active = $allRules[$geo] ?? $allRules['IT'] ?? [
            'geo' => $geo,
            'pattern' => '^.*$',
            'minlength' => 7,
            'maxlength' => 15,
            'name_err' => 'Please enter your real name without numbers',
            'phone_err' => 'Invalid phone number for selected country',
            'counter_intro' => ' digits entered, ',
            'counter_mid' => ' remaining',
            'counter_complete' => 'Number complete',
            'counter_err' => 'Enter a valid phone number',
            'country_iso' => strtolower($geo),
            'country_prefix' => '+1',
            'national_prefix' => '',
            'trunk_prefix' => false,
            'allowed_prefixes' => [],
            'phone_helper' => 'Enter valid phone number',
        ];

        $templatePath = __DIR__ . '/data/leadforge_validation_template.js';
        if (is_file($templatePath)) {
            $tmpl = (string) file_get_contents($templatePath);
            $mask = self::geoMasks()[$geo] ?? ['pattern' => '+39 3## ### ####'];
            $patternMask = (string) ($mask['pattern'] ?? '+39 3## ### ####');
            $replacements = [
                '// PATTERN: {{PATTERN}}' => '// PATTERN: ' . ($active['pattern'] ?? '^.*$') . "\n// PHONE_PATTERN: " . $patternMask,
                '{{GEO}}' => $geo,
                '{{PATTERN}}' => $active['pattern'] ?? '^.*$',
                '{{PHONE_PATTERN}}' => $patternMask,
                '{{MINLENGTH}}' => (string) ($active['minlength'] ?? 7),
                '{{MAXLENGTH}}' => (string) ($active['maxlength'] ?? 15),
                '{{NAME_ERR}}' => json_encode($active['name_err'] ?? 'Enter your name without numbers', JSON_UNESCAPED_UNICODE),
                '{{PHONE_ERR}}' => json_encode($active['phone_err'] ?? 'Invalid phone number', JSON_UNESCAPED_UNICODE),
                '{{COUNTER_INTRO}}' => json_encode($active['counter_intro'] ?? ' digits entered, ', JSON_UNESCAPED_UNICODE),
                '{{COUNTER_MID}}' => json_encode($active['counter_mid'] ?? ' remaining', JSON_UNESCAPED_UNICODE),
                '{{COUNTER_COMPLETE}}' => json_encode($active['counter_complete'] ?? 'Number complete', JSON_UNESCAPED_UNICODE),
                '{{COUNTER_ERR}}' => json_encode($active['counter_err'] ?? 'Enter valid phone number', JSON_UNESCAPED_UNICODE),
                '{{COUNTRY_ISO}}' => json_encode(strtolower($geo)),
                '{{COUNTRY_PREFIX}}' => json_encode($active['country_prefix'] ?? ''),
                '{{NATIONAL_PREFIX}}' => json_encode($active['national_prefix'] ?? ''),
                '{{TRUNK_PREFIX}}' => !empty($active['trunk_prefix']) ? 'true' : 'false',
                '{{ALLOWED_PREFIXES}}' => json_encode($active['allowed_prefixes'] ?? []),
                '{{PHONE_HELPER}}' => json_encode($active['phone_helper'] ?? '', JSON_UNESCAPED_UNICODE),
                '{{ALL_GEO_RULES}}' => json_encode($allRules, JSON_UNESCAPED_UNICODE),
                '{{SEP}}' => '<\\/script>',
            ];
            return str_replace(array_keys($replacements), array_values($replacements), $tmpl);
        }

        // Fallback minimal bridge
        return '/* LeadForge Adapter Fallback */';
    }

    /**
     * Generate the universal order.php bridge.
     * Supports: Dr.Cash, Webvork, LuckyOnline, KMA.biz, TerraLeads, Leadbit, LemonAD, Everad, Ezaff, Custom.
     */
    public static function orderPhp(array $o): string
    {
        $geo = strtoupper((string) ($o['geo'] ?? 'IT'));
        $rule = self::allGeoRules()[$geo] ?? null;
        $mask = self::geoMasks()[$geo] ?? [];
        $dial = ltrim((string) ($mask['code'] ?? $rule['country_prefix'] ?? '+39'), '+');
        $minLen = (int) ($mask['min'] ?? $rule['minlength'] ?? 7);
        $maxLen = (int) ($mask['max'] ?? $rule['maxlength'] ?? 15);

        $rulesJson = json_encode(self::allGeoRules(), JSON_UNESCAPED_UNICODE);

        $tpl = <<<'PHP'
<?php
/**
 * Orbitra LeadForge 2.0 — Universal CPA Order Bridge + CRM Vault Sync
 * Target Network: @@NETWORK@@ | Offer: @@OFFER_ID@@ | Target GEO: @@GEO@@
 *
 * Dual logging: the CPA network first, then the full raw lead snapshot into
 * the Orbitra CRM vault (in-process here, or /crm-ingest when this bundle is
 * hosted elsewhere). QA-flagged submissions never call the real network.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
    'phone_min'    => @@PHONE_MIN@@,
    'phone_max'    => @@PHONE_MAX@@,
);

// Endpoints
const DRCASH_ENDPOINT = 'https://order.drcash.sh/v1/order';
const WEBVORK_ENDPOINT_1 = 'https://api.webvork.com/v1/new-lead';
const WEBVORK_ENDPOINT_2 = 'https://api2.webvork.com/v1/new-lead';
const LUCKY_ENDPOINT = 'https://lucky.online/api/v1/lead-create/webmaster';
const KMA_ENDPOINT = 'https://api.kma.biz/lead/add';
const TERRALEADS_API_DOMAIN = 'https://t-api.org';
const LEADBIT_API_DOMAIN = 'http://wapi.leadbit.com';
const LEMONAD_ENDPOINT = 'https://lemonad.com/api/v2/lead/create';
const EZAFF_ENDPOINT = 'https://api.ezaff.com/send';

function lf_request_value(array $keys): string {
    foreach ($keys as $k) {
        if (!empty($_POST[$k])) {
            $v = trim((string) $_POST[$k]);
            if (!preg_match('/^\{[^{}]+\}$/', $v)) return $v;
        }
        if (!empty($_GET[$k])) {
            $v = trim((string) $_GET[$k]);
            if (!preg_match('/^\{[^{}]+\}$/', $v)) return $v;
        }
        if (!empty($_SESSION[$k])) {
            $v = trim((string) $_SESSION[$k]);
            if (!preg_match('/^\{[^{}]+\}$/', $v)) return $v;
        }
    }
    return '';
}

function lf_get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) return $ip;
        }
    }
    return '127.0.0.1';
}

function lf_all_geo_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $raw = '@@ALL_GEO_RULES_JSON@@';
    $rules = json_decode($raw, true) ?: [];
    return $rules;
}

function lf_normalize_phone(string $phone, string $country, string $defaultDial = ''): string {
    $country = strtoupper(trim($country));
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return '';
    $rules = lf_all_geo_rules();
    $cfg = $rules[$country] ?? null;
    $dial = $cfg['country_prefix'] ? ltrim($cfg['country_prefix'], '+') : $defaultDial;

    if ($dial !== '' && strpos($digits, $dial) === 0 && strlen($digits) > strlen($dial)) {
        $withoutDial = substr($digits, strlen($dial));
        if (!empty($cfg['pattern']) && @preg_match('/' . $cfg['pattern'] . '/', $withoutDial)) {
            $digits = $withoutDial;
        }
    }
    if (!empty($cfg['trunk_prefix']) && strpos($digits, '0') === 0 && strlen($digits) > 1) {
        $digits = substr($digits, 1);
    }

    if ($dial !== '') {
        return '+' . $dial . $digits;
    }
    return (strpos($phone, '+') === 0 ? '+' : '') . $digits;
}

function lf_http_call(string $url, $payload, array $headers, bool $asJson): array {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $asJson ? (is_string($payload) ? $payload : json_encode($payload)) : (is_array($payload) ? http_build_query($payload) : $payload),
                'timeout' => 15,
                'ignore_errors' => true,
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 200;
        return ['http_code' => $code, 'body' => is_string($body) ? $body : ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $asJson ? (is_string($payload) ? $payload : json_encode($payload)) : (is_array($payload) ? http_build_query($payload) : $payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $code, 'body' => is_string($body) ? $body : ''];
}

function lf_is_macro($val) {
    $v = trim((string)$val);
    return $v === '' || preg_match('/^\{[^{}]+\}$/', $v) === 1 || preg_match('/^\{\{[^{}]+\}\}$/', $v) === 1;
}

$name = lf_request_value(['name', 'full_name', 'fullname', 'fio', 'customer_name', 'client']) ?: 'Customer';
$rawPhone = lf_request_value(['phone', 'telephone', 'tel', 'mobile', 'phone_number', 'msisdn']);
$product = trim($_POST['product'] ?? '') ?: $LF['landing_name'];
$price = isset($_POST['price']) && $_POST['price'] !== '' ? (float) $_POST['price'] : 0.0;
$country = strtoupper(lf_request_value(['country', 'id_country', 'country_id', 'country_code']) ?: $LF['geo']);

$rawSubid = lf_request_value(['subid', 'sub_id', 'click_id', 'clickid', 'sub1', 'subid1', 'data1', 'utm_campaign']);
if (lf_is_macro($rawSubid)) {
    $rawSubid = '';
}
$subid = $rawSubid
    ?: (isset($_COOKIE['orbitra_click']) && !lf_is_macro($_COOKIE['orbitra_click']) ? $_COOKIE['orbitra_click'] : '')
    ?: (isset($_COOKIE['orbitra_subid']) && !lf_is_macro($_COOKIE['orbitra_subid']) ? $_COOKIE['orbitra_subid'] : '')
    ?: (isset($_COOKIE['subid']) && !lf_is_macro($_COOKIE['subid']) ? $_COOKIE['subid'] : '')
    ?: (isset($_SESSION['orbitra_click']) && !lf_is_macro($_SESSION['orbitra_click']) ? $_SESSION['orbitra_click'] : '')
    ?: (isset($_SESSION['subid']) && !lf_is_macro($_SESSION['subid']) ? $_SESSION['subid'] : '');

if (lf_is_macro($subid)) {
    $subid = '';
}
if ($subid === '') {
    foreach (['subid', 'clickid', 'click_id', 'sub1'] as $qk) {
        if (!empty($_GET[$qk]) && !lf_is_macro($_GET[$qk])) {
            $subid = trim((string)$_GET[$qk]);
            break;
        }
    }
}
if ($subid === '') {
    $subid = 'lead_' . bin2hex(random_bytes(8));
}

$ip = lf_get_ip();
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

if ($rawPhone === '') {
    http_response_code(422);
    die('Error: Phone number is required.');
}

if ($name !== '' && !preg_match('/\p{L}/u', $name)) {
    http_response_code(422);
    die('Error: Please enter a valid customer name (letters required).');
}

$cleanPhone = lf_normalize_phone($rawPhone, $country, $LF['dial_code']);
$rule = lf_all_geo_rules()[$country] ?? null;
$phoneDigits = preg_replace('/\D+/', '', $cleanPhone);
$dialDigits = preg_replace('/\D+/', '', $rule['country_prefix'] ?? $LF['dial_code']);
if ($dialDigits !== '' && strpos($phoneDigits, $dialDigits) === 0) {
    $natDigits = substr($phoneDigits, strlen($dialDigits));
} else {
    $natDigits = $phoneDigits;
}
$lfNational = $natDigits;

$minL = (int) ($rule['minlength'] ?? $LF['phone_min']);
$maxL = (int) ($rule['maxlength'] ?? $LF['phone_max']);
if (strlen($lfNational) < $minL || strlen($lfNational) > $maxL) {
    $lenMsg = (@@PHONE_MIN@@ === @@PHONE_MAX@@) ? ('exactly ' . @@PHONE_MIN@@) : (@@PHONE_MIN@@ . '-' . @@PHONE_MAX@@);
    http_response_code(422);
    die('Error: Phone number must have ' . $lenMsg . ' digits for ' . $country . '.');
}

$isQa = (($_POST['orbitra_qa'] ?? '') === '1') || (strpos($subid, 'qa_test_') === 0);

$subParams = [];
foreach (['sub1','sub2','sub3','sub4','sub5','sub6','sub7','sub8','sub9','sub10',
          'pixel','utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_placement',
          'fbclid','fbp','fbc','ttclid','gclid','adset_id','ad_id'] as $k) {
    if (isset($_POST[$k]) && $_POST[$k] !== '' && !lf_is_macro($_POST[$k])) {
        $subParams[$k] = substr((string) $_POST[$k], 0, 255);
    } elseif (isset($_GET[$k]) && $_GET[$k] !== '' && !lf_is_macro($_GET[$k])) {
        $subParams[$k] = substr((string) $_GET[$k], 0, 255);
    }
}
if (empty($subParams['sub1']) || lf_is_macro($subParams['sub1'])) {
    $subParams['sub1'] = $subid;
}
if (empty($subParams['fbp']) && !empty($_COOKIE['_fbp'])) $subParams['fbp'] = $_COOKIE['_fbp'];
if (empty($subParams['fbc']) && !empty($_COOKIE['_fbc'])) $subParams['fbc'] = $_COOKIE['_fbc'];

$data = array_merge($subParams, [
    'name' => $name,
    'phone' => $cleanPhone ?: $rawPhone,
    'raw_phone' => $rawPhone,
    'country' => $country,
    'ip' => $ip,
    'subid' => $subid,
    'offer_id' => $LF['offer_id'],
]);

$netRequest = ['endpoint' => '', 'method' => 'POST', 'timestamp' => date('Y-m-d H:i:s')];
$netResponse = ['http_code' => 0, 'body' => ''];
$networkLeadId = '';

if ($isQa) {
    $netResponse = ['http_code' => 200, 'body' => '{"qa":true,"simulated":true}'];
    $networkLeadId = 'qa_simulated';
} else {
    try {
        switch ($LF['network']) {
            case 'drcash':
                $netRequest['endpoint'] = DRCASH_ENDPOINT;
                $postedSub1 = (!empty($subParams['sub1']) && !lf_is_macro($subParams['sub1'])) ? $subParams['sub1'] : $subid;
                $payload = [
                    'stream_code' => $LF['offer_id'],
                    'client' => [
                        'name' => $name,
                        'phone' => $cleanPhone ?: $rawPhone,
                        'address' => $_POST['address'] ?? '',
                        'email' => $_POST['email'] ?? '',
                        'ip' => $ip,
                        'country' => $country ?: null,
                        'city' => $_POST['city'] ?? '',
                        'postcode' => $_POST['postcode'] ?? '',
                    ],
                    'sub1' => $postedSub1,
                    'sub2' => $subParams['sub2'] ?? '',
                    'sub3' => $subParams['sub3'] ?? '',
                    'sub4' => $subParams['sub4'] ?? '',
                    'sub5' => $subParams['sub5'] ?? '',
                ];
                $netRequest['payload'] = $payload;
                $res = lf_http_call(DRCASH_ENDPOINT, $payload, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $LF['api_key']
                ], true);
                break;

            case 'webvork':
                $payload = [
                    'token' => $LF['api_key'],
                    'offer_id' => $LF['offer_id'],
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'country' => $country,
                    'ip' => $ip,
                    'utm_campaign' => $subid,
                    'utm_source' => $subParams['utm_source'] ?? '',
                    'utm_medium' => $subParams['utm_medium'] ?? '',
                    'utm_content' => $subParams['utm_content'] ?? '',
                    'utm_term' => $subParams['utm_term'] ?? '',
                    'fbp' => $subParams['fbp'] ?? '',
                    'fbc' => $subParams['fbc'] ?? '',
                    'sub1' => $subParams['sub1'] ?? '',
                    'sub2' => $subParams['sub2'] ?? '',
                    'sub3' => $subParams['sub3'] ?? '',
                    'sub4' => $subParams['sub4'] ?? '',
                    'sub5' => $subParams['sub5'] ?? '',
                ];
                $netRequest['endpoint'] = WEBVORK_ENDPOINT_1;
                $netRequest['payload'] = $payload;
                $res = lf_http_call(WEBVORK_ENDPOINT_1, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                if ($res['http_code'] < 200 || $res['http_code'] >= 300) {
                    $res = lf_http_call(WEBVORK_ENDPOINT_2, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                }
                break;

            case 'lucky':
            case 'luckyonline':
                $payload = [
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'campaign_hash' => $LF['offer_id'],
                    'country' => $country,
                    'subid1' => $subid,
                    'subid' => $subid,
                    'subid2' => $subParams['sub2'] ?? '',
                    'subid3' => $subParams['sub3'] ?? '',
                    'utm_source' => $subParams['utm_source'] ?? '',
                    'utm_medium' => $subParams['utm_medium'] ?? '',
                    'utm_campaign' => $subParams['utm_campaign'] ?? $subid,
                    'utm_content' => $subParams['utm_content'] ?? '',
                    'utm_term' => $subParams['utm_term'] ?? '',
                ];
                $url = LUCKY_ENDPOINT . '?api_key=' . urlencode($LF['api_key']);
                $netRequest['endpoint'] = $url;
                $netRequest['payload'] = $payload;
                $res = lf_http_call($url, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                break;

            case 'kma':
                $payload = [
                    'channel' => $LF['offer_id'],
                    'ip' => $ip,
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'data1' => $subid,
                ];
                foreach (['data2', 'data3', 'data4', 'data5', 'fbp', 'click', 'referer', 'address'] as $f) {
                    if (!empty($_POST[$f])) $payload[$f] = trim((string)$_POST[$f]);
                }
                $headers = ['Accept: application/json', 'Authorization: Bearer ' . $LF['api_key']];
                $netRequest['endpoint'] = KMA_ENDPOINT;
                $netRequest['payload'] = $payload;
                $res = lf_http_call(KMA_ENDPOINT, $payload, $headers, false);
                break;

            case 'terraleads':
                $payloadData = [
                    'name' => $name,
                    'country' => $country,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'offer_id' => $LF['offer_id'],
                    'stream_id' => $_POST['stream_id'] ?? '',
                    'sub_id' => $subid,
                    'sub_id_1' => $subParams['sub1'] ?? '',
                    'sub_id_2' => $subParams['sub2'] ?? '',
                    'sub_id_3' => $subParams['sub3'] ?? '',
                    'sub_id_4' => $subParams['sub4'] ?? '',
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ];
                $userId = $_POST['user_id'] ?? '1';
                $fullPayload = ['user_id' => $userId, 'data' => $payloadData];
                $json = json_encode($fullPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $chk = sha1($json . $LF['api_key']);
                $url = TERRALEADS_API_DOMAIN . '/api/lead/create?check_sum=' . urlencode($chk);
                $netRequest['endpoint'] = $url;
                $netRequest['payload'] = $fullPayload;
                $res = lf_http_call($url, $json, ['Content-Type: application/json', 'Accept: application/json'], true);
                break;

            case 'leadbit':
                $payload = [
                    'flow_hash' => $LF['offer_id'],
                    'referrer' => $_SERVER['HTTP_REFERER'] ?? ($_SERVER['HTTP_HOST'] ?? ''),
                    'phone' => $cleanPhone ?: $rawPhone,
                    'name' => $name,
                    'country' => strtolower($country),
                    'address' => $_POST['address'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'ip' => $ip,
                    'sub1' => $subid,
                    'sub2' => $subParams['sub2'] ?? '',
                    'sub3' => $subParams['sub3'] ?? '',
                    'sub4' => $subParams['sub4'] ?? '',
                    'sub5' => $subParams['sub5'] ?? '',
                ];
                $url = LEADBIT_API_DOMAIN . '/api/pub/new-order/' . rawurlencode($LF['api_key']);
                $netRequest['endpoint'] = $url;
                $netRequest['payload'] = $payload;
                $res = lf_http_call($url, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                break;

            case 'lemonad':
                $payload = [
                    'api_token' => $LF['api_key'],
                    'offer_id' => $LF['offer_id'],
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'ip' => $ip,
                    'country' => $country,
                    'click_id' => $subid,
                ];
                $netRequest['endpoint'] = LEMONAD_ENDPOINT;
                $netRequest['payload'] = $payload;
                $res = lf_http_call(LEMONAD_ENDPOINT, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                break;

            case 'everad':
                $payload = [
                    'campaign_id' => $LF['offer_id'],
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'ip' => $ip,
                    'sid1' => $subid,
                ];
                $url = 'https://api.everad.com/campaigns/' . $LF['offer_id'] . '/order';
                $netRequest['endpoint'] = $url;
                $netRequest['payload'] = $payload;
                $res = lf_http_call($url, $payload, ['Content-Type: application/json', 'X-Api-Key: ' . $LF['api_key']], true);
                break;

            case 'ezaff':
                $payload = [
                    'offer_id' => $LF['offer_id'],
                    'publisher_id' => $_POST['publisher_id'] ?? '',
                    'api_key' => $LF['api_key'],
                    'name' => $name,
                    'phone' => $cleanPhone ?: $rawPhone,
                    'country' => $country,
                    'click_id' => $subid,
                    'publisher_sub_id' => $subParams['sub1'] ?? '',
                    'client_ip' => $ip,
                ];
                $netRequest['endpoint'] = EZAFF_ENDPOINT;
                $netRequest['payload'] = $payload;
                $res = lf_http_call(EZAFF_ENDPOINT, $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                break;

            default:
                if (!empty($LF['offer_id']) && filter_var($LF['offer_id'], FILTER_VALIDATE_URL)) {
                    $netRequest['endpoint'] = $LF['offer_id'];
                    $payload = $_POST;
                    $payload['subid'] = $subid;
                    $payload['phone'] = $cleanPhone ?: $rawPhone;
                    $payload['name'] = $name;
                    $netRequest['payload'] = 'form passthrough';
                    $res = lf_http_call($LF['offer_id'], $payload, ['Content-Type: application/x-www-form-urlencoded'], false);
                } else {
                    $res = ['http_code' => 200, 'body' => '{"status":"ok"}'];
                }
                break;
        }

        $netResponse['http_code'] = (int) ($res['http_code'] ?? 0);
        $netResponse['body'] = substr((string) ($res['body'] ?? ''), 0, 4096);
        $json = json_decode((string) ($res['body'] ?? ''), true);
        if (is_array($json)) {
            foreach (['uuid', 'lead_id', 'id', 'order_id', 'tid'] as $jk) {
                if (!empty($json[$jk])) {
                    $networkLeadId = (string) $json[$jk];
                    break;
                }
            }
            if ($networkLeadId === '' && !empty($json['data']['id'])) {
                $networkLeadId = (string) $json['data']['id'];
            }
        }
    } catch (\Throwable $e) {
        $netResponse['http_code'] = 0;
        $netResponse['body'] = 'exception: ' . $e->getMessage();
    }
}

// === CRM Vault Sync =====================================================
$crmOk = false;
if ($LF['crm_enabled']) {
    $crmPayload = [
        'click_id'        => $subid,
        'campaign_id'     => 0,
        'lander_id'       => 0,
        'offer_id'        => $LF['offer_id'],
        'network'         => $LF['network'],
        'network_lead_id' => $networkLeadId,
        'product'         => $product,
        'price'           => $price,
        'customer_name'   => $name,
        'raw_phone'       => $rawPhone,
        'clean_phone'     => $cleanPhone,
        'geo'             => $country,
        'ip'              => $ip,
        'user_agent'      => $userAgent,
        'status'          => 'lead',
        'payout'          => $LF['payout'],
        'currency'        => $LF['currency'],
        'is_qa_test'      => $isQa ? 1 : 0,
        'status_source'   => 'form_submit',
        'sub_data'        => $subParams,
        'network_request' => $netRequest,
        'network_response'=> $netResponse,
    ];
    foreach (['utm_source', 'utm_campaign', 'utm_placement', 'adset_id', 'ad_id', 'fbp', 'fbc'] as $pk) {
        if (isset($subParams[$pk])) $crmPayload[$pk] = $subParams[$pk];
    }

    if (function_exists('orbitraCrmRecordLead') && isset($pdo) && ($pdo instanceof PDO)) {
        $crmRes = orbitraCrmRecordLead($pdo, $crmPayload, false);
        $crmOk = !empty($crmRes['ok']);
    } elseif ($LF['tracker_base'] !== '') {
        $ch = curl_init(rtrim($LF['tracker_base'], '/') . '/crm-ingest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($crmPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $crmRaw = curl_exec($ch);
        curl_close($ch);
        $crmJson = json_decode((string) $crmRaw, true);
        $crmOk = !empty($crmJson['status']) && $crmJson['status'] === 'success';
    }
}

// === Failsafe local backup =============================================
$leadLog = [
    'time' => date('Y-m-d H:i:s'),
    'network' => $LF['network'],
    'network_lead_id' => $networkLeadId,
    'http_code' => $netResponse['http_code'],
    'name' => $name,
    'phone' => $rawPhone,
    'phone_e164' => $cleanPhone,
    'subid' => $subid,
    'ip' => $ip,
    'geo' => $country,
    'crm_synced' => $crmOk,
    'qa' => $isQa,
];
@file_put_contents(__DIR__ . '/orbitra_leads_backup.log', json_encode($leadLog) . PHP_EOL, FILE_APPEND | LOCK_EX);

// LeadForge plain lead log
$leadLogPath = __DIR__ . '/leadforge.leads.log';
if (!file_exists($leadLogPath)) {
    @file_put_contents($leadLogPath, "Date | Time | Name | Number | Subid\n", LOCK_EX);
}
@file_put_contents($leadLogPath, date('Y-m-d') . ' | ' . date('H:i:s') . ' | ' . str_replace('|', ' ', $name) . ' | ' . str_replace('|', ' ', $cleanPhone ?: $rawPhone) . ' | ' . str_replace('|', ' ', $subid) . "\n", FILE_APPEND | LOCK_EX);

// === Tracker conversion =================================================
if ($subid !== '' && (!$LF['crm_enabled'] || !$crmOk) && !$isQa) {
    $trackerHost = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $postbackUrl = $proto . '://' . $trackerHost . '/pixel.gif?action=conversion&subid=' . urlencode($subid)
        . '&status=lead&payout=' . rawurlencode((string) $LF['payout']) . '&currency=' . rawurlencode($LF['currency']);
    @file_get_contents($postbackUrl, false, stream_context_create(['http' => ['timeout' => 2]]));
}

// === Redirect ============================================================
$_SESSION['order_name'] = $name;
$_SESSION['order_phone'] = $cleanPhone ?: $rawPhone;
$_SESSION['order_id'] = $networkLeadId !== '' ? $networkLeadId : ('LF-' . date('YmdHis') . '-' . rand(1000, 9999));

header('Location: thank_you.php?name=' . urlencode($name) . '&phone=' . urlencode($cleanPhone ?: $rawPhone)
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
            '@@PHONE_MIN@@'    => (int) $minLen,
            '@@PHONE_MAX@@'    => (int) $maxLen,
            '@@ALL_GEO_RULES_JSON@@' => addcslashes($rulesJson, "'\\"),
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
            'IN' => ['title' => 'आपके ऑर्डर के लिए धन्यवाद!', 'subtitle' => 'आपका ऑर्डर सफलतापूर्वक दर्ज कर लिया गया है।', 'call' => 'हमारा प्रतिनिधि जल्द ही डिलीवरी की पुष्टि के लिए आपसे संपर्क करेगा।', 'details' => 'ऑर्डर विवरण:', 'name' => 'नाम:', 'phone' => 'फ़ोन:', 'order' => 'ऑर्डर संख्या:'],
            'AR' => ['title' => 'شكراً لطلبك!', 'subtitle' => 'تم تسجيل طلبك بنجاح.', 'call' => 'سيتصل بك ممثلنا قريباً لتأكيد تفاصيل التوصيل.', 'details' => 'تفاصيل الطلب:', 'name' => 'الاسم:', 'phone' => 'الهاتف:', 'order' => 'رقم الطلب:'],
            'SA' => ['title' => 'شكراً لطلبك!', 'subtitle' => 'تم تسجيل طلبك بنجاح.', 'call' => 'سيتصل بك ممثلنا قريباً لتأكيد تفاصيل التوصيل.', 'details' => 'تفاصيل الطلب:', 'name' => 'الاسم:', 'phone' => 'الهاتف:', 'order' => 'رقم الطلب:'],
            'AE' => ['title' => 'شكراً لطلبك!', 'subtitle' => 'تم تسجيل طلبك بنجاح.', 'call' => 'سيتصل بك ممثلنا قريباً لتأكيد تفاصيل التوصيل.', 'details' => 'تفاصيل الطلب:', 'name' => 'الاسم:', 'phone' => 'الهاتف:', 'order' => 'رقم الطلب:'],
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
