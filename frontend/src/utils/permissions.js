// Tab visibility helpers driven by the per-user permissions set in the
// UsersPage permissions modal. The modal (and the backend save_user handler)
// only knows five resources; every other tab has no permission key and
// defaults to visible.

const TAB_PERMISSION_KEYS = {
    campaigns: 'campaigns',
    landings: 'landings',
    offers: 'offers',
    media: 'media',
    push: 'push',
    networks: 'networks',
    sources: 'sources',
    domains: 'domains',
};

// Gear-menu (⚙️) tabs a non-admin may open. Value = required permission
// resource (tab hides when that resource's access is 'none'), null = open to
// every user. Verified against the backend: none of these pages call an
// admin-gated API action. All other gear tabs (admin_* prefix plus
// simulation) are admin-only.
const USER_GEAR_TABS = {
    admin_branding: null,   // theme personalization (save_settings)
    admin_feedback: null,   // static contact/support info
    admin_logs: 'logs',     // click-debugging log viewer (action=logs)
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

// Write access mirrors the backend gate (core/resource_access.php): 'read'
// is view-only and 'none' hides the tab; 'full', and the campaign-scoped
// 'own'/'selected' levels, may mutate within their scope.
export const canWriteResource = (user, resource) => {
    if (!user) return false;
    if (isAdminUser(user)) return true;
    const access = parsePermissions(user)[resource]?.access;
    return access !== 'read' && access !== 'none';
};

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
    ['campaigns', 'offers', 'landings', 'sources', 'networks', 'domains', 'dashboard']
        .find((tab) => canAccessTab(user, tab)) || 'dashboard';

// Report-metric id → finance family, mirroring the backend masker
// (core/finance_masking.php). An id belongs to a family when any of its
// underscore segments matches a finance word: revenue_confirmed, uepc_hold,
// cost_value, roi, profitability, ... Count-style ids (deposits, sales) stay
// visible — only money families hide.
const FINANCE_SEGMENTS = {
    costs: [/^cost/, 'cpc', 'cpv', 'cpa', 'cpl', 'cps', 'spend'],
    revenue: [/^profit/, 'revenue', 'roi', 'epc', 'uepc', 'epv'],
    payout: [/^payout/],
};

export const financeHiddenMetric = (id, visibility) => {
    if (!id || !visibility) return false;
    const segments = String(id).toLowerCase().split('_');
    for (const [family, matchers] of Object.entries(FINANCE_SEGMENTS)) {
        if (visibility[family]) continue;
        if (segments.some(seg => matchers.some(m => (m instanceof RegExp ? m.test(seg) : seg === m)))) {
            return true;
        }
    }
    return false;
};

// Financial visibility mirrors the backend masking (core/finance_masking.php):
// permissions.finance.{show_costs, show_revenue, show_payout}, where anything
// missing means "allowed" — pre-existing users keep seeing everything. Admins
// are never masked.
export const financeVisibility = (user) => {
    if (isAdminUser(user)) {
        return { costs: true, revenue: true, payout: true };
    }
    const finance = parsePermissions(user).finance;
    if (!finance || typeof finance !== 'object') {
        return { costs: true, revenue: true, payout: true };
    }
    return {
        costs: finance.show_costs !== false,
        revenue: finance.show_revenue !== false,
        payout: finance.show_payout !== false,
    };
};
