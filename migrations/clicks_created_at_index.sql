-- Orbitra uses SQLite in production. The application migration in config.php
-- applies this index automatically as schema version 27.
--
-- Standalone date index on clicks: the composite (campaign_id, created_at)
-- index only serves campaign-scoped ranges; dashboard-wide date filters
-- constrain created_at alone and previously fell back to full scans.
CREATE INDEX IF NOT EXISTS idx_clicks_created_at ON clicks(created_at);
