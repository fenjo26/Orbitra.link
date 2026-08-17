-- Orbitra Tracker — Database Schema (SQLite)
--
-- REFERENCE ONLY. The authoritative schema lives in config.php: $init_sql creates the
-- base tables and the numbered migration blocks evolve them. Nothing loads this file.
-- It is generated from config.php so it stays truthful; regenerate it after changing
-- the schema rather than hand-editing it.
--
-- Schema version: 12 (PRAGMA user_version)

CREATE TABLE affiliate_network_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        offer_params_template TEXT,
        postback_url_template TEXT,
        icon TEXT
    );

CREATE TABLE affiliate_networks (
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

CREATE TABLE aggregator_connections (
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

CREATE TABLE aggregator_sync_logs (
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

CREATE TABLE app_configs (
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

CREATE TABLE audit_logs (
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

CREATE TABLE backorder_domains (
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

CREATE TABLE bot_ips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_or_cidr TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE bot_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        signature TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE campaign_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pixel_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        traffic_source TEXT NOT NULL DEFAULT 'facebook',
        niche TEXT DEFAULT 'General',
        name TEXT NOT NULL,
        pixel_id TEXT NOT NULL,
        token TEXT NOT NULL DEFAULT '',
        event_url TEXT,
        test_event_code TEXT,
        events TEXT DEFAULT 'PageView,Lead,Purchase',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE INDEX idx_pixel_profiles_traffic_source ON pixel_profiles(traffic_source);
CREATE INDEX idx_pixel_profiles_niche ON pixel_profiles(niche);

CREATE TABLE campaign_pixels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        pixel_profile_id INTEGER,
        type TEXT NOT NULL,
        pixel_id TEXT NOT NULL,
        token TEXT,
        events TEXT DEFAULT 'PageView,Lead',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (pixel_profile_id) REFERENCES pixel_profiles(id) ON DELETE CASCADE
    );

CREATE INDEX idx_campaign_pixels_profile ON campaign_pixels(pixel_profile_id);

CREATE TABLE campaign_postbacks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        method TEXT DEFAULT 'GET',
        statuses TEXT DEFAULT 'lead,sale,rejected',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    );

CREATE TABLE campaigns (
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
        archived_at DATETIME, challenge_type TEXT DEFAULT 'none', challenge_custom_code TEXT,
        FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES campaign_groups(id) ON DELETE SET NULL,
        FOREIGN KEY (source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
        state TEXT DEFAULT 'active',                      -- play/pause toggle: 'disabled' stops serving
    );

CREATE TABLE clicks (
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

CREATE TABLE conversion_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        status_values TEXT NOT NULL,
        next_statuses TEXT,
        record_conversion INTEGER DEFAULT 1,
        record_revenue INTEGER DEFAULT 1,
        send_postback INTEGER DEFAULT 1,
        affect_cap INTEGER DEFAULT 1,
        color TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE conversions (
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

CREATE TABLE cost_records (
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
                    );

CREATE TABLE custom_metrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        formula TEXT NOT NULL,
        format TEXT DEFAULT 'number',
        decimals INTEGER DEFAULT 2,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE domain_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        index_campaign_id INTEGER,
        catch_404 INTEGER DEFAULT 0,
        group_id INTEGER,
        is_noindex INTEGER DEFAULT 0,
        https_only INTEGER DEFAULT 0,
        ssl_status TEXT DEFAULT 'none',                  -- 'none'|'pending'|'waiting_dns'|'installing'|'installed'|'failed'|'cloudflare'
        ssl_error TEXT,                                   -- SSL installation error message
        ssl_attempts INTEGER DEFAULT 0,
        ssl_last_attempt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, dns_status TEXT, dns_ip TEXT, dns_checked_at DATETIME,
        keitaro_id INTEGER,
        admin_access INTEGER DEFAULT 1,                  -- 0: admin panel returns 404 on this domain
        cloudflare_proxy INTEGER DEFAULT 0,              -- 1: SSL comes from the CF edge, certbot is skipped
        registrar TEXT DEFAULT '',
        dns_provider TEXT DEFAULT '',
        status TEXT DEFAULT 'OK',                        -- 'OK'|'Active'|'Disabled'; Disabled serves 404 on the whole host
        FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
        FOREIGN KEY (group_id) REFERENCES domain_groups(id) ON DELETE SET NULL
    );

CREATE TABLE geo_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        countries TEXT NOT NULL,
        is_template INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE landing_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE landings (
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

CREATE TABLE oauth_tokens (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        provider TEXT NOT NULL,
                        connection_id INTEGER,
                        access_token TEXT,
                        refresh_token TEXT,
                        expires_at DATETIME,
                        scope TEXT,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (connection_id) REFERENCES aggregator_connections(id) ON DELETE CASCADE
                    );

CREATE TABLE offer_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE offers (
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

CREATE TABLE postback_logs (
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

CREATE TABLE revenue_records (
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

CREATE TABLE s2s_postbacks_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversion_id INTEGER,
        url TEXT NOT NULL,
        status_code INTEGER,
        response TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, attempts INTEGER DEFAULT 0, http_code INTEGER, last_error TEXT, method TEXT DEFAULT 'GET', next_retry_at DATETIME, postback_id INTEGER, status TEXT DEFAULT 'delivered', updated_at DATETIME, headers_json TEXT,
        FOREIGN KEY (conversion_id) REFERENCES conversions(id) ON DELETE SET NULL
    );

CREATE TABLE schema_migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version INTEGER NOT NULL UNIQUE,
        description TEXT,
        status TEXT DEFAULT 'pending',
        executed_at DATETIME
    );

CREATE TABLE settings (
        key TEXT PRIMARY KEY,
        value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE streams (
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
        collect_clicks INTEGER DEFAULT 1,                   -- 0: serve the stream without a clicks row
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
        FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE SET NULL
    );

CREATE TABLE system_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        level TEXT DEFAULT 'INFO',
        message TEXT NOT NULL,
        context TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

CREATE TABLE telegram_bot_chats (
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

CREATE TABLE traffic_source_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        postback_url TEXT,
        parameters_json TEXT,
        icon TEXT
    );

CREATE TABLE traffic_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        template TEXT,
        postback_url TEXT,
        postback_statuses TEXT DEFAULT 'lead,sale',
        parameters_json TEXT,
        notes TEXT,
        state TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    , is_archived INTEGER DEFAULT 0, archived_at DATETIME, url TEXT, http_status TEXT DEFAULT 'unknown', last_checked DATETIME, status_message TEXT);

CREATE TABLE user_api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        key_name TEXT NOT NULL,
        api_key TEXT NOT NULL UNIQUE,
        permissions TEXT DEFAULT 'read',
        last_used DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );

CREATE TABLE users (
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

-- Indexes
CREATE INDEX idx_affiliate_networks_is_archived ON affiliate_networks(is_archived);
CREATE INDEX idx_campaign_postbacks_campaign_id ON campaign_postbacks(campaign_id);
CREATE INDEX idx_campaigns_is_archived ON campaigns(is_archived);
CREATE INDEX idx_cost_records_conn ON cost_records(connection_id, external_id);
CREATE INDEX idx_conversions_click ON conversions(click_id);
CREATE INDEX idx_conversions_click_status ON conversions(click_id, status);
CREATE INDEX idx_clicks_campaign_created ON clicks(campaign_id, created_at);
CREATE INDEX idx_clicks_created_at ON clicks(created_at);
CREATE INDEX idx_revenue_records_click ON revenue_records(click_id);
CREATE INDEX idx_cost_records_date ON cost_records(click_date);
CREATE INDEX idx_domains_dns_status ON domains(dns_status);
CREATE UNIQUE INDEX idx_oauth_tokens_provider_conn ON oauth_tokens(provider, connection_id);
CREATE INDEX idx_offers_affiliate_network_id ON offers(affiliate_network_id);
CREATE INDEX idx_offers_is_archived ON offers(is_archived);
CREATE INDEX idx_offers_network_archived ON offers(affiliate_network_id, is_archived);
CREATE INDEX idx_s2s_postbacks_inflight ON s2s_postbacks_log(status, updated_at);
CREATE INDEX idx_s2s_postbacks_queue ON s2s_postbacks_log(status, next_retry_at);
CREATE INDEX idx_streams_campaign_id ON streams(campaign_id);
CREATE INDEX idx_traffic_sources_http_status ON traffic_sources(http_status);
