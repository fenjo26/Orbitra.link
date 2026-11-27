import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { RefreshCw, Settings, X } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { StatusPill } from './LinkChecksPanel';
import { agoText, chainText, hostOf, reasonText } from './reason';

const API_URL = '/api.php';
const CELL = { padding: '8px 10px', verticalAlign: 'top' };

const codeTone = (code) => {
    if (!code) return 'var(--color-text-muted)';
    if (code >= 200 && code < 300) return 'var(--color-success, #10b981)';
    if (code >= 300 && code < 400) return 'var(--color-primary)';
    return 'var(--color-danger, #dc2626)';
};

// One link: why it has its status, every hop of its last check and the history
// of the checks before it. Loads its own row, so it opens from the list and
// from a dot on another page alike.
export default function LinkCheckDetails({ checkId, onClose, onChanged, canCheck, onEdit }) {
    const { t } = useLanguage();
    const [row, setRow] = useState(null);
    const [log, setLog] = useState([]);
    const [missing, setMissing] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const [rowRes, logRes] = await Promise.all([
                axios.get(API_URL, { params: { action: 'link_checks_list', id: checkId, status: '' } }),
                axios.get(API_URL, { params: { action: 'link_checks_log', id: checkId, limit: 30 } }),
            ]);
            const found = rowRes.data?.status === 'success' ? (rowRes.data.data.rows || [])[0] : null;
            setRow(found || null);
            setMissing(rowRes.data?.status === 'success' && !found);
            setLog(logRes.data?.status === 'success' ? (logRes.data.data || []) : []);
        } catch {
            setMissing(true);
        }
    }, [checkId]);

    useEffect(() => { load(); }, [load]);

    const checkNow = async () => {
        setBusy(true);
        try {
            await axios.post(API_URL, { ids: [checkId] }, { params: { action: 'link_check_now' } });
            await load();
            if (onChanged) onChanged();
        } catch {
            // The status stays as it is; the next poll tells the truth.
        } finally {
            setBusy(false);
        }
    };

    if (missing) {
        return (
            <div className="page-card flex items-center justify-between gap-3 text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                {t('linkChecks.notFound')}
                <button type="button" className="btn btn-ghost btn-sm" onClick={onClose}><X size={14} /></button>
            </div>
        );
    }
    if (!row) return null;

    const latest = log[0] || null;
    const hops = latest?.hops || [];
    const muted = { color: 'var(--color-text-secondary)' };

    return (
        <div className="page-card space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-muted)' }}>
                            {t(row.object_type === 'domain' ? 'linkChecks.domain' : 'linkChecks.offer')}
                        </span>
                        <StatusPill status={row.status} />
                    </div>
                    <h3 className="text-base font-semibold mt-1 truncate" title={row.label} style={{ color: 'var(--color-text-primary)' }}>{row.label}</h3>
                    <div className="font-mono text-xs mt-1 break-all" style={{ color: 'var(--color-text-muted)' }}>{row.url}</div>
                    {row.status !== 'ok' && row.status !== 'pending' && (
                        <p className="text-sm mt-2" style={{ color: 'var(--color-text-primary)' }}>{reasonText(t, row.error_code, row)}</p>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    {canCheck && (
                        <button type="button" className="btn btn-secondary" onClick={checkNow} disabled={busy}>
                            <RefreshCw size={14} className={busy ? 'animate-spin' : ''} />
                            {t('linkChecks.checkNow')}
                        </button>
                    )}
                    {onEdit && (
                        <button type="button" className="btn btn-ghost" title={t('linkChecks.settings')} onClick={() => onEdit(row)}>
                            <Settings size={16} />
                        </button>
                    )}
                    <button type="button" className="btn btn-ghost" title={t('common.close')} onClick={onClose}>
                        <X size={16} />
                    </button>
                </div>
            </div>

            <dl className="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs" style={muted}>
                <div>
                    <dt>{t('linkChecks.colLast')}</dt>
                    <dd style={{ color: 'var(--color-text-primary)' }}>{agoText(t, row.last_checked_at_ts) || t('linkChecks.never')}</dd>
                </div>
                <div>
                    <dt>{t('linkChecks.colNext')}</dt>
                    <dd style={{ color: 'var(--color-text-primary)' }}>{agoText(t, row.next_check_at_ts)}</dd>
                </div>
                <div>
                    <dt>{t('linkChecks.colProxy')}</dt>
                    <dd style={{ color: 'var(--color-text-primary)' }}>{row.proxy_name || t('linkChecks.noProxy')}</dd>
                </div>
                <div>
                    <dt>{t('linkChecks.interval')}</dt>
                    <dd style={{ color: 'var(--color-text-primary)' }}>
                        {row.interval_min ? t('linkChecks.everyMin', { n: row.interval_min }) : t('linkChecks.intervalDefaultShort')}
                    </dd>
                </div>
                {row.final_url && (
                    <div className="col-span-2">
                        <dt>{t('linkChecks.finalUrl')}</dt>
                        <dd className="font-mono break-all" style={{ color: 'var(--color-text-primary)' }}>{row.final_url}</dd>
                    </div>
                )}
                {row.learned_domain && (
                    <div className="col-span-2">
                        <dt>{t('linkChecks.expected')}</dt>
                        <dd style={{ color: 'var(--color-text-primary)' }}>{row.learned_domain}</dd>
                    </div>
                )}
            </dl>

            <div>
                <h4 className="text-sm font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('linkChecks.chain')}</h4>
                {!latest ? (
                    <p className="text-xs" style={muted}>{t('linkChecks.noHistory')}</p>
                ) : hops.length === 0 ? (
                    <p className="text-xs" style={muted}>{t('linkChecks.noHops')}</p>
                ) : (
                    <ol className="space-y-1.5">
                        {hops.map((hop, i) => (
                            <li key={i} className="flex flex-wrap items-center gap-2 text-xs">
                                <span className="w-5 text-right" style={{ color: 'var(--color-text-muted)' }}>{i + 1}</span>
                                <span className="font-mono break-all" style={{ color: 'var(--color-text-primary)' }}>{hop.url}</span>
                                {hop.status > 0 && <span className="badge" style={{ color: codeTone(hop.status), borderColor: 'currentColor' }}>HTTP {hop.status}</span>}
                                {hop.error && <span style={{ color: 'var(--color-danger, #dc2626)' }}>{hop.error}</span>}
                                {hop.ms > 0 && <span style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.ms', { n: hop.ms })}</span>}
                                {hop.location && (
                                    <span style={{ color: 'var(--color-text-muted)' }}>
                                    → {t('linkChecks.redirectsTo', { host: hostOf(hop.location) || hop.location })}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </div>

            {log.length > 0 && (
                <div>
                    <h4 className="text-sm font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('linkChecks.history')}</h4>
                    <div className="overflow-x-auto rounded-lg border" style={{ borderColor: 'var(--color-border)' }}>
                        <table className="w-full text-xs" style={{ borderCollapse: 'collapse' }}>
                            <thead>
                                <tr style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                                    {[t('linkChecks.colTime'), t('linkChecks.colStatus'), t('linkChecks.colReason'), t('linkChecks.colDuration')].map((label) => (
                                        <th key={label} data-col={label} className="text-left font-semibold whitespace-nowrap" style={{ ...CELL, color: 'var(--color-text-secondary)' }}>{label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {log.map((entry) => (
                                    <tr key={entry.id} style={{ borderTop: '1px solid var(--color-border)' }}>
                                        <td className="whitespace-nowrap" style={{ ...CELL, color: 'var(--color-text-secondary)' }}>{agoText(t, entry.started_at_ts)}</td>
                                        <td style={CELL}><StatusPill status={entry.status} /></td>
                                        <td style={{ ...CELL, color: 'var(--color-text-secondary)' }} title={chainText(entry.hops)}>{reasonText(t, entry.error_code, { ...row, ...entry })}</td>
                                        <td className="whitespace-nowrap" style={{ ...CELL, color: 'var(--color-text-muted)' }}>{entry.duration_ms != null ? t('linkChecks.ms', { n: entry.duration_ms }) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
