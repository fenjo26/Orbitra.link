// Role templates for the UsersPage user modal. Each template is a quick-pick
// that fills the REAL permission structure — {resource: {access, items}} in
// permissions_json plus the 'finance' subkey the backend masking reads — not
// a parallel permission model. 'custom' keeps whatever is currently set.

const buildPermissions = (accessByResource, finance) => {
    const permissions = {};
    Object.entries(accessByResource).forEach(([resource, access]) => {
        permissions[resource] = { access, items: [] };
    });
    permissions.finance = { ...finance };
    return permissions;
};

export const ROLE_TEMPLATES = {
    admin: {
        id: 'admin',
        role: 'admin',
        // Admins bypass permissions entirely — the backend ignores the field.
        permissions: null,
    },
    media_buyer: {
        id: 'media_buyer',
        role: 'user',
        permissions: buildPermissions(
            {
                campaigns: 'full',
                offers: 'full',
                landings: 'full',
                media: 'full',
                sources: 'full',
                networks: 'full',
                domains: 'read',
                logs: 'read',
            },
            { show_costs: true, show_revenue: true, show_payout: true }
        ),
    },
    video_editor: {
        id: 'video_editor',
        role: 'user',
        permissions: buildPermissions(
            {
                campaigns: 'none',
                offers: 'none',
                landings: 'full',
                media: 'full',
                sources: 'none',
                networks: 'none',
                domains: 'read',
                logs: 'none',
            },
            { show_costs: false, show_revenue: false, show_payout: false }
        ),
    },
    developer: {
        id: 'developer',
        role: 'user',
        permissions: buildPermissions(
            {
                campaigns: 'read',
                offers: 'read',
                landings: 'full',
                media: 'read',
                sources: 'read',
                networks: 'read',
                domains: 'read',
                logs: 'full',
            },
            { show_costs: false, show_revenue: false, show_payout: false }
        ),
    },
};

export const TEMPLATE_IDS = ['admin', 'media_buyer', 'video_editor', 'developer', 'custom'];

// Deep copy so a saved user never shares object identity with the preset.
export const templatePermissions = (templateId) => {
    const template = ROLE_TEMPLATES[templateId];
    if (!template || !template.permissions) return null;
    return JSON.parse(JSON.stringify(template.permissions));
};

// Which template does this role + permission set correspond to? Used to
// preselect the dropdown when editing an existing user. Anything that doesn't
// match a preset exactly is 'custom'. The 'items' scope is ignored — access
// levels and finance flags decide the match.
export const detectTemplate = (role, permissions) => {
    if (role === 'admin') return 'admin';
    const accessOf = (perms) => {
        const map = {};
        Object.entries(perms || {}).forEach(([resource, value]) => {
            if (resource !== 'finance' && value && typeof value === 'object') {
                map[resource] = value.access;
            }
        });
        return map;
    };
    const financeOf = (perms) => {
        const finance = perms && typeof perms === 'object' ? perms.finance : null;
        if (!finance || typeof finance !== 'object') return 'default';
        return ['show_costs', 'show_revenue', 'show_payout']
            .map((key) => (finance[key] === false ? '0' : '1'))
            .join('');
    };
    const userAccess = JSON.stringify(accessOf(permissions));
    const userFinance = financeOf(permissions);
    for (const template of Object.values(ROLE_TEMPLATES)) {
        if (template.role !== 'user') continue;
        if (JSON.stringify(accessOf(template.permissions)) !== userAccess) continue;
        if (financeOf(template.permissions) !== userFinance) continue;
        return template.id;
    }
    return 'custom';
};
