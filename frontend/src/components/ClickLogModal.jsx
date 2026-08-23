import React, { useState, useEffect } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, invalidateCache } from '../utils/apiCache';
import ClickDetailsModal from './ClickDetailsModal';

/**
 * Click Log overlay for a single campaign.
 *
 * Lifted out of CampaignEditor so the Campaigns list can open the very same
 * screen from its row actions — a user who clicks "Click log" in the table
 * should land on the panel they already know from the editor, pre-filtered,
 * not on a second look-alike. The campaign name rides in the header so it is
 * obvious whose clicks these are when the modal is opened from a list of many.
 *
 * Filtering is entirely server-side: api.php's `campaign_logs` action takes
 * campaign_id (plus route/hours/stream_id) and returns only that campaign's
 * rows, so there is no client-side narrowing to get wrong here.
 */
const ClickLogModal = ({
    campaignId,
    campaignName,
    initialRoute = 'all',
    initialHours = 0,
    initialStreamId = 0,
    onClose,
}) => {
    const { t } = useLanguage();
    const [route, setRoute] = useState(initialRoute); // 'all' | 'safe' | 'money'
    const hours = initialHours;     // 0 = no window
    const streamId = initialStreamId; // 0 = all streams
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedClickId, setSelectedClickId] = useState(null);

    // route segments the log by is_safe_page, hours narrows it to a recent
    // window (the cloak diagnostics panel links here with its own 24h window).
    // The cache is dropped first so re-opening always reflects traffic that
    // just arrived.
    const fetchLogs = async (nextRoute = route) => {
        if (!campaignId) return;
        setLoading(true);
        try {
            const params = { campaign_id: campaignId, limit: 100 };
            if (nextRoute && nextRoute !== 'all') params.route = nextRoute;
            if (hours > 0) params.hours = hours;
            if (streamId > 0) params.stream_id = streamId;
            invalidateCache('campaign_logs');
            const { data } = await cachedGet('campaign_logs', params, 0);
            setLogs(data.status === 'success' ? data.data : []);
        } catch (e) {
            console.error('Error fetching logs:', e);
            setLogs([]);
        } finally {
            setLoading(false);
        }
    };

    // One fetch per campaign the modal is opened for. The modal is mounted
    // only while open, so this doubles as the on-open refresh.
    useEffect(() => {
        fetchLogs(initialRoute);
        setRoute(initialRoute);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [campaignId, initialRoute, initialHours, initialStreamId]);

    return (
        <>
            <div className="modal-overlay">
                <div className="modal-content" style={{ maxWidth: '960px' }}>
                    <div className="modal-header">
                        <div className="min-w-0">
                            <h3 className="modal-title">{t('campaignEditor.clickLogTitle')}</h3>
                            {campaignName && (
                                <div className="text-xs truncate mt-0.5" style={{ color: 'var(--color-text-muted)' }} title={campaignName}>
                                    {campaignName}
                                </div>
                            )}
                        </div>
                        <button onClick={onClose} className="action-btn">
                            <X className="w-5 h-5" />
                        </button>
                    </div>

                    {/* SAFE / MONEY / ALL segmented filter, driven by is_safe_page */}
                    <div className="flex items-center gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                        {[
                            ['all', t('campaignEditor.clickLogFilterAll', 'ALL')],
                            ['safe', t('campaignEditor.clickLogFilterSafe', 'SAFE')],
                            ['money', t('campaignEditor.clickLogFilterMoney', 'MONEY')]
                        ].map(([value, label]) => (
                            <button
                                key={value}
                                onClick={() => { setRoute(value); fetchLogs(value); }}
                                className={`flex-1 px-3 py-2 rounded-md text-xs font-semibold tracking-wide transition-colors ${route === value
                                    ? 'bg-[var(--color-bg)] shadow-sm'
                                    : 'hover:text-[var(--color-text-primary)]'
                                    }`}
                                style={{ color: route === value ? 'var(--color-primary)' : 'var(--color-text-secondary)' }}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {hours > 0 && (
                        <div className="text-[11px] mt-2" style={{ color: 'var(--color-text-muted)' }}>
                            {t('campaignEditor.clickLogLast24h', 'Last 24 hours')}
                        </div>
                    )}

                    <div className="overflow-y-auto mt-2" style={{ maxHeight: '58vh' }}>
                        {loading ? (
                            <div className="text-center py-10" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading', 'Loading...')}</div>
                        ) : logs.length === 0 ? (
                            <div className="text-center py-10" style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.clickLogNoClicks')}</div>
                        ) : (
                            <div className="space-y-3">
                                {logs.map((log) => {
                                    // Reasons arrive as comma-separated `code:evidence`
                                    // strings; split on the FIRST ':' only so evidence
                                    // containing colons (IPv6 CIDR) survives intact.
                                    const reasons = (log.cloak_reasons || '').split(',').map(s => s.trim()).filter(Boolean);
                                    return (
                                        <div
                                            key={log.id}
                                            onClick={() => setSelectedClickId(log.id)}
                                            className="rounded-xl p-3 transition-colors hover:border-[var(--color-primary)]"
                                            style={{ border: '1px solid var(--color-border)', cursor: 'pointer' }}
                                            title={t('campaignEditor.clickLogOpenDetails', 'Open click details')}
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className="text-[11px] font-mono" style={{ color: 'var(--color-text-muted)' }}>{log.created_at}</span>
                                                    <span className={`status-badge ${log.is_safe_page === 1 ? 'status-inactive' : 'status-active'} text-[11px]`}>
                                                        {log.is_safe_page === 1 ? t('logs.routeSafe') : t('logs.routeMoney')}
                                                    </span>
                                                    <span className="text-[11px]" style={{ color: 'var(--color-text-secondary)' }}>
                                                        {t('campaignEditor.clickLogVerdict')}: <b style={{ color: 'var(--color-text-primary)' }}>{log.cloak_verdict || '—'}</b>
                                                    </span>
                                                </div>
                                                <span className="text-[11px] font-mono" style={{ color: 'var(--color-text-muted)' }}>#{log.id}</span>
                                            </div>

                                            {/* Reason chips: the code plus the evidence that matched, visible inline */}
                                            {reasons.length === 0 ? (
                                                <span className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>—</span>
                                            ) : (
                                                <div className="flex flex-wrap gap-1">
                                                    {reasons.map((reason, idx) => {
                                                        const colon = reason.indexOf(':');
                                                        const code = colon === -1 ? reason : reason.slice(0, colon);
                                                        const evidence = colon === -1 ? '' : reason.slice(colon + 1);
                                                        return (
                                                            <span
                                                                key={idx}
                                                                className="text-[10px] px-1.5 py-0.5 rounded font-mono inline-flex items-center gap-1"
                                                                style={{
                                                                    backgroundColor: 'var(--color-bg-soft)',
                                                                    color: 'var(--color-text-secondary)',
                                                                    border: '1px solid var(--color-border)'
                                                                }}
                                                                title={t(`cloakReasons.${code}`, '') || code}
                                                            >
                                                                {code}
                                                                {evidence && (
                                                                    <b style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{evidence}</b>
                                                                )}
                                                            </span>
                                                        );
                                                    })}
                                                </div>
                                            )}

                                            {/* ISP / ASN / proxy type */}
                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2 text-[11px]" style={{ color: 'var(--color-text-primary)' }}>
                                                <div className="truncate" title={log.isp || ''}>
                                                    <span style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.clickLogIsp')}: </span>{log.isp || '—'}
                                                </div>
                                                <div className="truncate font-mono" title={log.asn || ''}>
                                                    <span style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.clickLogAsn')}: </span>{log.asn || '—'}
                                                </div>
                                                <div className="truncate font-mono" title={log.proxy_type || ''}>
                                                    <span style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.clickLogProxyType')}: </span>{log.proxy_type || '—'}
                                                </div>
                                            </div>

                                            {/* Full User-Agent */}
                                            <div className="mt-2 text-[11px] font-mono break-all" style={{ color: 'var(--color-text-secondary)' }}>
                                                <span style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.clickLogUserAgent')}: </span>{log.user_agent || '—'}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Click Log → single-click detail modal */}
            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </>
    );
};

export default ClickLogModal;
