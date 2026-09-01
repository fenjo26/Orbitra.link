import React, { useState, useEffect, useCallback } from 'react';
import { Bell, KeyRound, Download, RefreshCw, Trash2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { canAccessTab, canWriteResource } from '../utils/permissions';
import axios from 'axios';

const API_URL = '/api.php';

// Push Base — the own-VAPID subscriber collection (phase 3). Minimal
// management surface: VAPID key card, subscriber table with status/country
// filters, CSV export. Sending lives in a later phase.

export default function PushBasePage({ user }) {
    const { t } = useLanguage();
    const [vapid, setVapid] = useState({ has_keys: false, public_key: '' });
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [totalActive, setTotalActive] = useState(0);
    const [page, setPage] = useState(1);
    const [pages, setPages] = useState(1);
    const [statusFilter, setStatusFilter] = useState('all');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const canWrite = canWriteResource(user, 'push');

    const load = useCallback(async (opts = {}) => {
        setLoading(true);
        setError('');
        try {
            const p = opts.page ?? page;
            const res = await axios.get(API_URL, {
                params: { action: 'push_subscribers', page: p, status: statusFilter },
            });
            const data = res.data?.data;
            if (data) {
                setRows(data.rows || []);
                setTotal(data.total || 0);
                setTotalActive(data.total_active || 0);
                setPage(data.page || 1);
                setPages(data.pages || 1);
            }
        } catch (e) {
            setError(e?.response?.data?.message || t('push.loadFailed'));
        } finally {
            setLoading(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, statusFilter]);

    const loadVapid = useCallback(async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'push_vapid_status' } });
            if (res.data?.data) setVapid(res.data.data);
        } catch { /* status is cosmetic when the API denies */ }
    }, []);

    useEffect(() => { loadVapid(); }, [loadVapid]);
    useEffect(() => { load({ page: 1 }); setPage(1); /* eslint-disable-line */ }, [statusFilter]);

    const generateKeys = async () => {
        if (!canWrite) return;
        const confirmed = !vapid.has_keys
            || window.confirm(t('push.rotateConfirm'));
        if (!confirmed) return;
        try {
            const res = await axios.post(`${API_URL}?action=push_vapid_generate`, { confirm: vapid.has_keys });
            if (res.data?.status === 'success') {
                setVapid({ has_keys: true, public_key: res.data.data.public_key });
            }
        } catch (e) {
            setError(e?.response?.data?.message || t('push.loadFailed'));
        }
    };

    const op = async (id, action) => {
        try {
            await axios.post(`${API_URL}?action=push_subscribers_op`, { ids: [id], op: action });
            load();
        } catch { setError(t('push.loadFailed')); }
    };

    if (user && !canAccessTab(user, 'push')) {
        return <div className="page-card"><div className="empty-state">{t('push.noAccess')}</div></div>;
    }

    return (
        <div className="page-card">
            <div className="page-header">
                <div className="flex flex-wrap gap-3 items-center">
                    <button onClick={() => load()} className="btn btn-primary">
                        <RefreshCw className="w-4 h-4" />
                        {t('common.refresh') || t('push.refresh')}
                    </button>
                    <a href={`${API_URL}?action=push_subscribers_export`} className="btn btn-secondary">
                        <Download className="w-4 h-4" />
                        {t('push.exportCsv')}
                    </a>
                </div>
            </div>

            <div className="page-card" style={{ margin: '0 0 16px', padding: 20, boxShadow: 'none', border: '1px solid var(--color-border)' }}>
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="flex items-start gap-3" style={{ minWidth: 0 }}>
                        <Bell className="w-5 h-5" style={{ color: 'var(--color-primary)', flex: 'none', marginTop: 2 }} />
                        <div style={{ minWidth: 0 }}>
                            <div className="text-sm font-semibold">{t('push.vapidTitle')}</div>
                            <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('push.vapidHint')}</div>
                            {vapid.has_keys ? (
                                <div className="text-xs mt-2 font-mono" style={{ wordBreak: 'break-all', color: 'var(--color-text-secondary)' }}>
                                    {vapid.public_key}
                                </div>
                            ) : (
                                <div className="text-xs mt-2" style={{ color: 'var(--color-danger)' }}>{t('push.vapidMissing')}</div>
                            )}
                        </div>
                    </div>
                    {canWrite && (
                        <button type="button" className="btn btn-secondary text-sm" onClick={generateKeys}>
                            <KeyRound className="w-4 h-4" />
                            {vapid.has_keys ? t('push.rotateKeys') : t('push.generateKeys')}
                        </button>
                    )}
                </div>
            </div>

            {error && (
                <div className="alert" style={{ background: 'color-mix(in srgb, var(--color-danger) 12%, transparent)', color: 'var(--color-danger)', margin: '12px 0', fontSize: '13px' }}>
                    {error}
                </div>
            )}

            <div className="flex flex-wrap gap-3 items-center mb-3">
                <select className="form-select" style={{ width: 'auto' }} value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); }}>
                    <option value="all">{t('push.filterAll')}</option>
                    <option value="active">{t('push.filterActive')}</option>
                    <option value="dead">{t('push.filterDead')}</option>
                </select>
                <span className="text-sm" style={{ color: 'var(--color-text-muted)' }}>
                    {t('push.totalActive')}: <b style={{ color: 'var(--color-text-primary)' }}>{totalActive.toLocaleString()}</b> · {t('push.totalAll')}: {total.toLocaleString()}
                </span>
                {loading && <RefreshCw className="w-4 h-4 animate-spin" style={{ color: 'var(--color-primary)' }} />}
            </div>

            <div className="overflow-x-auto">
                <table className="tracker-table w-full">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>{t('push.created')}</th>
                            <th>{t('push.country')}</th>
                            <th>{t('push.language')}</th>
                            <th>{t('push.clickId')}</th>
                            <th>{t('push.endpoint')}</th>
                            <th>{t('push.status')}</th>
                            {canWrite && <th />}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && !loading && (
                            <tr><td colSpan={8} className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('push.empty')}</td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="cell-text">{r.id}</td>
                                <td className="cell-text" style={{ whiteSpace: 'nowrap' }}>{String(r.created_at || '').replace('T', ' ').slice(0, 16)}</td>
                                <td className="cell-text">{r.country_code || '—'}</td>
                                <td className="cell-text">{r.language || '—'}</td>
                                <td className="cell-text" style={{ maxWidth: 140, overflow: 'hidden', textOverflow: 'ellipsis' }} title={r.click_id || ''}>{r.click_id || '—'}</td>
                                <td className="cell-text" style={{ maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis' }} title={r.endpoint}>{r.endpoint}</td>
                                <td>
                                    <span className="px-2 py-1 rounded text-xs font-semibold" style={{
                                        backgroundColor: r.is_active ? 'color-mix(in srgb, var(--color-success) 15%, transparent)' : 'color-mix(in srgb, var(--color-danger) 12%, transparent)',
                                        color: r.is_active ? 'var(--color-success)' : 'var(--color-danger)',
                                    }}>
                                        {r.is_active ? t('push.statusLive') : t('push.statusDead')}
                                    </span>
                                </td>
                                {canWrite && (
                                    <td>
                                        <button
                                            type="button"
                                            className="action-btn text-red"
                                            title={t('push.delete')}
                                            onClick={() => { if (window.confirm(t('push.deleteConfirm'))) op(r.id, 'delete'); }}
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {pages > 1 && (
                <div className="flex items-center justify-between mt-3">
                    <button type="button" className="btn btn-secondary btn-sm" disabled={page <= 1} onClick={() => { setPage(page - 1); load({ page: page - 1 }); }}>
                        ← {t('push.prev')}
                    </button>
                    <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{page} / {pages}</span>
                    <button type="button" className="btn btn-secondary btn-sm" disabled={page >= pages} onClick={() => { setPage(page + 1); load({ page: page + 1 }); }}>
                        {t('push.next')} →
                    </button>
                </div>
            )}
        </div>
    );
}
