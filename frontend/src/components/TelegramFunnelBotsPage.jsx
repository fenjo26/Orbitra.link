import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Copy, Plus, RefreshCw, Send, Trash2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';

const API_URL = '/api.php';

// Telegram AD-FUNNEL bots: the bots a "Telegram channel" offer sends ad
// visitors to. Deliberately separate from the admin bot (Integrations →
// Telegram), which only talks to the chats the operator admitted — strangers
// from an ad must never reach the bot that reads the panel's statistics.
// The server refuses the admin bot's token here.
const ERROR_KEYS = {
    token_format: 'tgFunnel.errTokenFormat',
    admin_token: 'tgFunnel.errAdminToken',
    duplicate: 'tgFunnel.errDuplicate',
    get_me: 'tgFunnel.errGetMe',
    bot_in_use: 'tgFunnel.errBotInUse',
    channel_format: 'tgFunnel.errChannelFormat',
    not_found: 'tgFunnel.errChannelNotFound',
};

const TelegramFunnelBotsPage = () => {
    const { t } = useLanguage();
    const [bots, setBots] = useState([]);
    const [poller, setPoller] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [form, setForm] = useState({ name: '', token: '' });
    const [busy, setBusy] = useState(false);
    const [channelInput, setChannelInput] = useState({});
    const [copied, setCopied] = useState(false);

    const errorText = (data) => {
        const key = ERROR_KEYS[data?.code];
        return key ? t(key) : (data?.message || t('tgFunnel.errGeneric'));
    };

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get(API_URL, { params: { action: 'tg_funnel_bots' } });
            if (res.data?.status === 'success') {
                setBots(res.data.data || []);
                setPoller(res.data.poller || null);
                setError('');
            } else {
                setError(res.data?.message || t('tgFunnel.errGeneric'));
            }
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => { load(); }, [load]);

    const post = async (body) => {
        setBusy(true);
        setNotice('');
        try {
            const res = await axios.post(API_URL, body, { params: { action: 'tg_funnel_bots' } });
            if (res.data?.status !== 'success') {
                setError(errorText(res.data));
                return null;
            }
            setError('');
            await load();
            return res.data;
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
            return null;
        } finally {
            setBusy(false);
        }
    };

    const addBot = async () => {
        const res = await post({ op: 'add', name: form.name, token: form.token.trim() });
        if (res) {
            setForm({ name: '', token: '' });
            setNotice(t('tgFunnel.added').replace('{username}', res.data?.username || ''));
        }
    };

    const addChannel = async (bot) => {
        const value = (channelInput[bot.id] || '').trim();
        if (!value) return;
        const res = await post({ op: 'add_channel', id: bot.id, channel: value });
        if (res) {
            setChannelInput({ ...channelInput, [bot.id]: '' });
            if (!Number(res.data?.can_invite)) setNotice(t('tgFunnel.noInviteRight'));
        }
    };

    const removeBot = async (bot) => {
        if (!window.confirm(t('tgFunnel.confirmDelete').replace('{name}', bot.name))) return;
        await post({ op: 'delete', id: bot.id });
    };

    const copyCron = async () => {
        if (!poller?.cron_line) return;
        if (await copyToClipboard(poller.cron_line)) {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        }
    };

    const muted = { color: 'var(--color-text-muted)' };

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div>
                    <h3 className="page-title m-0 flex items-center gap-2"><Send className="w-5 h-5" /> {t('tgFunnel.title')}</h3>
                    <p className="text-xs mt-1" style={muted}>{t('tgFunnel.subtitle')}</p>
                </div>
                <button type="button" className="btn btn-sm" onClick={load} title={t('common.refresh')}><RefreshCw className="w-3.5 h-3.5" /></button>
            </div>

            <div className="card p-4 mb-4 text-sm" style={{ borderLeft: '3px solid var(--color-primary)' }}>
                <div className="font-semibold mb-1">{t('tgFunnel.separateTitle')}</div>
                <div style={muted}>{t('tgFunnel.separateText')}</div>
            </div>

            {poller?.stale && (
                <div className="alert alert-warning mb-4 text-sm">
                    <div className="flex items-center gap-2 font-semibold"><AlertTriangle className="w-4 h-4" /> {t('tgFunnel.pollerStale')}</div>
                    <div className="mt-1">{t('tgFunnel.pollerStaleHint')}</div>
                    <div className="flex items-center gap-2 mt-2">
                        <code className="text-xs break-all">{poller.cron_line}</code>
                        <button type="button" className="action-btn" onClick={copyCron} title={t('common.copy')}><Copy className="w-4 h-4" /></button>
                        {copied && <span className="text-xs">{t('common.copied')}</span>}
                    </div>
                </div>
            )}

            {error && <div className="alert alert-danger mb-3">{error}</div>}
            {notice && <div className="alert alert-success mb-3">{notice}</div>}

            <div className="card p-4 mb-4">
                <div className="font-semibold mb-2">{t('tgFunnel.addTitle')}</div>
                <ol className="text-xs mb-3 space-y-1" style={{ ...muted, paddingLeft: 18, listStyle: 'decimal' }}>
                    <li>{t('tgFunnel.addStep1')}</li>
                    <li>{t('tgFunnel.addStep2')}</li>
                </ol>
                <div className="grid gap-2" style={{ gridTemplateColumns: 'minmax(0,1fr) minmax(0,2fr) auto' }}>
                    <input className="form-input" value={form.name} maxLength={100} placeholder={t('tgFunnel.namePlaceholder')}
                           onChange={e => setForm({ ...form, name: e.target.value })} />
                    <input className="form-input font-mono text-xs" type="password" autoComplete="off" value={form.token}
                           placeholder="1234567890:AA…" onChange={e => setForm({ ...form, token: e.target.value })} />
                    <button type="button" className="btn btn-primary" disabled={busy || !form.token.trim()} onClick={addBot}>
                        <Plus className="w-4 h-4" /> {t('tgFunnel.add')}
                    </button>
                </div>
            </div>

            {loading && <div className="py-8 text-center text-sm" style={muted}>{t('common.loading')}</div>}
            {!loading && bots.length === 0 && (
                <div className="card p-8 text-center text-sm" style={muted}>{t('tgFunnel.empty')}</div>
            )}

            {bots.map(bot => (
                <div key={bot.id} className="card p-4 mb-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div className="font-semibold">{bot.name}</div>
                            <a className="text-xs" href={`https://t.me/${bot.username}`} target="_blank" rel="noreferrer">@{bot.username}</a>
                            <span className="text-xs ml-3" style={muted}>
                                {t('tgFunnel.stats').replace('{starts}', bot.starts_30d).replace('{joins}', bot.joins_30d)}
                            </span>
                        </div>
                        <div className="flex items-center gap-3">
                            <label className="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" checked={bot.is_active === 1} disabled={busy}
                                       onChange={e => post({ op: 'update', id: bot.id, name: bot.name, is_active: e.target.checked })} />
                                {t('tgFunnel.active')}
                            </label>
                            <button type="button" className="action-btn text-red" title={t('common.delete')} onClick={() => removeBot(bot)}><Trash2 className="w-4 h-4" /></button>
                        </div>
                    </div>
                    {bot.last_error && (
                        <div className="text-xs mt-2" style={{ color: 'var(--color-danger)' }}>{t('tgFunnel.lastError')}: {bot.last_error}</div>
                    )}

                    <div className="mt-3">
                        <div className="text-xs font-semibold mb-1">{t('tgFunnel.channels')}</div>
                        {bot.channels.length === 0 && <div className="text-xs mb-2" style={muted}>{t('tgFunnel.noChannels')}</div>}
                        {bot.channels.map(ch => (
                            <div key={ch.chat_id} className="flex items-center justify-between gap-2 py-1 text-sm" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                <div className="min-w-0">
                                    <span className="font-medium">{ch.title || ch.chat_id}</span>
                                    <span className="text-xs ml-2" style={muted}>{ch.username ? `@${ch.username}` : ch.chat_id}</span>
                                    {!Number(ch.can_invite) && (
                                        <span className="text-xs ml-2" style={{ color: 'var(--color-warning)' }}>{t('tgFunnel.noInviteRightShort')}</span>
                                    )}
                                </div>
                                <button type="button" className="action-btn" title={t('common.delete')}
                                        onClick={() => post({ op: 'remove_channel', id: bot.id, chat_id: ch.chat_id })}><Trash2 className="w-3.5 h-3.5" /></button>
                            </div>
                        ))}
                        <div className="flex gap-2 mt-2">
                            <input className="form-input text-sm" style={{ maxWidth: 320 }} placeholder={t('tgFunnel.channelPlaceholder')}
                                   value={channelInput[bot.id] || ''}
                                   onChange={e => setChannelInput({ ...channelInput, [bot.id]: e.target.value })} />
                            <button type="button" className="btn btn-sm" disabled={busy || !(channelInput[bot.id] || '').trim()} onClick={() => addChannel(bot)}>
                                {t('tgFunnel.addChannel')}
                            </button>
                        </div>
                        <div className="text-xs mt-1" style={muted}>{t('tgFunnel.channelHint')}</div>
                    </div>
                </div>
            ))}

            {!loading && (
                <div className="card mt-4 p-4 text-sm" style={muted}>
                    <div className="font-semibold mb-1" style={{ color: 'var(--color-text-primary)' }}>{t('tgFunnel.howTitle')}</div>
                    <ol className="space-y-1" style={{ paddingLeft: 18, listStyle: 'decimal' }}>
                        <li>{t('tgFunnel.how1')}</li>
                        <li>{t('tgFunnel.how2')}</li>
                        <li>{t('tgFunnel.how3')}</li>
                        <li>{t('tgFunnel.how4')}</li>
                        <li>{t('tgFunnel.how5')}</li>
                    </ol>
                </div>
            )}
        </div>
    );
};

export default TelegramFunnelBotsPage;
