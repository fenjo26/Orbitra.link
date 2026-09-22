import React, { useState, useEffect, useCallback } from 'react';
import { ShieldAlert, Copy, CheckCircle, RefreshCw, X } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';

const API_URL = '/api.php';
const DISMISS_KEY = 'orbitra_server_setup_dismissed';

// The panel's update runs as the web user and cannot touch sudoers or install
// root helpers. When a release needs that (cli/server_setup.sh), this shows the
// one SSH command until the server reports the required setup version — in the
// dashboard banner stack (dismissible for the browser session only: it is a
// security step, it comes back) and on the Update page (never dismissible).
// Admins only; the endpoint answers 403 to everyone else.
const ServerSetupBanner = ({ variant = 'banner', onNavigate }) => {
    const { t } = useLanguage();
    const [status, setStatus] = useState(null);
    const [checking, setChecking] = useState(false);
    const [copied, setCopied] = useState(false);
    const [stillNeeded, setStillNeeded] = useState(false);
    const [dismissed, setDismissed] = useState(() => {
        if (variant !== 'banner') return false;
        try { return sessionStorage.getItem(DISMISS_KEY) === '1'; } catch { return false; }
    });

    const load = useCallback(async (manual = false) => {
        setChecking(true);
        try {
            const res = await axios.get(`${API_URL}?action=server_setup_status`);
            if (res.data.status === 'success') {
                setStatus(res.data.data);
                setStillNeeded(manual && res.data.data?.state === 'needed');
            }
        } catch {
            // Not an admin, or the check failed: render nothing.
        } finally {
            setChecking(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    if (dismissed || !status) return null;

    if (status.state !== 'needed') {
        if (variant === 'card' && status.state === 'ok') {
            return (
                <div className="page-card" style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                    <CheckCircle className="w-5 h-5" style={{ color: 'var(--color-success)' }} />
                    <span style={{ fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                        {t('serverSetup.ok').replace('{version}', String(status.installed))}
                    </span>
                </div>
            );
        }
        return null;
    }

    const copy = async () => {
        const ok = await copyToClipboard(status.command);
        if (ok !== false) {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    const body = (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', minWidth: 0 }}>
            <ShieldAlert className="w-6 h-6 flex-shrink-0" style={{ color: 'var(--color-warning)' }} />
            <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontWeight: 600, color: 'var(--color-text-primary)' }}>{t('serverSetup.title')}</div>
                <p style={{ fontSize: '13px', color: 'var(--color-text-secondary)', marginTop: '4px', lineHeight: 1.55 }}>
                    {status.legacy_rules ? t('serverSetup.textLegacy') : t('serverSetup.text')}
                </p>
                <ol style={{ fontSize: '13px', color: 'var(--color-text-secondary)', margin: '8px 0 0 18px', listStyle: 'decimal', lineHeight: 1.6 }}>
                    <li>{t('serverSetup.step1')}</li>
                    <li>{t('serverSetup.step2')}</li>
                </ol>
                <div style={{ display: 'flex', alignItems: 'stretch', gap: '8px', marginTop: '8px', flexWrap: 'wrap' }}>
                    <code style={{
                        flex: '1 1 320px', minWidth: 0, padding: '8px 10px', borderRadius: '8px', fontSize: '12px',
                        background: 'var(--color-bg-card)', border: '1px solid var(--color-border)',
                        color: 'var(--color-text-primary)', overflowX: 'auto', whiteSpace: 'nowrap', userSelect: 'all'
                    }}>{status.command}</code>
                    <button type="button" onClick={copy} className="btn btn-secondary btn-sm">
                        {copied ? <CheckCircle className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                        <span>{copied ? t('serverSetup.copied') : t('serverSetup.copy')}</span>
                    </button>
                </div>
                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '8px', lineHeight: 1.5 }}>
                    {t('serverSetup.step3')}
                </p>
                <div style={{ display: 'flex', gap: '8px', marginTop: '10px', flexWrap: 'wrap', alignItems: 'center' }}>
                    <button type="button" onClick={() => load(true)} disabled={checking} className="btn btn-primary btn-sm">
                        <RefreshCw className={`w-4 h-4 ${checking ? 'animate-spin' : ''}`} />
                        <span>{t('serverSetup.recheck')}</span>
                    </button>
                    {variant === 'banner' && onNavigate && (
                        <button type="button" onClick={onNavigate} className="btn btn-ghost btn-sm">
                            {t('serverSetup.moreOnUpdatePage')}
                        </button>
                    )}
                    {stillNeeded && !checking && (
                        <span style={{ fontSize: '12px', color: 'var(--color-warning)' }}>{t('serverSetup.stillNeeded')}</span>
                    )}
                </div>
            </div>
        </div>
    );

    if (variant === 'card') {
        return (
            <div style={{ background: 'var(--color-warning-bg)', border: '1px solid var(--color-warning-border)', borderRadius: '16px', padding: '16px' }}>
                {body}
            </div>
        );
    }

    return (
        <div className="mb-4 bg-[var(--color-warning-bg)] border border-[var(--color-warning-border)] rounded-lg p-4 flex items-start justify-between gap-3">
            {body}
            <button
                onClick={() => {
                    setDismissed(true);
                    try { sessionStorage.setItem(DISMISS_KEY, '1'); } catch { /* private mode */ }
                }}
                aria-label={t('common.close')}
                className="p-1 hover:bg-[var(--color-bg-hover)] rounded flex-shrink-0"
            >
                <X className="w-5 h-5 text-[var(--color-warning)]" />
            </button>
        </div>
    );
};

export default ServerSetupBanner;
