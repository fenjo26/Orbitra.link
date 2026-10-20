import React, { useCallback, useEffect, useState } from 'react';
import { Send, RefreshCw, Trash2, Plus, Copy, ExternalLink, Bell, BellOff, Clock, Users, MessageCircle, CheckCircle2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard as copyUtil } from '../utils/clipboard';
import TelegramAlertRules from './TelegramAlertRules';
import axios from 'axios';

const API_URL = '/api.php';

// Telegram half of the Notifications page. Moved out of IntegrationsPage:
// the bot CONNECTION (token, webhook/polling diagnostics) is an integration
// and stays there; everything about WHERE and ABOUT WHAT the bot writes —
// chats, per-chat toggles, digest schedule, channel rules — is notifications
// and lives here. Admin-only: the Notifications page hides this subtab for
// everyone else.

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export default function TelegramNotificationsPanel() {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [settings, setSettings] = useState(null);
    const [link, setLink] = useState(null);
    const [linkBusy, setLinkBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [saving, setSaving] = useState(false);
    // The digest schedule form (global, saved with save_telegram_settings).
    const [form, setForm] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=telegram_settings`);
            if (res.data.status === 'success') {
                const d = res.data.data;
                setSettings(d);
                setForm({
                    notify_conversions: !!d.notify_conversions,
                    daily_time: d.daily_time || '21:00',
                    weekly_day: d.weekly_day || 1,
                    weekly_time: d.weekly_time || '21:00',
                    monthly_day: d.monthly_day || 1,
                    monthly_time: d.monthly_time || '21:00',
                });
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const flagToggles = [
        { key: 'conversions', label: t('notifications.chatConversions'), icon: Bell },
        { key: 'daily', label: t('notifications.chatDaily'), icon: Clock },
        { key: 'weekly', label: t('notifications.chatWeekly'), icon: Clock },
        { key: 'monthly', label: t('notifications.chatMonthly'), icon: Clock },
    ];

    const toggleChatFlag = async (chatId, flag, value) => {
        try {
            await axios.post(API_URL, { chat_id: String(chatId), flag, value: value ? 1 : 0 }, { params: { action: 'telegram_chat_flags' } });
            const res = await axios.get(`${API_URL}?action=telegram_settings`);
            if (res.data.status === 'success') setSettings(res.data.data);
        } catch {
            setMessage({ type: 'error', text: t('common.error') });
        }
    };

    const removeChat = async (chat) => {
        const name = chat.username ? `@${chat.username}` : (chat.first_name || chat.chat_id);
        if (!window.confirm(t('telegram.removeChatConfirm').replace('{name}', name))) return;
        try {
            const res = await axios.post(API_URL, { chat_id: String(chat.chat_id) }, { params: { action: 'telegram_chat_remove' } });
            if (res.data.status === 'success') {
                await load();
            } else {
                setMessage({ type: 'error', text: res.data.message || t('common.error') });
            }
        } catch {
            setMessage({ type: 'error', text: t('common.error') });
        }
    };

    const generateLink = async () => {
        setLinkBusy(true);
        setMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=telegram_link_code`, {});
            if (res.data.status === 'success') {
                setLink(res.data.data);
            } else {
                setMessage({ type: 'error', text: res.data.message || t('telegram.linkError') });
            }
        } catch {
            setMessage({ type: 'error', text: t('telegram.linkError') });
        } finally {
            setLinkBusy(false);
        }
    };

    const saveSettings = async () => {
        setSaving(true);
        setMessage(null);
        try {
            await axios.post(`${API_URL}?action=save_telegram_settings`, {
                notify_conversions: form.notify_conversions,
                daily_time: form.daily_time,
                weekly_day: form.weekly_day,
                weekly_time: form.weekly_time,
                monthly_day: form.monthly_day,
                monthly_time: form.monthly_time,
            });
            setMessage({ type: 'success', text: t('telegram.settingsSaved') });
            setTimeout(() => setMessage(null), 3000);
            await load();
        } catch {
            setMessage({ type: 'error', text: t('common.error') });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="flex justify-center py-10">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }} />
            </div>
        );
    }

    // The bot is not connected: one card pointing at Integrations. The
    // connection (token, webhook/polling) is an integration and stays there.
    if (!settings?.token_set) {
        return (
            <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', maxWidth: 600 }}>
                <div style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)', marginBottom: 6 }}>
                    {t('notifications.tgNotConnectedTitle')}
                </div>
                <div style={{ fontSize: '13px', color: 'var(--color-text-secondary)', marginBottom: 14, lineHeight: 1.6 }}>
                    {t('notifications.tgNotConnectedDesc')}
                </div>
                <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={() => window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_integrations' } }))}
                >
                    <Send size={15} />
                    <span>{t('notifications.tgConnectBtn')}</span>
                </button>
            </div>
        );
    }

    return (
        <div style={{ maxWidth: 600 }} className="space-y-6">
            {message && (
                <div style={{
                    padding: '12px 16px', borderRadius: '12px', fontSize: '14px',
                    background: message.type === 'success' ? 'var(--color-success-bg, #dcfce7)' : 'var(--color-danger-bg, #fee2e2)',
                    color: message.type === 'success' ? '#166534' : '#991b1b',
                    border: `1px solid ${message.type === 'success' ? '#86efac' : '#fca5a5'}`,
                }}>
                    {message.text}
                </div>
            )}

            {/* Bot status one-liner + link back to the connection */}
            <div className="flex items-center justify-between flex-wrap gap-2" style={{
                padding: '12px 16px', borderRadius: '12px',
                background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)',
            }}>
                <div className="flex items-center gap-2">
                    <div style={{
                        width: '10px', height: '10px', borderRadius: '50%',
                        background: settings.connected ? '#22c55e' : '#ef4444',
                        boxShadow: settings.connected ? '0 0 8px #22c55e80' : '0 0 8px #ef444480',
                    }} />
                    <span style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-primary)' }}>
                        {t('notifications.tgBotLine').replace('{bot}', settings.bot_username ? '@' + settings.bot_username : 'Bot')}
                    </span>
                </div>
                <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={() => window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_integrations' } }))}
                >
                    {t('notifications.tgConnectionLink')}
                </button>
            </div>

            {/* Chats: who receives what */}
            <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                <div className="flex items-center gap-2 mb-1">
                    <Users size={16} style={{ color: 'var(--color-text-muted)' }} />
                    <span style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>
                        {t('notifications.tgChatsTitle')} ({settings.chats?.length || 0})
                    </span>
                </div>
                <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '12px' }}>
                    {t('notifications.tgChatsDesc')}
                </div>

                {(settings.chats?.length || 0) === 0 && (
                    <div style={{ fontSize: 13, color: 'var(--color-text-muted)', padding: '10px 0' }}>
                        {t('notifications.tgNoChats')}
                    </div>
                )}

                <div className="space-y-2">
                    {(settings.chats || []).map((chat) => (
                        <div key={chat.chat_id} style={{
                            padding: '10px 12px', borderRadius: '10px',
                            background: 'var(--color-bg-card)', border: '1px solid var(--color-border)',
                        }}>
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2" style={{ minWidth: 0 }}>
                                    <MessageCircle size={14} style={{ color: '#0088cc', flexShrink: 0 }} />
                                    <span style={{ fontSize: 13, color: 'var(--color-text-primary)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                        {chat.first_name} {chat.username ? `(@${chat.username})` : ''}
                                    </span>
                                    <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>{chat.language?.toUpperCase()}</span>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm"
                                    onClick={() => removeChat(chat)}
                                    title={t('telegram.removeChat')}
                                    style={{ color: '#ef4444', padding: '2px 6px' }}
                                >
                                    <Trash2 size={12} />
                                </button>
                            </div>
                            {/* Per-chat toggles: the same columns /notify,
                                /daily, /weekly and /monthly flip from the chat. */}
                            <div className="flex flex-wrap gap-x-4 gap-y-1" style={{ marginTop: 8 }}>
                                {flagToggles.map(({ key, label, icon: Icon }) => {
                                    const on = Number(chat['notify_' + key]) === 1;
                                    return (
                                        <label key={key} className="flex items-center gap-1.5 cursor-pointer" title={label}>
                                            <input
                                                type="checkbox"
                                                className="sr-only peer"
                                                checked={on}
                                                onChange={(e) => toggleChatFlag(chat.chat_id, key, e.target.checked)}
                                            />
                                            <span style={{
                                                width: 28, height: 16, borderRadius: 999, position: 'relative', display: 'inline-block',
                                                background: on ? '#22c55e' : 'var(--color-border)', transition: 'background .15s',
                                            }}>
                                                <span style={{
                                                    position: 'absolute', top: 2, left: on ? 14 : 2, width: 12, height: 12,
                                                    borderRadius: '50%', background: '#fff', transition: 'left .15s',
                                                }} />
                                            </span>
                                            <span style={{ fontSize: 12, color: on ? 'var(--color-text-primary)' : 'var(--color-text-muted)' }} className="flex items-center gap-1">
                                                <Icon size={12} />
                                                {label}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Connect a chat: one-time code, "/start CODE" */}
                <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: '1px solid var(--color-border)' }}>
                    <div style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)', marginBottom: '4px' }}>
                        {t('telegram.linkTitle')}
                    </div>
                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '10px', lineHeight: 1.55 }}>
                        {t('telegram.linkDesc')}
                    </p>
                    {link ? (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                            <div className="flex items-center gap-2" style={{ flexWrap: 'wrap' }}>
                                <code style={{
                                    padding: '6px 10px', borderRadius: '8px', fontSize: 14,
                                    background: 'var(--color-bg-card)', border: '1px solid var(--color-border)',
                                    color: 'var(--color-primary)', userSelect: 'all',
                                }}>{link.command}</code>
                                <button type="button" className="btn btn-secondary btn-sm" onClick={() => copyUtil(link.command)}>
                                    <Copy size={14} />
                                    <span>{t('common.copy')}</span>
                                </button>
                                {link.deep_link && (
                                    <a href={link.deep_link} target="_blank" rel="noopener noreferrer" className="btn btn-primary btn-sm">
                                        <ExternalLink size={14} />
                                        <span>{t('telegram.linkOpenBot')}</span>
                                    </a>
                                )}
                            </div>
                            <div style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>{t('telegram.linkExpires')}</div>
                        </div>
                    ) : (
                        <button type="button" onClick={generateLink} disabled={linkBusy} className="btn btn-secondary btn-sm">
                            {linkBusy ? <RefreshCw size={14} className="animate-spin" /> : <Plus size={14} />}
                            <span>{t('telegram.linkGenerate')}</span>
                        </button>
                    )}
                </div>
            </div>

            {/* Digest schedule */}
            <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                <h4 style={{ fontWeight: 600, marginBottom: '14px', color: 'var(--color-text-primary)' }}>
                    {t('telegram.notificationSettings')}
                </h4>
                <div className="space-y-4">
                    <label className="flex items-center justify-between cursor-pointer">
                        <div className="flex items-center gap-3">
                            {form.notify_conversions ? <Bell size={18} style={{ color: '#22c55e' }} /> : <BellOff size={18} style={{ color: 'var(--color-text-muted)' }} />}
                            <div>
                                <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('telegram.notifyConversions')}</div>
                                <div style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>{t('telegram.notifyConversionsDesc')}</div>
                            </div>
                        </div>
                        <label className="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" checked={form.notify_conversions} onChange={(e) => setForm({ ...form, notify_conversions: e.target.checked })} className="sr-only peer" />
                            <div className="w-11 h-6 bg-[var(--color-border)] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500" />
                        </label>
                    </label>

                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Clock size={18} style={{ color: 'var(--color-primary)' }} />
                            <div>
                                <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('telegram.dailySummary')}</div>
                                <div style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>{t('telegram.dailySummaryDesc')}</div>
                            </div>
                        </div>
                        <input
                            type="time"
                            value={form.daily_time}
                            onChange={(e) => setForm({ ...form, daily_time: e.target.value })}
                            className="form-input"
                            style={{ width: 110, textAlign: 'center' }}
                        />
                    </div>

                    {/* Weekly: weekday + time */}
                    <div className="flex items-center justify-between flex-wrap gap-2">
                        <div className="flex items-center gap-3">
                            <Clock size={18} style={{ color: 'var(--color-primary)' }} />
                            <div>
                                <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('notifications.weeklyDigest')}</div>
                                <div style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>{t('notifications.weeklyDigestDesc')}</div>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <select
                                className="form-select"
                                style={{ width: 'auto' }}
                                value={form.weekly_day}
                                onChange={(e) => setForm({ ...form, weekly_day: Number(e.target.value) })}
                            >
                                {WEEKDAYS.map((wd) => (
                                    <option key={wd} value={WEEKDAYS.indexOf(wd) + 1}>{t(`notifications.weekday${wd}`)}</option>
                                ))}
                            </select>
                            <input
                                type="time"
                                value={form.weekly_time}
                                onChange={(e) => setForm({ ...form, weekly_time: e.target.value })}
                                className="form-input"
                                style={{ width: 110, textAlign: 'center' }}
                            />
                        </div>
                    </div>

                    {/* Monthly: day of month + time */}
                    <div className="flex items-center justify-between flex-wrap gap-2">
                        <div className="flex items-center gap-3">
                            <Clock size={18} style={{ color: 'var(--color-primary)' }} />
                            <div>
                                <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('notifications.monthlyDigest')}</div>
                                <div style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>{t('notifications.monthlyDigestDesc')}</div>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <select
                                className="form-select"
                                style={{ width: 'auto' }}
                                value={form.monthly_day}
                                onChange={(e) => setForm({ ...form, monthly_day: Number(e.target.value) })}
                            >
                                {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                    <option key={d} value={d}>{d}</option>
                                ))}
                            </select>
                            <input
                                type="time"
                                value={form.monthly_time}
                                onChange={(e) => setForm({ ...form, monthly_time: e.target.value })}
                                className="form-input"
                                style={{ width: 110, textAlign: 'center' }}
                            />
                        </div>
                    </div>

                    <div className="flex items-center justify-between">
                        <button onClick={saveSettings} disabled={saving} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                            {saving ? <RefreshCw size={15} className="animate-spin" /> : <CheckCircle2 size={15} />}
                            <span>{t('common.save')}</span>
                        </button>
                        <button onClick={load} className="btn btn-secondary btn-sm">
                            <RefreshCw size={15} />
                            <span>{t('telegram.refresh')}</span>
                        </button>
                    </div>
                </div>
            </div>

            {/* Rule-based conversion alerts to channels/groups */}
            <TelegramAlertRules />

            {/* Commands reference */}
            <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                <h4 style={{ fontWeight: 600, marginBottom: '12px', color: 'var(--color-text-primary)' }}>
                    {t('telegram.commandsTitle')}
                </h4>
                <div style={{ fontSize: '13px', lineHeight: 2 }}>
                    {[
                        ['/stats', t('telegram.cmdStats')],
                        ['/stats 7d', t('telegram.cmdStats7d')],
                        ['/campaigns', t('telegram.cmdCampaigns')],
                        ['/campaign ID', t('telegram.cmdCampaignId')],
                        ['/top', t('telegram.cmdTop')],
                        ['/conversions', t('telegram.cmdConversions')],
                        ['/notify on|off', t('telegram.cmdNotify')],
                        ['/daily on|off', t('telegram.cmdDaily')],
                        ['/weekly on|off', t('notifications.cmdWeekly')],
                        ['/monthly on|off', t('notifications.cmdMonthly')],
                        ['/lang ru|en', t('telegram.cmdLang')],
                    ].map(([cmd, desc]) => (
                        <div key={cmd} className="flex gap-3">
                            <code style={{ color: 'var(--color-primary)', fontWeight: 500, minWidth: '140px', flexShrink: 0 }}>{cmd}</code>
                            <span style={{ color: 'var(--color-text-secondary)' }}>— {desc}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
