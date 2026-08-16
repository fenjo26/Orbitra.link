// Tab visibility helpers driven by the per-user permissions set in the
// UsersPage permissions modal. The modal (and the backend save_user handler)
// only knows five resources; every other tab has no permission key and
// defaults to visible.

const TAB_PERMISSION_KEYS = {
    campaigns: 'campaigns',
    landings: 'landings',
    offers: 'offers',
    networks: 'networks',
    sources: 'sources',
};

// Gear-menu (⚙️) tabs a non-admin may open. Value = required permission
// resource (tab hides when that resource's access is 'none'), null = open to
// every user. Verified against the backend: none of these pages call an
// admin-gated API action. All other gear tabs (admin_* prefix plus
// simulation) are admin-only.
const USER_GEAR_TABS = {
    admin_branding: null,   // theme personalization (save_settings)
    admin_feedback: null,   // static contact/support info
    admin_logs: null,       // click-debugging log viewer (action=logs)
    postback: 'campaigns',  // postback settings (settings/save_settings)
    conversions: 'campaigns'
};

const ADMIN_ONLY_TABS = new Set(['simulation']);

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

export const isAdminUser = (user) => user?.role === 'admin';

const hasResourceAccess = (user, permKey) =>
    parsePermissions(user)[permKey]?.access !== 'none';

// A permission-keyed tab is hidden only when its access is explicitly 'none';
// full/read/selected/own all keep the tab visible.
export const canAccessTab = (user, tab) => {
    if (!user) return false;
    if (isAdminUser(user)) return true; // backend ignores permissions for admins
    if (tab in USER_GEAR_TABS) {
        const required = USER_GEAR_TABS[tab];
        return required ? hasResourceAccess(user, required) : true;
    }
    if (typeof tab === 'string' && (tab.startsWith('admin_') || ADMIN_ONLY_TABS.has(tab))) {
        return false;
    }
    const permKey = TAB_PERMISSION_KEYS[tab];
    if (!permKey) return true;
    return hasResourceAccess(user, permKey);
};

export const firstAllowedTab = (user) =>
    ['campaigns', 'offers', 'landings', 'sources', 'networks', 'dashboard']
        .find((tab) => canAccessTab(user, tab)) || 'dashboard';
