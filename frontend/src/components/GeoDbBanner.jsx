import React, { useState, useEffect } from 'react';
import { AlertTriangle, Download, Settings, X, RefreshCw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
const DISMISS_KEY = 'orbitra_geo_db_banner_dismissed';
// Separate key: closing the "only the basic base" hint must not also silence
// the critical "no database at all" banner if every base later goes missing.
const BASIC_DISMISS_KEY = 'orbitra_geo_db_basic_banner_dismissed';
const BASIC_ONLY_ID = 'sypex_city_lite';

// Sits in the dashboard notification stack (next to the update banner, which
// also renders on every tab). Shows while not a single geo database works:
// on a fresh install there is none, so country/city stay empty, geo filters
// match nothing and every visitor cloaks as country Unknown — the operator
// reads that as "cloaking is broken" instead of "no database installed".
//
// Second, softer level: the installer ships Sypex Geo City Lite, so a fresh
// install is never at zero — but Sypex alone has no proxy/VPN, ISP or ASN data
// and a rough city. While Sypex is the only working base the banner asks for
// MaxMind / IP2Location instead; the operator can close it for good.
const GeoDbBanner = () => {
    const { t } = useLanguage();
    const [dbs, setDbs] = useState(null);
    const [dismissed, setDismissed] = useState(() => {
        try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch { return false; }
    });
    const [basicDismissed, setBasicDismissed] = useState(() => {
        try { return localStorage.getItem(BASIC_DISMISS_KEY) === '1'; } catch { return false; }
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

    if (!Array.isArray(dbs)) return null;
    const working = dbs.filter(d => String(d.status || '').toUpperCase() === 'OK');
    const basicOnly = working.length > 0 && working.every(d => d.id === BASIC_ONLY_ID);
    if (working.length > 0 && !basicOnly) return null;
    if (basicOnly ? basicDismissed : dismissed) return null;

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
                    <span className="font-medium text-[var(--color-text-primary)]">{t(basicOnly ? 'app.geoDbBasicTitle' : 'app.geoDbTitle')}</span>
                    <div className="mt-1 text-[var(--color-text-secondary)] text-sm">{t(basicOnly ? 'app.geoDbBasicText' : 'app.geoDbText')}</div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {!basicOnly && <button
                            type="button"
                            onClick={installSypex}
                            disabled={installing}
                            className="btn btn-secondary flex items-center gap-2 text-xs"
                        >
                            {installing
                                ? <RefreshCw className="w-4 h-4 animate-spin" />
                                : <Download className="w-4 h-4" />}
                            {installing ? t('app.geoDbInstalling') : t('app.geoDbInstall')}
                        </button>}
                        <button
                            type="button"
                            onClick={() => window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_geo_dbs' } }))}
                            className="btn btn-secondary flex items-center gap-2 text-xs"
                        >
                            <Settings className="w-4 h-4" />
                            {t(basicOnly ? 'app.geoDbBasicAction' : 'app.geoDbSettings')}
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
                    if (basicOnly) setBasicDismissed(true); else setDismissed(true);
                    try { localStorage.setItem(basicOnly ? BASIC_DISMISS_KEY : DISMISS_KEY, '1'); } catch { /* private mode */ }
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
