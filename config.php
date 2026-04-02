<?php
// config.php
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
    $LATEST_SCHEMA_VERSION = 8;

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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME
    );

    CREATE TABLE IF NOT EXISTS domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        index_campaign_id INTEGER,
        catch_404 INTEGER DEFAULT 0,
        group_id INTEGER,
        is_noindex INTEGER DEFAULT 0,
        https_only INTEGER DEFAULT 0,
        ssl_status TEXT DEFAULT 'none',                  -- 'none'|'pending'|'installing'|'installed'|'failed'
        ssl_error TEXT,                                   -- SSL installation error message
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES offer_groups(id) ON DELETE SET NULL
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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_archived INTEGER DEFAULT 0,
        archived_at DATETIME,
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
        schema_type TEXT DEFAULT 'redirect',
        action_payload TEXT,
        schema_custom_json TEXT,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS clicks (
        id TEXT PRIMARY KEY,
        campaign_id INTEGER NOT NULL,
        offer_id INTEGER NOT NULL,
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
        ['global_macros', '[]'],
        ['privacy_enabled', '1'],
        ['telegram_bot_token', ''],
        ['telegram_webhook_set', '0'],
        ['telegram_notify_conversions', '1'],
        ['telegram_daily_time', '21:00']
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
