import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Copy, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { copyToClipboard as copyUtil } from '../../utils/clipboard';
import { agoText } from './reason';
import { openLinkChecks } from './useLinkChecks';

const API_URL = '/api.php';
const INTERVALS = [15, 30, 60, 180, 360, 720, 1440];
const DEFAULT_CRON_LINE = '* * * * * php /var/www/orbitra/link_checks_cron.php >> /var/www/orbitra/var/logs/link_checks.log 2>&1 # orbitra-link-checks';

const Section = ({ title, children }) => (
    <div className="page-card" style={{ padding: 20 }}>
        <h3 className="text-base font-semibold mb-3" style={{ color: 'var(--color-text-primary)' }}>{title}</h3>
        {children}
    </div>
);

const ProxyStatus = ({ proxy, t }) => {
    if (proxy.status === 'ok') return <span className="badge badge-success">{t('linkChecks.proxyOk')}</span>;
    if (proxy.status === 'failed') return <span className="badge badge-danger" title={proxy.last_error}>{t('linkChecks.proxyFailed')}</span>;
    return <span className="badge" style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.proxyUnknown')}</span>;
};

// The "Link checks" card of Integrations: the switch panel, the worker's
// state and the proxies. Everything about what is checked and why it is
// broken lives under Notifications → Link Monitoring; this is the setup.
export default function LinkChecksSettings() {
    const { t } = useLanguage();
    const [data, setData] = useState(null);
    const [form, setForm] = useState(null);
    const [proxyLines, setProxyLines] = useState('');
    const [proxyType, setProxyType] = useState('socks5');
    const [proxyName, setProxyName] = useState('');
    const [busy, setBusy] = useState('');
    const [message, setMessage] = useState(null);
    const [copied, setCopied] = useState(false);

    // The form follows the server only when asked: the worker state is polled
    // every half a minute, and a poll must not wipe half-typed settings.
    const load = useCallback(async (withForm) => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'link_checks_settings' } });
            if (res.data?.status !== 'success') return;
            const loaded = res.data.data;
            setData(loaded);
            const next = {
                enabled: !!loaded.settings.enabled,
                offers: loaded.settings.offers === 'all',
                domains: loaded.settings.domains === 'all',
                interval_min: loaded.settings.interval_min,
                user_agent: loaded.settings.user_agent,
                autopause: !!loaded.settings.autopause,
                proxy_mode: loaded.settings.proxy_mode === 'rotate' ? 'rotate' : 'direct',
            };
            setForm((current) => (withForm || current === null ? next : current));
        } catch {
            // The next poll tries again.
        }
    }, []);

    useEffect(() => {
        load(true);
        const timer = setInterval(() => load(false), 30000);
        return () => clearInterval(timer);
    }, [load]);

    const say = (type, text) => {
        setMessage({ type, text });
        if (type === 'success') setTimeout(() => setMessage(null), 5000);
    };

    const save = async () => {
        setBusy('save');
        try {
            const payload = {
                ...form,
                offers: form.offers ? 'all' : 'off',
                domains: form.domains ? 'all' : 'off',
            };
            const res = await axios.post(API_URL, payload, { params: { action: 'save_link_checks_settings' } });
            if (res.data?.status === 'success') {
                say('success', t('linkChecks.saved'));
                await load(true);
            } else {
                say('error', res.data?.message || t('common.error'));
            }
        } catch {
            say('error', t('common.error'));
        } finally {
            setBusy('');
        }
    };

    const addProxies = async () => {
        if (proxyLines.trim() === '') return;
        setBusy('add');
        try {
            const res = await axios.post(API_URL, {
                urls: proxyLines,
                default_type: proxyType,
                name: proxyName.trim(),
            }, { params: { action: 'save_check_proxy' } });
            if (res.data?.status === 'success') {
                const result = res.data.data || {};
                const addedCount = Number(result.added_count || 0);
                const skipped = Number(result.skipped || 0);
                const errors = Array.isArray(result.errors) ? result.errors : [];
                if (errors.length === 0) {
                    setProxyLines('');
                    setProxyName('');
                } else {
                    setProxyLines(errors.map((err) => err.raw).join('\n'));
                }
                if (addedCount === 1 && errors.length === 0 && skipped === 0 && !result.pending_probe && result.added?.[0]) {
                    const one = result.added[0];
                    say('success', one.status === 'ok'
                        ? t('linkChecks.proxyAdded', { ip: one.exit_ip })
                        : t('linkChecks.proxyAddedFailed'));
                } else {
                    const parts = [];
                    if (addedCount > 0) {
                        parts.push(result.pending_probe
                            ? t('linkChecks.proxyBulkAddedDeferred', { n: addedCount })
                            : t('linkChecks.proxyBulkAdded', { n: addedCount }));
                    }
                    if (skipped > 0) {
                        parts.push(t('linkChecks.proxyBulkSkipped', { n: skipped }));
                    }
                    if (errors.length > 0) {
                        const firstErr = t(`linkChecks.errors.${errors[0].code}`) || errors[0].message;
                        parts.push(t('linkChecks.proxyBulkErrors', { n: errors.length, reason: firstErr }));
                    }
                    say(errors.length > 0 && addedCount === 0 ? 'error' : 'success', parts.join(' '));
                }
                await load(false);
            } else {
                say('error', t(`linkChecks.errors.${res.data?.code}`) || res.data?.message || t('common.error'));
            }
        } catch {
            say('error', t('common.error'));
        } finally {
            setBusy('');
        }
    };

    const testProxy = async (proxy) => {
        setBusy(`test-${proxy.id}`);
        try {
            const res = await axios.post(API_URL, { id: proxy.id }, { params: { action: 'test_check_proxy' } });
            if (res.data?.status === 'success') {
                say(res.data.data.ok ? 'success' : 'error', res.data.data.ok ? t('linkChecks.proxyTestOk') : t('linkChecks.proxyTestFailed', { error: res.data.data.error }));
            } else {
                say('error', res.data?.message || t('common.error'));
            }
            await load(false);
        } catch {
            say('error', t('common.error'));
        } finally {
            setBusy('');
        }
    };

    const deleteProxy = async (proxy) => {
        if (!window.confirm(t('linkChecks.proxyDeleteConfirm', { name: proxy.name }))) return;
        setBusy(`delete-${proxy.id}`);
        try {
            const res = await axios.post(API_URL, { id: proxy.id }, { params: { action: 'delete_check_proxy' } });
            if (res.data?.status === 'success') {
                say('success', t('linkChecks.proxyDeleted'));
                await load(false);
            } else {
                say('error', res.data?.message || t('common.error'));
            }
        } catch {
            say('error', t('common.error'));
        } finally {
            setBusy('');
        }
    };

    const worker = data?.worker || {};
    const cronLine = worker.cron_line || DEFAULT_CRON_LINE;
    const proxies = data?.proxies || [];

    const copyCron = async () => {
        await copyUtil(cronLine);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    if (!form) {
        return (
            <div className="flex justify-center py-10">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }} />
            </div>
        );
    }

    const label = 'block text-xs font-medium mb-1';
    const labelText = { color: 'var(--color-text-secondary)' };
    const muted = { color: 'var(--color-text-muted)' };
    const cell = { padding: '8px 10px', verticalAlign: 'top' };

    return (
        <div className="space-y-4" style={{ maxWidth: 860 }}>
            {message && (
                <div className="rounded-lg border px-4 py-3 text-sm" style={{
                    borderColor: 'var(--color-border)',
                    color: message.type === 'success' ? 'var(--color-success, #10b981)' : 'var(--color-danger, #dc2626)',
                }}>
                    {message.text}
                </div>
            )}

            <Section title={t('linkChecks.settingsSection')}>
                <div className="space-y-4">
                    <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} style={{ accentColor: 'var(--color-primary)' }} />
                        {t('linkChecks.enabledGlobal')}
                    </label>
                    <div className="flex flex-wrap gap-x-8 gap-y-3 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={form.offers} onChange={(e) => setForm({ ...form, offers: e.target.checked })} style={{ accentColor: 'var(--color-primary)' }} />
                            {t('linkChecks.scopeOffers')}
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={form.domains} onChange={(e) => setForm({ ...form, domains: e.target.checked })} style={{ accentColor: 'var(--color-primary)' }} />
                            {t('linkChecks.scopeDomains')}
                        </label>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className={label} style={labelText} htmlFor="lcs-interval">{t('linkChecks.intervalDefault')}</label>
                            <select
                                id="lcs-interval"
                                className="form-select w-full"
                                value={INTERVALS.includes(Number(form.interval_min)) ? form.interval_min : 'custom'}
                                onChange={(e) => setForm({ ...form, interval_min: e.target.value === 'custom' ? 45 : Number(e.target.value) })}
                            >
                                {INTERVALS.map((minutes) => (
                                    <option key={minutes} value={minutes}>{t('linkChecks.everyMin', { n: minutes })}</option>
                                ))}
                                <option value="custom">{t('linkChecks.intervalCustom')}</option>
                            </select>
                            {!INTERVALS.includes(Number(form.interval_min)) && (
                                <input
                                    type="number"
                                    min="5"
                                    max="10080"
                                    className="form-input w-full mt-2"
                                    value={form.interval_min}
                                    onChange={(e) => setForm({ ...form, interval_min: Number(e.target.value) })}
                                    aria-label={t('linkChecks.intervalCustom')}
                                />
                            )}
                        </div>
                        <div>
                            <label className={label} style={labelText} htmlFor="lcs-ua">{t('linkChecks.userAgent')}</label>
                            <input
                                id="lcs-ua"
                                type="text"
                                className="form-input w-full font-mono"
                                value={form.user_agent}
                                onChange={(e) => setForm({ ...form, user_agent: e.target.value })}
                                placeholder={t('linkChecks.userAgentDefault')}
                            />
                            <p className="text-xs mt-1" style={muted}>{t('linkChecks.userAgentHint')}</p>
                        </div>
                    </div>
                    <div>
                        <label className={label} style={labelText} htmlFor="lcs-proxy-mode">{t('linkChecks.proxyMode')}</label>
                        <select
                            id="lcs-proxy-mode"
                            className="form-select w-full md:w-1/2"
                            value={form.proxy_mode}
                            onChange={(e) => setForm({ ...form, proxy_mode: e.target.value })}
                        >
                            <option value="direct">{t('linkChecks.proxyModeDirect')}</option>
                            <option value="rotate">{t('linkChecks.proxyModeRotate')}</option>
                        </select>
                        <p className="text-xs mt-1" style={muted}>{t('linkChecks.proxyModeHint')}</p>
                    </div>
                    <label className="flex items-start gap-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <input type="checkbox" checked={form.autopause} onChange={(e) => setForm({ ...form, autopause: e.target.checked })} style={{ accentColor: 'var(--color-primary)', marginTop: 3 }} />
                        <span>
                            {t('linkChecks.autopauseLabel')}
                            <span className="block text-xs" style={muted}>{t('linkChecks.autopauseHint')}</span>
                        </span>
                    </label>
                    <div className="rounded-lg border px-4 py-3 text-sm" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                        {t('linkChecks.clickWarning')}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" className="btn btn-primary" onClick={save} disabled={busy !== ''}>
                            {busy === 'save' ? t('linkChecks.saving') : t('linkChecks.save')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => openLinkChecks()}>
                            {t('linkChecks.openChecks')}
                        </button>
                    </div>
                </div>
            </Section>

            <Section title={t('linkChecks.workerTitle')}>
                {worker.alive ? (
                    <p className="text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <span className="badge badge-success">{t('linkChecks.workerOn')}</span>
                        {worker.last_ping_ts ? <span className="ml-2" style={muted}>{agoText(t, worker.last_ping_ts)}</span> : null}
                        {worker.overdue > 0 && <span className="ml-2" style={{ color: 'var(--color-warning, #d97706)' }}>{t('linkChecks.workerOverdue', { n: worker.overdue })}</span>}
                    </p>
                ) : (
                    <div className="space-y-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <p><span className="badge badge-warning">{t('linkChecks.workerOff')}</span></p>
                        <p style={muted}>{form.enabled ? t('linkChecks.workerDead') : t('linkChecks.workerIdle')}</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <code className="font-mono text-xs px-2 py-1.5 rounded break-all" style={{ backgroundColor: 'var(--color-bg-hover)' }}>{cronLine}</code>
                            <button type="button" className="btn btn-ghost btn-sm" onClick={copyCron}>
                                <Copy size={14} /> {copied ? t('linkChecks.copied') : t('common.copy', 'Copy')}
                            </button>
                        </div>
                        <p className="text-xs" style={muted}>{t('linkChecks.workerCronHint')}</p>
                    </div>
                )}
                {worker.last_error && (
                    <p className="text-xs mt-2 font-mono break-all" style={{ color: 'var(--color-danger, #dc2626)' }}>{worker.last_error}</p>
                )}
            </Section>

            <Section title={t('linkChecks.proxiesTitle')}>
                <p className="text-sm mb-3" style={muted}>{t('linkChecks.proxiesIntro')}</p>
                <div className="space-y-2 mb-3">
                    <textarea
                        rows={4}
                        className="form-input font-mono w-full text-xs"
                        value={proxyLines}
                        onChange={(e) => setProxyLines(e.target.value)}
                        placeholder={t('linkChecks.proxyListPlaceholder')}
                        aria-label={t('linkChecks.proxyPlaceholder')}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="form-select"
                            style={{ width: 'auto', minWidth: 130 }}
                            value={proxyType}
                            onChange={(e) => setProxyType(e.target.value)}
                            aria-label={t('linkChecks.proxyDefaultType')}
                            title={t('linkChecks.proxyDefaultType')}
                        >
                            <option value="socks5">SOCKS5</option>
                            <option value="http">HTTP</option>
                            <option value="https">HTTPS</option>
                            <option value="socks4">SOCKS4</option>
                        </select>
                        <input
                            type="text"
                            className="form-input flex-[1_1_220px]"
                            value={proxyName}
                            onChange={(e) => setProxyName(e.target.value)}
                            placeholder={t('linkChecks.proxyNamePrefixPlaceholder')}
                            aria-label={t('linkChecks.colName')}
                        />
                        <button type="button" className="btn btn-primary" onClick={addProxies} disabled={busy !== '' || proxyLines.trim() === ''}>
                            {busy === 'add' ? t('linkChecks.adding') : <><Plus size={14} /> {t('linkChecks.addProxy')}</>}
                        </button>
                    </div>
                </div>
                {proxies.length === 0 ? (
                    <p className="text-sm" style={muted}>{t('linkChecks.noProxies')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border" style={{ borderColor: 'var(--color-border)' }}>
                        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
                            <thead>
                                <tr style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                                    {[t('linkChecks.colName'), t('linkChecks.colExit'), t('linkChecks.colStatus'), t('linkChecks.colLinks'), ''].map((title, i) => (
                                        <th key={i} data-col={i} className="text-left font-semibold whitespace-nowrap" style={{ ...cell, color: 'var(--color-text-secondary)', fontSize: '12px' }}>{title}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {proxies.map((proxy) => (
                                    <tr key={proxy.id} style={{ borderTop: '1px solid var(--color-border)', opacity: proxy.is_active ? 1 : 0.55 }}>
                                        <td style={{ ...cell, color: 'var(--color-text-primary)' }}>
                                            <div className="font-medium">{proxy.name}</div>
                                            <div className="font-mono text-xs" style={muted}>{proxy.type}://{proxy.username ? `${proxy.username}@` : ''}{proxy.host}:{proxy.port}</div>
                                        </td>
                                        <td className="font-mono text-xs" style={{ ...cell, color: 'var(--color-text-secondary)' }}>
                                            {proxy.exit_ip || '—'}
                                            {proxy.exit_country ? <span className="badge ml-1">{proxy.exit_country}</span> : null}
                                        </td>
                                        <td style={cell}><ProxyStatus proxy={proxy} t={t} /></td>
                                        <td className="text-xs" style={{ ...cell, color: 'var(--color-text-secondary)' }}>{proxy.links}</td>
                                        <td className="whitespace-nowrap text-right" style={cell}>
                                            <button type="button" className="btn btn-ghost btn-sm" title={t('linkChecks.testProxy')} disabled={busy !== ''} onClick={() => testProxy(proxy)}>
                                                <RefreshCw size={14} className={busy === `test-${proxy.id}` ? 'animate-spin' : ''} />
                                            </button>
                                            <button type="button" className="btn btn-ghost btn-sm" title={t('common.delete', 'Delete')} disabled={busy !== ''} onClick={() => deleteProxy(proxy)}>
                                                <Trash2 size={14} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="text-xs mt-3" style={muted}>{t('linkChecks.telegramHint')}</p>
            </Section>
        </div>
    );
}
