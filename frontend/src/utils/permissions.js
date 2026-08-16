// Tab visibility helpers driven by the per-user permissions set in the
// UsersPage permissions modal. The modal (and the backend save_user handler)
// only knows these five resources; every other tab has no permission key and
// stays visible.

const TAB_PERMISSION_KEYS = {
    campaigns: 'campaigns',
    landings: 'landings',
    offers: 'offers',
    networks: 'networks',
    sources: 'sources',
};

// Tabs without the admin_ prefix that still live inside the admin gear menu.
const ADMIN_MENU_TABS = new Set(['postback', 'conversions', 'simulation']);

export const isAdminUser = (user) => user?.role === 'admin';

export const isAdminTab = (tab) =>
    typeof tab === 'string' && (tab.startsWith('admin_') || ADMIN_MENU_TABS.has(tab));

// The backend decodes permissions_json on login, so this is normally already
// an object — but it is [] when the user has none saved, and a stale string
// from an older session is cheap to tolerate.
const parsePermissions = (user) => {
    const raw = user?.permissions;
    if (!raw) return {};
    if (typeof raw === 'string') {
        try {
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch {
            return {};
        }
    }
    return typeof raw === 'object' ? raw : {};
};

// A tab is hidden only when its resource access is explicitly 'none';
// full/read/selected/own all keep the tab visible.
export const canAccessTab = (user, tab) => {
    if (!user) return false;
    if (isAdminUser(user)) return true; // backend ignores permissions for admins
    const permKey = TAB_PERMISSION_KEYS[tab];
    if (!permKey) return true;
    return parsePermissions(user)[permKey]?.access !== 'none';
};

export const firstAllowedTab = (user) =>
    ['campaigns', 'offers', 'landings', 'sources', 'networks', 'dashboard']
        .find((tab) => canAccessTab(user, tab)) || 'dashboard';
