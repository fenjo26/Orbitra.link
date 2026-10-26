import React, { useEffect, useState } from 'react';
import { ScanFace, Check, AlertTriangle, Info } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
const MODES = ['off', 'observe', 'soft', 'strict'];

/**
 * Settings → Bot protection → "Browser check" (core/BrowserCheck.php).
 * One mode for the whole tracker; a cloak stream can opt out in its own
 * settings. Every mode carries its risk in plain words — the operator must
 * never lose real visitors without knowing it could happen.
 */
const BrowserCheckCard = () => {
    const { t } = useLanguage();
    const [state, setState] = useState(null);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        fetch(`${API_URL}?action=browser_check_settings`)
            .then(r => r.json())
            .then(d => { if (d.status === 'success') setState(d.data); })
            .catch(() => setError(t('browserCheck.loadError')));
    }, [t]);

    const saveMode = async (mode) => {
        setSaving(true);
        setError('');
        try {
            const res = await fetch(`${API_URL}?action=browser_check_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode }),
            });
            const d = await res.json();
            if (d.status !== 'success') throw new Error(d.message || 'error');
            setState(d.data);
            setSaved(true);
            setTimeout(() => setSaved(false), 1500);
            window.dispatchEvent(new CustomEvent('orbitra:browser-check-changed'));
        } catch {
            setError(t('browserCheck.saveError'));
        } finally {
            setSaving(false);
        }
    };

    const mode = state?.mode || 'off';
    const stats = state?.stats;
    const topFlags = stats ? Object.entries(stats.by_flag || {}).slice(0, 5) : [];

    return (
        <div className="page-card" data-testid="browser-check-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <div className="flex items-center gap-2">
                    <ScanFace size={18} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('browserCheck.title')}</h3>
                    {saved && <span className="text-xs flex items-center gap-1" style={{ color: 'var(--color-success, rgb(22,163,74))' }}><Check size={13} /> {t('browserCheck.saved')}</span>}
                </div>
            </div>
            <p className="text-sm mt-2 mb-3" style={{ color: 'var(--color-text-secondary)' }}>{t('browserCheck.intro')}</p>

            <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label={t('browserCheck.title')}>
                {MODES.map(m => {
                    const active = mode === m;
                    return (
                        <button
                            key={m}
                            type="button"
                            role="radio"
                            aria-checked={active}
                            disabled={!state || saving}
                            onClick={() => !active && saveMode(m)}
                            data-testid={`browser-check-mode-${m}`}
                            className="text-left p-3 rounded-xl border transition"
                            style={{
                                borderColor: active ? 'var(--color-primary)' : 'var(--color-border)',
                                background: active ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                            }}
                        >
                            <div className="flex items-center gap-2 font-semibold text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                <span className="w-3.5 h-3.5 rounded-full border-2 flex-shrink-0" style={{ borderColor: active ? 'var(--color-primary)' : 'var(--color-border)', background: active ? 'var(--color-primary)' : 'transparent' }} />
                                {t(`browserCheck.mode_${m}`)}
                                {m === 'soft' && <span className="text-[10px] px-1.5 py-0.5 rounded-full font-semibold" style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>{t('browserCheck.recommended')}</span>}
                            </div>
                            <div className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t(`browserCheck.mode_${m}_what`)}</div>
                            <div className="text-xs mt-1 flex items-start gap-1" style={{ color: m === 'strict' ? 'rgb(180,83,9)' : 'var(--color-text-muted)' }}>
                                {m === 'strict' ? <AlertTriangle size={12} className="flex-shrink-0 mt-0.5" /> : null}
                                <span>{t(`browserCheck.mode_${m}_risk`)}</span>
                            </div>
                        </button>
                    );
                })}
            </div>

            {(mode === 'soft' || mode === 'strict') && (
                <div className="flex items-start gap-2 text-xs p-3 mt-3 rounded-lg" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                    <Info size={14} className="flex-shrink-0 mt-0.5" />
                    <span>{t('browserCheck.whereItActs')}</span>
                </div>
            )}
            <div className="flex items-start gap-2 text-xs p-3 mt-2 rounded-lg" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                <Info size={14} className="flex-shrink-0 mt-0.5" />
                <span>{t('browserCheck.noUniqueness')}</span>
            </div>

            {stats && mode !== 'off' && (
                <div className="mt-3 text-xs" style={{ color: 'var(--color-text-secondary)' }} data-testid="browser-check-stats">
                    <div className="font-semibold mb-1" style={{ color: 'var(--color-text-primary)' }}>{t('browserCheck.statsTitle', { days: stats.days })}</div>
                    <div>{t('browserCheck.statsLine', { checked: stats.checked, bot: stats.bot, suspect: stats.suspect })}</div>
                    {topFlags.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-2">
                            {topFlags.map(([code, n]) => (
                                <span key={code} className="px-2 py-0.5 rounded-full border" style={{ borderColor: 'var(--color-border)' }}>
                                    {t(`browserCheck.flag_${code}`, code)}: {n}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            )}
            {error && <div className="text-xs mt-2" style={{ color: 'var(--color-danger)' }}>{error}</div>}
        </div>
    );
};

export default BrowserCheckCard;
