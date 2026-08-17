import React, { useState, useEffect, useRef } from 'react';
import { Plus, Globe, Check, X, AlertCircle, Search, Copy, Edit2, Trash2, ShieldAlert, RefreshCw, Clock, Cloud, ShoppingCart, Download } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import GroupsModal from './GroupsModal';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost } from '../utils/apiCache';

// Mirrors the backend cleaner: pasted URLs become bare hosts before they ever
// reach save_domain, so an invalid-format rejection never surprises the user.
const cleanNameInput = (v) => v.toLowerCase().replace(/^https?:\/\//, '').replace(/\/+$/, '').replace(/\s+/g, '');

// Compact two-option pill switch used by the Crawlers / Admin / HTTPS /
// Cloudflare toggles. Segmented control over a hidden state pair — the same
// visual language as the rest of the panel, theme vars included.
const ToggleGroup = ({ value, options, onChange }) => (
    <div className="flex rounded-lg overflow-hidden" style={{ border: '1px solid var(--color-border)' }}>
        {options.map((opt, i) => (
            <button
                key={opt.value}
                type="button"
                onClick={() => onChange(opt.value)}
                className="flex-1 text-xs font-medium py-1.5 px-2 transition"
                style={{
                    background: value === opt.value ? 'var(--color-primary)' : 'var(--color-bg-soft)',
                    color: value === opt.value ? 'var(--color-text-inverse, #fff)' : 'var(--color-text-secondary)',
                    borderRight: i < options.length - 1 ? '1px solid var(--color-border)' : 'none',
                    cursor: 'pointer'
                }}
            >
                {opt.label}
            </button>
        ))}
    </div>
);

const Domains = ({ campaigns }) => {
    const { t } = useLanguage();
    const [domains, setDomains] = useState([]);
    const [filteredDomains, setFilteredDomains] = useState([]);
    const [searchTerm, setSearchTerm] = useState('');
    const [serverIp, setServerIp] = useState('');
    const [loading, setLoading] = useState(true);
    const [ignoreDnsUi, setIgnoreDnsUi] = useState(() => {
        // UI-only toggle for migrations/tests when DNS isn't set yet.
        // Do not use this in production to "fix" misconfigured DNS.
        const v = localStorage.getItem('domains_ignore_dns_ui');
        return v === '1';
    });
    const [copiedIp, setCopiedIp] = useState(false);
    const [forceChecking, setForceChecking] = useState(false);
    const [sslRunning, setSslRunning] = useState(false);
    // What this server can actually do about certificates. Fetched once: a host
    // that cannot run external commands will never issue anything, and leaving
    // the operator to work that out from a permanent "waiting" status is the
    // single most confusing thing this page did.
    const [sslEnv, setSslEnv] = useState(null);

    // Edit Modal State. Defaults follow the Keitaro-style spec: crawlers
    // disallowed, admin denied, HTTPS-only on, Cloudflare proxy off. Denying
    // admin here only affects this domain — the panel stays reachable on
    // every other host (and via the server IP).
    const defaultFormData = {
        id: null, name: '',
        group_id: '',
        index_campaign_id: '',
        catch_404: false,
        is_noindex: true,
        admin_access: false,
        https_only: true,
        cloudflare_proxy: false,
        registrar: '',
        dns_provider: '',
        status: 'OK'
    };
    const [showModal, setShowModal] = useState(false);
    const [formData, setFormData] = useState(defaultFormData);
    const [error, setError] = useState('');
    const [domainGroups, setDomainGroups] = useState([]);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    // "Add more": save, keep the modal open and the settings, clear the name.
    const [addMore, setAddMore] = useState(false);
    const [saveNotice, setSaveNotice] = useState('');
    const nameInputRef = useRef(null);

    // DNS Warning Modal State
    const [showDnsModal, setShowDnsModal] = useState(false);

    // Namecheap integration: when connected, "Register Domain" and
    // "Import from Namecheap" appear, and adding domains auto-parks their DNS.
    const [ncConnected, setNcConnected] = useState(false);
    const [showRegister, setShowRegister] = useState(false);
    const [regDomain, setRegDomain] = useState('');
    const [regChecking, setRegChecking] = useState(false);
    const [regResult, setRegResult] = useState(null); // {domain, available, is_premium, price}
    const [regBuying, setRegBuying] = useState(false);
    const [regMessage, setRegMessage] = useState('');
    const [showImport, setShowImport] = useState(false);
    const [ncImport, setNcImport] = useState({ loading: false, domains: [], selected: {}, importing: false, message: '' });

    useEffect(() => {
        cachedGet('namecheap_status')
            .then(({ data }) => { if (data.status === 'success') setNcConnected(!!data.data.connected); })
            .catch(() => {});
    }, []);

    const fetchDomainGroups = async () => {
        try {
            const { data } = await cachedGet('domain_groups');
            if (data.status === 'success') setDomainGroups(data.data || []);
        } catch (e) { /* the select simply shows "No group" */ }
    };

    useEffect(() => {
        fetchDomainGroups();
    }, []);

    const checkNcDomain = async () => {
        const domain = regDomain.trim().toLowerCase();
        if (!domain) return;
        setRegChecking(true); setRegResult(null); setRegMessage('');
        try {
            const { data } = await cachedPost('namecheap_check_domain', { domain });
            if (data.status === 'success') {
                setRegResult(data.data);
            } else {
                setRegMessage(data.message || t('common.error'));
            }
        } catch (e) {
            setRegMessage(t('common.networkError'));
        } finally {
            setRegChecking(false);
        }
    };

    const buyAndPark = async () => {
        const domain = regResult?.domain || regDomain.trim().toLowerCase();
        const priceNote = regResult?.price ? ` (${regResult.price})` : '';
        if (!window.confirm(`${t('namecheap.buyConfirm')}: ${domain}${priceNote}?`)) return;
        setRegBuying(true); setRegMessage('');
        try {
            const { data } = await cachedPost('namecheap_register_domain', { domain });
            if (data.status === 'success') {
                setShowRegister(false);
                setRegDomain(''); setRegResult(null);
                fetchDomains();
                const lines = [`${t('namecheap.registeredOk')}: ${data.data.domain}`];
                if (data.data.namecheap) lines.push(`✓ ${t('namecheap.parkedOk')}: ${data.data.namecheap}`);
                lines.push(t('domains.sslQueued', 'SSL сертификат устанавливается в фоновом режиме (1-2 минуты)'));
                alert(lines.join('\n'));
            } else {
                setRegMessage(data.message || t('common.error'));
            }
        } catch (e) {
            setRegMessage(t('common.networkError'));
        } finally {
            setRegBuying(false);
        }
    };

    const openImport = async () => {
        setShowImport(true);
        setNcImport({ loading: true, domains: [], selected: {}, importing: false, message: '' });
        try {
            const [{ data: listRes }] = await Promise.all([cachedPost('namecheap_domains', {})]);
            if (listRes.status !== 'success') {
                setNcImport(s => ({ ...s, loading: false, message: listRes.message || t('common.error') }));
                return;
            }
            const have = new Set(domains.map(d => d.name.toLowerCase()));
            const fresh = (listRes.data.domains || []).filter(d => !have.has(d));
            const selected = {};
            fresh.forEach(d => { selected[d] = true; });
            setNcImport({ loading: false, domains: listRes.data.domains || [], selected, importing: false, message: '' });
        } catch (e) {
            setNcImport(s => ({ ...s, loading: false, message: t('common.networkError') }));
        }
    };

    const importSelected = async () => {
        const names = Object.keys(ncImport.selected).filter(k => ncImport.selected[k]);
        if (!names.length) return;
        setNcImport(s => ({ ...s, importing: true, message: '' }));
        try {
            const { data } = await cachedPost('save_domain', { name: names.join(', ') });
            if (data.status === 'success') {
                setShowImport(false);
                fetchDomains();
                const parked = (data.domains || []).filter(d => d.namecheap).map(d => `${d.name}: ${d.namecheap}`);
                const warnings = data.warnings || [];
                const lines = [`${t('namecheap.importedCount')}: ${(data.domains || []).length}`];
                if (parked.length) lines.push('', `✓ ${t('namecheap.parkedOk')}:`, ...parked);
                if (warnings.length) lines.push('', `⚠ ${warnings.join('; ')}`);
                if (data.ssl) lines.push('', data.ssl);
                alert(lines.join('\n'));
            } else {
                setNcImport(s => ({ ...s, importing: false, message: data.message || t('common.error') }));
            }
        } catch (e) {
            setNcImport(s => ({ ...s, importing: false, message: t('common.networkError') }));
        }
    };

    useEffect(() => {
        fetchDomains();
    }, []);
    const fetchDomains = async () => {
        try {
            const { data } = await cachedGet('domains');
            if (data.status === 'success') {
                setDomains(data.data);
                setFilteredDomains(data.data);
                setServerIp(data.server_ip || t('common.notSet'));
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const lowercased = searchTerm.toLowerCase();
        setFilteredDomains(domains.filter(d => d.name.toLowerCase().includes(lowercased)));
    }, [searchTerm, domains]);

    useEffect(() => {
        localStorage.setItem('domains_ignore_dns_ui', ignoreDnsUi ? '1' : '0');
    }, [ignoreDnsUi]);

    // Poll for SSL status updates every 5 seconds when there are pending/installing domains
    useEffect(() => {
        const interval = setInterval(async () => {
            // Only poll if there are domains with pending/installing SSL
            const hasPending = domains.some(d => ['pending', 'installing', 'waiting_dns'].includes(d.ssl_status));
            if (hasPending) {
                await fetchDomains();
            }
        }, 5000); // Check every 5 seconds
        return () => clearInterval(interval);
    }, [domains]);

    const handleEdit = (domain) => {
        setFormData({
            id: domain.id,
            name: domain.name,
            index_campaign_id: domain.index_campaign_id || '',
            catch_404: domain.catch_404 === 1,
            group_id: domain.group_id ? String(domain.group_id) : '',
            is_noindex: domain.is_noindex === 1,
            admin_access: Number(domain.admin_access ?? 1) === 1,
            https_only: domain.https_only === 1,
            cloudflare_proxy: Number(domain.cloudflare_proxy ?? 0) === 1,
            registrar: domain.registrar || '',
            dns_provider: domain.dns_provider || '',
            status: domain.status || 'OK'
        });
        setError('');
        setSaveNotice('');
        setShowModal(true);
    };

    const forceCheckAllDns = async () => {
        if (!window.confirm(t('domains.forceCheckConfirm'))) return;
        setForceChecking(true);
        try {
            const { data } = await cachedGet('force_check_all_dns');
            if (data.status === 'success') {
                fetchDomains();
            }
        } catch (e) {
            console.error(e);
        } finally {
            setForceChecking(false);
        }
    };

    /**
     * Issue certificates now, from the panel.
     *
     * Issuance normally runs from cron and from a process spawned in the
     * background on save. Both need shell_exec, which plenty of hosts disable —
     * and when they do, every domain sits at "pending" with nothing to click and
     * no way to see why. This runs the same worker inside the request and reports
     * back, including the reasons it could not work.
     */
    useEffect(() => {
        let cancelled = false;
        cachedGet('ssl_environment')
            .then(({ data }) => { if (!cancelled && data.status === 'success') setSslEnv(data.data); })
            .catch(() => { /* the page works without the banner */ });
        return () => { cancelled = true; };
    }, []);

    /**
     * The SSL endpoints answer with codes, not sentences — the panel speaks seven
     * languages, so the wording lives in the locale files. Anything unmapped is
     * shown as sent: Certbot's own output is diagnostic text from the server and
     * is not ours to translate.
     */
    const translateSslCode = (code) => {
        const keys = {
            php_no_shell: 'domains.sslEnvNoShell',
            no_certbot: 'domains.sslEnvNoCertbot',
            no_nginx_config: 'domains.sslEnvNoNginx',
            acme_not_writable: 'domains.sslEnvAcmeNotWritable',
            certbot_no_output: 'domains.sslCertbotNoOutput',
            incomplete_chain: 'domains.sslIncompleteChain',
            dns_mismatch: 'domains.sslWaitingDns',
        };
        return keys[code] ? t(keys[code]) : String(code);
    };

    /**
     * ssl_error carries either a JSON payload we produced (a code plus the
     * addresses involved) or raw Certbot output. Both end up in the same tooltip.
     */
    const describeSslError = (raw) => {
        if (!raw) return '';
        try {
            const parsed = JSON.parse(raw);
            if (parsed && parsed.code) {
                const text = translateSslCode(parsed.code);
                if (parsed.code === 'dns_mismatch') {
                    const seen = Array.isArray(parsed.seen) && parsed.seen.length
                        ? parsed.seen.join(', ')
                        : t('domains.sslNoARecord');
                    return `${text}\n${t('domains.sslDnsSeen')}: ${seen}\n${t('domains.serverIp')}: ${parsed.expected || '—'}`;
                }
                return text;
            }
        } catch (e) {
            // Not ours — Certbot output, shown verbatim.
        }
        return String(raw);
    };

    const runSslWorker = async () => {
        setSslRunning(true);
        try {
            const { data } = await cachedPost('run_ssl_worker', {});
            if (data.status === 'success') {
                const r = data.data || {};
                const lines = [
                    `${t('domains.sslRunIssued')}: ${r.issued ?? 0}`,
                    `${t('domains.sslRunWaitingDns')}: ${r.waiting ?? 0}`,
                    `${t('domains.sslRunFailed')}: ${r.failed ?? 0}`,
                ];
                if (r.server_ip) lines.push(`${t('domains.serverIp')}: ${r.server_ip}`);
                if (Array.isArray(r.notes) && r.notes.length) lines.push('', ...r.notes.map(translateSslCode));
                alert(lines.join('\n'));
                fetchDomains();
            } else {
                alert(data.message || t('domains.sslRunError'));
            }
        } catch (e) {
            alert(`${t('domains.sslRunError')}: ${e.response?.data?.message || e.message}`);
        } finally {
            setSslRunning(false);
        }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('domains.deleteConfirm'))) return;
        try {
            await cachedPost('delete_domain', { id });
            fetchDomains();
        } catch (e) {
            console.error(e);
        }
    };

    const copyIp = async () => {
        try {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(serverIp);
                setCopiedIp(true);
                setTimeout(() => setCopiedIp(false), 2000);
                return;
            }
        } catch (err) {
            console.warn('Clipboard API failed, falling back to execCommand', err);
        }

        // Fallback for non-HTTPS contexts
        try {
            const textarea = document.createElement('textarea');
            textarea.value = serverIp;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);

            if (successful) {
                setCopiedIp(true);
                setTimeout(() => setCopiedIp(false), 2000);
            } else {
                throw new Error('execCommand failed');
            }
        } catch (err) {
            console.error('Failed to copy IP:', err);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        try {
            const res = await cachedPost('save_domain', formData);
            if (res.data.status === 'success') {
                fetchDomains();
                // Zero-config parking report: when the Namecheap integration
                // wrote the A records itself, say so — otherwise the operator
                // goes looking for the DNS instructions that are no longer needed.
                const parked = (res.data.domains || []).filter(d => d.namecheap).map(d => `${d.name}: ${d.namecheap}`);
                if (parked.length) {
                    alert(`✓ ${t('namecheap.parkedOk')}:\n${parked.join('\n')}`);
                }
                if (!formData.id && addMore) {
                    // Keep the chosen group and settings — the point of the
                    // checkbox is parking a batch into the same configuration —
                    // and clear only the name for the next entry.
                    setFormData(prev => ({ ...defaultFormData, group_id: prev.group_id, index_campaign_id: prev.index_campaign_id, catch_404: prev.catch_404, is_noindex: prev.is_noindex, admin_access: prev.admin_access, https_only: prev.https_only, cloudflare_proxy: prev.cloudflare_proxy, registrar: prev.registrar, dns_provider: prev.dns_provider, status: prev.status }));
                    setSaveNotice(t('domains.savedAddMore', 'Saved — ready for the next domain'));
                    setTimeout(() => setSaveNotice(''), 3000);
                    nameInputRef.current?.focus();
                    return;
                }
                setShowModal(false);
                setFormData(defaultFormData);
            } else {
                setError(res.data.message || t('common.error'));
            }
        } catch (e) {
            setError(t('common.networkError'));
        }
    };

    return (
        <div className="page-card mb-6">
            <InfoBanner storageKey="help_domains" title={t('help.domainBannerTitle')}>
                <p>{t('help.domainBanner')}</p>
            </InfoBanner>
            <div className="flex justify-between items-center mb-6">
                <div className="flex items-center gap-4">
                    <h2 className="text-lg font-semibold flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                        <Globe size={20} style={{ color: 'var(--color-text-secondary)' }} />
                        {t('domains.title')}
                    </h2>
                    {serverIp && (
                        <div className="flex items-center px-3 py-1 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                            <span className="font-medium mr-2">{t('domains.serverIp')}</span>
                            <span className="font-mono">{serverIp}</span>
                            <button onClick={copyIp} className="ml-2 hover:text-[var(--color-primary)] transition flex items-center gap-1" style={{ color: 'var(--color-text-secondary)' }} title={copiedIp ? t('migrations.copied') : t('common.copy')}>
                                {copiedIp ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                                {copiedIp && <span className="text-xs text-green-500">{t('migrations.copied')}</span>}
                            </button>
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--color-text-secondary)' }} />
                        <input
                            type="text"
                            placeholder={t('domains.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="form-input w-64"
                            style={{ paddingLeft: '36px' }}
                        />
                    </div>
                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }} title={t('domains.ignoreDnsHint')}>
                        <input
                            type="checkbox"
                            checked={ignoreDnsUi}
                            onChange={(e) => setIgnoreDnsUi(Boolean(e.target.checked))}
                        />
                        <span style={{ color: 'var(--color-text-primary)' }}>{t('domains.ignoreDnsLabel')}</span>
                    </label>
                    <button
                        onClick={forceCheckAllDns}
                        disabled={forceChecking}
                        className="btn flex items-center gap-2"
                        style={{
                            background: forceChecking ? 'var(--color-bg-soft)' : 'var(--color-success, #10b981)',
                            color: forceChecking ? 'var(--color-text-muted)' : 'white',
                            cursor: forceChecking ? 'not-allowed' : 'pointer'
                        }}
                        title={t('domains.forceCheckTitle')}
                    >
                        <RefreshCw size={16} className={forceChecking ? 'animate-spin' : ''} />
                        {forceChecking ? t('domains.checkingShort') : t('domains.checkDns')}
                    </button>
                    <button
                        onClick={runSslWorker}
                        disabled={sslRunning}
                        className="btn btn-secondary flex items-center gap-2"
                        title={t('domains.issueSslTitle')}
                    >
                        <ShieldAlert size={16} className={sslRunning ? 'animate-spin' : ''} />
                        {sslRunning ? t('domains.checkingShort') : t('domains.issueSsl')}
                    </button>
                    {ncConnected && (
                        <>
                            <button
                                onClick={() => { setShowRegister(true); setRegResult(null); setRegMessage(''); }}
                                className="btn btn-secondary flex items-center gap-2"
                                title={t('namecheap.registerHint', 'Купить домен через баланс Namecheap и припарковать его сюда одним кликом')}
                            >
                                <ShoppingCart size={16} /> {t('namecheap.registerBtn', 'Register Domain')}
                            </button>
                            <button
                                onClick={openImport}
                                className="btn btn-secondary flex items-center gap-2"
                                title={t('namecheap.importHint', 'Выбрать домены из аккаунта Namecheap и добавить их в трекер')}
                            >
                                <Download size={16} /> {t('namecheap.importBtn', 'Import from Namecheap')}
                            </button>
                        </>
                    )}
                    <button
                        onClick={() => {
                            setFormData(defaultFormData);
                            setError('');
                            setSaveNotice('');
                            setShowModal(true);
                        }}
                        className="btn btn-primary"
                    >
                        <Plus size={16} /> {t('domains.addDomain')}
                    </button>
                </div>
            </div>

            {/* Said once, on load, rather than only after clicking "Issue SSL".
                A server that cannot run external commands never issues anything,
                and a permanent "waiting for certificate" with no explanation is
                what sent people hunting through logs. */}
            {sslEnv && !sslEnv.can_issue && (
                <div className="mb-4 p-4 rounded-2xl flex gap-3" style={{
                    backgroundColor: 'var(--color-warning-bg)',
                    border: '1px solid var(--color-warning)'
                }}>
                    <ShieldAlert size={20} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: '2px' }} />
                    <div style={{ color: 'var(--color-text-primary)', fontSize: '13.5px', lineHeight: 1.55 }}>
                        <div className="font-semibold mb-1" style={{ color: 'var(--color-warning)' }}>
                            {t('domains.sslEnvTitle')}
                        </div>
                        <p style={{ margin: 0 }}>
                            {sslEnv.shell === false
                                ? t('domains.sslEnvNoShell')
                                : t('domains.sslEnvNoCertbot')}
                        </p>
                        {sslEnv.nginx_config === false && (
                            <p className="mt-1" style={{ margin: '4px 0 0' }}>{t('domains.sslEnvNoNginx')}</p>
                        )}
                        <p className="mt-1" style={{ margin: '4px 0 0', color: 'var(--color-text-secondary)' }}>
                            {t('domains.sslEnvHint')}
                        </p>
                    </div>
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th>{t('domains.domain')}</th>
                            <th>{t('domains.group')}</th>
                            <th>{t('domains.status')}</th>
                            <th>{t('domains.indexPage')}</th>
                            <th className="text-center">{t('domains.https')}</th>
                            <th className="text-center">{t('domains.sslStatus')}</th>
                            <th>{t('domains.dateAdded')}</th>
                            <th className="text-right">{t('domains.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="8" className="text-center py-8">{t('domains.loading')}</td></tr>
                        ) : filteredDomains.length === 0 ? (
                            <tr><td colSpan="8" className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('domains.noDomains')}</td></tr>
                        ) : (
                            filteredDomains.map(domain => (
                                <tr key={domain.id}>
                                    <td className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{domain.name}</td>
                                    <td>
                                        {domain.group_name
                                            ? <span className="badge" style={{ background: 'color-mix(in srgb, var(--color-primary) 10%, transparent)', color: 'var(--color-primary)' }}>{domain.group_name}</span>
                                            : <span style={{ color: 'var(--color-text-muted)' }}>—</span>}
                                    </td>
                                    <td>
                                        {String(domain.status) === 'Disabled' ? (
                                            <span className="badge badge-danger"><X size={14} /> {t('domains.statusDisabled')}</span>
                                        ) : (ignoreDnsUi || domain.dns_state === 'active') ? (
                                            <span className="badge badge-success">
                                                <Check size={14} /> {String(domain.status) === 'Active' ? t('domains.statusActive') : t('domains.ok')}
                                            </span>
                                        ) : (
                                            <button
                                                onClick={() => setShowDnsModal(true)}
                                                className="badge badge-danger cursor-pointer hover:bg-red-500/20 transition"
                                            >
                                                <ShieldAlert size={14} /> {t('domains.awaitingDns')}
                                            </button>
                                        )}
                                    </td>
                                    <td>{domain.index_campaign_name || <span className="italic" style={{ color: 'var(--color-text-muted)' }}>{t('domains.notSelected')}</span>}</td>
                                    <td className="text-center">
                                        {domain.https_only ? <Check size={16} className="text-green-500 mx-auto" /> : <X size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} />}
                                    </td>
                                    <td className="text-center">
                                        {/* A tick here used to mean "a certificate file exists",
                                            which is not the same as "the browser gets it": nginx
                                            only serves it once its config was rebuilt with the
                                            certificate already on disk. https_active carries that
                                            distinction, so a certificate nobody wired up no longer
                                            shows as done. The status is also no longer gated on
                                            https_only — every parked domain gets a certificate. */}
                                        {domain.ssl_status === 'cloudflare' ? (
                                            <Cloud size={16} className="mx-auto" style={{ color: 'var(--color-primary)' }} title={t('domains.sslCloudflare', 'SSL от Cloudflare (проксированный домен)')} />
                                        ) : domain.ssl_status === 'installed' && domain.https_active === false ? (
                                            <AlertCircle size={16} className="text-orange-500 mx-auto" title={t('domains.sslNotWired')} />
                                        ) : domain.ssl_status === 'installed' ? (
                                            <Check size={16} className="text-green-500 mx-auto" title={t('domains.sslInstalled')} />
                                        ) : domain.ssl_status === 'installing' ? (
                                            <RefreshCw size={16} className="text-blue-500 mx-auto animate-spin" title={t('domains.sslInstalling')} />
                                        ) : domain.ssl_status === 'waiting_dns' ? (
                                            <Clock size={16} className="text-yellow-500 mx-auto" title={describeSslError(domain.ssl_error) || t('domains.sslWaitingDns')} />
                                        ) : domain.ssl_status === 'failed' ? (
                                            <AlertCircle size={16} className="text-red-500 mx-auto" title={`${t('domains.sslRetrying')}\n\n${describeSslError(domain.ssl_error)}`} />
                                        ) : domain.ssl_status === 'pending' ? (
                                            <Clock size={16} className="text-yellow-500 mx-auto" title={t('domains.sslPending')} />
                                        ) : (
                                            <Clock size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} title={t('domains.sslPending')} />
                                        )}
                                    </td>
                                    <td className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{domain.created_at}</td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <button onClick={() => handleEdit(domain)} className="hover:text-[var(--color-primary)] transition" style={{ color: 'var(--color-text-muted)' }} title={t('components.edit')}>
                                                <Edit2 size={16} />
                                            </button>
                                            <button onClick={() => handleDelete(domain.id)} className="hover:text-red-500 transition" style={{ color: 'var(--color-text-muted)' }} title={t('common.delete')}>
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {showModal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-2xl" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{formData.id ? t('domains.editDomain') : t('domains.addDomainTitle')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowModal(false)}>
                                <X size={20} />
                            </button>
                        </div>
                        {error && <div className="alert alert-danger mb-4 flex items-center gap-2"><AlertCircle size={16} />{error}</div>}
                        {saveNotice && (
                            <div className="alert alert-success mb-4 flex items-center gap-2"><Check size={16} />{saveNotice}</div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            {/* Domain */}
                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.domainName')}</label>
                                <input
                                    ref={nameInputRef}
                                    type="text"
                                    required
                                    autoFocus={!formData.id}
                                    className="form-input w-full"
                                    placeholder={t('domains.bulkPlaceholder')}
                                    value={formData.name}
                                    onChange={e => setFormData({ ...formData, name: cleanNameInput(e.target.value) })}
                                />
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('domains.domainBulkHelper')} {t('domains.bulkExample')}
                                </p>
                            </div>

                            {/* Group */}
                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.group')}</label>
                                <div className="flex gap-2">
                                    <select
                                        className="form-select w-full"
                                        value={formData.group_id}
                                        onChange={e => setFormData({ ...formData, group_id: e.target.value })}
                                    >
                                        <option value="">{t('domains.noGroup')}</option>
                                        {domainGroups.map(g => (
                                            <option key={g.id} value={g.id}>{g.name}</option>
                                        ))}
                                    </select>
                                    <button
                                        type="button"
                                        className="btn btn-secondary whitespace-nowrap"
                                        onClick={() => setShowGroupsModal(true)}
                                    >
                                        <Plus size={14} /> {t('domains.createGroup')}
                                    </button>
                                </div>
                            </div>

                            {/* Control toggles */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label className="block text-xs font-medium mb-1.5">{t('domains.searchRobots')}</label>
                                    <ToggleGroup
                                        value={formData.is_noindex ? 'disallow' : 'allow'}
                                        onChange={v => setFormData({ ...formData, is_noindex: v === 'disallow' })}
                                        options={[
                                            { value: 'allow', label: t('domains.allowRobotsShort') },
                                            { value: 'disallow', label: t('domains.disallowShort') }
                                        ]}
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium mb-1.5">{t('domains.adminDashboard')}</label>
                                    <ToggleGroup
                                        value={formData.admin_access ? 'allow' : 'deny'}
                                        onChange={v => setFormData({ ...formData, admin_access: v === 'allow' })}
                                        options={[
                                            { value: 'allow', label: t('domains.allowAccess') },
                                            { value: 'deny', label: t('domains.denyAccess') }
                                        ]}
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium mb-1.5 flex items-center gap-1">
                                        {t('domains.httpsOnlyShort')} <HelpTooltip textKey="help.httpsTooltip" />
                                    </label>
                                    <ToggleGroup
                                        value={formData.https_only ? 'on' : 'off'}
                                        onChange={v => setFormData({ ...formData, https_only: v === 'on' })}
                                        options={[
                                            { value: 'on', label: t('domains.on') },
                                            { value: 'off', label: t('domains.off') }
                                        ]}
                                    />
                                </div>
                            </div>
                            {!formData.admin_access && (
                                <p className="text-xs -mt-2" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.adminAccessHint')}</p>
                            )}

                            {/* Cloudflare Proxy */}
                            <div className="flex items-center justify-between gap-4 p-3 rounded-lg" style={{ background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                <div>
                                    <label className="block text-sm font-medium">{t('domains.cloudflareProxy')}</label>
                                    <p className="text-xs mt-0.5" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.cfProxyHint')}</p>
                                </div>
                                <div style={{ minWidth: '140px' }}>
                                    <ToggleGroup
                                        value={formData.cloudflare_proxy ? 'on' : 'off'}
                                        onChange={v => setFormData({ ...formData, cloudflare_proxy: v === 'on' })}
                                        options={[
                                            { value: 'on', label: t('domains.on') },
                                            { value: 'off', label: t('domains.off') }
                                        ]}
                                    />
                                </div>
                            </div>

                            {/* Index page */}
                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.indexPageLabel')} <HelpTooltip textKey="help.indexCampaignTooltip" /></label>
                                <div className="flex items-center gap-3">
                                    <select
                                        className="form-select flex-1"
                                        value={formData.index_campaign_id}
                                        onChange={e => setFormData({ ...formData, index_campaign_id: e.target.value })}
                                    >
                                        <option value="">-- {t('domains.notSelected')} --</option>
                                        {campaigns.map(c => (
                                            <option key={c.id} value={c.id}>{c.name} ({c.alias})</option>
                                        ))}
                                    </select>
                                    <label className="inline-flex items-center gap-2 text-sm font-medium whitespace-nowrap cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.catch_404}
                                            onChange={e => setFormData({ ...formData, catch_404: e.target.checked })}
                                        />
                                        {t('domains.catch404')}
                                    </label>
                                </div>
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.indexPageHint')}</p>
                            </div>

                            {/* Metadata */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label className="block text-xs font-medium mb-1.5">{t('domains.registrar')}</label>
                                    <input
                                        type="text"
                                        className="form-input w-full"
                                        placeholder={t('domains.optional')}
                                        value={formData.registrar}
                                        onChange={e => setFormData({ ...formData, registrar: e.target.value })}
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium mb-1.5">{t('domains.dnsProvider')}</label>
                                    <input
                                        type="text"
                                        className="form-input w-full"
                                        placeholder="Cloudflare"
                                        value={formData.dns_provider}
                                        onChange={e => setFormData({ ...formData, dns_provider: e.target.value })}
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium mb-1.5">{t('domains.status')}</label>
                                    <select
                                        className="form-select w-full"
                                        value={formData.status}
                                        onChange={e => setFormData({ ...formData, status: e.target.value })}
                                    >
                                        <option value="OK">{t('domains.statusOk')}</option>
                                        <option value="Active">{t('domains.statusActive')}</option>
                                        <option value="Disabled">{t('domains.statusDisabled')}</option>
                                    </select>
                                </div>
                            </div>

                            <div className="modal-footer mt-6">
                                {!formData.id && (
                                    <label className="inline-flex items-center gap-2 text-sm font-medium cursor-pointer mr-auto">
                                        <input
                                            type="checkbox"
                                            checked={addMore}
                                            onChange={e => setAddMore(e.target.checked)}
                                        />
                                        {t('domains.addMore')}
                                    </label>
                                )}
                                <button type="button" onClick={() => setShowModal(false)} className="btn btn-secondary">{t('common.cancel')}</button>
                                <button type="submit" className="btn btn-primary">{formData.id ? t('common.save') : t('common.add')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Domain groups: create/delete from inside the Add/Edit modal */}
            {showGroupsModal && (
                <GroupsModal
                    type="domain"
                    onClose={setShowGroupsModal}
                    onGroupCreated={(g) => {
                        fetchDomainGroups();
                        if (g && g.id) setFormData(f => ({ ...f, group_id: String(g.id) }));
                    }}
                />
            )}

            {/* Namecheap: Register Domain */}
            {showRegister && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <ShoppingCart size={18} /> {t('namecheap.registerTitle', 'Register Domain')}
                            </h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowRegister(false)}>
                                <X size={20} />
                            </button>
                        </div>
                        <div className="space-y-4">
                            <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('namecheap.registerHintLong', 'Домен регистрируется через баланс вашего аккаунта Namecheap: сразу после покупки A-запись указывает на этот сервер, и SSL Let\'s Encrypt выпускается автоматически.')}
                            </p>
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    className="form-input"
                                    style={{ flex: 1 }}
                                    placeholder="my-new-domain.com"
                                    value={regDomain}
                                    onChange={e => { setRegDomain(e.target.value.toLowerCase()); setRegResult(null); }}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); checkNcDomain(); } }}
                                />
                                <button
                                    type="button"
                                    className="btn btn-secondary"
                                    disabled={regChecking || !regDomain.trim()}
                                    onClick={checkNcDomain}
                                >
                                    {regChecking ? t('domains.checkingShort') : t('namecheap.checkBtn', 'Check Availability')}
                                </button>
                            </div>

                            {regResult && (
                                <div className="rounded-2xl p-4" style={{ border: '1px solid var(--color-border)', background: 'var(--color-bg-soft)' }}>
                                    {regResult.available ? (
                                        <>
                                            <div className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                                <Check size={16} className="text-green-500" />
                                                <span className="font-medium">{regResult.domain}</span>
                                                <span className="text-xs">— {t('namecheap.available', 'свободен')}</span>
                                            </div>
                                            {regResult.price && (
                                                <div className="text-sm mt-1" style={{ color: 'var(--color-text-primary)' }}>
                                                    {regResult.is_premium ? t('namecheap.premium', 'Premium-домен') + ': ' : t('namecheap.price', 'Цена') + ': '}
                                                    <span className="font-semibold">${regResult.price}</span>
                                                </div>
                                            )}
                                            <button
                                                type="button"
                                                className="btn btn-primary mt-3 w-full"
                                                disabled={regBuying}
                                                onClick={buyAndPark}
                                            >
                                                <ShoppingCart size={16} />
                                                {regBuying ? t('namecheap.buying', 'Покупаем…') : t('namecheap.buyPark', 'Buy & Park Domain')}
                                            </button>
                                        </>
                                    ) : (
                                        <div className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                            <X size={16} className="text-red-500" />
                                            <span className="font-medium">{regResult.domain}</span>
                                            <span className="text-xs">— {t('namecheap.taken', 'занят')}</span>
                                        </div>
                                    )}
                                </div>
                            )}

                            {regMessage && (
                                <div className="alert alert-danger flex items-center gap-2"><AlertCircle size={16} />{regMessage}</div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Namecheap: Import domains from the account */}
            {showImport && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-lg" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <Download size={18} /> {t('namecheap.importTitle', 'Import from Namecheap')}
                            </h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowImport(false)}>
                                <X size={20} />
                            </button>
                        </div>
                        <div className="space-y-4">
                            <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('namecheap.importHintLong', 'Все домены аккаунта Namecheap. Отмеченные добавляются в трекер: A-запись и SSL настраиваются автоматически.')}
                            </p>
                            {ncImport.loading ? (
                                <div className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                    <RefreshCw size={20} className="animate-spin mx-auto mb-2" />
                                    {t('common.loading')}
                                </div>
                            ) : (
                                <>
                                    <div className="overflow-y-auto rounded-2xl" style={{ maxHeight: '320px', border: '1px solid var(--color-border)' }}>
                                        {ncImport.domains.map(d => {
                                            // Домены, которых нет в selected, уже были в трекере —
                                            // при открытии модалки отмечаются только новые.
                                            const inTracker = !Object.prototype.hasOwnProperty.call(ncImport.selected, d);
                                            const isSel = !!ncImport.selected[d];
                                            return (
                                                <label key={d} className="flex items-center gap-3 px-4 py-2 cursor-pointer" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                                    <input
                                                        type="checkbox"
                                                        checked={isSel}
                                                        onChange={e => setNcImport(s => ({ ...s, selected: { ...s.selected, [d]: e.target.checked } }))}
                                                    />
                                                    <span className="font-mono text-sm" style={{ color: isSel ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}>{d}</span>
                                                    {inTracker && (
                                                        <span className="badge badge-success ml-auto text-xs"><Check size={12} /> {t('namecheap.inTracker', 'уже в трекере')}</span>
                                                    )}
                                                </label>
                                            );
                                        })}
                                        {!ncImport.domains.length && (
                                            <div className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('namecheap.noDomains', 'В аккаунте нет доменов')}
                                            </div>
                                        )}
                                    </div>
                                    {ncImport.message && (
                                        <div className="alert alert-danger flex items-center gap-2"><AlertCircle size={16} />{ncImport.message}</div>
                                    )}
                                    <div className="modal-footer">
                                        <button type="button" className="btn btn-secondary" onClick={() => setShowImport(false)}>{t('common.cancel')}</button>
                                        <button
                                            type="button"
                                            className="btn btn-primary"
                                            disabled={ncImport.importing || !Object.values(ncImport.selected).some(Boolean)}
                                            onClick={importSelected}
                                        >
                                            <Download size={16} />
                                            {ncImport.importing
                                                ? t('namecheap.importing', 'Импортируем…')
                                                : `${t('namecheap.importBtn2', 'Импортировать')} (${Object.values(ncImport.selected).filter(Boolean).length})`}
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* DNS Warning Modal */}
            {showDnsModal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: 0 }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <AlertCircle className="text-orange-500" /> {t('domains.dnsTitle')}
                            </h3>
                            <button onClick={() => setShowDnsModal(false)} className="btn btn-ghost btn-icon">
                                <X size={20} />
                            </button>
                        </div>
                        <div className="p-6">
                            <p className="text-sm mb-4" style={{ color: 'var(--color-text-primary)' }}>
                                {t('domains.dnsInstruction')}
                            </p>
                            <div className="bg-[var(--color-bg-soft)] border border-[var(--color-border)] rounded p-4 mb-4 font-mono text-sm text-center text-[var(--color-text-primary)]">
                                @ &nbsp;&nbsp; IN &nbsp;&nbsp; <span className="font-bold text-blue-600">{serverIp}</span>
                            </div>
                            <p className="text-sm mb-2 items-center flex gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                <span className="w-1.5 h-1.5 rounded-full bg-gray-400"></span> {t('domains.dnsNote1')}
                            </p>
                            <p className="text-sm items-center flex gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span> {t('domains.dnsNote2')}
                            </p>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowDnsModal(false)} className="btn btn-secondary">{t('common.close')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Domains;
