/**
 * Night-mode toggle that respects the chosen colour scheme.
 *
 * The navbar sun/moon button used to flip `light <-> dark` only, so any
 * palette theme (cobalt, canary, indigo, terminal, custom…) was thrown
 * away on the first click and the panel fell back to the default orange
 * scheme. Now:
 *   - every built-in scheme swaps to its own light/dark twin
 *     (cobalt <-> cobalt-dark, terminal <-> terminal-light, light <-> dark …);
 *   - the custom theme has no twin, so it switches to the base light/dark
 *     scheme and is remembered: the next click brings it back.
 */
import { getContrastTextColor } from './themeContrast';

export const MODE_KEY = 'orbitra_mode';
export const RETURN_KEY = 'orbitra_mode_return';

const PAIRS = {
    light: 'dark',
    cobalt: 'cobalt-dark',
    canary: 'canary-dark',
    parchment: 'parchment-dark',
    indigo: 'indigo-dark',
    green: 'green-dark',
    'neon-light': 'neon',
    'terminal-light': 'terminal',
    'aurora-light': 'aurora',
};
const DARK_TO_LIGHT = Object.fromEntries(Object.entries(PAIRS).map(([l, d]) => [d, l]));

const readCustomIsDark = (storage) => {
    try {
        const colors = JSON.parse(storage.getItem('orbitra_custom_colors') || 'null');
        const bg = colors && colors['--color-bg-main'];
        // White text on the background == the background is dark.
        return bg ? getContrastTextColor(bg) === '#ffffff' : false;
    } catch {
        return false;
    }
};

export const isDarkMode = (mode, storage = window.localStorage) => {
    if (mode in DARK_TO_LIGHT) return true;
    if (mode in PAIRS) return false;
    if (mode === 'custom') return readCustomIsDark(storage);
    return false;
};

/** Mode the toggle should switch to from `mode` (pure, no side effects). */
export const nextToggleMode = (mode, returnMode) => {
    if (mode in PAIRS) {
        // Base light/dark may be a stop-over from a twinless theme.
        if (mode === 'light' && returnMode) return returnMode;
        return PAIRS[mode];
    }
    if (mode in DARK_TO_LIGHT) {
        if (mode === 'dark' && returnMode) return returnMode;
        return DARK_TO_LIGHT[mode];
    }
    return null; // twinless: caller decides from brightness
};

/**
 * Flip night mode, persist it and return the new mode.
 * Dispatching `themeChanged` is left to the caller.
 */
export const toggleNightMode = (storage = window.localStorage) => {
    const mode = storage.getItem(MODE_KEY) || 'light';
    const returnMode = storage.getItem(RETURN_KEY);

    let next = nextToggleMode(mode, returnMode);
    if (next === null) {
        // Custom (twinless) theme: step to the opposite base scheme, remember ours.
        next = isDarkMode(mode, storage) ? 'light' : 'dark';
        storage.setItem(RETURN_KEY, mode);
    } else if (returnMode && next === returnMode) {
        storage.removeItem(RETURN_KEY);
    }

    storage.setItem(MODE_KEY, next);
    return next;
};

/** An explicit theme pick (Branding page) forgets any pending return. */
export const setThemeMode = (mode, storage = window.localStorage) => {
    storage.setItem(MODE_KEY, mode);
    storage.removeItem(RETURN_KEY);
};
