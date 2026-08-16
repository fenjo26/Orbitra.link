// Country display names follow the UI language, not the backend list: the
// API serves canonical English names and Intl.DisplayNames renders them in
// the active locale, with the backend name as the fallback for codes the
// browser doesn't recognize (or browsers without Intl.DisplayNames support).
const displayNamesCache = {};

const getDisplayNames = (language) => {
    const locales = [language || 'en', 'en'];
    const cacheKey = locales.join('|');
    if (!(cacheKey in displayNamesCache)) {
        try {
            displayNamesCache[cacheKey] = new Intl.DisplayNames(locales, { type: 'region' });
        } catch (e) {
            displayNamesCache[cacheKey] = null;
        }
    }
    return displayNamesCache[cacheKey];
};

export const localizedCountryName = (code, language, fallbackName) => {
    if (!code || code === 'Unknown' || code === '??') return fallbackName || code;
    const upper = String(code).toUpperCase();
    const dn = getDisplayNames(language);
    if (dn) {
        try {
            const name = dn.of(upper);
            if (name && name !== upper) return name;
        } catch (e) { /* not a valid region code — fall through to the fallback */ }
    }
    return fallbackName || code;
};
