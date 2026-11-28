import React, { createContext, useContext } from 'react';
import ru from '../locales/ru';
import en from '../locales/en';
import uk from '../locales/uk';
import es from '../locales/es';
import zh from '../locales/zh';
import fr from '../locales/fr';
import de from '../locales/de';
import ko from '../locales/ko';
import vi from '../locales/vi';

export const translations = { ru, en, uk, es, zh, fr, de, ko, vi };
const SUPPORTED = Object.keys(translations);
export const LanguageContext = createContext();

// Detect the best matching UI language from the browser preference.
// 'en-US' → 'en', 'ru-RU' → 'ru', 'zh-CN' → 'zh'. Falls back to 'en' so a
// first-time visitor to an international SaaS never lands on Russian just
// because nothing else was configured.
export const detectBrowserLanguage = () => {
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
    } catch { /* no readable browser language — English below */ }
    return 'en';
};

export const useLanguage = () => useContext(LanguageContext);
