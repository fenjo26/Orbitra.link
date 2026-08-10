import React, { createContext, useContext, useState, useEffect } from 'react';
import ru from '../locales/ru';
import en from '../locales/en';
import uk from '../locales/uk';
import es from '../locales/es';
import zh from '../locales/zh';
import fr from '../locales/fr';
import de from '../locales/de';

const translations = { ru, en, uk, es, zh, fr, de };
const SUPPORTED = Object.keys(translations);
const LanguageContext = createContext();

// Detect the best matching UI language from the browser preference.
// 'en-US' → 'en', 'ru-RU' → 'ru', 'zh-CN' → 'zh'. Falls back to 'en' so a
// first-time visitor to an international SaaS never lands on Russian just
// because nothing else was configured.
const detectBrowserLanguage = () => {
    try {
        const candidates = [];
        if (navigator.languages && navigator.languages.length) {
            candidates.push(...navigator.languages);
        }
        if (navigator.language) candidates.push(navigator.language);
        for (const raw of candidates) {
            if (!raw) continue;
            const lower = raw.toLowerCase();
            if (SUPPORTED.includes(lower)) return lower;
            const primary = lower.split('-')[0];
            if (SUPPORTED.includes(primary)) return primary;
        }
    } catch (e) { }
    return 'en';
};

export const LanguageProvider = ({ children }) => {
    // Precedence: user profile → manual choice → browser language → 'en'.
    const [language, setLanguageState] = useState(() => {
        try {
            const user = JSON.parse(localStorage.getItem('orbitra_user') || '{}');
            if (user && user.language && translations[user.language]) {
                return user.language;
            }
            const saved = localStorage.getItem('orbitra_lang');
            if (saved && translations[saved]) {
                return saved;
            }
        } catch (e) { }
        return detectBrowserLanguage();
    });

    // Update language from user session if it changes (e.g. upon login)
    useEffect(() => {
        const checkUserLang = () => {
            try {
                const user = JSON.parse(localStorage.getItem('orbitra_user') || '{}');
                if (user && user.language && translations[user.language] && user.language !== language) {
                    setLanguageState(user.language);
                }
            } catch (e) { }
        };
        // Listen to custom event when user logs in or updates profile
        window.addEventListener('userUpdated', checkUserLang);
        return () => window.removeEventListener('userUpdated', checkUserLang);
    }, [language]);

    const setLanguage = (lang) => {
        if (translations[lang]) {
            setLanguageState(lang);
            localStorage.setItem('orbitra_lang', lang);
        }
    };

    // Translation function — always returns a string, never an object.
    const t = (key, fallback = '') => {
        const keys = key.split('.');
        
        const resolve = (dict) => {
            let val = dict;
            for (const k of keys) {
                if (val && typeof val === 'object' && k in val) {
                    val = val[k];
                } else {
                    return null;
                }
            }
            if (val && typeof val === 'object') {
                return null;
            }
            return val;
        };

        let value = resolve(translations[language]);
        if (value !== null && value !== undefined) {
            return typeof value === 'string' ? value : String(value);
        }

        if (language !== 'en') {
            let fallbackValue = resolve(translations['en']);
            if (fallbackValue !== null && fallbackValue !== undefined) {
                return typeof fallbackValue === 'string' ? fallbackValue : String(fallbackValue);
            }
        }

        return fallback || key;
    };

    return (
        <LanguageContext.Provider value={{ language, setLanguage, t }}>
            {children}
        </LanguageContext.Provider>
    );
};

export const useLanguage = () => useContext(LanguageContext);
