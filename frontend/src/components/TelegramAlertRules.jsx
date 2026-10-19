import React, { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { CheckCircle2, Megaphone, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Conversion alerts to a Telegram channel or group through the ADMIN bot
// (adset-style "Telegram Bot" pixel): which chat, which statuses, which
// campaigns, and an HTML template with macros — ad names included, so the
// channel shows which ad paid. The per-chat "notify" toggle above stays as it
// was; rules are an addition on top of it.
const EMPTY = { id: 0, name: '', is_active: true, chat: '', chat_id: '', chat_title: '', statuses: ['deposit'], campaign_ids: [], template: '' };

const CHECK_ERRORS = {
    chat_format: 'tgAlerts.errChatFormat',
    no_admin_bot: 'tgAlerts.errNoBot',
    not_found: 'tgAlerts.errChatNotFound',
};

const TelegramAlertRules = () => {
    const { t } = useLanguage();
    const [rules, setRules] = useState([]);
    const [meta, setMeta] = useState({ statuses: [], macros: [] });
    const [campaigns, setCampaigns] = useState([]);
    const [form, setForm] = useState(null);
    const [checking, setChecking] = useState(false);
    const [chatInfo, setChatInfo] = useState(null);
    const [msg, setMsg] = useState(null);
    const [log, setLog] = useState(null);
    const templateRef = useRef(null);

    const load = useCallback(async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'tg_notify_rules' } });
            if (res.data?.status === 'success') {
                setRules(res.data.data || []);
                setMeta(res.data.meta || { statuses: [], macros: [] });
            }
        } catch {
            /* the card simply stays empty */
        }
    }, []);

    useEffect(() => {
        load();
        axios.get(API_URL, { params: { action: 'campaigns_simple' } })
            .then(res => setCampaigns(res.data?.status === 'success' ? (res.data.data || []) : []))
            .catch(() => setCampaigns([]));
    }, [load]);

    const post = (body) => axios.post(API_URL, body, { params: { action: 'tg_notify_rules' } }).then(r => r.data);

    const open = (rule = null) => {
        setMsg(null);
        setLog(null);
        if (rule) {
            setForm({ ...EMPTY, ...rule, is_active: !!rule.is_active, chat: rule.chat_title || rule.chat_id });
            setChatInfo({ chat_id: rule.chat_id, title: rule.chat_title, can_post: true });
        } else {
            setForm({ ...EMPTY, template: t('tgAlerts.defaultTemplate') });
            setChatInfo(null);
        }
    };

    const checkChat = async () => {
        setChecking(true);
        setMsg(null);
        try {
            const data = await post({ op: 'check_chat', chat: form.chat });
            if (data.status === 'success') {
                setChatInfo(data.data);
                setForm(f => ({ ...f, chat_id: data.data.chat_id, chat_title: data.data.title }));
            } else {
                setChatInfo(null);
                setMsg({ type: 'danger', text: CHECK_ERRORS[data.code] ? t(CHECK_ERRORS[data.code]) : (data.message || t('tgAlerts.errGeneric')) });
            }
        } finally {
            setChecking(false);
        }
    };

    const save = async () => {
        const data = await post({ op: 'save', ...form });
        if (data.status === 'success') {
            setForm(null);
            load();
        } else {
            setMsg({ type: 'danger', text: data.code === 'chat_unchecked' ? t('tgAlerts.errCheckFirst') : (data.message || t('tgAlerts.errGeneric')) });
        }
    };

    const test = async () => {
        const data = await post({ op: 'test', id: form.id, chat_id: form.chat_id, template: form.template });
        setMsg(data.status === 'success'
            ? { type: 'success', text: t('tgAlerts.testSent') }
            : { type: 'danger', text: `${t('tgAlerts.testFailed')}: ${data.message || ''}` });
    };

    const remove = async (rule) => {
        if (!window.confirm(t('tgAlerts.confirmDelete').replace('{name}', rule.name || rule.chat_title))) return;
        await post({ op: 'delete', id: rule.id });
        load();
    };

    const showLog = async () => {
        const data = await post({ op: 'log', id: form.id });
        setLog(data.status === 'success' ? (data.data || []) : []);
    };

    const toggle = (list, value) => (list.includes(value) ? list.filter(v => v !== value) : [...list, value]);

    const insertMacro = (macro) => {
        const el = templateRef.current;
        const token = `{${macro}}`;
        if (!el) {
            setForm(f => ({ ...f, template: f.template + token }));
            return;
        }
        const start = el.selectionStart ?? form.template.length;
        const end = el.selectionEnd ?? start;
        const next = form.template.slice(0, start) + token + form.template.slice(end);
        setForm(f => ({ ...f, template: next }));
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const STATUS_LABELS = {
        sale: t('tgAlerts.statusSale'),
        deposit: t('tgAlerts.statusDeposit'),
        registration: t('tgAlerts.statusRegistration'),
        hold: t('tgAlerts.statusHold'),
        rejected: t('tgAlerts.statusRejected'),
        trash: t('tgAlerts.statusTrash'),
        tg_join: t('tgAlerts.statusTgJoin'),
    };
    const statusLabel = (key) => STATUS_LABELS[key] || key;
    const muted = { fontSize: '12px', color: 'var(--color-text-muted)' };

    return (
        <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
            <div className="flex items-center justify-between gap-2 mb-2">
                <h4 className="flex items-center gap-2" style={{ fontWeight: 600, color: 'var(--color-text-primary)', margin: 0 }}>
                    <Megaphone size={18} /> {t('tgAlerts.title')}
                </h4>
                <button type="button" className="btn btn-primary btn-sm" onClick={() => open()}><Plus size={14} /> {t('tgAlerts.add')}</button>
            </div>
            <div style={{ ...muted, marginBottom: 12 }}>{t('tgAlerts.subtitle')}</div>

            {rules.length === 0 && <div style={muted}>{t('tgAlerts.empty')}</div>}
            {rules.map(rule => (
                <div key={rule.id} className="flex items-center justify-between gap-2 py-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                    <div className="min-w-0">
                        <div style={{ fontSize: 14, fontWeight: 500, opacity: rule.is_active ? 1 : 0.5 }}>{rule.name || rule.chat_title}</div>
                        <div style={muted}>
                            {rule.chat_title || rule.chat_id} · {rule.statuses.length ? rule.statuses.map(statusLabel).join(', ') : t('tgAlerts.allStatuses')}
                            {' · '}{rule.campaign_ids.length ? t('tgAlerts.nCampaigns').replace('{n}', rule.campaign_ids.length) : t('tgAlerts.allCampaigns')}
                            {rule.last && !Number(rule.last.ok) && <span style={{ color: 'var(--color-danger)' }}> · {t('tgAlerts.lastFailed')}: {rule.last.error}</span>}
                        </div>
                    </div>
                    <div className="flex gap-1">
                        <button type="button" className="action-btn" title={t('common.edit')} onClick={() => open(rule)}><Pencil size={15} /></button>
                        <button type="button" className="action-btn text-red" title={t('common.delete')} onClick={() => remove(rule)}><Trash2 size={15} /></button>
                    </div>
                </div>
            ))}

            {form && (
                <div className="modal-overlay" onClick={() => setForm(null)}>
                    <div className="modal-content" onClick={e => e.stopPropagation()} style={{ maxWidth: 620, maxHeight: '90vh', overflowY: 'auto' }}>
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-base font-bold m-0">{form.id ? t('tgAlerts.edit') : t('tgAlerts.add')}</h3>
                            <button type="button" className="action-btn" onClick={() => setForm(null)}><X size={18} /></button>
                        </div>
                        {msg && <div className={`alert alert-${msg.type} mb-3 text-sm`}>{msg.text}</div>}
                        <div className="space-y-3">
                            <div>
                                <label className="form-label">{t('tgAlerts.name')}</label>
                                <input className="form-input w-full" maxLength={100} value={form.name} placeholder={t('tgAlerts.namePlaceholder')}
                                       onChange={e => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div>
                                <label className="form-label">{t('tgAlerts.chat')}</label>
                                <div className="flex gap-2">
                                    <input className="form-input w-full" value={form.chat} placeholder="@my_channel / -100…"
                                           onChange={e => { setForm({ ...form, chat: e.target.value, chat_id: '' }); setChatInfo(null); }} />
                                    <button type="button" className="btn btn-sm" disabled={checking || !form.chat.trim()} onClick={checkChat}>{t('tgAlerts.check')}</button>
                                </div>
                                <div style={{ ...muted, marginTop: 4 }}>{t('tgAlerts.chatHint')}</div>
                                {chatInfo && (
                                    <div className="text-sm mt-1 flex items-center gap-1" style={{ color: chatInfo.can_post ? 'var(--color-success)' : 'var(--color-warning)' }}>
                                        <CheckCircle2 size={14} /> {chatInfo.title} ({chatInfo.chat_id}){!chatInfo.can_post && ` — ${t('tgAlerts.cannotPost')}`}
                                    </div>
                                )}
                            </div>
                            <div>
                                <label className="form-label">{t('tgAlerts.statuses')}</label>
                                <div className="flex flex-wrap gap-3">
                                    {meta.statuses.map(key => (
                                        <label key={key} className="flex items-center gap-1 text-sm cursor-pointer">
                                            <input type="checkbox" checked={form.statuses.includes(key)} onChange={() => setForm({ ...form, statuses: toggle(form.statuses, key) })} />
                                            {statusLabel(key)}
                                        </label>
                                    ))}
                                </div>
                                <div style={{ ...muted, marginTop: 4 }}>{t('tgAlerts.statusesHint')}</div>
                            </div>
                            <div>
                                <label className="form-label">{t('tgAlerts.campaigns')}</label>
                                <select multiple className="form-select w-full" style={{ minHeight: 96 }}
                                        value={form.campaign_ids.map(String)}
                                        onChange={e => setForm({ ...form, campaign_ids: Array.from(e.target.selectedOptions).map(o => Number(o.value)) })}>
                                    {campaigns.map(c => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                                </select>
                                <div style={{ ...muted, marginTop: 4 }}>{t('tgAlerts.campaignsHint')}</div>
                            </div>
                            <div>
                                <label className="form-label">{t('tgAlerts.template')}</label>
                                <textarea ref={templateRef} className="form-input w-full font-mono text-xs" rows={6} maxLength={3000}
                                          value={form.template} onChange={e => setForm({ ...form, template: e.target.value })} />
                                <div className="flex flex-wrap gap-1 mt-1">
                                    {meta.macros.map(m => (
                                        <button key={m} type="button" className="btn btn-sm"
                                                style={{ padding: '2px 6px', fontSize: 11, opacity: form.template.includes(`{${m}}`) ? 0.55 : 1 }}
                                                onClick={() => insertMacro(m)}>{`{${m}}`}</button>
                                    ))}
                                </div>
                                <div style={{ ...muted, marginTop: 4 }}>{t('tgAlerts.templateHint')}</div>
                            </div>
                            <label className="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" checked={form.is_active} onChange={e => setForm({ ...form, is_active: e.target.checked })} />
                                {t('tgAlerts.active')}
                            </label>

                            {log && (
                                <div className="text-xs" style={{ maxHeight: 160, overflowY: 'auto', borderTop: '1px solid var(--color-border)', paddingTop: 8 }}>
                                    {log.length === 0 && <div style={muted}>{t('tgAlerts.logEmpty')}</div>}
                                    {log.map((row, i) => (
                                        <div key={i} style={{ color: Number(row.ok) ? 'var(--color-text-secondary)' : 'var(--color-danger)' }}>
                                            {row.created_at} · {row.status} · {row.click_id} {Number(row.ok) ? '✓' : `✗ ${row.error}`}
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="flex flex-wrap justify-between gap-2 pt-2">
                                <div className="flex gap-2">
                                    <button type="button" className="btn btn-sm" disabled={!form.chat_id} onClick={test}><Send size={14} /> {t('tgAlerts.test')}</button>
                                    {form.id > 0 && <button type="button" className="btn btn-sm" onClick={showLog}>{t('tgAlerts.log')}</button>}
                                </div>
                                <div className="flex gap-2">
                                    <button type="button" className="btn btn-secondary" onClick={() => setForm(null)}>{t('common.cancel')}</button>
                                    <button type="button" className="btn btn-primary" disabled={!form.chat_id} onClick={save}>{t('common.save')}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default TelegramAlertRules;
