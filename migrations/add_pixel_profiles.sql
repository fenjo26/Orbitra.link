-- Orbitra uses SQLite in production. The application migration in config.php
-- applies this schema automatically as schema version 26.
CREATE TABLE IF NOT EXISTS pixel_profiles (
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

CREATE INDEX IF NOT EXISTS idx_pixel_profiles_traffic_source
    ON pixel_profiles(traffic_source);
CREATE INDEX IF NOT EXISTS idx_pixel_profiles_niche
    ON pixel_profiles(niche);
