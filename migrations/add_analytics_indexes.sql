-- Performance indexes for Analytics/Trends filter queries
-- These indexes significantly speed up queries that filter by country, device, browser, OS, etc.

-- Indexes for filter fields used in trends API
CREATE INDEX IF NOT EXISTS idx_clicks_country_code ON clicks(country_code);
CREATE INDEX IF NOT EXISTS idx_clicks_device_type ON clicks(device_type);
CREATE INDEX IF NOT EXISTS idx_clicks_browser ON clicks(browser);
CREATE INDEX IF NOT EXISTS idx_clicks_os ON clicks(os);
CREATE INDEX IF NOT EXISTS idx_clicks_ip ON clicks(ip);
CREATE INDEX IF NOT EXISTS idx_clicks_is_conversion ON clicks(is_conversion);
CREATE INDEX IF NOT EXISTS idx_clicks_offer_id ON clicks(offer_id);
CREATE INDEX IF NOT EXISTS idx_clicks_source_id ON clicks(source_id);
CREATE INDEX IF NOT EXISTS idx_clicks_stream_id ON clicks(stream_id);

-- Composite index for common query patterns (created_at + is_conversion)
CREATE INDEX IF NOT EXISTS idx_clicks_created_conversion ON clicks(created_at, is_conversion);
