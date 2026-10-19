import React, { useState, useEffect, useCallback } from 'react';
import { Bell, KeyRound, Download, RefreshCw, Trash2, Plus, Send, Pencil, MessageSquare, AlertTriangle } from 'lucide-react';
import { createPortal } from 'react-dom';
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
    image_url: '',
    link_url: '',
    countries: '',
    languages: '',
    kind: 'manual',
    event: 'install',
    delay_seconds: 0,
    segment: 'all',
    local_time: false,
    active: true,
};

export default function PushBasePage({ user }) {
    const { t } = useLanguage();
    const [tab, setTab] = useState('subscribers');
    // {offer.value} macro preview (stage 2.4): country + offer, expanded server-side.
    const [previewCountry, setPreviewCountry] = useState('');
    const [previewOfferId, setPreviewOfferId] = useState('');
    const [previewResult, setPreviewResult] = useState(null);
    const [offersSimple, setOffersSimple] = useState([]);

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
    const [testSendingId, setTestSendingId] = useState(0);

    const canWrite = canWriteResource(user, 'push');

    // The saved contact must be a real one: the API refuses key generation
    // without it (Apple validates the JWT "sub" claim and answers 403 to the
    // placeholder, killing every iOS send), so the UI mirrors that gate
    // instead of letting the click fail with a bare error.
    const contactValid = (() => {
        const c = String(vapid.contact || '').trim();
        if (!c) return false;
        if (vapid.contact_default && c === vapid.contact_default) return false;
        return /^mailto:/i.test(c) || /^https:\/\//i.test(c);
    })();

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

    // Diagnostics strip (subscribers tab): when the delivery worker last ran.
    const workerLastRunLabel = (() => {
        const stamp = String(queue.last_run_at || '').trim();
        if (!stamp) return t('push.diagWorkerNever');
        const ts = Date.parse(stamp.replace(' ', 'T') + 'Z');
        if (Number.isNaN(ts)) return t('push.diagWorkerNever');
        const min = Math.max(0, Math.round((Date.now() - ts) / 60000));
        return t('push.diagWorkerAgo').replace('{n}', min.toLocaleString());
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

    useEffect(() => { loadVapid(); loadMessages(); /* eslint-disable-line */ }, [loadVapid]);
    useEffect(() => { load({ page: 1 }); setPage(1); /* eslint-disable-line */ }, [statusFilter]);
    useEffect(() => { if (tab === 'messages') loadMessages(); /* eslint-disable-line */ }, [tab]);

    useEffect(() => {
        axios.get(API_URL, { params: { action: 'offers_simple' } })
            .then((res) => setOffersSimple(res.data?.data || []))
            .catch(() => {});
    }, []);

    const runPreview = () => {
        axios.post(API_URL, { text: form.text || form.title || '', country: previewCountry, offer_id: previewOfferId || 0 }, { params: { action: 'push_macro_preview' } })
            .then((res) => setPreviewResult(res.data?.data?.expanded ?? null))
            .catch(() => setPreviewResult(null));
    };

    const translateApiError = useCallback((e, fallback) => {
        const msg = e?.response?.data?.message || '';
        if (msg === 'push.keys_exist') return t('push.keysExist');
        if (msg === 'push.contactInvalid') return t('push.contactInvalid');
        if (msg === 'push.contactRequired') return t('push.contactRequired');
        if (msg === 'push.titleTextRequired') return t('push.titleTextRequired');
        if (msg === 'push.tooLong') return t('push.tooLong');
        if (msg === 'push.eventRequired') return t('push.eventRequired');
        return msg || fallback;
    }, [t]);

    const generateKeys = async () => {
        if (!canWrite) return;
        // The API refuses generation without a real contact — gate here so the
        // operator fixes the field instead of reading a bare error.
        if (!contactValid) {
            setError(t('push.contactRequired'));
            return;
        }
        if (vapid.has_keys) {
            // Rotation mints a new keypair: every existing subscription stays
            // bound to the old one (eternal 403s on sends) until each device
            // reopens the app and silently re-subscribes under the new key.
            const n = Number(totalActive) || 0;
            if (!window.confirm(t('push.rotateConfirm') + ' ' + t('push.rotateAffects').replace('{n}', n.toLocaleString()))) return;
        }
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

    // Direct test delivery to ONE subscriber, bypassing the queue: the answer
    // (ok / 403 / 404 / other) is the fastest "why is my pipe dead" probe.
    const sendTest = async (subscriptionId) => {
        if (!canWrite || testSendingId) return;
        setTestSendingId(subscriptionId);
        setError('');
        setInfo('');
        try {
            const res = await axios.post(`${API_URL}?action=push_test_send`, { subscription_id: subscriptionId });
            const d = res.data?.data || {};
            if (d.ok) {
                setInfo(t('push.testDelivered'));
            } else if (d.code === 403) {
                setError(t('push.fail403Hint'));
            } else if (d.code === 404 || d.code === 410) {
                setError(t('push.testDead'));
            } else {
                setError(`${t('push.testRejected')} ${d.code || ''} ${d.error || ''}`.trim());
            }
        } catch (e) {
            setError(translateApiError(e, t('push.sendFailed')));
        } finally {
            setTestSendingId(0);
        }
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
            image_url: m.image_url || '',
            link_url: m.link_url || '',
            countries: m.countries || '',
            local_time: Number(m.local_time) === 1,
            languages: m.languages || '',
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
                image_url: form.image_url,
                link_url: form.link_url,
                countries: form.countries,
                local_time: form.local_time ? 1 : 0,
                languages: form.languages,
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
                        onChange={(v) => { setTab(v); setInfo(''); setError(''); }}
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
                                    {/* Why-no-subscribers strip: keys, contact,
                                        worker liveness, base size and the last
                                        delivery failure code — the five things
                                        every "push does not work" report needs. */}
                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs mt-3" style={{ color: 'var(--color-text-muted)' }}>
                                        <span>{t('push.diagKeys')}: <b style={{ color: vapid.has_keys ? 'var(--color-success)' : 'var(--color-danger)' }}>{vapid.has_keys ? '✓' : '✗'}</b></span>
                                        <span>{t('push.diagContact')}: <b style={{ color: contactValid ? 'var(--color-success)' : 'var(--color-danger)' }}>{contactValid ? '✓' : '✗'}</b></span>
                                        <span>{t('push.diagWorker')}: <b style={{ color: workerStalled ? 'var(--color-danger)' : 'var(--color-text-primary)' }}>{workerLastRunLabel}</b></span>
                                        <span>{t('push.totalActive')}: <b style={{ color: 'var(--color-text-primary)' }}>{totalActive.toLocaleString()}</b></span>
                                        {queue.last_fail_code != null && (
                                            <span style={{ color: Number(queue.last_fail_code) === 403 ? 'var(--color-warning, #d97706)' : 'var(--color-danger)' }}>
                                                {t('push.lastFailCode')}: <b>{queue.last_fail_code || t('push.failCodeNoReply')}</b>
                                                {Number(queue.last_fail_code) === 403 ? <> — {t('push.fail403Hint')}</> : null}
                                            </span>
                                        )}
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
                                                <div className="flex gap-1">
                                                    <button
                                                        type="button"
                                                        className="action-btn"
                                                        title={t('push.testSend')}
                                                        disabled={testSendingId === r.id}
                                                        onClick={() => sendTest(r.id)}
                                                    >
                                                        <Send className={`w-4 h-4 ${testSendingId === r.id ? 'animate-pulse' : ''}`} />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="action-btn text-red"
                                                        title={t('push.delete')}
                                                        onClick={() => { if (window.confirm(t('push.deleteConfirm'))) op(r.id, 'delete'); }}
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

                    {(() => {
                        // Delivery health (stage 2.3): sends that mostly fail mean
                        // the base is stale. 404/410 endpoints are aged out
                        // automatically on send; the hint explains the number.
                        const totalSent = messages.reduce((acc, m) => acc + Number(m.sent || 0), 0);
                        const totalFailed = messages.reduce((acc, m) => acc + Number(m.failed || 0), 0);
                        const deliveredPct = (totalSent + totalFailed) > 0 ? Math.round(totalSent / (totalSent + totalFailed) * 100) : null;
                        if (deliveredPct === null || deliveredPct >= 50 || totalSent + totalFailed < 20) return null;
                        return (
                            <div className="mb-3 rounded-xl border px-4 py-2.5 text-sm flex items-center gap-2"
                                 style={{ borderColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 40%, transparent)', backgroundColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 8%, transparent)' }}>
                                <span>⚠️ {t('push.deliveryHint', { pct: deliveredPct })}</span>
                            </div>
                        );
                    })()}
                    <div className="overflow-x-auto">
                        <table className="tracker-table w-full">
                            <thead>
                                <tr>
                                    <th>{t('push.messageTitle')}</th>
                                    <th>{t('push.kind')}</th>
                                    <th>{t('push.segment')}</th>
                                    <th>{t('push.sent')}</th>
                                    <th>{t('push.viewed')}</th>
                                    <th>{t('push.clicked')}</th>
                                    <th>{t('push.attrRegs')}</th>
                                    <th>{t('push.attrFtd')}</th>
                                    <th>{t('push.attrRevenue')}</th>
                                    <th>{t('push.failed')}</th>
                                    <th>{t('push.queued')}</th>
                                    <th>{t('push.created')}</th>
                                    {canWrite && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {messages.length === 0 && !messagesLoading && (
                                    <tr><td colSpan={9} className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('push.messagesEmpty')}</td></tr>
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
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap', color: Number(m.clicked || 0) > 0 ? 'var(--color-primary)' : undefined }}>
                                            {Number(m.clicked || 0).toLocaleString()}
                                            {Number(m.sent || 0) > 0 && (
                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                    {' '}{Math.round((Number(m.clicked || 0) / Number(m.sent)) * 100)}%
                                                </span>
                                            )}
                                        </td>
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap' }} title={t('push.viewedHint')}>
                                            {Number(m.viewed || 0).toLocaleString()}
                                            {Number(m.sent || 0) > 0 && (
                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                    {' '}{Math.round((Number(m.viewed || 0) / Number(m.sent)) * 100)}%
                                                </span>
                                            )}
                                        </td>
                                        <td className="cell-text">{Number(m.attr_regs || 0).toLocaleString()}</td>
                                        <td className="cell-text" style={{ whiteSpace: 'nowrap' }}>
                                            {Number(m.attr_ftd || 0).toLocaleString()}
                                            {m.click_to_ftd !== null && m.click_to_ftd !== undefined && (
                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}> · {m.click_to_ftd}%</span>
                                            )}
                                        </td>
                                        <td className="cell-text" style={{ color: Number(m.attr_revenue || 0) > 0 ? 'var(--color-success)' : undefined }}>
                                            {(Number(m.attr_revenue || 0)).toFixed(2)}
                                        </td>
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

            {modalOpen && createPortal(
                <div
                    className="fixed inset-0 flex items-center justify-center p-3 sm:p-6"
                    style={{ background: 'rgba(0,0,0,0.55)', zIndex: 2000 }}
                    onClick={() => setModalOpen(false)}
                >
                    <div
                        className="page-card"
                        style={{ width: 900, maxWidth: '100%', maxHeight: 'calc(100dvh - 24px)', display: 'flex', flexDirection: 'column', overflow: 'hidden', margin: 0, padding: 0 }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3" style={{ borderBottom: '1px solid var(--color-border)', flexShrink: 0 }}>
                            <div className="text-base font-semibold">{form.id ? t('push.editMessage') : t('push.newMessage')}</div>
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

                        <div className="px-5 py-4" style={{ overflowY: 'auto', flex: '1 1 auto', minHeight: 0 }}>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.messageTitle')} *</label>
                                <input
                                    className="form-input w-full"
                                    maxLength={250}
                                    value={form.title}
                                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                                    placeholder={t('push.messageTitlePlaceholder')}
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.segment')}</label>
                                <select
                                    className="form-select w-full"
                                    value={form.segment}
                                    onChange={(e) => setForm({ ...form, segment: e.target.value })}
                                >
                                    {SEGMENTS.map((seg) => (
                                        <option key={seg} value={seg}>{segmentLabel(seg)}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div className="flex items-baseline justify-between gap-2 mb-1">
                            <label className="block text-xs font-semibold">{t('push.messageText')} *</label>
                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{form.text.length} / 400</span>
                        </div>
                        <textarea
                            className="form-input w-full mb-3"
                            rows={2}
                            style={{ resize: 'vertical' }}
                            maxLength={400}
                            value={form.text}
                            onChange={(e) => setForm({ ...form, text: e.target.value })}
                            placeholder={t('push.messageTextPlaceholder')}
                        />

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-1">
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.iconUrl')}</label>
                                <input
                                    className="form-input w-full"
                                    value={form.icon_url}
                                    onChange={(e) => setForm({ ...form, icon_url: e.target.value })}
                                    placeholder="https://…/icon.png"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.imageUrl')}</label>
                                <input
                                    className="form-input w-full"
                                    value={form.image_url}
                                    onChange={(e) => setForm({ ...form, image_url: e.target.value })}
                                    placeholder="https://…/banner.png"
                                />
                            </div>
                        </div>
                        <div className="text-xs mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('push.imageUrlHint')}</div>

                        <label className="block text-xs font-semibold mb-1">{t('push.linkUrl')}</label>
                        <input
                            className="form-input w-full mb-1"
                            value={form.link_url}
                            onChange={(e) => setForm({ ...form, link_url: e.target.value })}
                            placeholder="https://tracker.example.com/camp?subid={subid}"
                        />
                        <div className="text-xs mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('push.linkHint')}</div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.countries')}</label>
                                <input
                                    className="form-input w-full"
                                    value={form.countries}
                                    onChange={(e) => setForm({ ...form, countries: e.target.value })}
                                    placeholder="US, DE"
                                />
                                <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('push.countriesHint')}</div>
                            </div>
                            <div>
                                <label className="block text-xs font-semibold mb-1">{t('push.languages')}</label>
                                <input
                                    className="form-input w-full"
                                    value={form.languages}
                                    onChange={(e) => setForm({ ...form, languages: e.target.value })}
                                    placeholder="en, es"
                                />
                                <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('push.languagesHint')}</div>
                            </div>
                        </div>

                        {form.kind === 'event' && (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label className="block text-xs font-semibold mb-1">{t('push.event')}</label>
                                    <select
                                        className="form-select w-full"
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
                                        className="form-input w-full"
                                        type="number"
                                        min={0}
                                        value={form.delay_seconds}
                                        onChange={(e) => setForm({ ...form, delay_seconds: e.target.value })}
                                    />
                                    <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('push.delayHint')}</div>
                                </div>
                            </div>
                        )}

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                            <label className="flex items-start gap-2 text-sm cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={!!form.local_time}
                                    onChange={(e) => setForm({ ...form, local_time: e.target.checked })}
                                />
                                <span>
                                    <span className="block">{t('push.localTime')}</span>
                                    <span className="block text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('push.localTimeHint')}</span>
                                </span>
                            </label>
                            <label className="flex items-center gap-2 text-sm cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={form.active}
                                    onChange={(e) => setForm({ ...form, active: e.target.checked })}
                                />
                                {t('push.filterActive')}
                            </label>
                        </div>

                        {(form.text || '').includes('{offer.') && (
                            <div className="mb-3 p-3 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                <div className="text-xs font-semibold mb-2">{t('push.macroPreview')}</div>
                                <div className="flex flex-wrap gap-2 mb-2 items-center">
                                    <input
                                        className="form-input"
                                        style={{ width: 90, textTransform: 'uppercase' }}
                                        maxLength={2}
                                        placeholder="GR"
                                        value={previewCountry}
                                        onChange={(e) => setPreviewCountry(e.target.value.toUpperCase())}
                                    />
                                    <select
                                        className="form-select"
                                        style={{ width: 'auto' }}
                                        value={previewOfferId}
                                        onChange={(e) => setPreviewOfferId(e.target.value)}
                                    >
                                        <option value="">{t('push.previewPickOffer')}</option>
                                        {(offersSimple || []).map((o) => (
                                            <option key={o.id} value={o.id}>{o.name}</option>
                                        ))}
                                    </select>
                                    <button type="button" className="btn btn-sm" onClick={runPreview}>{t('push.previewButton')}</button>
                                </div>
                                {previewResult !== null && (
                                    <div className="text-sm" style={{ color: 'var(--color-text-primary)', wordBreak: 'break-word' }}>{previewResult}</div>
                                )}
                            </div>
                        )}
                        </div>

                        <div className="flex justify-end gap-2 px-5 py-3" style={{ borderTop: '1px solid var(--color-border)', flexShrink: 0 }}>
                            <button type="button" className="btn btn-secondary" onClick={() => setModalOpen(false)}>
                                {t('common.cancel')}
                            </button>
                            <button type="button" className="btn btn-primary" disabled={saving || !form.title.trim() || !form.text.trim()} onClick={saveMessage}>
                                {t('common.save')}
                            </button>
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </div>
    );
}
