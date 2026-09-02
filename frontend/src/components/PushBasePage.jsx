import React, { useState, useEffect, useCallback } from 'react';
import { Bell, KeyRound, Download, RefreshCw, Trash2, Plus, Send, Pencil, MessageSquare, AlertTriangle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { canAccessTab, canWriteResource } from '../utils/permissions';
import SegmentedControl from './common/SegmentedControl';
import axios from 'axios';

const API_URL = '/api.php';

// Push Base — the own-VAPID subscriber base. Phase 3: collection (VAPID keys,
// subscriber table). Phase 4: the Messages tab — message CRUD, segments,
// delivery stats and "send now" (queued for cli/push_cron.php).

const SEGMENTS = ['all', 'reg0', 'reg1dep0', 'reg1dep1'];
const EVENTS = ['install', 'lead', 'sale'];
const EMPTY_FORM = {
    id: 0,
    title: '',
    text: '',
    icon_url: '',
    link_url: '',
    kind: 'manual',
    event: 'install',
    delay_seconds: 0,
    segment: 'all',
    active: true,
};

export default function PushBasePage({ user }) {
    const { t } = useLanguage();
    const [tab, setTab] = useState('subscribers');

    // --- subscribers tab state (phase 3) ---
    const [vapid, setVapid] = useState({ has_keys: false, public_key: '' });
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [totalActive, setTotalActive] = useState(0);
    const [page, setPage] = useState(1);
    const [pages, setPages] = useState(1);
    const [statusFilter, setStatusFilter] = useState('all');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    // --- messages tab state (phase 4) ---
    const [messages, setMessages] = useState([]);
    const [messagesLoading, setMessagesLoading] = useState(false);
    const [queue, setQueue] = useState({ pending: 0, done: 0, failed: 0, last_run_at: '', last_fail_code: null });
    const [contactDraft, setContactDraft] = useState('');
    const [contactSaving, setContactSaving] = useState(false);
    const [contactSaved, setContactSaved] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [form, setForm] = useState(EMPTY_FORM);
    const [saving, setSaving] = useState(false);
    const [info, setInfo] = useState('');

    const canWrite = canWriteResource(user, 'push');

    // Nothing in this panel sends a push: every message is queued and drained
    // by cli/push_cron.php. With that cron missing, "send now" reports
    // "queued: 1" forever and the phone never rings — which reads as broken
    // push rather than as a missing crontab line. push_cron stamps
    // push_cron_last_ping_at on every run, so a stamp older than 15 minutes
    // (or absent) with rows waiting is proof the worker is not running.
    const workerStalled = (() => {
        if (!Number(queue.pending || 0)) return false;
        const stamp = String(queue.last_run_at || '').trim();
        if (!stamp) return true;
        // SQLite writes 'YYYY-MM-DD HH:MM:SS' in UTC.
        const ts = Date.parse(stamp.replace(' ', 'T') + 'Z');
        if (Number.isNaN(ts)) return false;
        return Date.now() - ts > 15 * 60 * 1000;
    })();

    const segmentLabel = useCallback((seg) => ({
        all: t('push.segmentAll'),
        reg0: t('push.segmentReg0'),
        reg1dep0: t('push.segmentReg1Dep0'),
        reg1dep1: t('push.segmentReg1Dep1'),
    }[seg] || seg), [t]);

    const eventLabel = useCallback((ev) => ({
        install: t('push.eventInstall'),
        lead: t('push.eventLead'),
        sale: t('push.eventSale'),
    }[ev] || (ev || '—')), [t]);

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

    const loadMessages = useCallback(async () => {
        setMessagesLoading(true);
        setError('');
        try {
            const [msgs, queueRes] = await Promise.all([
                axios.get(API_URL, { params: { action: 'push_messages' } }),
                axios.get(API_URL, { params: { action: 'push_queue_list' } }),
            ]);
            setMessages(msgs.data?.data?.rows || []);
            if (queueRes.data?.data) setQueue(queueRes.data.data);
        } catch (e) {
            setError(e?.response?.data?.message || t('push.loadFailed'));
        } finally {
            setMessagesLoading(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const loadVapid = useCallback(async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'push_vapid_status' } });
            if (res.data?.data) {
                setVapid(res.data.data);
                setContactDraft(res.data.data.contact || '');
            }
        } catch { /* status is cosmetic when the API denies */ }
    }, []);

    useEffect(() => { loadVapid(); }, [loadVapid]);
    useEffect(() => { load({ page: 1 }); setPage(1); /* eslint-disable-line */ }, [statusFilter]);
    useEffect(() => { if (tab === 'messages') loadMessages(); /* eslint-disable-line */ }, [tab]);

    const translateApiError = useCallback((e, fallback) => {
        const msg = e?.response?.data?.message || '';
        if (msg === 'push.keys_exist') return t('push.keysExist');
        if (msg === 'push.contactInvalid') return t('push.contactInvalid');
        if (msg === 'push.titleTextRequired') return t('push.titleTextRequired');
        if (msg === 'push.tooLong') return t('push.tooLong');
        if (msg === 'push.eventRequired') return t('push.eventRequired');
        return msg || fallback;
    }, [t]);

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
            setError(translateApiError(e, t('push.loadFailed')));
        }
    };

    // The VAPID "sub" claim. Apple's push service validates it and answers
    // 403 on a contact it rejects, so the placeholder default has to be
    // replaceable from here — nothing else in the panel writes this row.
    const saveContact = async () => {
        if (!canWrite || contactSaving) return;
        setContactSaving(true);
        setError('');
        try {
            const res = await axios.post(`${API_URL}?action=push_vapid_contact_save`, { contact: contactDraft.trim() });
            if (res.data?.status === 'success') {
                setVapid((v) => ({ ...v, contact: res.data.data.contact }));
                setContactSaved(true);
                setTimeout(() => setContactSaved(false), 2500);
            } else {
                setError(res.data?.message === 'push.contactInvalid' ? t('push.contactInvalid') : t('push.loadFailed'));
            }
        } catch (e) {
            setError(translateApiError(e, t('push.loadFailed')));
        } finally {
            setContactSaving(false);
        }
    };

    const op = async (id, action) => {
        try {
            await axios.post(`${API_URL}?action=push_subscribers_op`, { ids: [id], op: action });
            load();
        } catch { setError(t('push.loadFailed')); }
    };

    const openNewMessage = () => {
        setForm(EMPTY_FORM);
        setInfo('');
        setModalOpen(true);
    };

    const openEditMessage = (m) => {
        setForm({
            id: m.id,
            title: m.title || '',
            text: m.text || '',
            icon_url: m.icon_url || '',
            link_url: m.link_url || '',
            kind: m.kind === 'event' ? 'event' : 'manual',
            event: EVENTS.includes(m.event) ? m.event : 'install',
            delay_seconds: Number(m.delay_seconds) || 0,
            segment: SEGMENTS.includes(m.segment) ? m.segment : 'all',
            active: Number(m.active) === 1,
        });
        setInfo('');
        setModalOpen(true);
    };

    const saveMessage = async () => {
        if (!canWrite || saving) return;
        setSaving(true);
        setError('');
        try {
            await axios.post(`${API_URL}?action=push_message_save`, {
                id: form.id || undefined,
                title: form.title,
                text: form.text,
                icon_url: form.icon_url,
                link_url: form.link_url,
                kind: form.kind,
                event: form.kind === 'event' ? form.event : null,
                delay_seconds: Number(form.delay_seconds) || 0,
                segment: form.segment,
                active: form.active ? 1 : 0,
            });
            setModalOpen(false);
            loadMessages();
        } catch (e) {
            setError(translateApiError(e, t('push.saveFailed')));
        } finally {
            setSaving(false);
        }
    };

    const deleteMessage = async (id) => {
        if (!canWrite || !window.confirm(t('push.messageDeleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=push_message_delete`, { id });
            loadMessages();
        } catch (e) {
            setError(translateApiError(e, t('push.saveFailed')));
        }
    };

    const sendNow = async (id) => {
        if (!canWrite || !window.confirm(t('push.confirmSend'))) return;
        setError('');
        setInfo('');
        try {
            const res = await axios.post(`${API_URL}?action=push_send_now`, { message_id: id });
            const n = Number(res.data?.data?.enqueued) || 0;
            setInfo(`${t('push.sendQueued')} ${n.toLocaleString()}`);
            loadMessages();
        } catch (e) {
            setError(translateApiError(e, t('push.sendFailed')));
        }
    };

    if (user && !canAccessTab(user, 'push')) {
        return <div className="page-card"><div className="empty-state">{t('push.noAccess')}</div></div>;
    }

    return (
        <div className="page-card">
            <div className="page-header">
                <div style={{ maxWidth: 420 }}>
                    <SegmentedControl
                        value={tab}
                        onChange={setTab}
                        ariaLabel={t('push.navLabel')}
                        options={[
                            { value: 'subscribers', label: t('push.tabSubscribers'), icon: Bell },
                            { value: 'messages', label: t('push.tabMessages'), icon: MessageSquare },
                        ]}
                    />
                </div>
                {tab === 'subscribers' ? (
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
                ) : (
                    canWrite && (
                        <button onClick={openNewMessage} className="btn btn-primary">
                            <Plus className="w-4 h-4" />
                            {t('push.newMessage')}
                        </button>
                    )
                )}
            </div>

            {tab === 'subscribers' && (
                <>
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
                                    {/* VAPID "sub": the contact every push service is
                                        handed with the JWT. Apple validates it and
                                        answers 403 on one it does not accept, so the
                                        built-in placeholder has to be replaceable. */}
                                    <div className="mt-3">
                                        <div className="text-xs font-medium">{t('push.contactLabel')}</div>
                                        <div className="text-xs mt-0.5" style={{ color: 'var(--color-text-muted)' }}>{t('push.contactHint')}</div>
                                        <div className="flex flex-wrap items-center gap-2 mt-1.5">
                                            <input
                                                type="text"
                                                className="form-input text-sm"
                                                style={{ width: 'auto', minWidth: 260 }}
                                                value={contactDraft}
                                                placeholder={vapid.contact_default || 'mailto:you@example.com'}
                                                onChange={(e) => setContactDraft(e.target.value)}
                                                disabled={!canWrite || contactSaving}
                                            />
                                            {canWrite && (
                                                <button type="button" className="btn btn-secondary text-sm" onClick={saveContact} disabled={contactSaving}>
                                                    {contactSaved ? t('editor.saved') : t('common.save')}
                                                </button>
                                            )}
                                        </div>
                                    </div>
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
                </>
            )}

            {tab === 'messages' && (
                <>
                    {error && (
                        <div className="alert" style={{ background: 'color-mix(in srgb, var(--color-danger) 12%, transparent)', color: 'var(--color-danger)', margin: '12px 0', fontSize: '13px' }}>
                            {error}
                        </div>
                    )}
                    {info && (
                        <div className="alert" style={{ background: 'color-mix(in srgb, var(--color-success) 12%, transparent)', color: 'var(--color-success)', margin: '12px 0', fontSize: '13px' }}>
                            {info}
                        </div>
                    )}

                    {workerStalled && (
                        <div className="alert flex items-start gap-2" style={{ background: 'color-mix(in srgb, var(--color-warning, #d97706) 12%, transparent)', color: 'var(--color-warning, #d97706)', margin: '12px 0', fontSize: '13px' }}>
                            <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" />
                            <span>
                                {t('push.workerStalled')}
                                <code className="block mt-1 select-all" style={{ fontSize: '12px' }}>
                                    * * * * * php /var/www/orbitra/cli/push_cron.php --quiet &gt;&gt; /var/www/orbitra/var/logs/push.log 2&gt;&amp;1
                                </code>
                            </span>
                        </div>
                    )}

                    <div className="flex flex-wrap gap-3 items-center mb-3">
                        <span className="text-sm" style={{ color: 'var(--color-text-muted)' }}>
                            {t('push.queued')}: <b style={{ color: 'var(--color-text-primary)' }}>{Number(queue.pending || 0).toLocaleString()}</b>
                            {' · '}{t('push.sent')}: {Number(queue.done || 0).toLocaleString()}
                            {' · '}{t('push.failed')}: {Number(queue.failed || 0).toLocaleString()}
                            {Number(queue.failed || 0) > 0 && queue.last_fail_code != null && (
                                <> {' · '}{t('push.lastFailCode')}: <b style={{ color: 'var(--color-danger)' }}>{queue.last_fail_code || t('push.failCodeNoReply')}</b></>
                            )}
                        </span>
                        <button onClick={() => loadMessages()} className="btn btn-secondary btn-sm">
                            <RefreshCw className={`w-4 h-4 ${messagesLoading ? 'animate-spin' : ''}`} />
                            {t('common.refresh') || t('push.refresh')}
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="tracker-table w-full">
                            <thead>
                                <tr>
                                    <th>{t('push.messageTitle')}</th>
                                    <th>{t('push.kind')}</th>
                                    <th>{t('push.segment')}</th>
                                    <th>{t('push.sent')}</th>
                                    <th>{t('push.failed')}</th>
                                    <th>{t('push.queued')}</th>
                                    <th>{t('push.created')}</th>
                                    {canWrite && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {messages.length === 0 && !messagesLoading && (
                                    <tr><td colSpan={8} className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('push.messagesEmpty')}</td></tr>
                                )}
                                {messages.map((m) => (
                                    <tr key={m.id}>
                                        <td className="cell-text" style={{ maxWidth: 280 }}>
                                            <div className="font-semibold" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={m.title}>{m.title}</div>
                                            <div className="text-xs" style={{ color: 'var(--color-text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={m.text}>{m.text}</div>
                                        </td>
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap' }}>
                                            {m.kind === 'event' ? (
                                                <span className="px-2 py-1 rounded text-xs font-semibold" style={{
                                                    backgroundColor: 'color-mix(in srgb, var(--color-primary) 12%, transparent)',
                                                    color: 'var(--color-primary)',
                                                }}>
                                                    {t('push.kindEvent')}: {eventLabel(m.event)}
                                                    {Number(m.delay_seconds) > 0 ? ` +${Number(m.delay_seconds).toLocaleString()}${t('push.delayUnit')}` : ''}
                                                </span>
                                            ) : t('push.kindManual')}
                                        </td>
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap' }}>{segmentLabel(m.segment)}</td>
                                        <td className="cell-text" style={{ color: 'var(--color-success)' }}>{Number(m.sent || 0).toLocaleString()}</td>
                                        <td className="cell-text" style={{ color: Number(m.failed || 0) > 0 ? 'var(--color-danger)' : undefined }}>{Number(m.failed || 0).toLocaleString()}</td>
                                        <td className="cell-text">{Number(m.queued || 0).toLocaleString()}</td>
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap' }}>{String(m.created_at || '').replace('T', ' ').slice(0, 16)}</td>
                                        {canWrite && (
                                            <td>
                                                <div className="flex gap-1">
                                                    <button
                                                        type="button"
                                                        className="action-btn"
                                                        title={t('push.sendNow')}
                                                        onClick={() => sendNow(m.id)}
                                                    >
                                                        <Send className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="action-btn"
                                                        title={t('common.edit')}
                                                        onClick={() => openEditMessage(m)}
                                                    >
                                                        <Pencil className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="action-btn text-red"
                                                        title={t('common.delete')}
                                                        onClick={() => deleteMessage(m.id)}
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}

            {modalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={() => setModalOpen(false)}>
                    <div className="page-card" style={{ width: 520, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto', margin: 0 }} onClick={(e) => e.stopPropagation()}>
                        <div className="text-base font-semibold mb-4">{form.id ? t('push.editMessage') : t('push.newMessage')}</div>

                        <label className="block text-xs font-semibold mb-1">{t('push.messageTitle')} *</label>
                        <input
                            className="form-input w-full mb-3"
                            maxLength={250}
                            value={form.title}
                            onChange={(e) => setForm({ ...form, title: e.target.value })}
                            placeholder={t('push.messageTitlePlaceholder')}
                        />

                        <label className="block text-xs font-semibold mb-1">{t('push.messageText')} *</label>
                        <textarea
                            className="form-input w-full mb-1"
                            rows={3}
                            maxLength={400}
                            value={form.text}
                            onChange={(e) => setForm({ ...form, text: e.target.value })}
                            placeholder={t('push.messageTextPlaceholder')}
                        />
                        <div className="text-xs mb-3" style={{ color: 'var(--color-text-muted)', textAlign: 'right' }}>{form.text.length} / 400</div>

                        <label className="block text-xs font-semibold mb-1">{t('push.iconUrl')}</label>
                        <input
                            className="form-input w-full mb-3"
                            value={form.icon_url}
                            onChange={(e) => setForm({ ...form, icon_url: e.target.value })}
                            placeholder="https://…/icon.png"
                        />

                        <label className="block text-xs font-semibold mb-1">{t('push.linkUrl')}</label>
                        <input
                            className="form-input w-full mb-1"
                            value={form.link_url}
                            onChange={(e) => setForm({ ...form, link_url: e.target.value })}
                            placeholder="https://tracker.example.com/camp?subid={subid}"
                        />
                        <div className="text-xs mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('push.linkHint')}</div>

                        <label className="block text-xs font-semibold mb-1">{t('push.segment')}</label>
                        <select
                            className="form-select w-full mb-3"
                            value={form.segment}
                            onChange={(e) => setForm({ ...form, segment: e.target.value })}
                        >
                            {SEGMENTS.map((seg) => (
                                <option key={seg} value={seg}>{segmentLabel(seg)}</option>
                            ))}
                        </select>

                        <div className="mb-3" style={{ maxWidth: 280 }}>
                            <SegmentedControl
                                value={form.kind}
                                onChange={(kind) => setForm({ ...form, kind })}
                                ariaLabel={t('push.kind')}
                                options={[
                                    { value: 'manual', label: t('push.kindManual'), icon: Send },
                                    { value: 'event', label: t('push.kindEvent'), icon: Bell },
                                ]}
                            />
                        </div>

                        {form.kind === 'event' && (
                            <div className="flex flex-wrap gap-3 mb-3">
                                <div>
                                    <label className="block text-xs font-semibold mb-1">{t('push.event')}</label>
                                    <select
                                        className="form-select"
                                        value={form.event}
                                        onChange={(e) => setForm({ ...form, event: e.target.value })}
                                    >
                                        {EVENTS.map((ev) => (
                                            <option key={ev} value={ev}>{eventLabel(ev)}</option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold mb-1">{t('push.delay')}</label>
                                    <input
                                        className="form-input"
                                        type="number"
                                        min={0}
                                        style={{ width: 120 }}
                                        value={form.delay_seconds}
                                        onChange={(e) => setForm({ ...form, delay_seconds: e.target.value })}
                                    />
                                    <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('push.delayHint')}</div>
                                </div>
                            </div>
                        )}

                        <label className="flex items-center gap-2 mb-4 text-sm">
                            <input
                                type="checkbox"
                                checked={form.active}
                                onChange={(e) => setForm({ ...form, active: e.target.checked })}
                            />
                            {t('push.filterActive')}
                        </label>

                        <div className="flex justify-end gap-2">
                            <button type="button" className="btn btn-secondary" onClick={() => setModalOpen(false)}>
                                {t('common.cancel')}
                            </button>
                            <button type="button" className="btn btn-primary" disabled={saving || !form.title.trim() || !form.text.trim()} onClick={saveMessage}>
                                {t('common.save')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
