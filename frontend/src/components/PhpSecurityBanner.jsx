import React, { useEffect, useState } from 'react';
import { ShieldAlert, ChevronDown, ChevronUp, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
// Dismissal is scoped to advisory + level: a future advisory (or a level
// change after a branch upgrade) re-opens the banner instead of inheriting
// an old "closed" verdict.
const dismissKey = (info) => `orbitra_php_sec_${info.advisory}_${info.level}`;

// CVE-2026-109618/109619 ("php2shell") banner — the interpreter, not the
// tracker, is the attack surface, and a tracker is an internet-facing PHP
// app by definition. The level comes from core/php_security.php via
// action=php_security; distro builds hide the backported patch inside an
// older version number, which is why the banner is dismissible rather than
// permanent. Mirrors GeoDbBanner: sits in the dashboard notification stack,
// disappears on its own once the server reports a fixed interpreter.
const PhpSecurityBanner = () => {
    const { t } = useLanguage();
    const [info, setInfo] = useState(null);
    const [expanded, setExpanded] = useState(false);

    useEffect(() => {
        fetch(`${API_URL}?action=php_security`)
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success' || !d.data || d.data.level === 'ok') return;
                try {
                    if (localStorage.getItem(dismissKey(d.data)) === '1') return;
                } catch { /* private mode — show the banner */ }
                setInfo(d.data);
            })
            .catch(() => { /* unknown state — render nothing */ });
    }, []);

    if (!info) return null;
    const eol = info.level === 'eol';
    const branch = `${Math.floor(info.php_version_id / 10000)}.${Math.floor(info.php_version_id / 100) % 100}`;

    const close = () => {
        setInfo(null);
        try { localStorage.setItem(dismissKey(info), '1'); } catch { /* private mode */ }
    };

    return (
        <div className="mb-4 bg-[var(--color-danger-bg)] border border-[var(--color-danger-border)] rounded-lg p-4 flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
                <ShieldAlert className="w-6 h-6 text-[var(--color-danger)] flex-shrink-0" />
                <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('phpSecurity.title')}</span>
                    <div className="mt-1 text-[var(--color-text-secondary)] text-sm">
                        {t(eol ? 'phpSecurity.textEol' : 'phpSecurity.text', {
                            version: info.php_version,
                            fixed_in: info.fixed_in || '',
                        })}
                    </div>
                    <button
                        type="button"
                        onClick={() => setExpanded(v => !v)}
                        className="mt-3 btn btn-secondary flex items-center gap-2 text-xs"
                    >
                        {expanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
                        {t('phpSecurity.howToFix')}
                    </button>
                    {expanded && (
                        <div className="mt-3">
                            {eol ? (
                                <>
                                    <div className="text-sm text-[var(--color-text-secondary)]">{t('phpSecurity.eolStep1')}</div>
                                    <code className="block mt-2 mb-2 px-3 py-2 rounded-lg text-xs font-mono"
                                        style={{ backgroundColor: 'var(--color-bg-hover)', color: 'var(--color-text-primary)' }}>
                                        sudo bash install.sh
                                    </code>
                                    <div className="text-sm text-[var(--color-text-secondary)]">{t('phpSecurity.eolStep2')}</div>
                                </>
                            ) : (
                                <>
                                    <div className="text-sm text-[var(--color-text-secondary)]">{t('phpSecurity.aptNote')}</div>
                                    <code className="block mt-2 px-3 py-2 rounded-lg text-xs font-mono whitespace-pre-wrap"
                                        style={{ backgroundColor: 'var(--color-bg-hover)', color: 'var(--color-text-primary)' }}>
{`apt update && apt upgrade 'php*'
systemctl restart php${branch}-fpm nginx apache2
php -v`}
                                    </code>
                                    <div className="mt-2 text-sm text-[var(--color-text-secondary)]">{t('phpSecurity.distroNote')}</div>
                                    <div className="mt-1 text-sm text-[var(--color-text-secondary)]">{t('phpSecurity.panelNote')}</div>
                                </>
                            )}
                        </div>
                    )}
                </div>
            </div>
            <button
                onClick={close}
                aria-label={t('common.close')}
                className="p-1 hover:bg-[var(--color-bg-hover)] rounded flex-shrink-0"
            >
                <X className="w-5 h-5 text-[var(--color-danger)]" />
            </button>
        </div>
    );
};

export default PhpSecurityBanner;
