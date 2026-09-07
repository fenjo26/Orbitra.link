import React, { useEffect, useState } from 'react';
import { X, Loader2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import axios from 'axios';

const API_URL = '/api.php';

// Per-screen funnel card for one PWA landing (action=pwa_funnel_stats):
// views/uniques per screen, entry & exit distributions and the screen→screen
// transition matrix from the pwa_screen_views log. Read-only reporting —
// the editor owns the funnel config, this card only shows what happened.
export default function PwaFunnelCard({ landingId, landingName, onClose }) {
    const { t } = useLanguage();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        setLoading(true);
        axios.get(API_URL, { params: { action: 'pwa_funnel_stats', id: landingId } })
            .then((res) => {
                if (!alive) return;
                if (res.data?.status === 'success') {
                    setData(res.data.data);
                } else {
                    setError(res.data?.message || t('pwa.funnelCardLoadFailed'));
                }
            })
            .catch(() => {
                if (alive) setError(t('pwa.funnelCardLoadFailed'));
            })
            .finally(() => {
                if (alive) setLoading(false);
            });
        return () => { alive = false; };
    }, [landingId, t]);

    const screens = data?.screens || [];
    const maxViews = Math.max(1, ...screens.map((s) => s.views));
    const fmt = (n) => (typeof n === 'number' ? n.toLocaleString() : '—');

    return (
        <div className="modal-overlay" onClick={onClose}>
            <div
                className="modal-content"
                onClick={(e) => e.stopPropagation()}
                style={{ maxWidth: '760px', width: '94vw', maxHeight: '88vh', overflow: 'auto' }}
            >
                <div className="modal-header">
                    <h3 className="modal-title">{t('pwa.funnelCardTitle')}{landingName ? ` — ${landingName}` : ''}</h3>
                    <button type="button" className="btn btn-ghost btn-icon" onClick={onClose} title={t('common.close')}>
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div style={{ padding: '16px 20px 20px' }}>
                    {loading && (
                        <div className="flex items-center gap-2 justify-center text-sm" style={{ color: 'var(--color-text-muted)', padding: '32px 0' }}>
                            <Loader2 className="w-4 h-4 animate-spin" />
                            {t('common.loading')}
                        </div>
                    )}
                    {!loading && error && (
                        <div className="alert" style={{ background: 'color-mix(in srgb, var(--color-danger) 12%, transparent)', color: 'var(--color-danger)', fontSize: '13px' }}>
                            {error}
                        </div>
                    )}
                    {!loading && !error && data && (
                        <>
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-4">
                                {[
                                    { label: t('pwa.funnelCardClicks'), value: data.totals?.clicks },
                                    { label: t('pwa.funnelCardWithScreens'), value: data.totals?.with_screens },
                                    { label: t('pwa.funnelCardIntents'), value: data.totals?.intents },
                                    { label: t('pwa.funnelCardInstalls'), value: data.totals?.installs },
                                    { label: t('pwa.funnelCardOfferClicks'), value: data.totals?.offer_clicks },
                                    { label: t('pwa.funnelCardPushSubs'), value: data.totals?.push_subs },
                                ].map((card) => (
                                    <div
                                        key={card.label}
                                        className="rounded-xl px-3 py-2"
                                        style={{ border: '1px solid var(--color-border)', background: 'var(--color-bg-soft)' }}
                                    >
                                        <div className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{card.label}</div>
                                        <div className="text-lg font-semibold" style={{ color: 'var(--color-text-primary)' }}>{fmt(card.value)}</div>
                                    </div>
                                ))}
                            </div>

                            {screens.length === 0 ? (
                                <div className="text-sm text-center" style={{ color: 'var(--color-text-muted)', padding: '24px 0' }}>
                                    {t('pwa.funnelCardNoData')}
                                </div>
                            ) : (
                                <>
                                    <div className="text-sm font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('pwa.funnelCardScreens')}
                                    </div>
                                    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--color-border)' }}>
                                        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                                            <thead>
                                                <tr style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>
                                                    <th className="text-left px-3 py-2 font-medium">{t('pwa.funnelCardScreen')}</th>
                                                    <th className="text-right px-3 py-2 font-medium">{t('pwa.funnelCardViews')}</th>
                                                    <th className="text-right px-3 py-2 font-medium">{t('pwa.funnelCardUniq')}</th>
                                                    <th className="text-right px-3 py-2 font-medium">{t('pwa.funnelCardEntry')}</th>
                                                    <th className="text-right px-3 py-2 font-medium">{t('pwa.funnelCardExits')}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {screens.map((s) => (
                                                    <tr key={s.screen} style={{ borderTop: '1px solid var(--color-border)' }}>
                                                        <td className="px-3 py-2" style={{ color: 'var(--color-text-primary)' }}>
                                                            <div className="flex items-center gap-2 min-w-0">
                                                                <span
                                                                    className="rounded"
                                                                    style={{
                                                                        width: `${Math.max(4, Math.round((s.views / maxViews) * 48))}px`,
                                                                        height: 8,
                                                                        background: 'var(--color-primary)',
                                                                        flex: 'none',
                                                                    }}
                                                                />
                                                                <span className="font-mono text-xs truncate">{s.screen}</span>
                                                            </div>
                                                        </td>
                                                        <td className="text-right px-3 py-2" style={{ color: 'var(--color-text-primary)' }}>{fmt(s.views)}</td>
                                                        <td className="text-right px-3 py-2" style={{ color: 'var(--color-text-muted)' }}>{fmt(s.uniq)}</td>
                                                        <td className="text-right px-3 py-2" style={{ color: 'var(--color-text-muted)' }}>{fmt(s.entry)}</td>
                                                        <td className="text-right px-3 py-2" style={{ color: s.exits > 0 ? 'var(--color-danger)' : 'var(--color-text-muted)' }}>{fmt(s.exits)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {(data.transitions || []).length > 0 && (
                                        <>
                                            <div className="text-sm font-semibold mt-4 mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('pwa.funnelCardTransitions')}
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                {data.transitions.map((tr, i) => (
                                                    <span
                                                        key={`${tr.from}-${tr.to}-${i}`}
                                                        className="text-xs px-2 py-1 rounded-lg font-mono"
                                                        style={{ border: '1px solid var(--color-border)', background: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}
                                                    >
                                                        {tr.from} → {tr.to} · <strong>{fmt(tr.n)}</strong>
                                                    </span>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
