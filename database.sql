-- LTT Tracker Database Schema (SQLite)
-- This file documents the database structure used by config.php
-- The actual tables are created automatically in config.php

-- Affiliate Networks (Партнёрские сети)
-- Stores information about affiliate networks for postback handling
CREATE TABLE IF NOT EXISTS affiliate_networks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    postback_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_archived INTEGER DEFAULT 0,
    archived_at DATETIME
);

-- Offer Groups (Группы офферов)
-- Organizational grouping for offers
CREATE TABLE IF NOT EXISTS offer_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Offers (Офферы)
-- Stores offer configurations including payout settings and URLs
CREATE TABLE IF NOT EXISTS offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    group_id INTEGER,
    affiliate_network_id INTEGER,
    url TEXT,
    redirect_type TEXT DEFAULT 'redirect',        -- redirect, frame, local
    is_local INTEGER DEFAULT 0,                   -- 1 = local offer (ZIP upload)
    geo TEXT,                                     -- Country codes (e.g., RU, US, GB)
    payout_type TEXT DEFAULT 'cpa',               -- cpa, cpc
    payout_value REAL DEFAULT 0.00,
    payout_auto INTEGER DEFAULT 0,                -- 1 = auto from postback
    allow_rebills INTEGER DEFAULT 0,              -- 1 = allow rebills
    capping_limit INTEGER DEFAULT 0,              -- Daily conversion limit
    capping_timezone TEXT DEFAULT 'UTC',
    alt_offer_id INTEGER,                         -- Fallback offer when capping reached
    notes TEXT,
    values_json TEXT,                             -- JSON array for offer_value macros
    state TEXT DEFAULT 'active',                  -- active, archived
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_archived INTEGER DEFAULT 0,
    archived_at DATETIME,
    FOREIGN KEY (group_id) REFERENCES offer_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (affiliate_network_id) REFERENCES affiliate_networks(id) ON DELETE SET NULL,
    FOREIGN KEY (alt_offer_id) REFERENCES offers(id) ON DELETE SET NULL
);

-- Landing Groups (Группы лендингов)
CREATE TABLE IF NOT EXISTS landing_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Landings (Лендинги)
-- Stores landing page configurations
CREATE TABLE IF NOT EXISTS landings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    group_id INTEGER,
    type TEXT DEFAULT 'local',                    -- local, redirect, preload, action
    url TEXT,
    action_payload TEXT,
    state TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_archived INTEGER DEFAULT 0,
    archived_at DATETIME,
    FOREIGN KEY (group_id) REFERENCES landing_groups(id) ON DELETE SET NULL
);

-- Domains (Домены)
-- Tracks domains pointing to this tracker
CREATE TABLE IF NOT EXISTS domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    index_campaign_id INTEGER,                    -- Campaign for root requests
    catch_404 INTEGER DEFAULT 0,                  -- Catch 404 errors
    group_id INTEGER,                             -- Organizational grouping for domains
    is_noindex INTEGER DEFAULT 0,                 -- Add X-Robots-Tag: noindex
    https_only INTEGER DEFAULT 0,                 -- Force HTTPS redirects
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (index_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (group_id) REFERENCES offer_groups(id) ON DELETE SET NULL
);

-- Campaign Groups (Группы кампаний)
CREATE TABLE IF NOT EXISTS campaign_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Traffic Sources (Источники трафика)
CREATE TABLE IF NOT EXISTS traffic_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    postback_url TEXT,
    parameters_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_archived INTEGER DEFAULT 0,
    archived_at DATETIME
);

-- Campaigns (Кампании)
-- Main tracking campaigns with routing configuration
CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    alias TEXT NOT NULL UNIQUE,                   -- URL identifier (e.g., /r/my-campaign)
    domain_id INTEGER,
    group_id INTEGER,
    source_id INTEGER,
    cost_model TEXT DEFAULT 'CPC',                -- CPC, CPA, RevShare, Auto
    cost_value REAL DEFAULT 0.00,
    uniqueness_method TEXT DEFAULT 'IP',          -- IP, IP_UA, Cookies
    uniqueness_hours INTEGER DEFAULT 24,
    rotation_type TEXT DEFAULT 'weight',          -- weight, position
    catch_404_stream_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_archived INTEGER DEFAULT 0,
    archived_at DATETIME,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    FOREIGN KEY (group_id) REFERENCES campaign_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
);

-- Campaign Postbacks (S2S Postbacks)
-- External postback URLs to notify on conversions
CREATE TABLE IF NOT EXISTS campaign_postbacks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    url TEXT NOT NULL,
    method TEXT DEFAULT 'GET',                    -- GET, POST
    statuses TEXT DEFAULT 'lead,sale,rejected',   -- Trigger statuses
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

