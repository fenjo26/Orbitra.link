// Shared color resolution for conversion statuses. The source of truth is the
// conversion_types table (Settings → Conversion Types): each type may carry a
// custom label color. Statuses with no matching type — or a type with no color
// set — fall back to the built-in palette so the six standard statuses look
// right on a fresh install with an empty conversion_types table.

export const DEFAULT_CONVERSION_COLORS = {
    lead: '#0ea5e9',
    sale: '#10b981',
    rejected: '#ef4444',
    trash: '#6b7280',
    registration: '#8b5cf6',
    deposit: '#f59e0b',
};

export const FALLBACK_CONVERSION_COLOR = '#6b7280';

// 16-contrast swatch palette offered in the type editor.
export const CONVERSION_COLOR_SWATCHES = [
    '#f97316', '#f59e0b', '#eab308', '#84cc16',
    '#22c55e', '#10b981', '#14b8a6', '#06b6d4',
    '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6',
    '#a855f7', '#d946ef', '#ec4899', '#ef4444',
];

const normalize = value => String(value || '').trim().toLowerCase();

/**
 * Resolve the display color for a conversion status value.
 *
 * @param {string} status raw status from the conversions table
 * @param {Array} types conversion_types rows ({name, status_values, color})
 * @returns {string} hex color, never empty
 */
export const resolveConversionColor = (status, types) => {
    const needle = normalize(status);
    if (!needle) {
        return FALLBACK_CONVERSION_COLOR;
    }

    if (Array.isArray(types)) {
        for (const type of types) {
            if (!type) {
                continue;
            }
            const values = String(type.status_values || '')
                .split(',')
                .map(normalize)
                .filter(Boolean);
            const matches = normalize(type.name) === needle || values.includes(needle);
            if (matches && /^#[0-9a-fA-F]{6}$/.test(String(type.color || '').trim())) {
                return String(type.color).trim();
            }
        }
    }

    return DEFAULT_CONVERSION_COLORS[needle] || FALLBACK_CONVERSION_COLOR;
};
