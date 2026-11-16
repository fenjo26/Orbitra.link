import React, { useState } from 'react';
import { ChevronDown, HelpCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

// "How access works" — the plain-language manual for the Users page. Open on
// the first visit, then remembers whether the admin folded it away.
const STORAGE_KEY = 'orbitra_access_help_open';
const ITEMS = ['Roles', 'Levels', 'Selected', 'Groups', 'Limits', 'Stats', 'Finance', 'Templates', 'Offboard'];

const readOpen = () => {
    try {
        return localStorage.getItem(STORAGE_KEY) !== '0';
    } catch {
        return true;
    }
};

const AccessHelp = () => {
    const { t } = useLanguage();
    const [open, setOpen] = useState(readOpen);

    const toggle = () => {
        const next = !open;
        setOpen(next);
        try {
            localStorage.setItem(STORAGE_KEY, next ? '1' : '0');
        } catch {
            // private mode: the card simply opens again next time
        }
    };

    return (
        <div className="page-card" data-access-help>
            <button
                type="button"
                onClick={toggle}
                aria-expanded={open}
                className="flex items-center gap-2"
                style={{ width: '100%', background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: 'var(--color-text-primary)', textAlign: 'left' }}
            >
                <HelpCircle size={18} style={{ color: 'var(--color-primary)', flexShrink: 0 }} />
                <span style={{ fontWeight: 600, fontSize: 14, flex: 1 }}>{t('users.helpTitle')}</span>
                <ChevronDown size={18} style={{ color: 'var(--color-text-secondary)', transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 0.15s' }} />
            </button>
            {open && (
                <ul style={{ margin: '14px 0 0', padding: 0, listStyle: 'none', display: 'grid', gap: 10 }}>
                    {ITEMS.map((item) => (
                        <li key={item} style={{ fontSize: 13, lineHeight: 1.55, color: 'var(--color-text-secondary)' }}>
                            <span style={{ fontWeight: 600, color: 'var(--color-text-primary)' }}>{t(`users.help${item}Title`)}.</span>{' '}
                            {t(`users.help${item}Text`)}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
};

export default AccessHelp;
