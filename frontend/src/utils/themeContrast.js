/**
 * Custom-theme contrast helpers.
 *
 * The custom theme paints --color-primary onto :root as an inline style,
 * which otherwise leaves --color-text-inverse inherited from :root
 * (#ffffff) no matter how light the picked primary is — white text on a
 * lime primary is unreadable. Every place that applies custom colors must
 * also derive the inverse, and leaving the custom theme must clear the
 * inline override so the built-in [data-theme] blocks win again.
 */

/**
 * Perceived-brightness contrast pick (black vs white) for a hex color.
 *
 * The 0.55 threshold keeps mid-saturation accents on white text — the
 * default coral #f05a3e lands at ~0.52 — and only flips to black for
 * genuinely light colors such as the neon lime #A3E635 (~0.74), matching
 * what the curated themes already hardcode.
 *
 * @param {string} hex
 * @returns {string} '#000000' or '#ffffff'
 */
export const getContrastTextColor = (hex) => {
    if (!hex || typeof hex !== 'string') return '#ffffff';
    let clean = hex.replace('#', '').trim();
    if (clean.length === 3) {
        clean = clean.split('').map((c) => c + c).join('');
    }
    if (clean.length !== 6 || /[^0-9a-fA-F]/.test(clean)) return '#ffffff';
    const r = parseInt(clean.slice(0, 2), 16);
    const g = parseInt(clean.slice(2, 4), 16);
    const b = parseInt(clean.slice(4, 6), 16);
    const brightness = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return brightness > 0.55 ? '#000000' : '#ffffff';
};

/**
 * Apply a custom palette (CSS variable -> value) plus the derived
 * --color-text-inverse. A palette without --color-primary keeps the
 * active theme's own inverse by clearing any previous inline override.
 *
 * @param {Record<string, string>} colors
 * @param {HTMLElement} [root]
 */
export const applyCustomThemeVars = (colors, root = document.documentElement) => {
    Object.entries(colors || {}).forEach(([key, value]) => {
        root.style.setProperty(key, value);
    });
    const primary = colors && colors['--color-primary'];
    if (primary) {
        root.style.setProperty('--color-text-inverse', getContrastTextColor(primary));
    } else {
        clearInverseText(root);
    }
};

/**
 * Drop the inline --color-text-inverse override.
 *
 * @param {HTMLElement} [root]
 */
export const clearInverseText = (root = document.documentElement) => {
    root.style.removeProperty('--color-text-inverse');
};
