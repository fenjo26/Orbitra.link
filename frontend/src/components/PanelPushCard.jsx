import React, { useCallback, useEffect, useState } from 'react';
import { Bell, BellOff, RefreshCw, Send, Smartphone, Trash2, Monitor, CheckCircle2, AlertTriangle, Download } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import axios from 'axios';

const API_URL = '/api.php';

// Panel push notifications — web push to the operator's own devices. The
// browser side of core/AdminPushNotify.php: subscribe this browser through
// the panel service worker (the VAPID keypair is shared with the landing
// push base), list/retire own devices, and pick what to be notified about
// (conversion statuses/campaigns, day/week/month digests).

const urlB64ToUint8Array = (b64) => {
    const padding = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
};

const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || !!navigator.standalone;

// A human label for a subscription endpoint: the push service host, because
// that is the only part that identifies the browser family.
const endpointHost = (endpoint) => {
    try { return new URL(endpoint).host; } catch { return endpoint; }
};

const deviceIcon = (ua) => /iphone|ipad|ipod|android|mobile/i.test(ua || '') ? Smartphone : Monitor;

export default function PanelPushCard() {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [vapid, setVapid] = useState({ has_keys: false, public_key: '' });
    const [devices, setDevices] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [form, setForm] = useState(null);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [info, setInfo] = useState('');
    const [testResults, setTestResults] = useState(null);
    const [installEvt, setInstallEvt] = useState(() => window.__orbitraInstallPrompt || null);

    useEffect(() => {
        // The beforeinstallprompt may fire long before this card is opened;
        // App.jsx parks it on window, and a live listener catches a re-fire.
        const onPrompt = (e) => { e.preventDefault(); window.__orbitraInstallPrompt = e; setInstallEvt(e); };
        window.addEventListener('beforeinstallprompt', onPrompt);
        return () => window.removeEventListener('beforeinstallprompt', onPrompt);
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await axios.get(API_URL, { params: { action: 'admin_push_state' } });
            const d = res.data?.data || {};
            setVapid({ has_keys: !!d.has_keys, public_key: d.public_key || '' });
            setDevices(d.devices || []);
            setStatuses(d.statuses || []);
            setForm((prev) => ({
                conv_enabled: d.settings?.conv_enabled !== 0,
                conv_statuses: d.settings?.conv_statuses ? String(d.settings.conv_statuses).split(',').filter(Boolean) : [],
                conv_campaigns: d.settings?.conv_campaigns || '',
                digest_daily: !!d.settings?.digest_daily,
                digest_weekly: !!d.settings?.digest_weekly,
                digest_monthly: !!d.settings?.digest_monthly,
                digest_time: d.settings?.digest_time || '21:00',
                ...(prev ? {} : {}),
            }));
        } catch (e) {
            setError(e?.response?.data?.message || t('panelPush.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => { load(); }, [load]);

    const activeCount = devices.filter((d) => d.is_active).length;

    const enableOnThisDevice = async () => {
        if (busy) return;
        setError('');
        setInfo('');
        if (!vapid.has_keys) {
            setError(t('panelPush.needKeys'));
            return;
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            setError(t('panelPush.unsupported'));
            return;
        }
        if (isIos() && !isStandalone()) {
            setError(t('panelPush.iosInstallFirst'));
            return;
        }
        setBusy('subscribe');
        try {
            if (Notification.permission === 'default') {
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') {
                    setError(t('panelPush.permissionDenied'));
                    return;
                }
            } else if (Notification.permission !== 'granted') {
                setError(t('panelPush.permissionDenied'));
                return;
            }

            // The panel SW is registered by UpdateToast on every load; if it
            // is not there yet (fresh browser, SW still installing), the same
            // idempotent register call brings it up.
            const panelParam = encodeURIComponent(window.location.pathname || '/admin.php');
            let reg = await navigator.serviceWorker.getRegistration('/');
            if (!reg) {
                reg = await navigator.serviceWorker.register(`/sw.js?panel=${panelParam}`, { scope: '/' });
            }
            await navigator.serviceWorker.ready;

            const existing = await reg.pushManager.getSubscription();
            const sub = existing && existing.applicationServerKey
                && btoa(String.fromCharCode(...new Uint8Array(existing.applicationServerKey))) === btoa(String.fromCharCode(...urlB64ToUint8Array(vapid.public_key)))
                ? existing
                : await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8Array(vapid.public_key),
                });

            const json = sub.toJSON();
            await axios.post(API_URL, {
                endpoint: json.endpoint,
                keys: json.keys,
                user_agent: navigator.userAgent,
            }, { params: { action: 'admin_push_subscribe' } });
            setInfo(t('panelPush.subscribed'));
            await load();
        } catch (e) {
            setError(e?.response?.data?.message || e?.message || t('panelPush.subscribeFailed'));
        } finally {
            setBusy('');
        }
    };

    const removeDevice = async (id) => {
        if (busy) return;
        if (!window.confirm(t('panelPush.removeConfirm'))) return;
        setBusy('rm' + id);
        try {
            await axios.post(API_URL, { id }, { params: { action: 'admin_push_unsubscribe' } });
            await load();
        } catch (e) {
            setError(e?.response?.data?.message || t('panelPush.loadFailed'));
        } finally {
            setBusy('');
        }
    };

    const testSend = async () => {
        if (busy) return;
        setError('');
        setInfo('');
        setTestResults(null);
        setBusy('test');
        try {
            const res = await axios.post(API_URL, {}, { params: { action: 'admin_push_test' } });
            const results = res.data?.data?.results || [];
            setTestResults(results);
            if (!results.length) setError(t('panelPush.noDevices'));
        } catch (e) {
            setError(e?.response?.data?.message || t('panelPush.sendFailed'));
        } finally {
            setBusy('');
        }
    };

    const saveSettings = async () => {
        if (busy || !form) return;
        setBusy('save');
        setError('');
        setInfo('');
        try {
            await axios.post(API_URL, {
                conv_enabled: form.conv_enabled ? 1 : 0,
                conv_statuses: form.conv_statuses,
                conv_campaigns: form.conv_campaigns,
                digest_daily: form.digest_daily ? 1 : 0,
                digest_weekly: form.digest_weekly ? 1 : 0,
                digest_monthly: form.digest_monthly ? 1 : 0,
                digest_time: form.digest_time,
            }, { params: { action: 'admin_push_settings_save' } });
            setInfo(t('editor.saved'));
            setTimeout(() => setInfo(''), 2500);
            await load();
        } catch (e) {
            setError(e?.response?.data?.message || t('panelPush.saveFailed'));
        } finally {
            setBusy('');
        }
    };

    const installApp = async () => {
        if (!installEvt) return;
        installEvt.prompt();
        const { outcome } = await installEvt.userChoice;
        if (outcome === 'accepted') setInfo(t('panelPush.installed'));
        window.__orbitraInstallPrompt = null;
        setInstallEvt(null);
    };

    const toggleStatus = (key) => {
        setForm((f) => ({
            ...f,
            conv_statuses: f.conv_statuses.includes(key)
                ? f.conv_statuses.filter((s) => s !== key)
                : [...f.conv_statuses, key],
        }));
    };

    if (loading || !form) {
        return (
            <div className="flex items-center justify-center py-10">
                <RefreshCw className="w-5 h-5 animate-spin" style={{ color: 'var(--color-primary)' }} />
            </div>
        );
    }

    const Toggle = ({ checked, onChange, iconOn, iconOff, label, hint }) => (
        <label className="flex items-center justify-between cursor-pointer">
            <div className="flex items-center gap-3">
                {checked ? React.cloneElement(iconOn, { size: 18, style: { color: '#22c55e' } }) : React.cloneElement(iconOff, { size: 18, style: { color: 'var(--color-text-muted)' } })}
                <div>
                    <div style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>{label}</div>
                    {hint && <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>{hint}</div>}
                </div>
            </div>
            <label className="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="sr-only peer" />
                <div className="w-11 h-6 bg-[var(--color-border)] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500" />
            </label>
        </label>
    );

    return (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            {error && (
                <div className="flex items-start gap-2" style={{ padding: '10px 14px', borderRadius: '10px', marginBottom: 14, fontSize: 13,
                    background: 'color-mix(in srgb, var(--color-danger) 12%, transparent)', color: 'var(--color-danger)' }}>
                    <AlertTriangle size={15} className="flex-shrink-0 mt-0.5" />
                    <span>{error}</span>
                </div>
            )}
            {info && (
                <div className="flex items-center gap-2" style={{ padding: '10px 14px', borderRadius: '10px', marginBottom: 14, fontSize: 13,
                    background: 'color-mix(in srgb, var(--color-success) 12%, transparent)', color: 'var(--color-success)' }}>
                    <CheckCircle2 size={15} />
                    <span>{info}</span>
                </div>
            )}

            {/* What this is — the discoverability the feature never had */}
            <div style={{ padding: '18px 20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', marginBottom: 16 }}>
                <h4 style={{ fontWeight: 600, marginBottom: 6, color: 'var(--color-text-primary)' }}>{t('panelPush.title')}</h4>
                <div style={{ fontSize: 13, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>{t('panelPush.intro')}</div>
                <div style={{ display: 'flex', gap: 10, marginTop: 14, flexWrap: 'wrap' }}>
                    <button onClick={enableOnThisDevice} disabled={busy === 'subscribe'} className="btn btn-primary btn-sm">
                        {busy === 'subscribe' ? <RefreshCw size={15} className="animate-spin" /> : <Bell size={15} />}
                        <span>{activeCount > 0 ? t('panelPush.addAnotherDevice') : t('panelPush.enableDevice')}</span>
                    </button>
                    <button onClick={testSend} disabled={busy === 'test' || activeCount === 0} className="btn btn-secondary btn-sm">
                        {busy === 'test' ? <RefreshCw size={15} className="animate-spin" /> : <Send size={15} />}
                        <span>{t('panelPush.testSend')}</span>
                    </button>
                    {installEvt && (
                        <button onClick={installApp} className="btn btn-secondary btn-sm">
                            <Download size={15} />
                            <span>{t('panelPush.installApp')}</span>
                        </button>
                    )}
                </div>
                {isIos() && !isStandalone() && (
                    <div style={{ marginTop: 12, fontSize: 12, color: 'var(--color-text-muted)', lineHeight: 1.6 }}>
                        {t('panelPush.iosHint')}
                    </div>
                )}
                {isStandalone() && (
                    <div style={{ marginTop: 12, fontSize: 12, color: 'var(--color-success)' }}>✓ {t('panelPush.runningStandalone')}</div>
                )}
            </div>

            {/* VAPID keys are the shared prerequisite; without them nothing subscribes */}
            {!vapid.has_keys && (
                <div style={{ padding: '14px 16px', borderRadius: '12px', marginBottom: 16, fontSize: 13,
                    background: 'color-mix(in srgb, var(--color-warning, #d97706) 10%, transparent)',
                    border: '1px solid color-mix(in srgb, var(--color-warning, #d97706) 35%, transparent)',
                    color: 'var(--color-warning, #d97706)' }}>
                    {t('panelPush.needKeys')}
                </div>
            )}

            {/* Devices */}
            <div style={{ padding: '18px 20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', marginBottom: 16 }}>
                <h4 style={{ fontWeight: 600, marginBottom: 12, color: 'var(--color-text-primary)' }}>
                    {t('panelPush.devices')} <span style={{ fontWeight: 400, fontSize: 12, color: 'var(--color-text-muted)' }}>· {activeCount}</span>
                </h4>
                {devices.length === 0 ? (
                    <div style={{ fontSize: 13, color: 'var(--color-text-muted)', padding: '10px 0' }}>{t('panelPush.noDevices')}</div>
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                        {devices.map((d) => {
                            const Icon = deviceIcon(d.user_agent);
                            const tr = (testResults || []).find((r) => r.id === d.id);
                            return (
                                <div key={d.id} className="flex items-center justify-between gap-3" style={{ padding: '10px 12px', borderRadius: '10px', background: 'var(--color-bg-card, var(--color-bg))', border: '1px solid var(--color-border)' }}>
                                    <div className="flex items-center gap-3" style={{ minWidth: 0 }}>
                                        <Icon size={17} style={{ color: d.is_active ? 'var(--color-primary)' : 'var(--color-text-muted)', flexShrink: 0 }} />
                                        <div style={{ minWidth: 0 }}>
                                            <div style={{ fontSize: 13, fontWeight: 500, color: 'var(--color-text-primary)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                {endpointHost(d.endpoint)}
                                                {!d.is_active && <span style={{ color: 'var(--color-danger)', fontWeight: 400 }}> · {t('panelPush.deviceDead')}</span>}
                                            </div>
                                            <div style={{ fontSize: 11, color: 'var(--color-text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={d.user_agent}>
                                                {String(d.created_at || '').slice(0, 16).replace('T', ' ')}
                                                {d.last_seen ? ` · ${t('panelPush.lastSeen')} ${String(d.last_seen).slice(0, 16).replace('T', ' ')}` : ''}
                                            </div>
                                        </div>
                                        {tr && (
                                            <span style={{ fontSize: 11, flexShrink: 0, color: tr.ok ? 'var(--color-success)' : 'var(--color-danger)' }}>
                                                {tr.ok ? '✓' : '✗'} {tr.code ?? tr.error ?? ''}
                                            </span>
                                        )}
                                    </div>
                                    <button onClick={() => removeDevice(d.id)} disabled={busy === 'rm' + d.id}
                                        className="action-btn text-red" title={t('common.delete')}>
                                        <Trash2 size={15} />
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* What to notify about */}
            <div style={{ padding: '18px 20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                <h4 style={{ fontWeight: 600, marginBottom: 14, color: 'var(--color-text-primary)' }}>{t('panelPush.settingsTitle')}</h4>
                <div className="space-y-4">
                    <Toggle
                        checked={form.conv_enabled}
                        onChange={(v) => setForm({ ...form, conv_enabled: v })}
                        iconOn={<Bell />} iconOff={<BellOff />}
                        label={t('panelPush.notifyConversions')}
                        hint={t('panelPush.notifyConversionsDesc')}
                    />

                    {form.conv_enabled && (
                        <div style={{ paddingLeft: 4 }}>
                            <div style={{ fontSize: 12, fontWeight: 500, marginBottom: 6, color: 'var(--color-text-secondary)' }}>
                                {t('panelPush.statusFilter')} <span style={{ fontWeight: 400, color: 'var(--color-text-muted)' }}>— {t('panelPush.statusFilterHint')}</span>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {statuses.map((key) => {
                                    const on = form.conv_statuses.includes(key);
                                    return (
                                        <button key={key} type="button" onClick={() => toggleStatus(key)}
                                            className="px-2.5 py-1 rounded-lg text-xs font-semibold"
                                            style={{
                                                border: '1px solid ' + (on ? 'transparent' : 'var(--color-border)'),
                                                background: on ? 'color-mix(in srgb, var(--color-primary) 15%, transparent)' : 'transparent',
                                                color: on ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                            }}>
                                            {key}
                                        </button>
                                    );
                                })}
                            </div>
                            <div style={{ marginTop: 10 }}>
                                <div style={{ fontSize: 12, fontWeight: 500, marginBottom: 4, color: 'var(--color-text-secondary)' }}>{t('panelPush.campaignFilter')}</div>
                                <input
                                    className="form-input"
                                    style={{ width: 240 }}
                                    value={form.conv_campaigns}
                                    placeholder="1, 7, 12"
                                    onChange={(e) => setForm({ ...form, conv_campaigns: e.target.value })}
                                />
                                <div style={{ fontSize: 11, color: 'var(--color-text-muted)', marginTop: 3 }}>{t('panelPush.campaignFilterHint')}</div>
                            </div>
                        </div>
                    )}

                    <div style={{ borderTop: '1px solid var(--color-border)', paddingTop: 14 }}>
                        <div style={{ fontSize: 13, fontWeight: 500, marginBottom: 4, color: 'var(--color-text-primary)' }}>{t('panelPush.digestsTitle')}</div>
                        <div style={{ fontSize: 12, color: 'var(--color-text-muted)', marginBottom: 10 }}>{t('panelPush.digestsDesc')}</div>
                        <div className="space-y-3">
                            <Toggle checked={form.digest_daily} onChange={(v) => setForm({ ...form, digest_daily: v })}
                                iconOn={<Bell />} iconOff={<BellOff />} label={t('panelPush.digestDaily')} hint={t('panelPush.digestDailyDesc')} />
                            <Toggle checked={form.digest_weekly} onChange={(v) => setForm({ ...form, digest_weekly: v })}
                                iconOn={<Bell />} iconOff={<BellOff />} label={t('panelPush.digestWeekly')} hint={t('panelPush.digestWeeklyDesc')} />
                            <Toggle checked={form.digest_monthly} onChange={(v) => setForm({ ...form, digest_monthly: v })}
                                iconOn={<Bell />} iconOff={<BellOff />} label={t('panelPush.digestMonthly')} hint={t('panelPush.digestMonthlyDesc')} />
                        </div>
                        <div className="flex items-center gap-3" style={{ marginTop: 12 }}>
                            <div style={{ fontSize: 12, color: 'var(--color-text-secondary)' }}>{t('panelPush.digestTime')}</div>
                            <input
                                type="time"
                                className="form-input"
                                style={{ width: 110, textAlign: 'center' }}
                                value={form.digest_time}
                                onChange={(e) => setForm({ ...form, digest_time: e.target.value })}
                            />
                            <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>{t('panelPush.digestTimeHint')}</span>
                        </div>
                    </div>

                    <button onClick={saveSettings} disabled={busy === 'save'} className="btn btn-primary btn-sm" style={{ marginTop: 6 }}>
                        {busy === 'save' ? <RefreshCw size={15} className="animate-spin" /> : <CheckCircle2 size={15} />}
                        <span>{t('common.save')}</span>
                    </button>
                </div>
            </div>
        </div>
    );
}
