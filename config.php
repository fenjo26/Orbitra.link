<?php
// config.php
// Landing slug/path helpers are needed by the migration below (slug backfill)
// and by the API + index.php path resolution. Safe to load before $pdo exists:
// the functions only touch the database when given a $pdo argument.
require_once __DIR__ . '/core/landing_path.php';
// Stream filter AND/OR combination — shared by every click-matching engine.
require_once __DIR__ . '/core/StreamFilters.php';

$db_file = __DIR__ . '/orbitra_db.sqlite';
$postback_key = 'fd12e72';

try {
    // 5 seconds timeout ensures PHP waits if the database is temporarily locked by another process
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ];
    $pdo = new PDO("sqlite:" . $db_file, null, null, $pdoOptions);

    // Set busy timeout FIRST so subsequent commands will wait up to 5 seconds if DB is locked
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    try {
        // SQLite journal mode affects the presence of `*.sqlite-wal/*.sqlite-shm` files.
        // WAL improves concurrency, but some setups prefer DELETE to avoid extra files in the project dir.
        //
        // Override via env (server-level): ORBITRA_SQLITE_JOURNAL_MODE=WAL|DELETE
        $journalMode = getenv('ORBITRA_SQLITE_JOURNAL_MODE');
        $journalMode = is_string($journalMode) ? strtoupper(trim($journalMode)) : '';
        if ($journalMode !== 'WAL' && $journalMode !== 'DELETE') {
            $journalMode = 'DELETE';
        }

        $pdo->exec("PRAGMA journal_mode = {$journalMode};");
        // WAL works best with NORMAL; DELETE is safer with FULL (less risk on power loss).
        $pdo->exec("PRAGMA synchronous = " . ($journalMode === 'WAL' ? "NORMAL" : "FULL") . ";");
    } catch (\Throwable $e) {
        // Ignore if we can't switch mode right now (it's persistent anyway)
    }

    // Включаем поддержку внешших ключей в SQLite
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // ---- Schema init/migrations -------------------------------------------------
    //
    // IMPORTANT: Do not run DDL + seed logic on every request.
    // It causes constant writes/locks in SQLite and breaks concurrent API calls
    // (e.g. Backorder auto-check loop) with "database is locked".
    //
    // We use SQLite PRAGMA user_version as a lightweight schema version marker.
    // DDL + seed is executed only when user_version is behind.
    $LATEST_SCHEMA_VERSION = 25;

    $schemaVersion = 0;
    try {
        $schemaVersion = (int) ($pdo->query("PRAGMA user_version")->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        $schemaVersion = 0;
    }

    $runMigrations = function () use ($pdo, $LATEST_SCHEMA_VERSION, &$schemaVersion, &$postback_key) : void {
        if ($schemaVersion >= $LATEST_SCHEMA_VERSION) {
            return;
        }

        // Best-effort single-instance lock for migrations (avoid concurrent DDL attempts).
        $lockDir = __DIR__ . '/var/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $lockFile = $lockDir . '/db_schema_migrate.lock';
        $fp = @fopen($lockFile, 'c+');
        if ($fp) {
            // Blocking lock: only relevant during deployment/first run.
            @flock($fp, LOCK_EX);
        }

        try {
            // Another process may have migrated while we were waiting for the lock.
            try {
                $schemaVersion = (int) ($pdo->query("PRAGMA user_version")->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                $schemaVersion = 0;
            }
            if ($schemaVersion >= $LATEST_SCHEMA_VERSION) {
                return;
            }

            // Инициализация базы данных, если она пустая (or old installs without user_version)
    $init_sql = "
    CREATE TABLE IF NOT EXISTS affiliate_networks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        template TEXT,
        offer_params TEXT,
        postback_url TEXT,
        notes TEXT,
        state TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS affiliate_network_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        offer_params_template TEXT,
        postback_url_template TEXT,
        icon TEXT
    );

    CREATE TABLE IF NOT EXISTS offer_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        group_id INTEGER,
        affiliate_network_id INTEGER,
        url TEXT,
        redirect_type TEXT DEFAULT 'redirect',
        is_local INTEGER DEFAULT 0,
        geo TEXT,
        payout_type TEXT DEFAULT 'cpa',
        payout_value REAL DEFAULT 0.00,
        payout_auto INTEGER DEFAULT 0,
        allow_rebills INTEGER DEFAULT 0,
        capping_limit INTEGER DEFAULT 0,
        capping_timezone TEXT DEFAULT 'UTC',
        alt_offer_id INTEGER,
        notes TEXT,
        values_json TEXT,
        state TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME,
        FOREIGN KEY (group_id) REFERENCES offer_groups(id) ON DELETE SET NULL,
        FOREIGN KEY (affiliate_network_id) REFERENCES affiliate_networks(id) ON DELETE SET NULL,
        FOREIGN KEY (alt_offer_id) REFERENCES offers(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS landing_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS landings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        group_id INTEGER,
        type TEXT DEFAULT 'local',
        state TEXT DEFAULT 'active',
        action_payload TEXT,
        action_type TEXT DEFAULT '',
        slug TEXT DEFAULT '',
        redirect_type TEXT DEFAULT 'redirect',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS domain_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        index_campaign_id INTEGER,
        catch_404 INTEGER DEFAULT 0,
        group_id INTEGER,
        is_noindex INTEGER DEFAULT 0,
        https_only INTEGER DEFAULT 0,
        ssl_status TEXT DEFAULT 'none',                  -- 'none'|'pending'|'waiting_dns'|'installing'|'installed'|'failed'|'cloudflare'
        ssl_error TEXT,                                   -- last Certbot output / DNS mismatch detail
        ssl_attempts INTEGER DEFAULT 0,                   -- failures so far, drives the retry backoff
        ssl_last_attempt TEXT,                            -- when the last attempt ran
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        dns_status TEXT, dns_ip TEXT, dns_checked_at DATETIME,
        keitaro_id INTEGER,
        admin_access INTEGER DEFAULT 1,                  -- 0: admin panel returns 404 on this domain
        cloudflare_proxy INTEGER DEFAULT 0,              -- 1: SSL comes from the CF edge, certbot is skipped
        registrar TEXT DEFAULT '',
        dns_provider TEXT DEFAULT '',
        status TEXT DEFAULT 'OK',                        -- 'OK'|'Active'|'Disabled'; Disabled serves 404 on the whole host
        FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES domain_groups(id) ON DELETE SET NULL
    );

    -- Backorder / domain availability tracker (separate from tracking domains)
    CREATE TABLE IF NOT EXISTS backorder_domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        status TEXT DEFAULT 'unknown',                 -- unknown|registered|available|rate_limited|unsupported|error
        notes TEXT,
        ahrefs_dr REAL,
        ahrefs_ur REAL,
        ahrefs_ref_domains INTEGER,
        last_checked_at DATETIME,
        last_http_code INTEGER,
        last_error TEXT,
        last_rdap_url TEXT,
        last_result_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS campaign_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS traffic_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        template TEXT,
        postback_url TEXT,
        postback_statuses TEXT DEFAULT 'lead,sale',
        parameters_json TEXT,
        notes TEXT,
        state TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS traffic_source_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        postback_url TEXT,
        parameters_json TEXT,
        icon TEXT
    );

    CREATE TABLE IF NOT EXISTS campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        alias TEXT NOT NULL UNIQUE,
        domain_id INTEGER,
        group_id INTEGER,
        source_id INTEGER,
        cost_model TEXT DEFAULT 'CPC',
        cost_value REAL DEFAULT 0.00,
        uniqueness_method TEXT DEFAULT 'IP',
        uniqueness_hours INTEGER DEFAULT 24,
        rotation_type TEXT DEFAULT 'position',
        token TEXT,
        catch_404_stream_id INTEGER,
        parameters_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME,
        state TEXT DEFAULT 'active',                      -- play/pause toggle: 'disabled' stops serving (503)
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES campaign_groups(id) ON DELETE SET NULL,
        FOREIGN KEY (source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS campaign_postbacks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        method TEXT DEFAULT 'GET',
        statuses TEXT DEFAULT 'lead,sale,rejected',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS streams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        offer_id INTEGER,
        name TEXT,
        weight INTEGER DEFAULT 100,
        is_active INTEGER DEFAULT 1,
        type TEXT DEFAULT 'regular',
        position INTEGER DEFAULT 0,
        filters_json TEXT,
        filters_logic TEXT DEFAULT 'and',
        schema_type TEXT DEFAULT 'redirect',
        action_payload TEXT,
        schema_custom_json TEXT,
        offer_selection TEXT DEFAULT 'before',
        collect_clicks INTEGER DEFAULT 1,                   -- 0: serve the stream without a clicks row (no stats, no sub_id)
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS clicks (
        id TEXT PRIMARY KEY,
        campaign_id INTEGER NOT NULL,
        offer_id INTEGER,
        stream_id INTEGER,
        source_id INTEGER,
        landing_id INTEGER,
        ip TEXT NOT NULL,
        user_agent TEXT,
        referer TEXT,
        country TEXT,
        country_code TEXT,
        region TEXT,
        city TEXT,
        latitude REAL,
        longitude REAL,
        zipcode TEXT,
        timezone TEXT,
        device_type TEXT DEFAULT 'Unknown',
        os TEXT,
        browser TEXT,
        language TEXT,
        accept_language_raw TEXT,
        is_conversion INTEGER DEFAULT 0,
        revenue REAL DEFAULT 0.00,
        cost REAL DEFAULT 0.00,
        parameters_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
        FOREIGN KEY (source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
    );
    CREATE TABLE IF NOT EXISTS conversions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        click_id TEXT NOT NULL,
        tid TEXT,
        status TEXT NOT NULL,
        original_status TEXT,
        payout REAL DEFAULT 0.00,
        currency TEXT DEFAULT 'USD',
        cost REAL DEFAULT 0.00,
        sub_id_1 TEXT,
        sub_id_2 TEXT,
        sub_id_3 TEXT,
        sub_id_4 TEXT,
        sub_id_5 TEXT,
        offer_id INTEGER,
        campaign_id INTEGER,
        ip TEXT,
        user_agent TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(click_id, tid),
        FOREIGN KEY (click_id) REFERENCES clicks(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS postback_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        click_id TEXT,
        status TEXT,
        original_status TEXT,
        payout REAL,
        currency TEXT,
        tid TEXT,
        ip TEXT,
        request_url TEXT,
        request_body TEXT,
        response TEXT,
        is_success INTEGER DEFAULT 0,
        error_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        email TEXT,
        role TEXT DEFAULT 'user',
        permissions_json TEXT DEFAULT '{}',
        api_key TEXT,
        is_active INTEGER DEFAULT 1,
        last_login DATETIME,
        language TEXT DEFAULT 'ru',
        timezone TEXT DEFAULT 'Europe/Moscow',
        first_day_of_week INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS user_api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        key_name TEXT NOT NULL,
        api_key TEXT NOT NULL UNIQUE,
        permissions TEXT DEFAULT 'read',
        last_used DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS geo_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        countries TEXT NOT NULL,
        is_template INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS conversion_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        status_values TEXT NOT NULL,
        next_statuses TEXT,
        record_conversion INTEGER DEFAULT 1,
        record_revenue INTEGER DEFAULT 1,
        send_postback INTEGER DEFAULT 1,
        affect_cap INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS custom_metrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        formula TEXT NOT NULL,
        format TEXT DEFAULT 'number',
        decimals INTEGER DEFAULT 2,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS bot_ips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_or_cidr TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS bot_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        signature TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        level TEXT DEFAULT 'INFO',
        message TEXT NOT NULL,
        context TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        resource TEXT,
        resource_id TEXT,
        context TEXT,
        ip TEXT,
        user_agent TEXT,
        status_code INTEGER DEFAULT 200,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS s2s_postbacks_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversion_id INTEGER,
        url TEXT NOT NULL,
        status_code INTEGER,
        response TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversion_id) REFERENCES conversions(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS telegram_bot_chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL UNIQUE,
        username TEXT,
        first_name TEXT,
        language TEXT DEFAULT 'ru',
        notify_conversions INTEGER DEFAULT 1,
        notify_daily INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS campaign_pixels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        pixel_id TEXT NOT NULL,
        token TEXT,
        events TEXT DEFAULT 'PageView,Lead',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS app_configs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER,
        name TEXT NOT NULL,
        config_key TEXT NOT NULL UNIQUE,
        config_json TEXT NOT NULL DEFAULT '{}',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS schema_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version INTEGER NOT NULL UNIQUE,
        description TEXT,
        status TEXT DEFAULT 'pending',
        executed_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS aggregator_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        affiliate_network_id INTEGER,
        name TEXT NOT NULL,
        engine TEXT NOT NULL DEFAULT 'generic',
        auth_type TEXT DEFAULT 'api_key',
        credentials_json TEXT,
        base_url TEXT,
        deal_type TEXT DEFAULT 'cpa',
        baseline REAL DEFAULT 0,
        click_id_param TEXT DEFAULT 'sub_id',
        field_mapping_json TEXT,
        sync_interval_hours INTEGER DEFAULT 2,
        last_sync_at DATETIME,
        last_sync_status TEXT,
        last_sync_error TEXT,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (affiliate_network_id) REFERENCES affiliate_networks(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS revenue_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        connection_id INTEGER NOT NULL,
        external_id TEXT,
        click_id TEXT,
        player_id TEXT,
        event_type TEXT DEFAULT 'ftd',
        amount REAL DEFAULT 0.00,
        currency TEXT DEFAULT 'USD',
        country TEXT,
        brand TEXT,
        sub_id TEXT,
        event_date DATE,
        raw_json TEXT,
        is_matched INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (connection_id) REFERENCES aggregator_connections(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS aggregator_sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        connection_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        records_fetched INTEGER DEFAULT 0,
        records_matched INTEGER DEFAULT 0,
        records_new INTEGER DEFAULT 0,
        error_message TEXT,
        duration_ms INTEGER DEFAULT 0,
        date_from DATE,
        date_to DATE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (connection_id) REFERENCES aggregator_connections(id) ON DELETE CASCADE
    );

    ";

    $pdo->exec($init_sql);

    // Migrations for existing tables gracefully
    try {
        $pdo->exec("ALTER TABLE domains ADD COLUMN group_id INTEGER");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE domains ADD COLUMN is_noindex INTEGER DEFAULT 0");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE domains ADD COLUMN https_only INTEGER DEFAULT 0");
    }
    catch (\Exception $e) {
    }

    try {
        $pdo->exec("ALTER TABLE offers ADD COLUMN is_archived INTEGER DEFAULT 0");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE offers ADD COLUMN archived_at DATETIME");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE affiliate_networks ADD COLUMN is_archived INTEGER DEFAULT 0");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE affiliate_networks ADD COLUMN archived_at DATETIME");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN is_archived INTEGER DEFAULT 0");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN archived_at DATETIME");
    }
    catch (\Exception $e) {
    }

    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN language TEXT DEFAULT 'ru'");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN timezone TEXT DEFAULT 'Europe/Moscow'");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN first_day_of_week INTEGER DEFAULT 1");
    }
    catch (\Exception $e) {
    }

    // Clicks table backward-compatible migrations (older installs may miss these columns)
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN source_id INTEGER");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN parameters_json TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN country_code TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN region TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN city TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN latitude REAL");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN longitude REAL");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN zipcode TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN timezone TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN os TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN browser TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN language TEXT");
    }
    catch (\Exception $e) {
    }
    try {
        $pdo->exec("ALTER TABLE clicks ADD COLUMN accept_language_raw TEXT");
    }
    catch (\Exception $e) {
    }

    // Insert default geo profile templates
    $geoTemplates = [
        ['USA and Canada', ['US', 'CA']],
        ['West Europe', ['GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY']],
        ['Europe', ['AL', 'GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI']],
        ['exUSSR', ['AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG', 'LV', 'LT', 'MD', 'RU', 'TJ', 'TM', 'UA', 'UZ']],
        ['English-Speaking', ['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB']],
        ['German-Speaking', ['AT', 'CH', 'LI', 'LU', 'DE']],
        ['French-Speaking', ['FR', 'MC', 'LU', 'CD', 'MG', 'CI', 'CM', 'BF', 'NE', 'SN', 'ML', 'BE']],
        ['Portuguese-Speaking', ['AO', 'BR', 'PT', 'CV', 'GW', 'MZ', 'ST', 'GQ', 'MU']],
        ['Spanish-Speaking', ['CO', 'ES', 'AR', 'MX', 'VE', 'PE', 'CL', 'EC', 'GT', 'CU', 'DO', 'HN', 'BO', 'SV', 'NI', 'PY', 'CR', 'UY', 'PA', 'GQ']],
        ['Italian-Speaking', ['IT', 'CH', 'SM', 'VA', 'MT', 'HR', 'SI']],
        ['North America', ['AI', 'AG', 'AW', 'BS', 'BB', 'BZ', 'BM', 'VI', 'CA', 'KY', 'CR', 'CU', 'DO', 'SV', 'GL', 'GD', 'GP', 'GT', 'HT', 'HN', 'JM', 'MQ', 'MX', 'MS', 'NL', 'NI', 'PA', 'PR', 'KN', 'LC', 'PM', 'VC', 'TT', 'TC', 'US']],
        ['USA, Canada and Europe', ['US', 'CA', 'AL', 'GB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'IE', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI']],
        ['English-Speaking and West Europe', ['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY']],
        ['English-Speaking and Europe', ['US', 'GB', 'CA', 'AU', 'NZ', 'IE', 'ZA', 'SG', 'JM', 'TT', 'GY', 'BB', 'AL', 'DE', 'FR', 'IT', 'AT', 'CH', 'ES', 'NL', 'BE', 'DK', 'SE', 'NO', 'PT', 'FI', 'IS', 'LI', 'LU', 'MC', 'AD', 'GI', 'GR', 'MT', 'SM', 'VA', 'FO', 'CY', 'BY', 'BA', 'BG', 'HR', 'CZ', 'EE', 'HU', 'LV', 'LT', 'MK', 'MD', 'ME', 'PL', 'RO', 'RS', 'SK', 'SI']],
        // Asia
        ['Asia', ['AF', 'BD', 'BT', 'BN', 'KH', 'CN', 'IN', 'ID', 'JP', 'KZ', 'KP', 'KR', 'KG', 'LA', 'MY', 'MV', 'MN', 'MM', 'NP', 'PK', 'PH', 'SG', 'LK', 'TW', 'TJ', 'TH', 'TL', 'TM', 'UZ', 'VN']],
        ['East Asia', ['CN', 'JP', 'KP', 'KR', 'TW', 'MN']],
        ['Southeast Asia', ['BN', 'KH', 'ID', 'LA', 'MY', 'MM', 'PH', 'SG', 'TH', 'TL', 'VN']],
        ['South Asia', ['AF', 'BD', 'BT', 'IN', 'MV', 'NP', 'PK', 'LK']],
        ['Central Asia', ['KZ', 'KG', 'TJ', 'TM', 'UZ']],
        ['Middle East', ['BH', 'CY', 'EG', 'IR', 'IQ', 'IL', 'JO', 'KW', 'LB', 'OM', 'PS', 'QA', 'SA', 'SY', 'TR', 'AE', 'YE']],
        ['Gulf Countries', ['BH', 'KW', 'OM', 'QA', 'SA', 'AE']],
        // Latin America
        ['Latin America', ['AR', 'BO', 'BR', 'CL', 'CO', 'CR', 'CU', 'DO', 'EC', 'SV', 'GT', 'HT', 'HN', 'MX', 'NI', 'PA', 'PY', 'PE', 'PR', 'UY', 'VE']],
        ['South America', ['AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'GY', 'PY', 'PE', 'SR', 'UY', 'VE']],
        ['Central America', ['BZ', 'CR', 'SV', 'GT', 'HN', 'NI', 'PA']],
        ['Caribbean', ['AI', 'AG', 'AW', 'BS', 'BB', 'BM', 'VG', 'KY', 'CU', 'DM', 'DO', 'GD', 'GP', 'HT', 'JM', 'MQ', 'MS', 'PR', 'KN', 'LC', 'VC', 'TT', 'TC', 'VI']],
        // Africa
        ['Africa', ['DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CV', 'CM', 'CF', 'TD', 'KM', 'CG', 'CD', 'CI', 'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE', 'LS', 'LR', 'LY', 'MG', 'MW', 'ML', 'MR', 'MU', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RW', 'ST', 'SN', 'SC', 'SL', 'SO', 'ZA', 'SS', 'SD', 'TZ', 'TG', 'TN', 'UG', 'ZM', 'ZW']],
        ['North Africa', ['DZ', 'EG', 'LY', 'MA', 'TN', 'EH']],
        ['West Africa', ['BJ', 'BF', 'CV', 'CI', 'GM', 'GH', 'GN', 'GW', 'LR', 'ML', 'MR', 'NE', 'NG', 'SN', 'SL', 'TG']],
        ['East Africa', ['BI', 'KM', 'DJ', 'ER', 'ET', 'KE', 'MG', 'MU', 'MZ', 'RW', 'SC', 'SO', 'TZ', 'UG', 'ZM', 'ZW']],
        ['Southern Africa', ['BW', 'SZ', 'LS', 'NA', 'ZA', 'MZ', 'ZW', 'AO']],
        ['Central Africa', ['AO', 'CM', 'CF', 'TD', 'CG', 'CD', 'GQ', 'GA', 'ST']],
        // Oceania
        ['Oceania', ['AU', 'NZ', 'PG', 'FJ', 'NC', 'PF', 'WS', 'TO', 'VU', 'SB', 'KI', 'TV', 'FM', 'MH', 'PW', 'NR', 'CK']],
    ];

    $stmtGeo = $pdo->prepare("INSERT OR IGNORE INTO geo_profiles (name, countries, is_template) VALUES (?, ?, 1)");
    foreach ($geoTemplates as $tpl) {
        $stmtGeo->execute([$tpl[0], json_encode($tpl[1])]);
    }

    // Insert default settings if not exist
    $defaultSettings = [
        ['postback_key', 'fd12e72'],
        ['currency', 'USD'],
        ['postback_aliases', json_encode(['clickid' => 'subid', 'transaction_id' => 'tid', 'revenue' => 'payout', 'profit' => 'payout', 'type' => 'status'])],
        ['stats_enabled', '1'],
        ['stats_retention_days', '256'],
        ['audit_retention_days', '30'],
        ['landing_token_ttl', '3600'],
        // PHP landings execute uploaded code in the web root. Off unless an admin
        // deliberately turns it on, and capped so a hung landing cannot occupy a
        // PHP-FPM worker indefinitely.
        ['allow_php_landings', '0'],
        ['php_landing_timeout', '3'],
        ['archive_retention_days', '30'],
        ['report_display', 'table'],
        ['report_date_type', 'click'],
        ['landing_path', 'landings/'],
        ['s2s_timeout', '10'],
        ['auto_save_campaigns', '0'],
        ['admin_ip_access', '0'],
        ['use_cookies', '1'],
        ['allow_php_in_landings', '0'],
        ['ignore_prefetch', '1'],
        // Global Bot ISP blacklist for cloak streams (Settings → Bots). Matched
        // as whole words against the visitor's ISP+ASN string, so keep entries
        // provider-specific — generic words would hit residential ISPs too.
        ['bot_isp_list', 'facebook,meta,amazon,aws,amazon web services,google,googlebot,google cloud,google fiber,google proxy,digital ocean,digitalocean,hetzner,netstack,beget,kaspersky,microsoft,bingbot,azure,ovh,cloudflare,university of california,terrahost,web hosted group,zscaler,linode,vultr,centurylink,level3,qwarta,host europe,hostinger'],
        ['global_macros', '[]'],
        ['privacy_enabled', '1'],
        ['telegram_bot_token', ''],
        ['telegram_webhook_set', '0'],
        ['telegram_notify_conversions', '1'],
        ['telegram_daily_time', '21:00'],
        ['recaptcha_v2_site_key', ''],
        ['recaptcha_v2_secret_key', ''],
        ['recaptcha_v3_site_key', ''],
        ['recaptcha_v3_secret_key', ''],
        ['recaptcha_v3_threshold', '0.5'],
        ['turnstile_site_key', ''],
        ['turnstile_secret_key', '']
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaultSettings as $s) {
        $stmt->execute($s);
    }

    // ---- v2: store original Keitaro IDs for easier migration/debugging ----
    if ($schemaVersion < 2) {
        $alters = [
            "ALTER TABLE domains ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE offers ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE affiliate_networks ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE campaigns ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE streams ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE campaign_postbacks ADD COLUMN keitaro_id INTEGER",
        ];
        foreach ($alters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Ignore on existing installs (column already exists).
            }
        }

        $indexes = [
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_domains_keitaro_id ON domains(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_offers_keitaro_id ON offers(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_affiliate_networks_keitaro_id ON affiliate_networks(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_campaigns_keitaro_id ON campaigns(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_streams_keitaro_id ON streams(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_campaign_postbacks_keitaro_id ON campaign_postbacks(keitaro_id)",
        ];
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    // ---- v3: extend keitaro_id coverage for full migrations ----
    if ($schemaVersion < 3) {
        $alters = [
            "ALTER TABLE landings ADD COLUMN keitaro_id INTEGER",
            "ALTER TABLE traffic_sources ADD COLUMN keitaro_id INTEGER",
        ];
        foreach ($alters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Ignore if already exists.
            }
        }

        $indexes = [
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_landings_keitaro_id ON landings(keitaro_id)",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_traffic_sources_keitaro_id ON traffic_sources(keitaro_id)",
        ];
        foreach ($indexes as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    // ---- v4: add token for click API integration ----
    if ($schemaVersion < 4) {
        try {
            $pdo->exec("ALTER TABLE campaigns ADD COLUMN token TEXT");
        } catch (Throwable $e) {
            // Ignore if already exists.
        }
    }

    // Migration 5: Add DNS caching columns to domains table and performance indexes
    if ($schemaVersion < 5) {
        try {
            $pdo->exec("ALTER TABLE domains ADD COLUMN dns_status TEXT");
            $pdo->exec("ALTER TABLE domains ADD COLUMN dns_ip TEXT");
            $pdo->exec("ALTER TABLE domains ADD COLUMN dns_checked_at DATETIME");
            // Create index for faster lookups
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_domains_dns_status ON domains(dns_status)");

            // Performance indexes for affiliate_networks query optimization
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offers_affiliate_network_id ON offers(affiliate_network_id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offers_is_archived ON offers(is_archived)");
            // Composite index for the COUNT query in affiliate_networks endpoint
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offers_network_archived ON offers(affiliate_network_id, is_archived)");

            // Indexes for affiliate_networks table (used in WHERE and ORDER BY)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_affiliate_networks_is_archived ON affiliate_networks(is_archived)");

            // Index for campaigns table (used in various queries)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaigns_is_archived ON campaigns(is_archived)");

            // CRITICAL: Index for streams.campaign_id - used in get_campaign!
            // Without this, loading campaigns with many streams is very slow
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_streams_campaign_id ON streams(campaign_id)");

            // Index for campaign_postbacks
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_postbacks_campaign_id ON campaign_postbacks(campaign_id)");
        } catch (Throwable $e) {
            // Ignore if columns/indexes already exist.
        }
    }

    // Migration 6: Add critical performance indexes for campaign loading
    if ($schemaVersion < 6) {
        try {
            // CRITICAL: Index for streams.campaign_id - used in get_campaign!
            // Without this, loading campaigns with many streams is VERY slow (full table scan)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_streams_campaign_id ON streams(campaign_id)");

            // Index for campaign_postbacks - also used in get_campaign
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_postbacks_campaign_id ON campaign_postbacks(campaign_id)");
        } catch (Throwable $e) {
            // Ignore if indexes already exist.
        }
    }

    // Migration 7: Add SSL installation status tracking for domains
    if ($schemaVersion < 7) {
        try {
            $pdo->exec("ALTER TABLE domains ADD COLUMN ssl_status TEXT DEFAULT 'none'");
            $pdo->exec("ALTER TABLE domains ADD COLUMN ssl_error TEXT");

            // Mark existing HTTPS domains as having SSL installed
            $pdo->exec("UPDATE domains SET ssl_status = 'installed' WHERE https_only = 1");
        } catch (Throwable $e) {
            // Ignore if columns already exist.
        }
    }

            // Migration 8: Add URL checking fields to traffic_sources
            if ($schemaVersion < 8) {
                try {
                    $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN url TEXT");
                    $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN http_status TEXT DEFAULT 'unknown'");
                    $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN last_checked DATETIME");
                    $pdo->exec("ALTER TABLE traffic_sources ADD COLUMN status_message TEXT");
                    // Index for faster lookups of sources with URLs
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_traffic_sources_http_status ON traffic_sources(http_status)");
                } catch (Throwable $e) {
                    // Ignore if columns already exist.
                }
            }

            // Migration 9: allow clicks.offer_id to be NULL so a stream with a
            // landing and no offer ("landing only") can log clicks without
            // violating the offers(id) foreign key (which previously caused a 500).
            if ($schemaVersion < 9) {
                try {
                    $offerCol = null;
                    foreach ($pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                        if ($c['name'] === 'offer_id') {
                            $offerCol = $c;
                            break;
                        }
                    }
                    // Only rebuild if offer_id is still NOT NULL.
                    if ($offerCol && (int) $offerCol['notnull'] === 1) {
                        $createSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='clicks'")->fetchColumn();
                        if (is_string($createSql) && $createSql !== '') {
                            // Exact column list to copy data faithfully.
                            $colNames = [];
                            foreach ($pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                                $colNames[] = '"' . $c['name'] . '"';
                            }
                            $colList = implode(', ', $colNames);

                            // Preserve user-defined indexes to recreate after rebuild.
                            $idxSqls = $pdo->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='clicks' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

                            // Clone CREATE, rename table, drop NOT NULL on offer_id.
                            $newSql = preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?"?clicks"?/i', 'CREATE TABLE clicks_new', $createSql, 1);
                            $newSql = preg_replace('/(\boffer_id\b\s+INTEGER)\s+NOT\s+NULL/i', '$1', $newSql, 1);

                            if ($newSql && strpos($newSql, 'clicks_new') !== false) {
                                $pdo->exec("PRAGMA foreign_keys = OFF");
                                $pdo->exec("BEGIN");
                                $pdo->exec($newSql);
                                $pdo->exec("INSERT INTO clicks_new ($colList) SELECT $colList FROM clicks");
                                $pdo->exec("DROP TABLE clicks");
                                $pdo->exec("ALTER TABLE clicks_new RENAME TO clicks");
                                $pdo->exec("COMMIT");
                                $pdo->exec("PRAGMA foreign_keys = ON");

                                foreach ($idxSqls as $idxSql) {
                                    try {
                                        $pdo->exec($idxSql);
                                    } catch (\Throwable $e) {
                                        // ignore index recreation errors
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        try {
                            $pdo->exec("ROLLBACK");
                        } catch (\Throwable $e2) {
                        }
                    }
                    try {
                        $pdo->exec("PRAGMA foreign_keys = ON");
                    } catch (\Throwable $e3) {
                    }
                    // On failure the schema stays as-is; index.php still guards the
                    // INSERT so visitors never get a 500.
                }
            }

            // Migration 10: Bot Challenge — per-campaign human verification settings
            if ($schemaVersion < 10) {
                try {
                    $pdo->exec("ALTER TABLE campaigns ADD COLUMN challenge_type TEXT DEFAULT 'none'");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
                try {
                    $pdo->exec("ALTER TABLE campaigns ADD COLUMN challenge_custom_code TEXT");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
            }

            // Migration 11: Durable S2S postback queue, cost import, OAuth token store.
            if ($schemaVersion < 11) {
                // s2s_postbacks_log becomes the retry queue: add attempt tracking.
                $s2sCols = [
                    'attempts INTEGER DEFAULT 0',
                    'next_retry_at DATETIME',
                    "status TEXT DEFAULT 'delivered'",
                    'last_error TEXT',
                    'http_code INTEGER',
                    "method TEXT DEFAULT 'GET'",
                    'postback_id INTEGER',
                ];
                foreach ($s2sCols as $colDef) {
                    try {
                        $pdo->exec('ALTER TABLE s2s_postbacks_log ADD COLUMN ' . $colDef);
                    } catch (\Throwable $e) {
                        // Ignore if already exists.
                    }
                }
                // Queue index: pending rows due for retry. CREATE INDEX is idempotent
                // enough for our purposes but we wrap to avoid a hard failure on re-run.
                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_s2s_postbacks_queue ON s2s_postbacks_log(status, next_retry_at)");
                } catch (\Throwable $e) {
                }

                // cost_records — imported spend from traffic sources (FB / Google Ads / ...),
                // parallel to revenue_records. Joined back to clicks via source_campaign_id / ad_id.
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS cost_records (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        connection_id INTEGER NOT NULL,
                        external_id TEXT,
                        source_campaign_id TEXT,
                        ad_id TEXT,
                        adset_id TEXT,
                        amount REAL DEFAULT 0.00,
                        currency TEXT DEFAULT 'USD',
                        click_date DATE,
                        raw_json TEXT,
                        is_matched INTEGER DEFAULT 0,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (connection_id) REFERENCES aggregator_connections(id) ON DELETE CASCADE
                    )");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cost_records_conn ON cost_records(connection_id, external_id)");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cost_records_date ON cost_records(click_date)");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }

                // oauth_tokens — store for future OAuth2 flows (FB / Google Ads). Today
                // the engines read tokens from aggregator_connections.credentials_json; this
                // table is the forward-compatible home for access/refresh token lifecycles.
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_tokens (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        provider TEXT NOT NULL,
                        connection_id INTEGER,
                        access_token TEXT,
                        refresh_token TEXT,
                        expires_at DATETIME,
                        scope TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (connection_id) REFERENCES aggregator_connections(id) ON DELETE CASCADE
                    )");
                    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_oauth_tokens_provider_conn ON oauth_tokens(provider, connection_id)");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
            }

            if ($schemaVersion < 12) {
                // Migration 12: the queue worker needs to know when a row was last touched
                // so it can reclaim entries left in 'in_flight' by a crashed worker.
                try {
                    $pdo->exec("ALTER TABLE s2s_postbacks_log ADD COLUMN updated_at DATETIME");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
                try {
                    $pdo->exec("UPDATE s2s_postbacks_log SET updated_at = created_at WHERE updated_at IS NULL");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_s2s_postbacks_inflight ON s2s_postbacks_log(status, updated_at)");
                } catch (\Throwable $e) {
                    // Non-critical.
                }
            }

            if ($schemaVersion < 13) {
                // Migration 13: landings gain an explicit action type. Until now the
                // payload alone decided what happened, which meant a landing could
                // only ever render HTML — 404, plain text, "do nothing" and handing
                // the visitor to another campaign had nowhere to live.
                try {
                    $pdo->exec("ALTER TABLE landings ADD COLUMN action_type TEXT DEFAULT ''");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
                try {
                    // Existing action landings kept working by echoing their payload
                    // as HTML, so that is what they are, and an empty one did nothing.
                    $pdo->exec(
                        "UPDATE landings SET action_type = CASE
                            WHEN action_payload IS NULL OR action_payload = '' THEN 'do_nothing'
                            ELSE 'show_html' END
                         WHERE type = 'action' AND (action_type IS NULL OR action_type = '')"
                    );
                } catch (\Throwable $e) {
                    // Non-critical: an empty action_type falls back to the same behaviour.
                }

                // A stream can now decide when the offer is picked. 'before' keeps
                // the existing behaviour — the offer is chosen while the landing is
                // being served — so every existing stream keeps working untouched.
                try {
                    $pdo->exec("ALTER TABLE streams ADD COLUMN offer_selection TEXT DEFAULT 'before'");
                } catch (\Throwable $e) {
                    // Ignore if already exists.
                }
                try {
                    $pdo->exec("UPDATE streams SET offer_selection = 'before' WHERE offer_selection IS NULL OR offer_selection = ''");
                } catch (\Throwable $e) {
                    // Non-critical: an empty value is read as 'before' anyway.
                }
            }

            if ($schemaVersion < 14) {
                // Migration 14: the admin panel can be moved off /admin.php.
                // Empty means "stay at /admin.php", so every existing install
                // keeps the URL its users have bookmarked.
                try {
                    $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('admin_path', '')");
                } catch (\Throwable $e) {
                    // Non-critical: an absent row reads as empty anyway.
                }
            }

            if ($schemaVersion < 15) {
                // Migration 15: landings gain a slug (so files live in
                // landings/<slug>/ instead of landings/<id>/, matching Keitaro's
                // /lander/<name>) and a redirect_type (so a redirect landing can
                // pick HTTP 302 / JS / meta-refresh like an offer can).
                try {
                    $pdo->exec("ALTER TABLE landings ADD COLUMN slug TEXT DEFAULT ''");
                } catch (\Throwable $e) {
                    // Column may already exist on a half-migrated DB.
                }
                try {
                    $pdo->exec("ALTER TABLE landings ADD COLUMN redirect_type TEXT DEFAULT 'redirect'");
                } catch (\Throwable $e) {
                }

                // Backfill slugs for existing local landings that have none. The
                // folder for these still resolves by id as a fallback, so an empty
                // slug is safe — but generating one lets every landing move to the
                // /lander/<slug> layout on its next edit. Idempotent: only fills
                // rows where slug = ''.
                try {
                    $rows = $pdo->query("SELECT id, name, slug FROM landings WHERE slug IS NULL OR slug = ''")->fetchAll(PDO::FETCH_ASSOC);
                    $used = [];
                    foreach ($rows as $row) {
                        $base = orbitraSlugify($row['name'] ?? ('landing-' . $row['id']));
                        if ($base === '') {
                            $base = 'landing-' . $row['id'];
                        }
                        $slug = $base;
                        $n = 2;
                        while (isset($used[$slug]) || $pdo->query("SELECT 1 FROM landings WHERE slug = " . $pdo->quote($slug) . " LIMIT 1")->fetchColumn()) {
                            $slug = $base . '-' . $n++;
                        }
                        $used[$slug] = true;
                        $pdo->prepare("UPDATE landings SET slug = ? WHERE id = ?")->execute([$slug, $row['id']]);
                    }
                } catch (\Throwable $e) {
                    // Non-critical: an empty slug resolves by id.
                }
            }

            if ($schemaVersion < 16) {
                // Migration 16: certificate issuance retries instead of giving up
                // after one attempt, so it needs to remember how many times it has
                // tried and when — without that there is no way to space attempts
                // and Let's Encrypt's five-failures-per-hour limit gets burned.
                try {
                    $pdo->exec("ALTER TABLE domains ADD COLUMN ssl_attempts INTEGER DEFAULT 0");
                } catch (\Throwable $e) {
                    // Column may already exist on a half-migrated DB.
                }
                try {
                    $pdo->exec("ALTER TABLE domains ADD COLUMN ssl_last_attempt TEXT");
                } catch (\Throwable $e) {
                }
                // A domain left in 'failed' by the old one-shot installer has most
                // likely just never been retried. Put those back in the queue so
                // the worker picks them up on its next run.
                try {
                    $pdo->exec("UPDATE domains SET ssl_status = 'pending', ssl_attempts = 0 WHERE ssl_status = 'failed'");
                } catch (\Throwable $e) {
                    // Non-critical: the worker re-evaluates every domain anyway.
                }
            }

            if ($schemaVersion < 17) {
                // Migration 17: Facebook Conversions API + cost-import correctness.
                //
                // CAPI events are delivered by the same worker as outbound S2S
                // postbacks, so a queue row has to be able to carry a JSON body
                // (and its own proxy) instead of only a URL with a query string.
                $alters = [
                    "ALTER TABLE s2s_postbacks_log ADD COLUMN payload_json TEXT",
                    "ALTER TABLE s2s_postbacks_log ADD COLUMN content_type TEXT",
                    "ALTER TABLE s2s_postbacks_log ADD COLUMN proxy_url TEXT",
                    // Pixel rows gain the server-side half: status→event mapping,
                    // Meta's test event code, API version and an optional egress proxy.
                    "ALTER TABLE campaign_pixels ADD COLUMN mapping_json TEXT",
                    "ALTER TABLE campaign_pixels ADD COLUMN test_event_code TEXT",
                    "ALTER TABLE campaign_pixels ADD COLUMN proxy_url TEXT",
                    "ALTER TABLE campaign_pixels ADD COLUMN api_version TEXT",
                ];
                foreach ($alters as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (\Throwable $e) {
                        // Column already present on a half-migrated DB.
                    }
                }

                // Cost import resolves spend to clicks by ad/adset/campaign id for a
                // given day. Without an index on the date that is a full scan of
                // clicks per cost record, which at real traffic volume turns one sync
                // into a minutes-long write lock.
                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_created_date ON clicks(date(created_at))");
                } catch (\Throwable $e) {
                    // Expression indexes need SQLite 3.9+; matching still works without it.
                }
                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cost_records_conn_ext ON cost_records(connection_id, external_id)");
                } catch (\Throwable $e) {
                }
                try {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_s2s_queue_pending ON s2s_postbacks_log(status, next_retry_at)");
                } catch (\Throwable $e) {
                }
            }

            if ($schemaVersion < 18) {
                // Migration 18: campaign-level URL parameters. The editor's
                // "Параметры" tab used to live only in browser memory, so the
                // macros a campaign link was built from were lost on reopen.
                try {
                    $pdo->exec("ALTER TABLE campaigns ADD COLUMN parameters_json TEXT");
                } catch (\Throwable $e) {
                    // Column already present on a half-migrated DB.
                }
            }

            if ($schemaVersion < 19) {
                // Migration 19: report indexes.
                //
                // Every report metric is derived from conversions joined on click id,
                // and the campaigns list joins clicks by campaign. Neither had an
                // index, so a dashboard load scanned both tables end to end for each
                // campaign. These three turn that into lookups.
                foreach ([
                    "CREATE INDEX IF NOT EXISTS idx_conversions_click ON conversions(click_id)",
                    "CREATE INDEX IF NOT EXISTS idx_conversions_click_status ON conversions(click_id, status)",
                    "CREATE INDEX IF NOT EXISTS idx_clicks_campaign_created ON clicks(campaign_id, created_at)",
                    "CREATE INDEX IF NOT EXISTS idx_revenue_records_click ON revenue_records(click_id)",
                ] as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (\Throwable $e) {
                        // Table absent on an older install; the reports fall back to 0.
                    }
                }
            }

            if ($schemaVersion < 20) {
                // Migration 20: honest report-metric flags on clicks.
                //
                // The Keitaro-parity metrics (Bots, Bot %, Proxies, unique clicks by
                // campaign/flow/global, Visitors, Time since LP click) used to render
                // plausible zeros: nothing recorded them. These columns are filled by
                // core/ClickFlags.php right after every click INSERT. Old rows keep the
                // defaults — bots/proxies 0 (unknowable retroactively) and uniqueness 1
                // (period-unique semantics for historical data).
                $alters = [
                    "ALTER TABLE clicks ADD COLUMN is_bot INTEGER DEFAULT 0",
                    "ALTER TABLE clicks ADD COLUMN is_proxy INTEGER DEFAULT 0",
                    "ALTER TABLE clicks ADD COLUMN uniq_campaign INTEGER DEFAULT 1",
                    "ALTER TABLE clicks ADD COLUMN uniq_stream INTEGER DEFAULT 1",
                    "ALTER TABLE clicks ADD COLUMN uniq_global INTEGER DEFAULT 1",
                    "ALTER TABLE clicks ADD COLUMN landing_at TEXT",
                    "ALTER TABLE clicks ADD COLUMN offer_at TEXT",
                ];
                foreach ($alters as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (\Throwable $e) {
                        // Column already present on a half-migrated DB.
                    }
                }
                try {
                    // The uniqueness lookups probe by ip within the window.
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clicks_ip_created ON clicks(ip, created_at)");
                } catch (\Throwable $e) {
                }
            }

            if ($schemaVersion < 21) {
                // Migration 21: per-stream filter combination logic (AND/OR).
                // Every existing stream keeps AND — the behavior it was built
                // with; OR is opt-in from the stream's FILTERS header.
                try {
                    $pdo->exec("ALTER TABLE streams ADD COLUMN filters_logic TEXT DEFAULT 'and'");
                } catch (\Throwable $e) {
                    // Column already present on a half-migrated DB.
                }
            }

            if ($schemaVersion < 22) {
                // Migration 22: per-pixel event_source_url for Meta CAPI.
                // Optional thank-you/checkout page URL sent with every event;
                // supports {campaign_url}/{landing_url}/{clickid} macros that
                // postback.php resolves against the converting click.
                try {
                    $pdo->exec("ALTER TABLE campaign_pixels ADD COLUMN event_source_url TEXT");
                } catch (\Throwable $e) {
                    // Column already present on a half-migrated DB.
                }
            }

            if ($schemaVersion < 23) {
                // Migration 23: dedicated domain groups + Keitaro-style domain
                // attributes (admin access, Cloudflare proxy, registrar/DNS
                // metadata, manual status).
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS domain_groups (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL UNIQUE,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )");
                } catch (\Throwable $e) {}

                // Domains used to share offer_groups for grouping. Carry the
                // group rows a domain actually references into the new table
                // under the same ids, so existing group_id values keep naming
                // the same group.
                try {
                    $pdo->exec("INSERT OR IGNORE INTO domain_groups (id, name, created_at)
                        SELECT og.id, og.name, COALESCE(og.created_at, datetime('now'))
                        FROM offer_groups og
                        WHERE og.id IN (SELECT DISTINCT group_id FROM domains WHERE group_id IS NOT NULL)");
                } catch (\Throwable $e) {}

                // admin_access defaults to 1: flipping existing rows to Deny
                // would lock the operator out of the panel on the very host
                // they are reading it from. Deny stays opt-in per domain.
                $alters = [
                    "ALTER TABLE domains ADD COLUMN admin_access INTEGER DEFAULT 1",
                    "ALTER TABLE domains ADD COLUMN cloudflare_proxy INTEGER DEFAULT 0",
                    "ALTER TABLE domains ADD COLUMN registrar TEXT DEFAULT ''",
                    "ALTER TABLE domains ADD COLUMN dns_provider TEXT DEFAULT ''",
                    "ALTER TABLE domains ADD COLUMN status TEXT DEFAULT 'OK'",
                ];
                foreach ($alters as $sql) {
                    try { $pdo->exec($sql); } catch (\Throwable $e) {}
                }

                // The declared FK on group_id still targets offer_groups, and
                // with foreign_keys=ON deleting an offer group with a matching
                // id would null out unrelated domain groups. Rebuild the table
                // with the FK pointing at domain_groups. Column lists are
                // intersected with what actually exists — installs differ
                // (keitaro_id and the dns_* columns arrived in later
                // migrations) — so a partial column set copies rather than
                // drops. Create-copy-drop-rename order: renaming the old table
                // first would rewrite the domain_id FKs that point at it.
                try {
                    $existingCols = array_map(
                        static fn($r) => (string) $r['name'],
                        $pdo->query("PRAGMA table_info(domains)")->fetchAll(PDO::FETCH_ASSOC)
                    );
                    $canonical = ['id','name','index_campaign_id','catch_404','group_id','is_noindex','https_only','ssl_status','ssl_error','ssl_attempts','ssl_last_attempt','created_at','dns_status','dns_ip','dns_checked_at','keitaro_id','admin_access','cloudflare_proxy','registrar','dns_provider','status'];
                    $common = array_values(array_intersect($existingCols, $canonical));
                    if ($common) {
                        $pdo->exec("PRAGMA foreign_keys = OFF");
                        $pdo->exec("DROP TABLE IF EXISTS domains_v23_tmp");
                        $pdo->exec("CREATE TABLE domains_v23_tmp (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            name TEXT NOT NULL UNIQUE,
                            index_campaign_id INTEGER,
                            catch_404 INTEGER DEFAULT 0,
                            group_id INTEGER,
                            is_noindex INTEGER DEFAULT 0,
                            https_only INTEGER DEFAULT 0,
                            ssl_status TEXT DEFAULT 'none',
                            ssl_error TEXT,
                            ssl_attempts INTEGER DEFAULT 0,
                            ssl_last_attempt TEXT,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            dns_status TEXT, dns_ip TEXT, dns_checked_at DATETIME,
                            keitaro_id INTEGER,
                            admin_access INTEGER DEFAULT 1,
                            cloudflare_proxy INTEGER DEFAULT 0,
                            registrar TEXT DEFAULT '',
                            dns_provider TEXT DEFAULT '',
                            status TEXT DEFAULT 'OK',
                            FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
                            FOREIGN KEY (group_id) REFERENCES domain_groups(id) ON DELETE SET NULL
                        )");
                        $colList = implode(', ', $common);
                        $pdo->exec("INSERT INTO domains_v23_tmp ($colList) SELECT $colList FROM domains");
                        $pdo->exec("DROP TABLE domains");
                        $pdo->exec("ALTER TABLE domains_v23_tmp RENAME TO domains");
                        $pdo->exec("PRAGMA foreign_keys = ON");
                    }
                } catch (\Throwable $e) {
                    // The ALTERs above already added the columns, so a failed
                    // rebuild leaves a fully working table with the legacy FK
                    // — only the automatic group cleanup on offer-group delete
                    // stays wrong. Put the original back if the swap died
                    // between DROP and RENAME.
                    try {
                        $hasDomains = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='domains'")->fetchColumn();
                        $hasTmp = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='domains_v23_tmp'")->fetchColumn();
                        if (!$hasDomains && $hasTmp) {
                            $pdo->exec("ALTER TABLE domains_v23_tmp RENAME TO domains");
                        } elseif ($hasTmp) {
                            $pdo->exec("DROP TABLE domains_v23_tmp");
                        }
                    } catch (\Throwable $e2) {}
                    try { $pdo->exec("PRAGMA foreign_keys = ON"); } catch (\Throwable $e2) {}
                }
            }

            if ($schemaVersion < 24) {
                // Migration 24: stream-level "Collect clicks". A stream with
                // collect_clicks=0 still serves its destination, but the visit
                // is never inserted into clicks — white-page fallbacks stop
                // polluting CR/CPA. Default 1 keeps every existing stream
                // counted exactly as before.
                try {
                    $pdo->exec("ALTER TABLE streams ADD COLUMN collect_clicks INTEGER DEFAULT 1");
                } catch (\Throwable $e) {
                    // Column already present on a half-migrated DB.
                }
            }

            if ($schemaVersion < 25) {
                // Migration 25: campaigns.state. The play/pause toggle writes
                // it and index.php refuses to serve disabled campaigns. It was
                // assumed to exist — landings and offers carry a state column,
                // campaigns never did, on any install.
                try {
                    $pdo->exec("ALTER TABLE campaigns ADD COLUMN state TEXT DEFAULT 'active'");
                } catch (\Throwable $e) {
                    // Column already present on a half-migrated DB.
                }
            }

            // Mark schema as up-to-date. This must be last.
            $pdo->exec("PRAGMA user_version = " . (int) $LATEST_SCHEMA_VERSION . ";");
            $schemaVersion = $LATEST_SCHEMA_VERSION;
        } finally {
            if (isset($fp) && is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    };

    $runMigrations();

    // Override hardcoded postback_key with the one from settings table for routers
    try {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'postback_key'");
        if ($stmt) {
            $db_key = $stmt->fetchColumn();
            if ($db_key) {
                $postback_key = $db_key;
            }
        }
    }
    catch (\Exception $e) {
    }

}
catch (\PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