-- Streams (Потоки)
-- Routing rules within campaigns
CREATE TABLE IF NOT EXISTS streams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    offer_id INTEGER NOT NULL,
    weight INTEGER DEFAULT 100,                   -- For weighted rotation
    is_active INTEGER DEFAULT 1,
    type TEXT DEFAULT 'regular',                  -- intercepting, regular, fallback
    position INTEGER DEFAULT 0,                   -- For waterfall rotation
    filters_json TEXT,                            -- Filter conditions (geo, device, etc.)
    schema_type TEXT DEFAULT 'redirect',          -- redirect, landing_offer, action
    action_payload TEXT,                          -- Action to perform
    schema_custom_json TEXT,                      -- Landing + Offer split test config
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE
);

-- Clicks (Клики)
-- Records of all incoming clicks/visits
CREATE TABLE IF NOT EXISTS clicks (
    id TEXT PRIMARY KEY,                          -- UUID click_id
    campaign_id INTEGER NOT NULL,
    offer_id INTEGER NOT NULL,
    stream_id INTEGER,                            -- Matched stream
    source_id INTEGER,                            -- Traffic source
    landing_id INTEGER,                           -- Landing page ID if used
    ip TEXT NOT NULL,
    user_agent TEXT,
    referer TEXT,
    country TEXT,                                 -- Legacy country code field
    country_code TEXT,                            -- Normalized country code
    region TEXT,
    city TEXT,
    latitude REAL,
    longitude REAL,
    zipcode TEXT,
    timezone TEXT,
    device_type TEXT DEFAULT 'Unknown',           -- Desktop, Mobile, Tablet
    os TEXT,
    browser TEXT,
    language TEXT,                                -- Browser/device language from Accept-Language
    is_conversion INTEGER DEFAULT 0,
    revenue REAL DEFAULT 0.00,
    cost REAL DEFAULT 0.00,
    parameters_json TEXT,                         -- JSON dict of sub1-30, keyword, etc.
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES streams(id) ON DELETE SET NULL,
    FOREIGN KEY (source_id) REFERENCES traffic_sources(id) ON DELETE SET NULL
);

-- Conversions (Конверсии)
-- Records of conversions/postbacks received
CREATE TABLE IF NOT EXISTS conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    click_id TEXT NOT NULL,
    tid TEXT,                                     -- Transaction ID for rebills
    status TEXT NOT NULL,                         -- lead, sale, rejected, etc.
    original_status TEXT,                         -- Original status from network
    payout REAL DEFAULT 0.00,
    currency TEXT DEFAULT 'USD',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(click_id, tid),                        -- Prevent duplicate conversions
    FOREIGN KEY (click_id) REFERENCES clicks(id) ON DELETE CASCADE
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_clicks_campaign ON clicks(campaign_id);
CREATE INDEX IF NOT EXISTS idx_clicks_offer ON clicks(offer_id);
CREATE INDEX IF NOT EXISTS idx_clicks_created ON clicks(created_at);
CREATE INDEX IF NOT EXISTS idx_clicks_ip ON clicks(ip);
CREATE INDEX IF NOT EXISTS idx_conversions_click ON conversions(click_id);
CREATE INDEX IF NOT EXISTS idx_streams_campaign ON streams(campaign_id);

-- Conversion Types (Типы конверсий)
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

-- Custom Metrics (Пользовательские метрики)
CREATE TABLE IF NOT EXISTS custom_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    formula TEXT NOT NULL,
    format TEXT DEFAULT 'number',
    decimals INTEGER DEFAULT 2,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Bots IP List
CREATE TABLE IF NOT EXISTS bot_ips (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_or_cidr TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Bots Signatures (User Agent)
CREATE TABLE IF NOT EXISTS bot_signatures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    signature TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- System Logs (Системный лог)
CREATE TABLE IF NOT EXISTS system_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT DEFAULT 'INFO',
    message TEXT NOT NULL,
    context TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Audit Logs (Лог аудита)
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

-- S2S Postbacks Log (Отправленные Postbacks)
CREATE TABLE IF NOT EXISTS s2s_postbacks_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversion_id INTEGER,
    url TEXT NOT NULL,
    status_code INTEGER,
    response TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversion_id) REFERENCES conversions(id) ON DELETE SET NULL
);

-- Schema Migrations (Миграции базы данных)
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version INTEGER NOT NULL UNIQUE,
    description TEXT,
    status TEXT DEFAULT 'pending',
    executed_at DATETIME
);
