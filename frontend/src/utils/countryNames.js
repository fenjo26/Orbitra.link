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
        } catch {
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
        } catch { /* not a valid region code — fall through to the fallback */ }
    }
    return fallbackName || code;
};

// Language codes as stored on clicks ('ru', 'en', occasionally 'en-us')
// rendered as names in the UI language, Keitaro-style: "ru" → "русский" in
// a Russian panel, "Russian" in an English one. Unknown or invalid codes
// fall back to the code itself.
const languageNamesCache = {};

export const localizedLanguageName = (code, language, fallbackName) => {
    if (!code || code === 'Unknown' || code === '??') return fallbackName || code;
    const locales = [language || 'en', 'en'];
    const cacheKey = locales.join('|');
    if (!(cacheKey in languageNamesCache)) {
        try {
            languageNamesCache[cacheKey] = new Intl.DisplayNames(locales, { type: 'language' });
        } catch {
            languageNamesCache[cacheKey] = null;
        }
    }
    const dn = languageNamesCache[cacheKey];
    if (dn) {
        try {
            const name = dn.of(String(code).replace('_', '-'));
            if (name && name.toLowerCase() !== String(code).toLowerCase()) {
                // Intl returns lower-case names in some locales ("русский");
                // capitalise the first letter so a report column reads evenly.
                return name.charAt(0).toLocaleUpperCase(language || 'en') + name.slice(1);
            }
        } catch { /* not a valid language tag — fall through */ }
    }
    return fallbackName || code;
};
