import React, { createContext, useContext, useState, useEffect } from 'react';
import ru from '../locales/ru';
import en from '../locales/en';

const translations = { ru, en };
const LanguageContext = createContext();

export const LanguageProvider = ({ children }) => {
    // Try to get language from user session first, then localStorage, defaulting to 'ru'
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
        return 'ru';
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
        let value = translations[language];

        for (const k of keys) {
            if (value && typeof value === 'object' && k in value) {
                value = value[k];
            } else {
                return fallback || key;
            }
        }
        // Guard: if the resolved value is still an object (e.g. a nested section),
        // return the key path to avoid React error #310 (objects as children).
        if (value && typeof value === 'object') {
            return fallback || key;
        }
        return typeof value === 'string' ? value : String(value ?? (fallback || key));
    };

    return (
        <LanguageContext.Provider value={{ language, setLanguage, t }}>
            {children}
        </LanguageContext.Provider>
    );
};

export const useLanguage = () => useContext(LanguageContext);
