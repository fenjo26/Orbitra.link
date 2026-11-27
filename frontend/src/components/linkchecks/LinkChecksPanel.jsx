import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { RefreshCw, Search, Settings, X } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import LinkCheckDetails from './LinkCheckDetails';
import LinkCheckSettingsModal from './LinkCheckSettingsModal';
import { takeLinkChecksFocus } from './useLinkChecks';
import { agoText, reasonText, statusTone } from './reason';

const API_URL = '/api.php';
const POLL_MS = 60000;
const CELL = { padding: '10px 12px', verticalAlign: 'top' };

export const StatusPill = ({ status }) => {
    const { t } = useLanguage();
    const tone = statusTone(status);
    return (
        <span
            className={`badge whitespace-nowrap ${tone === 'muted' ? '' : `badge-${tone}`}`}
            style={tone === 'muted' ? { color: 'var(--color-text-muted)' } : undefined}
        >
            {t(`linkChecks.${status || 'pending'}`)}
        </span>
    );
};

// The "Checks" sub-tab of Notifications: one list of everything the tracker
// opens on a schedule — offers and domains — with what is broken and why.
// Problems come first; a row opens its chain of redirects and history.
export default function LinkChecksPanel({ user }) {
    const { t } = useLanguage();
    const isAdmin = user?.role === 'admin';
    const [summary, setSummary] = useState(null);
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [type, setType] = useState('');
    const [status, setStatus] = useState('problem');
    const [q, setQ] = useState('');
    const [query, setQuery] = useState('');
    // A dot on another page may have named the link to open.
    const [selectedId, setSelectedId] = useState(() => takeLinkChecksFocus());
    const [editing, setEditing] = useState(null);
    const [proxies, setProxies] = useState([]);
    const [busyIds, setBusyIds] = useState([]);
    const [notice, setNotice] = useState('');

    // The search settles before it is sent.
    useEffect(() => {
        const timer = setTimeout(() => setQuery(q.trim()), 350);
        return () => clearTimeout(timer);
    }, [q]);

    const load = useCallback(async () => {
        try {
            const [listRes, sumRes] = await Promise.all([
                axios.get(API_URL, { params: { action: 'link_checks_list', type, status, q: query, limit: 200 } }),
                axios.get(API_URL, { params: { action: 'link_checks_summary' } }),
            ]);
            setFailed(listRes.data?.status !== 'success' || sumRes.data?.status !== 'success');
            if (listRes.data?.status === 'success') {
                setRows(listRes.data.data.rows || []);
                setTotal(listRes.data.data.total || 0);
            }
            if (sumRes.data?.status === 'success') setSummary(sumRes.data.data);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [type, status, query]);

    useEffect(() => {
        load();
        const timer = setInterval(load, POLL_MS);
        return () => clearInterval(timer);
    }, [load]);

    // A dot next to an offer or a domain names the link to open.
    useEffect(() => {
        const onFocus = (e) => setSelectedId(Number(e?.detail?.check) || null);
        window.addEventListener('orbitra:link-checks-focus', onFocus);
        return () => window.removeEventListener('orbitra:link-checks-focus', onFocus);
    }, []);

    const say = (text) => {
        setNotice(text);
        setTimeout(() => setNotice(''), 4000);
    };

    const checkNow = async (ids) => {
        setBusyIds(ids);
        try {
            const res = await axios.post(API_URL, { ids }, { params: { action: 'link_check_now' } });
            say(res.data?.status === 'success' ? t('linkChecks.queued', { n: res.data.data.queued }) : (res.data?.message || t('common.error')));
            if (res.data?.status === 'success') await load();
        } catch {
            say(t('common.error'));
        } finally {
            setBusyIds([]);
        }
    };

    // The settings dialog offers the proxies; they are read when it opens, so
    // a proxy added a minute ago in Integrations is already on the list.
    const openSettings = async (row) => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'check_proxies' } });
            if (res.data?.status === 'success') setProxies(res.data.data || []);
        } catch {
            // The dialog still opens with the default route.
        }
        setEditing(row);
    };

    const count = (key) => (summary ? (summary.offers?.[key] || 0) + (summary.domains?.[key] || 0) : 0);
    const problemCount = count('failed') + count('warning');
    const chips = [
        { value: 'problem', label: t('linkChecks.statusProblem'), n: problemCount },
        { value: 'failed', label: t('linkChecks.failed'), n: count('failed') },
        { value: 'warning', label: t('linkChecks.warning'), n: count('warning') },
        { value: 'ok', label: t('linkChecks.ok'), n: count('ok') },
        { value: '', label: t('linkChecks.statusAll'), n: null },
    ];
    const columns = [
        ['type', t('linkChecks.colType')],
        ['name', t('linkChecks.colName')],
        ['status', t('linkChecks.colStatus')],
        ['reason', t('linkChecks.colReason')],
        ['last', t('linkChecks.colLast')],
        ['next', t('linkChecks.colNext')],
        ['proxy', t('linkChecks.colProxy')],
        ['actions', ''],
    ];
    const hasFilters = type || status || query;
    // The default view with nothing in it is good news, not an empty search.
    const allGood = !type && !query && status === 'problem' && count('total') > 0 && problemCount === 0;

    if (loading) {
        return (
            <div className="flex justify-center py-10">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }} />
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {summary && !summary.enabled && (
                <div className="rounded-lg border px-4 py-3 text-sm" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                    {t(isAdmin ? 'linkChecks.featureOffAdmin' : 'linkChecks.featureOff')}
                </div>
            )}
            {failed && (
                <div className="rounded-lg border px-4 py-3 text-sm" style={{ borderColor: 'var(--color-border)', color: 'var(--color-danger, #dc2626)' }}>
                    {t('linkChecks.loadFailed')}
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {chips.map((chip) => (
                    <button
                        key={chip.value || 'all'}
                        type="button"
                        onClick={() => setStatus(chip.value)}
                        className="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors"
                        style={{
                            backgroundColor: status === chip.value ? 'var(--color-primary-light)' : 'transparent',
                            color: status === chip.value ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                        }}
                    >
                        {chip.label}{chip.n !== null && <span className="ml-1.5" style={{ opacity: 0.75 }}>{chip.n}</span>}
                    </button>
                ))}
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <select
                        value={type}
                        onChange={(e) => setType(e.target.value)}
                        className="form-select w-40"
                        aria-label={t('linkChecks.colType')}
                    >
                        <option value="">{t('linkChecks.typeAll')}</option>
                        <option value="offer">{t('linkChecks.typeOffer')}</option>
                        <option value="domain">{t('linkChecks.typeDomain')}</option>
                    </select>
                    <div className="relative">
                        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('linkChecks.searchPlaceholder')}
                            className="form-input w-56"
                            style={{ paddingLeft: '32px' }}
                        />
                    </div>
                    {isAdmin && (
                        <button type="button" className="btn btn-secondary" onClick={() => checkNow(rows.map((row) => row.id))} disabled={busyIds.length > 0 || rows.length === 0}>
                            <RefreshCw size={14} className={busyIds.length ? 'animate-spin' : ''} />
                            {t('linkChecks.checkAll')}
                        </button>
                    )}
                </div>
            </div>

            {notice && <div className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{notice}</div>}
            {rows.length > 0 && rows.length < total && (
                <div className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.shown', { shown: rows.length, total })}</div>
            )}

            {rows.length === 0 ? (
                <div className="text-center py-12">
                    <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                        {allGood ? t('linkChecks.allGood') : hasFilters ? t('linkChecks.nothingFound') : t('linkChecks.noChecks')}
                    </p>
                    {hasFilters && !allGood && (
                        <button type="button" className="btn btn-ghost btn-sm mt-3" onClick={() => { setType(''); setStatus(''); setQ(''); }}>
                            <X size={14} /> {t('linkChecks.resetFilters')}
                        </button>
                    )}
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border" style={{ borderColor: 'var(--color-border)' }}>
                    <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                        <thead>
                            <tr style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                                {columns.map(([key, label]) => (
                                    <th key={key} data-col={key} className="text-left font-semibold whitespace-nowrap" style={{ ...CELL, color: 'var(--color-text-secondary)', fontSize: '12px' }}>
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.id}
                                    onClick={() => setSelectedId(row.id === selectedId ? null : row.id)}
                                    className="cursor-pointer transition hover:bg-[var(--color-bg-hover)]"
                                    style={{ borderTop: '1px solid var(--color-border)', ...(row.id === selectedId ? { backgroundColor: 'var(--color-primary-light)' } : {}) }}
                                >
                                    <td style={CELL} className="whitespace-nowrap text-xs">
                                        {t(row.object_type === 'domain' ? 'linkChecks.domain' : 'linkChecks.offer')}
                                    </td>
                                    <td style={CELL}>
                                        <div className="font-medium truncate max-w-[280px]" title={row.label} style={{ color: 'var(--color-text-primary)' }}>{row.label}</div>
                                        <div className="font-mono truncate max-w-[280px]" title={row.url} style={{ color: 'var(--color-text-muted)', fontSize: '11px' }}>{row.url}</div>
                                    </td>
                                    <td style={CELL} className="whitespace-nowrap">
                                        <StatusPill status={row.status} />
                                        {row.status === 'warning' && row.fail_streak === 1 && (
                                            <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.confirming')}</div>
                                        )}
                                    </td>
                                    <td className="text-xs" style={{ ...CELL, color: 'var(--color-text-secondary)' }}>{reasonText(t, row.error_code, row)}</td>
                                    <td style={{ ...CELL, color: 'var(--color-text-secondary)' }} className="whitespace-nowrap text-xs">{agoText(t, row.last_checked_at_ts) || t('linkChecks.never')}</td>
                                    <td style={{ ...CELL, color: 'var(--color-text-secondary)' }} className="whitespace-nowrap text-xs">{agoText(t, row.next_check_at_ts)}</td>
                                    <td style={{ ...CELL, color: 'var(--color-text-secondary)' }} className="text-xs">{row.proxy_name || ''}</td>
                                    <td style={CELL} className="whitespace-nowrap text-right" onClick={(e) => e.stopPropagation()}>
                                        {isAdmin && (
                                            <button type="button" className="btn btn-ghost btn-sm" title={t('linkChecks.checkNow')} disabled={busyIds.includes(row.id)} onClick={() => checkNow([row.id])}>
                                                <RefreshCw size={14} className={busyIds.includes(row.id) ? 'animate-spin' : ''} />
                                            </button>
                                        )}
                                        {isAdmin && (
                                            <button type="button" className="btn btn-ghost btn-sm" title={t('linkChecks.settings')} onClick={() => openSettings(row)}>
                                                <Settings size={14} />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {selectedId !== null && (
                <LinkCheckDetails
                    checkId={selectedId}
                    onClose={() => setSelectedId(null)}
                    onChanged={load}
                    canCheck={isAdmin}
                    onEdit={isAdmin ? (row) => openSettings(row) : null}
                />
            )}

            {editing && (
                <LinkCheckSettingsModal
                    row={editing}
                    proxies={proxies}
                    onClose={() => setEditing(null)}
                    onSaved={() => { setEditing(null); load(); }}
                />
            )}
        </div>
    );
}
