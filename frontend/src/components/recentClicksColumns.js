// Columns of the dashboard "Recent clicks" table. One list drives the table,
// the settings modal and the saved-preference migration, so they cannot drift.
// Order here is the on-screen order, whatever order the user ticked them in.

export const RECENT_CLICK_COLUMNS = [
    { id: 'click_id', labelKey: 'dashboard.colClickId' },
    { id: 'created_at', labelKey: 'dashboard.colDate' },
    { id: 'campaign_name', labelKey: 'dashboard.colCampaign' },
    { id: 'domain', labelKey: 'dashboard.colDomain' },
    { id: 'country_code', labelKey: 'dashboard.colGeo' },
    { id: 'os', labelKey: 'dashboard.colOs' },
    { id: 'browser', labelKey: 'dashboard.colBrowser' },
    { id: 'device_type', labelKey: 'dashboard.colDeviceType' },
    { id: 'ip', labelKey: 'dashboard.colIp' },
    { id: 'user_agent', labelKey: 'dashboard.colUa' },
    { id: 'redirect_url', labelKey: 'dashboard.colDirection' },
];

// Keitaro's layout: event ID, time, campaign, OS logo, browser logo, IP, destination.
export const DEFAULT_CLICK_COLUMNS = ['click_id', 'created_at', 'campaign_name', 'os', 'browser', 'ip', 'redirect_url'];

// Defaults that earlier versions auto-saved on first visit. A saved list still
// identical to one of them was never customised and moves to the new default.
const LEGACY_DEFAULTS = [
    'created_at,campaign_name,device_type,ip,user_agent,redirect_url',
    'created_at,campaign_name,country_code,ip,device_type,user_agent,redirect_url',
];

export const migrateClickColumns = (columns) => {
    if (!Array.isArray(columns)) return [...DEFAULT_CLICK_COLUMNS];
    return LEGACY_DEFAULTS.includes(columns.join(',')) ? [...DEFAULT_CLICK_COLUMNS] : columns;
};
