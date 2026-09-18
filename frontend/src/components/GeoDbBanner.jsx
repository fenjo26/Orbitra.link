import React, { useState, useEffect } from 'react';
import { AlertTriangle, Download, Settings, X, RefreshCw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
const DISMISS_KEY = 'orbitra_geo_db_banner_dismissed';

// Sits in the dashboard notification stack (next to the update banner, which
// also renders on every tab). Shows while not a single geo database works:
// on a fresh install there is none, so country/city stay empty, geo filters
// match nothing and every visitor cloaks as country Unknown — the operator
// reads that as "cloaking is broken" instead of "no database installed".
const GeoDbBanner = () => {
    const { t } = useLanguage();
    const [dbs, setDbs] = useState(null);
    const [dismissed, setDismissed] = useState(() => {
        try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch { return false; }
    });
    const [installing, setInstalling] = useState(false);
    const [failed, setFailed] = useState(false);

    const fetchDbs = () => {
        fetch(`${API_URL}?action=geo_dbs`)
            .then(r => r.json())
            .then(d => { if (d.status === 'success' && Array.isArray(d.data)) setDbs(d.data); })
            .catch(() => { /* unknown state — render nothing */ });
    };

    useEffect(() => { fetchDbs(); }, []);

    if (dismissed || !Array.isArray(dbs)) return null;
    if (dbs.some(d => String(d.status || '').toUpperCase() === 'OK')) return null;

    const installSypex = async () => {
        setInstalling(true);
        setFailed(false);
        try {
            const res = await fetch(`${API_URL}?action=geo_db_update`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: 'sypex_city_lite' })
            });
            const data = await res.json();
            if (data.status === 'success') {
                fetchDbs(); // a working DB makes the banner disappear on its own
            } else {
                setFailed(true);
            }
        } catch {
            setFailed(true);
        } finally {
            setInstalling(false);
        }
    };

    return (
        <div className="mb-4 bg-[var(--color-warning-bg)] border border-[var(--color-warning-border)] rounded-lg p-4 flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
                <AlertTriangle className="w-6 h-6 text-[var(--color-warning)] flex-shrink-0" />
                <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('app.geoDbTitle')}</span>
                    <div className="mt-1 text-[var(--color-text-secondary)] text-sm">{t('app.geoDbText')}</div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={installSypex}
                            disabled={installing}
                            className="btn btn-secondary flex items-center gap-2 text-xs"
                        >
                            {installing
                                ? <RefreshCw className="w-4 h-4 animate-spin" />
                                : <Download className="w-4 h-4" />}
                            {installing ? t('app.geoDbInstalling') : t('app.geoDbInstall')}
                        </button>
                        <button
                            type="button"
                            onClick={() => window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_geo_dbs' } }))}
                            className="btn btn-secondary flex items-center gap-2 text-xs"
                        >
                            <Settings className="w-4 h-4" />
                            {t('app.geoDbSettings')}
                        </button>
                    </div>
                    {failed && (
                        <div className="mt-2 text-sm" style={{ color: 'var(--color-warning)' }}>
                            {t('app.geoDbFailed')}
                        </div>
                    )}
                </div>
            </div>
            <button
                onClick={() => {
                    setDismissed(true);
                    try { localStorage.setItem(DISMISS_KEY, '1'); } catch { /* private mode */ }
                }}
                aria-label={t('common.close')}
                className="p-1 hover:bg-[var(--color-bg-hover)] rounded flex-shrink-0"
            >
                <X className="w-5 h-5 text-[var(--color-warning)]" />
            </button>
        </div>
    );
};

export default GeoDbBanner;
