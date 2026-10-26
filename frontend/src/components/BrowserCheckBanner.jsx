import React, { useEffect, useState } from 'react';
import { ScanFace, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

/**
 * One-time offer after an update to schema 77: existing installs start with
 * the browser check off (an update must not change routing by itself), and
 * this banner lets the admin switch the soft mode on with one click, read
 * more first, or say no. Fresh installs never see it — they start in soft.
 */
const BrowserCheckBanner = ({ onOpenSettings }) => {
    const { t } = useLanguage();
    const [pending, setPending] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;
        const load = () => fetch(`${API_URL}?action=browser_check_settings`)
            .then(r => r.json())
            .then(d => { if (!cancelled && d.status === 'success') setPending(!!d.data?.offer_pending); })
            .catch(() => { /* not an admin or offline — no banner */ });
        load();
        window.addEventListener('orbitra:browser-check-changed', load);
        return () => { cancelled = true; window.removeEventListener('orbitra:browser-check-changed', load); };
    }, []);

    if (!pending) return null;

    const post = async (body) => {
        setBusy(true);
        try {
            const res = await fetch(`${API_URL}?action=browser_check_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const d = await res.json();
            if (d.status === 'success') setPending(!!d.data?.offer_pending);
        } catch { /* keep the banner; the admin can retry */ } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mb-4 rounded-lg p-4 flex items-start justify-between gap-3 border" style={{ background: 'var(--color-primary-light)', borderColor: 'var(--color-primary)' }} data-testid="browser-check-banner">
            <div className="flex items-start gap-3">
                <ScanFace className="w-6 h-6 flex-shrink-0" style={{ color: 'var(--color-primary)' }} />
                <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('browserCheck.bannerTitle')}</span>
                    <div className="mt-1 text-[var(--color-text-secondary)] text-sm">{t('browserCheck.bannerText')}</div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <button type="button" className="btn btn-primary text-sm" disabled={busy} onClick={() => post({ mode: 'soft' })} data-testid="browser-check-banner-enable">
                            {t('browserCheck.bannerEnable')}
                        </button>
                        <button type="button" className="btn btn-secondary text-sm" onClick={onOpenSettings}>
                            {t('browserCheck.bannerMore')}
                        </button>
                        <button type="button" className="btn btn-secondary text-sm" disabled={busy} onClick={() => post({ dismiss_offer: true })}>
                            {t('browserCheck.bannerNo')}
                        </button>
                    </div>
                </div>
            </div>
            <button type="button" className="action-btn" aria-label={t('common.close', 'Close')} disabled={busy} onClick={() => post({ dismiss_offer: true })}>
                <X className="w-4 h-4" />
            </button>
        </div>
    );
};

export default BrowserCheckBanner;
