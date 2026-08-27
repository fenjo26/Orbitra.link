import React, { useState, useEffect, useRef, useMemo } from 'react';
import { Plus, Globe, Check, X, AlertCircle, Search, Copy, Edit2, Trash2, ShieldAlert, RefreshCw, Clock, Cloud, ShoppingCart, Download, Folder, ChevronUp, ChevronDown, ChevronsUpDown, GripVertical } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import GroupsModal from './GroupsModal';
import MobileCards from './common/MobileCards';
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
    const [selectedGroupId, setSelectedGroupId] = useState('');
    const [selectedDomainIds, setSelectedDomainIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'asc' });
    const [serverIp, setServerIp] = useState('');
    const [loading, setLoading] = useState(true);
    const [bulkGroupModal, setBulkGroupModal] = useState(false);
    const [bulkGroupId, setBulkGroupId] = useState('');
    const [ignoreDnsUi, setIgnoreDnsUi] = useState(() => {
        // UI-only toggle for migrations/tests when DNS isn't set yet.
        // Do not use this in production to "fix" misconfigured DNS.
        const v = localStorage.getItem('domains_ignore_dns_ui');
        return v === '1';
    });
    const [copiedIp, setCopiedIp] = useState(false);
    const [forceChecking, setForceChecking] = useState(false);
    const [sslRunning, setSslRunning] = useState(false);
    const [reissuingSsl, setReissuingSsl] = useState(null); // Track which domain is being re-issued
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
        dns_account_id: null,
        status: 'OK',
        custom_ssl_cert: '',
        custom_ssl_key: '',
        ssl_source: 'auto'
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

    // SSL Error Modal State
    const [showSslErrorModal, setShowSslErrorModal] = useState(false);
    const [sslErrorDomain, setSslErrorDomain] = useState(null);

    // Namecheap integration: when connected, "Register Domain" and
    // "Import from Namecheap" appear, and adding domains auto-parks their DNS.
    // Buyers keep several Namecheap accounts — both dialogs pick the one to
    // act through (namecheap_status returns them with balances).
    const [ncConnected, setNcConnected] = useState(false);
    // Status answer arrived (success OR failure). Until it does, both NC
    // buttons render disabled instead of appearing after the fetch and
    // reflowing the toolbar — which layout you got was a network race.
    const [ncResolved, setNcResolved] = useState(false);
    const [ncAccounts, setNcAccounts] = useState([]);
    const [ncAccountId, setNcAccountId] = useState(null);
    const [ncIntent, setNcIntent] = useState(null); // deep-link from Integrations cards
    const [showRegister, setShowRegister] = useState(false);
    const [regDomain, setRegDomain] = useState('');
    const [regChecking, setRegChecking] = useState(false);
    const [regResult, setRegResult] = useState(null); // {domain, available, is_premium, price}
    const [regBuying, setRegBuying] = useState(false);
    const [regMessage, setRegMessage] = useState('');
    const [showImport, setShowImport] = useState(false);
    const [ncImport, setNcImport] = useState({ loading: false, domains: [], selected: {}, importing: false, message: '' });
    // Cloudflare side of the multi-account DNS pin («manage via» in the
    // Add/Edit modal); the Namecheap side reuses ncAccounts below.
    const [cfAccounts, setCfAccounts] = useState([]);

    useEffect(() => {
        cachedGet('cloudflare_accounts_list', { _: Date.now() }, 0)
            .then(({ data }) => { if (data.status === 'success') setCfAccounts(data.data.accounts || []); })
            .catch(() => {});
        // ttl=0: the account list must be fresh — a just-added account would
        // otherwise stay invisible for the cache window.
        cachedGet('namecheap_status', {}, 0)
            .then(({ data }) => {
                if (data.status !== 'success') {
                    // A failed check must still resolve the buttons — leaving
                    // them permanently dead is the failure this guards against.
                    setNcResolved(true);
                    return;
                }
                const accounts = data.data.accounts || [];
                setNcAccounts(accounts);
                setNcConnected(!!data.data.connected);
                setNcResolved(true);
                if (accounts.length) setNcAccountId(a => a || accounts[0].id);
            })
            .catch(() => setNcResolved(true));
        // Deep-link request from an Integrations account card (Buy / Import):
        // read once; the dialog opens from the effect below once the domain
        // list is loaded — the import dialog needs it to tell fresh domains
        // from already-tracked ones.
        try {
            const raw = localStorage.getItem('orbitra_nc_intent');
            if (raw) {
                localStorage.removeItem('orbitra_nc_intent');
                setNcIntent(JSON.parse(raw));
            }
        } catch (e) { /* malformed intent — ignore */ }
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

    // The account a dialog acts through: explicit choice, else the first one.
    const activeNcAccount = ncAccounts.find(a => a.id === ncAccountId) || ncAccounts[0] || null;

    // Deep-link from Integrations: fire once the domain list is ready (and an
    // account actually exists — the card pointed at one).
    useEffect(() => {
        if (!ncIntent || loading || !ncAccounts.length) return;
        const target = ncAccounts.find(a => a.id === ncIntent.account_id);
        setNcIntent(null);
        if (!target) return;
        setNcAccountId(target.id);
        if (ncIntent.mode === 'import') {
            openImport(target.id);
        } else {
            setShowRegister(true); setRegResult(null); setRegMessage('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loading, ncAccounts, ncIntent]);

    const checkNcDomain = async () => {
        const domain = regDomain.trim().toLowerCase();
        if (!domain) return;
        setRegChecking(true); setRegResult(null); setRegMessage('');
        try {
            const { data } = await cachedPost('namecheap_check_domain', { domain, account_id: activeNcAccount?.id });
            if (data.status === 'success') {
                setRegResult(data.data);
            } else {
                setRegMessage(data.message || t('common.error'));
            }
        } catch (e) {
            setRegMessage((e?.message ? String(e.message) : t('common.networkError')));
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
            const { data } = await cachedPost('namecheap_register_domain', { domain, account_id: activeNcAccount?.id });
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
            setRegMessage((e?.message ? String(e.message) : t('common.networkError')));
        } finally {
            setRegBuying(false);
        }
    };

    const openImport = async (accountId) => {
        // onClick passes the SyntheticEvent as the first argument; only a real
        // numeric account id counts, anything else falls through to the active
        // account. Guard here (not at call sites) so future miswiring is inert.
        const accId = (typeof accountId === 'number' ? accountId : null) ?? activeNcAccount?.id ?? null;
        setShowImport(true);
        setNcImport({ loading: true, domains: [], selected: {}, importing: false, message: '', ipHint: '' });
        try {
            const [{ data: listRes }] = await Promise.all([cachedPost('namecheap_domains', accId ? { account_id: accId } : {})]);
            if (listRes.status !== 'success') {
                let errorMsg = listRes.message || t('common.error');
                // Add IP whitelist hint if Namecheap returned one
                const detectedIp = listRes.detail?.ip || '';
                if (detectedIp) {
                    errorMsg = t('namecheap.errConnection') + '. ' + t('namecheap.whitelistError', 'Server IP must be whitelisted').replace('{ip}', detectedIp);
                    setNcImport(s => ({ ...s, loading: false, message: errorMsg, ipHint: detectedIp }));
                } else {
                    setNcImport(s => ({ ...s, loading: false, message: errorMsg }));
                }
                return;
            }
            const have = new Set(domains.map(d => d.name.toLowerCase()));
            const fresh = (listRes.data.domains || []).filter(d => !have.has(d));
            const selected = {};
            fresh.forEach(d => { selected[d] = true; });
            setNcImport({ loading: false, domains: listRes.data.domains || [], selected, importing: false, message: '', ipHint: '' });
        } catch (e) {
            // The backend's diagnostic (whitelist IP hint, HTTP status) dies with
            // e when it is swallowed — and so does a client-side exception that
            // never touched the network, which this key used to hide entirely.
            setNcImport(s => ({ ...s, loading: false, message: e?.message ? String(e.message) : t('common.networkError') }));
        }
    };

    const importSelected = async () => {
        const names = Object.keys(ncImport.selected).filter(k => ncImport.selected[k]);
        if (!names.length) return;
        setNcImport(s => ({ ...s, importing: true, message: '' }));
        try {
            const payload = { name: names.join(', ') };
            if (activeNcAccount?.id) {
                // Park through the account whose list is on screen, not the
                // default one.
                payload.dns_provider = 'namecheap';
                payload.dns_account_id = activeNcAccount.id;
            }
            const { data } = await cachedPost('save_domain', payload);
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
            setNcImport(s => ({ ...s, importing: false, message: (e?.message ? String(e.message) : t('common.networkError')) }));
        }
    };

    useEffect(() => {
        fetchDomains();
    }, []);
    const fetchDomains = async () => {
        try {
            const { data } = await cachedGet('domains');
            if (data.status === 'success') {
                // The 5s SSL poll below calls this repeatedly while a domain
                // is stuck in a non-final state. Writing an identical (but
                // fresh-object) payload would re-render the whole page —
                // modals included — every ~30s for nothing, so the write is
                // gated on the payload actually changing. cache_age is a
                // per-request counter the UI never reads, so it is excluded
                // from the comparison.
                const payload = JSON.stringify(data.data, (key, value) => key === 'cache_age' ? undefined : value);
                if (payload !== lastDomainsPayloadRef.current) {
                    lastDomainsPayloadRef.current = payload;
                    setDomains(data.data);
                    setFilteredDomains(data.data);
                }
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
        setFilteredDomains(domains.filter(d => {
            const matchesSearch = d.name.toLowerCase().includes(lowercased);
            const matchesGroup = !selectedGroupId || String(d.group_id || '') === selectedGroupId;
            return matchesSearch && matchesGroup;
        }));
    }, [searchTerm, domains, selectedGroupId]);

    // Sorted domains
    const visibleDomains = useMemo(() => {
        if (!sortBy.key) return filteredDomains;
        const dirMul = sortBy.dir === 'asc' ? 1 : -1;
        return filteredDomains
            .map((d, idx) => ({ domain: d, idx }))
            .sort((a, b) => {
                const av = a.domain[sortBy.key];
                const bv = b.domain[sortBy.key];
                let cmp = 0;
                if (sortBy.key === 'id') {
                    cmp = (Number(av) || 0) - (Number(bv) || 0);
                } else {
                    cmp = String(av || '').localeCompare(String(bv || ''), undefined, { sensitivity: 'base' });
                }
                if (cmp !== 0) return cmp * dirMul;
                return a.idx - b.idx;
            })
            .map(x => x.domain);
    }, [filteredDomains, sortBy]);

    const toggleSelected = (id, checked) => {
        setSelectedDomainIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedDomainIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleDomains.forEach(d => next.add(d.id));
            } else {
                visibleDomains.forEach(d => next.delete(d.id));
            }
            return next;
        });
    };

    const allSelected = visibleDomains.length > 0 && visibleDomains.every(d => selectedDomainIds.has(d.id));
    const someSelected = visibleDomains.some(d => selectedDomainIds.has(d.id));

    const handleBulkDelete = async () => {
        const ids = Array.from(selectedDomainIds);
        if (ids.length === 0) return;
        if (!window.confirm(t('domains.bulkDeleteConfirm', 'Delete {count} domains?').replace('{count}', String(ids.length)))) return;
        try {
            await Promise.all(ids.map(id => cachedPost('delete_domain', { id })));
            setSelectedDomainIds(new Set());
            fetchDomains();
        } catch (e) {
            console.error(e);
            alert(t('common.error'));
        }
    };

    const handleBulkChangeGroup = async () => {
        const ids = Array.from(selectedDomainIds);
        if (ids.length === 0) return;
        if (!bulkGroupId) return;
        try {
            await Promise.all(ids.map(id => cachedPost('save_domain', { id, group_id: bulkGroupId })));
            setSelectedDomainIds(new Set());
            setBulkGroupModal(false);
            setBulkGroupId('');
            fetchDomains();
        } catch (e) {
            console.error(e);
            alert(t('common.error'));
        }
    };

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => {
            if (prev.key === key) {
                return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
            }
            return { key, dir: defaultDir };
        });
    };

    const SortIcon = ({ colKey }) => {
        if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3.5 h-3.5 opacity-40" />;
        return sortBy.dir === 'asc'
            ? <ChevronUp className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
            : <ChevronDown className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />;
    };

    useEffect(() => {
        localStorage.setItem('domains_ignore_dns_ui', ignoreDnsUi ? '1' : '0');
    }, [ignoreDnsUi]);

    // Poll for SSL status updates every 5 seconds when there are pending/installing domains.
    // The pending check reads a ref so the interval is created once instead of
    // being torn down and rebuilt on every domains array replacement.
    const pendingSslRef = useRef(false);
    useEffect(() => {
        pendingSslRef.current = domains.some(d => ['pending', 'installing', 'waiting_dns'].includes(d.ssl_status));
    }, [domains]);
    const lastDomainsPayloadRef = useRef(null);
    useEffect(() => {
        const interval = setInterval(() => {
            if (pendingSslRef.current) fetchDomains();
        }, 5000);
        return () => clearInterval(interval);
        // fetchDomains is re-created every render but always reads the same
        // refs and setters, so a single interval is enough.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const handleEdit = (domain) => {
        const sslSource = domain.ssl_source || 'auto';
        setFormData({
            id: domain.id,
            name: domain.name,
            index_campaign_id: domain.index_campaign_id || '',
            catch_404: domain.catch_404 === 1,
            group_id: domain.group_id ? String(domain.group_id) : '',
            is_noindex: domain.is_noindex === 1,
            admin_access: Number(domain.admin_access ?? 1) === 1,
            https_only: domain.https_only === 1,
            // ssl_source is the single source of truth for the SSL mode. The
            // proxy flag is derived from it on load, but a domain auto-detected
            // as proxied (ssl_source 'auto', flag 1) must not silently flip to
            // off just by opening and saving the dialog unchanged.
            cloudflare_proxy: sslSource === 'cloudflare_origin' || Number(domain.cloudflare_proxy ?? 0) === 1,
            registrar: domain.registrar || '',
            dns_provider: domain.dns_provider || '',
            dns_account_id: domain.dns_account_id || null,
            status: domain.status || 'OK',
            // All three must be loaded: Save writes them back, so omitting any
            // resets the stored SSL configuration to the defaults.
            ssl_source: sslSource,
            custom_ssl_cert: domain.custom_ssl_cert || '',
            custom_ssl_key: domain.custom_ssl_key || ''
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
            awaiting_dns_for_ssl_switch: 'domains.sslAwaitingDnsSwitch',
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

    // Short certificate-source label for the SSL Status column. The source is
    // already in the row but lived only in the icon's title attribute — invisible
    // in screenshots and support conversations. 'auto' has no source to name.
    const sslSourceShort = (domain) => {
        switch (domain.ssl_source) {
            case 'letsencrypt': return t('domains.sslLetsEncrypt', "Let's Encrypt");
            case 'cloudflare_origin': return t('domains.sslCloudflare', 'Cloudflare');
            case 'custom': return t('domains.sslCustom', 'Custom');
            case 'self_signed': return t('domains.sslSelfSigned', 'Self-signed');
            default: return '';
        }
    };

    // Shared renderers: the desktop table and the mobile cards call the same
    // helpers, so the seven-branch status conditional, the SSL cell and the
    // actions row never exist as two copies that can drift apart.
    const renderDomainStatus = (domain) => (
        String(domain.status) === 'Disabled' ? (
            <span className="badge badge-danger"><X size={14} /> {t('domains.statusDisabled')}</span>
        ) : ignoreDnsUi ? (
            <span className="badge badge-success">
                <Check size={14} /> {String(domain.status) === 'Active' ? t('domains.statusActive') : t('domains.ok')}
            </span>
        ) : domain.dns_state === 'active' ? (
            <span className="badge badge-success">
                {domain.dns_reason === 'cloudflare' ? (
                    <><Cloud size={14} /> {t('domains.activeCloudflare')}</>
                ) : domain.dns_reason === 'local' ? (
                    <><Check size={14} /> {t('domains.activeLocalhost')}</>
                ) : (
                    <><Check size={14} /> {String(domain.status) === 'Active' ? t('domains.statusActive') : t('domains.ok')}</>
                )}
            </span>
        ) : domain.dns_state === 'pending' && domain.dns_reason === 'no_resolve' ? (
            <button
                onClick={() => setShowDnsModal(true)}
                className="badge badge-warning cursor-pointer hover:bg-yellow-500/20 transition"
                title={t('domains.dnsReasonNoResolve')}
            >
                <Clock size={14} /> {t('domains.awaitingDns')}
            </button>
        ) : domain.dns_state === 'pending' && domain.dns_reason?.startsWith('wrong_ip:') ? (
            <button
                onClick={() => setShowDnsModal(true)}
                className="badge badge-danger cursor-pointer hover:bg-red-500/20 transition"
                title={t('domains.dnsReasonWrongIp', { ip: domain.dns_reason?.replace('wrong_ip:', '') })}
            >
                <ShieldAlert size={14} /> {t('domains.wrongIp')}
            </button>
        ) : (
            <button
                onClick={() => setShowDnsModal(true)}
                className="badge badge-danger cursor-pointer hover:bg-red-500/20 transition"
            >
                <ShieldAlert size={14} /> {t('domains.awaitingDns')}
            </button>
        )
    );

    const renderDomainSsl = (domain) => (
        /* A tick here used to mean "a certificate file exists", which is not
           the same as "the browser gets it": nginx only serves it once its
           config was rebuilt with the certificate already on disk.
           https_active carries that distinction, so a certificate nobody
           wired up no longer shows as done. The status is also no longer
           gated on https_only — every parked domain gets a certificate. */
        domain.ssl_status === 'cloudflare' ? (
            <span className="inline-flex items-center gap-1.5" title={t('domains.sslCloudflareStatus', 'SSL от Cloudflare (проксированный домен)')}>
                <Cloud size={16} style={{ color: 'var(--color-primary)' }} />
                <span className="text-xs whitespace-nowrap" style={{ color: 'var(--color-text-muted)' }}>{t('domains.sslCloudflare', 'Cloudflare')}</span>
            </span>
        ) : domain.ssl_status === 'installed' && domain.https_active === false ? (
            <button
                onClick={() => { setSslErrorDomain(domain); setShowSslErrorModal(true); }}
                className="hover:text-orange-400 transition"
                style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                title={t('domains.sslNotWired')}
            >
                <AlertCircle size={16} className="text-orange-500" />
            </button>
        ) : domain.ssl_status === 'installed' ? (
            <span className="inline-flex items-center gap-1.5" title={t('domains.sslInstalled')}>
                <Check size={16} className="text-green-500" />
                {sslSourceShort(domain) && <span className="text-xs whitespace-nowrap" style={{ color: 'var(--color-text-muted)' }}>{sslSourceShort(domain)}</span>}
            </span>
        ) : domain.ssl_status === 'installing' ? (
            <RefreshCw size={16} className="text-blue-500 animate-spin" title={t('domains.sslInstalling')} />
        ) : domain.ssl_status === 'waiting_dns' ? (
            <button
                onClick={() => { setSslErrorDomain(domain); setShowSslErrorModal(true); }}
                className="hover:text-yellow-400 transition"
                style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                title={describeSslError(domain.ssl_error) || t('domains.sslWaitingDns')}
            >
                <Clock size={16} className="text-yellow-500" />
            </button>
        ) : domain.ssl_status === 'failed' ? (
            <button
                onClick={() => { setSslErrorDomain(domain); setShowSslErrorModal(true); }}
                className="hover:text-red-400 transition"
                style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                title={`${t('domains.sslRetrying')}\n\n${describeSslError(domain.ssl_error)}`}
            >
                <AlertCircle size={16} className="text-red-500" />
            </button>
        ) : domain.ssl_status === 'pending' ? (
            <Clock size={16} className="text-yellow-500" title={t('domains.sslPending')} />
        ) : (
            <Clock size={16} style={{ color: 'var(--color-text-muted)' }} title={t('domains.sslPending')} />
        )
    );

    const renderDomainActions = (domain) => (
        <div className="flex items-center gap-2">
            <button
                onClick={() => reissueSsl(domain.id, domain.name)}
                disabled={reissuingSsl === domain.id}
                className={`hover:text-[var(--color-primary)] transition ${reissuingSsl === domain.id ? 'text-blue-500' : ''}`}
                style={{ color: reissuingSsl === domain.id ? 'var(--color-primary)' : 'var(--color-text-muted)', cursor: reissuingSsl === domain.id ? 'wait' : 'pointer' }}
                title={t('domains.reissueSsl', 'Re-issue SSL certificate')}
            >
                <RefreshCw size={16} className={reissuingSsl === domain.id ? 'animate-spin' : ''} />
            </button>
            <button onClick={() => handleEdit(domain)} className="hover:text-[var(--color-primary)] transition" style={{ color: 'var(--color-text-muted)' }} title={t('components.edit')}>
                <Edit2 size={16} />
            </button>
            <button onClick={() => handleDelete(domain.id)} className="hover:text-red-500 transition" style={{ color: 'var(--color-text-muted)' }} title={t('common.delete')}>
                <Trash2 size={16} />
            </button>
        </div>
    );

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

    /**
     * Re-issue SSL certificate for a specific domain.
     * This forces a new certificate to be issued, replacing any existing one.
     */
    const reissueSsl = async (domainId, domainName) => {
        if (!window.confirm(`${t('domains.reissueConfirm', 'Are you sure you want to re-issue the SSL certificate for')} ${domainName}?`)) return;
        setReissuingSsl(domainId);
        try {
            const { data } = await cachedPost('reissue_ssl', { id: domainId });
            if (data.status === 'success') {
                alert(data.message || t('domains.sslIssued', 'SSL certificate issued successfully'));
                fetchDomains();
            } else {
                alert(data.message || t('domains.sslError', 'Failed to issue SSL certificate'));
            }
        } catch (e) {
            alert(`${t('domains.sslError')}: ${e.response?.data?.message || e.message}`);
        } finally {
            setReissuingSsl(null);
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
                    setFormData(prev => ({ ...defaultFormData, group_id: prev.group_id, index_campaign_id: prev.index_campaign_id, catch_404: prev.catch_404, is_noindex: prev.is_noindex, admin_access: prev.admin_access, https_only: prev.https_only, cloudflare_proxy: prev.cloudflare_proxy, registrar: prev.registrar, dns_provider: prev.dns_provider, dns_account_id: prev.dns_account_id, status: prev.status }));
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
            setError((e?.message ? String(e.message) : t('common.networkError')));
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
                        /* tb-hide-sm: the readout costs a full toolbar row on a
                           phone; the IP stays one tap away in DNS check tooltips. */
                        <div className="flex items-center px-3 py-1 rounded text-sm border tb-hide-sm" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                            <span className="font-medium mr-2">{t('domains.serverIp')}</span>
                            <span className="font-mono">{serverIp}</span>
                            <button onClick={copyIp} className="ml-2 hover:text-[var(--color-primary)] transition flex items-center gap-1" style={{ color: 'var(--color-text-secondary)' }} title={copiedIp ? t('migrations.copied') : t('common.copy')}>
                                {copiedIp ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                                {copiedIp && <span className="text-xs text-green-500">{t('migrations.copied')}</span>}
                            </button>
                        </div>
                    )}
                </div>

                {/* Wraps as a unit on narrow screens; each label stays on one
                    line — a two-line "Check / DNS" read as clutter, not as two
                    controls' worth of information. */}
                <div className="flex flex-wrap items-center gap-3">
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
                    <select
                        value={selectedGroupId}
                        onChange={(e) => setSelectedGroupId(e.target.value)}
                        className="form-select text-xs py-1.5 px-3 rounded-xl tb-release"
                        style={{ width: '150px' }}
                    >
                        <option value="">{t('domains.allGroups', 'All Groups')}</option>
                        {domainGroups.map(g => (
                            <option key={g.id} value={String(g.id)}>{g.name}</option>
                        ))}
                        <option value="__no_group__">{t('domains.noGroup')}</option>
                    </select>
                    {selectedDomainIds.size > 0 && (
                        <>
                            <button
                                onClick={() => setBulkGroupModal(true)}
                                className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                                title={t('domains.bulkChangeGroupTitle', 'Change group for selected domains')}
                            >
                                <Folder size={16} />
                                {t('domains.bulkChangeGroup', 'Change Group')} ({selectedDomainIds.size})
                            </button>
                            <button
                                onClick={handleBulkDelete}
                                className="btn btn-danger flex items-center gap-2 whitespace-nowrap"
                                title={t('domains.bulkDeleteTitle', 'Delete selected domains')}
                            >
                                <Trash2 size={16} />
                                {t('common.deleteSelected', 'Delete')} ({selectedDomainIds.size})
                            </button>
                        </>
                    )}
                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }} title={t('domains.ignoreDnsHint')}>
                        <input
                            type="checkbox"
                            checked={ignoreDnsUi}
                            onChange={(e) => setIgnoreDnsUi(Boolean(e.target.checked))}
                        />
                        <span style={{ color: 'var(--color-text-primary)' }}>{t('domains.ignoreDnsLabel')}</span>
                    </label>
                    {/* btn-secondary, not a hardcoded success fill: the token
                        system themes every other control in this row. */}
                    <button
                        onClick={forceCheckAllDns}
                        disabled={forceChecking}
                        className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                        title={t('domains.forceCheckTitle')}
                    >
                        <RefreshCw size={16} className={forceChecking ? 'animate-spin' : ''} />
                        {forceChecking ? t('domains.checkingShort') : t('domains.checkDns')}
                    </button>
                    <button
                        onClick={runSslWorker}
                        disabled={sslRunning}
                        className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                        title={t('domains.issueSslTitle')}
                    >
                        <ShieldAlert size={16} className={sslRunning ? 'animate-spin' : ''} />
                        {sslRunning ? t('domains.checkingShort') : t('domains.issueSsl')}
                    </button>
                    <button
                        onClick={() => setShowGroupsModal(true)}
                        className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                        title={t('domains.groupsTitle', 'Manage domain groups')}
                    >
                        <Folder size={16} />
                        {t('domains.groups', 'Groups')}
                    </button>
                    {(!ncResolved || ncConnected) && (
                        <>
                            <button
                                onClick={() => { setShowRegister(true); setRegResult(null); setRegMessage(''); }}
                                disabled={!ncResolved}
                                className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                                style={ncResolved ? undefined : { opacity: 0.5, cursor: 'default' }}
                                title={t('namecheap.registerHint', 'Купить домен через баланс Namecheap и припарковать его сюда одним кликом')}
                            >
                                <ShoppingCart size={16} /> {t('namecheap.registerBtn', 'Register Domain')}
                            </button>
                            <button
                                onClick={() => openImport()}
                                disabled={!ncResolved}
                                className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                                style={ncResolved ? undefined : { opacity: 0.5, cursor: 'default' }}
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
                        className="btn btn-primary whitespace-nowrap"
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
                <div className="alert-banner alert-warning">
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

            {/* Below lg the nine-column table is replaced by stacked cards;
                the table keeps its scroll container for tablets. */}
            <div className="hidden lg:block overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-8" style={{ textAlign: 'left' }}>
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                    className="w-3.5 h-3.5 rounded"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />
                            </th>
                            <th>
                                <button
                                    type="button"
                                    onClick={() => requestSort('name')}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                                    style={{ color: sortBy.key === 'name' ? 'var(--color-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {t('domains.domain')}
                                    <SortIcon colKey="name" />
                                </button>
                            </th>
                            <th>
                                <button
                                    type="button"
                                    onClick={() => requestSort('group_name')}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                                    style={{ color: sortBy.key === 'group_name' ? 'var(--color-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {t('domains.group')}
                                    <SortIcon colKey="group_name" />
                                </button>
                            </th>
                            <th>
                                <button
                                    type="button"
                                    onClick={() => requestSort('status')}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                                    style={{ color: sortBy.key === 'status' ? 'var(--color-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {t('domains.status')}
                                    <SortIcon colKey="status" />
                                </button>
                            </th>
                            <th>{t('domains.indexPage')}</th>
                            <th className="text-center">{t('domains.https')}</th>
                            <th className="text-center">{t('domains.sslStatus')}</th>
                            <th>
                                <button
                                    type="button"
                                    onClick={() => requestSort('created_at')}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                                    style={{ color: sortBy.key === 'created_at' ? 'var(--color-primary)' : 'var(--color-text-secondary)' }}
                                >
                                    {t('domains.dateAdded')}
                                    <SortIcon colKey="created_at" />
                                </button>
                            </th>
                            <th className="text-right">{t('domains.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="9" className="text-center py-8">{t('domains.loading')}</td></tr>
                        ) : visibleDomains.length === 0 ? (
                            <tr><td colSpan="9" className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('domains.noDomains')}</td></tr>
                        ) : (
                            visibleDomains.map(domain => (
                                <tr key={domain.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedDomainIds.has(domain.id)}
                                            onChange={(e) => toggleSelected(domain.id, e.target.checked)}
                                            className="w-3.5 h-3.5 rounded"
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                    </td>
                                    <td className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{domain.name}</td>
                                    <td>
                                        {domain.group_name
                                            ? <span className="badge" style={{ background: 'color-mix(in srgb, var(--color-primary) 10%, transparent)', color: 'var(--color-primary)' }}>{domain.group_name}</span>
                                            : <span style={{ color: 'var(--color-text-muted)' }}>—</span>}
                                    </td>
                                    <td>
                                        {renderDomainStatus(domain)}
                                    </td>
                                    <td>{domain.index_campaign_name || <span className="italic" style={{ color: 'var(--color-text-muted)' }}>{t('domains.notSelected')}</span>}</td>
                                    <td className="text-center">
                                        {domain.https_only ? <Check size={16} className="text-green-500 mx-auto" /> : <X size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} />}
                                    </td>
                                    <td className="text-center">
                                        {renderDomainSsl(domain)}
                                    </td>
                                    <td className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{domain.created_at}</td>
                                    <td className="text-right">
                                        {renderDomainActions(domain)}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Mobile: stacked cards (below lg). Status, SSL and actions come
                from the same renderers the table uses. */}
            <div className="lg:hidden">
                <MobileCards
                    rows={visibleDomains}
                    getId={(d) => d.id}
                    renderTitle={(d) => (
                        <>
                            <input
                                type="checkbox"
                                checked={selectedDomainIds.has(d.id)}
                                onChange={(e) => toggleSelected(d.id, e.target.checked)}
                                className="w-3.5 h-3.5 rounded flex-shrink-0"
                                style={{ accentColor: 'var(--color-primary)' }}
                                aria-label={d.name}
                            />
                            <span className="font-semibold text-sm flex-1 min-w-0 line-clamp-2 break-words" style={{ color: 'var(--color-text-primary)' }}>{d.name}</span>
                        </>
                    )}
                    renderSubtitle={(d) => `#${d.id}${d.created_at ? ` · ${d.created_at}` : ''}`}
                    renderHeaderRight={renderDomainActions}
                    fields={[
                        {
                            id: 'group',
                            label: t('domains.group'),
                            render: (d) => d.group_name ? (
                                <span className="badge" style={{ background: 'color-mix(in srgb, var(--color-primary) 10%, transparent)', color: 'var(--color-primary)' }}>{d.group_name}</span>
                            ) : (
                                <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                            ),
                        },
                        { id: 'status', label: t('domains.status'), render: renderDomainStatus },
                        { id: 'https', label: t('domains.https'), render: (d) => d.https_only ? <Check size={16} className="text-green-500" /> : <X size={16} style={{ color: 'var(--color-text-muted)' }} /> },
                        { id: 'ssl', label: t('domains.sslStatus'), render: renderDomainSsl },
                        { id: 'index', label: t('domains.indexPage'), render: (d) => d.index_campaign_name || <span style={{ color: 'var(--color-text-muted)' }}>{t('domains.notSelected')}</span> },
                    ]}
                    primaryIds={['status', 'ssl']}
                    emptyState={<div className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('domains.noDomains')}</div>}
                />
            </div>

            {showModal && (
                <div className="modal-overlay">
                    <div 
                        className="modal-content" 
                        style={{ 
                            width: '100%', 
                            maxWidth: '840px', 
                            padding: '24px 28px',
                            borderRadius: '20px' 
                        }}
                    >
                        {/* Заголовок */}
                        <div className="modal-header" style={{ marginBottom: '18px', paddingBottom: '12px' }}>
                            <h3 className="modal-title" style={{ fontSize: '17px', fontWeight: 600 }}>
                                {formData.id ? t('domains.editDomain') : t('domains.addDomainTitle')}
                            </h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowModal(false)}>
                                <X size={20} />
                            </button>
                        </div>

                        {error && <div className="alert alert-danger mb-4 flex items-center gap-2"><AlertCircle size={16} />{error}</div>}
                        {saveNotice && <div className="alert alert-success mb-4 flex items-center gap-2"><Check size={16} />{saveNotice}</div>}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                
                                {/* === ЛЕВАЯ КОЛОНКА (Домен, Группа, Главная страница, Метаданные) === */}
                                <div className="space-y-4">
                                    {/* Domain name */}
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider mb-1.5" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('domains.domainName')}
                                        </label>
                                        <input
                                            ref={nameInputRef}
                                            type="text"
                                            required
                                            autoFocus={!formData.id}
                                            className="form-input w-full font-mono text-sm"
                                            placeholder={t('domains.bulkPlaceholder')}
                                            value={formData.name}
                                            onChange={e => setFormData({ ...formData, name: cleanNameInput(e.target.value) })}
                                        />
                                        <p className="text-[11px] mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('domains.domainBulkHelper')}
                                        </p>
                                    </div>

                                    {/* Group */}
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider mb-1.5" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('domains.group')}
                                        </label>
                                        <div className="flex gap-2">
                                            <select
                                                className="form-select flex-1"
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
                                                className="btn btn-secondary whitespace-nowrap btn-sm"
                                                onClick={() => setShowGroupsModal(true)}
                                            >
                                                <Plus size={14} /> {t('domains.createGroup')}
                                            </button>
                                        </div>
                                    </div>

                                    {/* Index page (Default campaign) */}
                                    <div>
                                        <label className="block text-xs font-semibold uppercase tracking-wider mb-1.5 flex items-center gap-1" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('domains.indexPageLabel')} <HelpTooltip textKey="help.indexCampaignTooltip" />
                                        </label>
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
                                            <label className="inline-flex items-center gap-1.5 text-xs font-medium whitespace-nowrap cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={formData.catch_404}
                                                    onChange={e => setFormData({ ...formData, catch_404: e.target.checked })}
                                                />
                                                {t('domains.catch404')}
                                            </label>
                                        </div>
                                    </div>

                                    {/* Metadata row */}
                                    <div className="grid grid-cols-3 gap-2.5 pt-1">
                                        <div>
                                            <label className="block text-[11px] font-medium mb-1" style={{ color: 'var(--color-text-muted)' }}>{t('domains.registrar')}</label>
                                            <input
                                                type="text"
                                                className="form-input w-full text-xs"
                                                placeholder={t('domains.optional')}
                                                value={formData.registrar}
                                                onChange={e => setFormData({ ...formData, registrar: e.target.value })}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-[11px] font-medium mb-1" style={{ color: 'var(--color-text-muted)' }}>{t('domains.dnsProvider')}</label>
                                            <input
                                                type="text"
                                                className="form-input w-full text-xs"
                                                placeholder="Cloudflare"
                                                value={formData.dns_provider}
                                                onChange={e => setFormData({ ...formData, dns_provider: e.target.value })}
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-[11px] font-medium mb-1" style={{ color: 'var(--color-text-muted)' }}>{t('domains.status')}</label>
                                            <select
                                                className="form-select w-full text-xs"
                                                value={formData.status}
                                                onChange={e => setFormData({ ...formData, status: e.target.value })}
                                            >
                                                <option value="OK">{t('domains.statusOk')}</option>
                                                <option value="Active">{t('domains.statusActive')}</option>
                                                <option value="Disabled">{t('domains.statusDisabled')}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                {/* === ПРАВАЯ КОЛОНКА (Безопасность, Доступ, Cloudflare) === */}
                                <div className="space-y-4">
                                    {/* 3 Переключателя доступа в ряд */}
                                    <div className="grid grid-cols-3 gap-2.5">
                                        <div>
                                            <label className="block text-[11px] font-semibold mb-1 truncate" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.searchRobots')}</label>
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
                                            <label className="block text-[11px] font-semibold mb-1 truncate" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.adminDashboard')}</label>
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
                                            <label className="block text-[11px] font-semibold mb-1 truncate flex items-center gap-0.5" style={{ color: 'var(--color-text-secondary)' }}>
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

                                    {/* Подсказка о закрытии админки */}
                                    {!formData.admin_access && (
                                        <p className="text-[11px] -mt-2" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                            {t('domains.adminAccessHint')}
                                        </p>
                                    )}

                                    {/* Просторная плашка Cloudflare Proxy */}
                                    <div 
                                        className="p-4 rounded-xl border flex items-center justify-between gap-4" 
                                        style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                                    >
                                        <div className="flex-1">
                                            <div className="font-semibold text-xs mb-0.5" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('domains.cloudflareProxy')}
                                            </div>
                                            <p className="text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                                {t('domains.cfProxyHint')}
                                            </p>
                                        </div>
                                        <div style={{ width: '120px', flexShrink: 0 }}>
                                            <ToggleGroup
                                                value={formData.cloudflare_proxy ? 'on' : 'off'}
                                                onChange={v => setFormData(f => ({
                                                    ...f,
                                                    cloudflare_proxy: v === 'on',
                                                    // Keep ssl_source in step: proxy on means the
                                                    // edge serves SSL; proxy off demotes an explicit
                                                    // Cloudflare choice back to auto.
                                                    ...(v === 'on'
                                                        ? { ssl_source: 'cloudflare_origin' }
                                                        : (f.ssl_source === 'cloudflare_origin' ? { ssl_source: 'auto' } : {}))
                                                }))}
                                                options={[
                                                    { value: 'on', label: t('domains.on') },
                                                    { value: 'off', label: t('domains.off') }
                                                ]}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* SSL Mode Selector */}
                                <div className="space-y-3">
                                    <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('domains.sslMode', 'SSL Mode')}
                                    </label>
                                    <div className="grid grid-cols-3 gap-2">
                                        {/* Every writer keeps ssl_source and the proxy flag in
                                            step — one decision, two stored columns. */}
                                        {/* Let's Encrypt */}
                                        <button
                                            type="button"
                                            className={`btn btn-sm ${formData.ssl_source === 'letsencrypt' ? 'btn-primary' : 'btn-secondary'}`}
                                            onClick={() => setFormData({ ...formData, ssl_source: 'letsencrypt', cloudflare_proxy: false })}
                                        >
                                            {t('domains.sslLetsEncrypt', "Let's Encrypt")}
                                        </button>
                                        {/* Cloudflare — highlight on ssl_source alone: mixing in
                                            the proxy flag lit two mutually exclusive modes at once. */}
                                        <button
                                            type="button"
                                            className={`btn btn-sm ${formData.ssl_source === 'cloudflare_origin' ? 'btn-primary' : 'btn-secondary'}`}
                                            onClick={() => setFormData({ ...formData, ssl_source: 'cloudflare_origin', cloudflare_proxy: true })}
                                        >
                                            {t('domains.sslCloudflare', 'Cloudflare')}
                                        </button>
                                        {/* Custom — an origin cert must not stay behind the proxy:
                                            that combination is Full Strict, a different setup. */}
                                        <button
                                            type="button"
                                            className={`btn btn-sm ${formData.ssl_source === 'custom' ? 'btn-primary' : 'btn-secondary'}`}
                                            onClick={() => setFormData({ ...formData, ssl_source: 'custom', cloudflare_proxy: false })}
                                        >
                                            {t('domains.sslCustom', 'Custom')}
                                        </button>
                                    </div>
                                    {/* Mode-specific hints */}
                                    {formData.ssl_source === 'letsencrypt' && (
                                        <p className="text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                            {t('domains.sslLetsEncryptHint', 'Auto-issued certificate. Point A record to server IP.')}
                                        </p>
                                    )}
                                    {formData.ssl_source === 'cloudflare_origin' && (
                                        <p className="text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                            {t('domains.sslCloudflareHint', 'Cloudflare serves SSL certificate. Self-signed cert on origin for Full SSL mode.')}
                                        </p>
                                    )}
                                    {formData.ssl_source === 'custom' && (
                                        <p className="text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                            {t('domains.sslCustomHint', 'Provide your own certificate and key file paths.')}
                                        </p>
                                    )}
                                </div>

                                {/* ORB-014: Custom SSL Certificate (Full Strict) */}
                                {(formData.cloudflare_proxy || formData.custom_ssl_cert || formData.custom_ssl_key) && (
                                    <div className="space-y-3" style={{ padding: '12px', borderRadius: '8px', backgroundColor: 'var(--color-bg-secondary)' }}>
                                        <div style={{ fontSize: '12px', fontWeight: 600, color: 'var(--color-text-secondary)', marginBottom: '8px' }}>
                                            {t('domains.customSslCert', 'Custom SSL Certificate (Full Strict)')}
                                        </div>
                                        <p className="text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4, marginBottom: '12px' }}>
                                            {t('domains.customSslHint', 'For Cloudflare Full Strict mode, paste the paths to your Cloudflare Origin CA certificate and key files on the server. Leave empty for automatic management (Let\'s Encrypt or self-signed).')}
                                        </p>
                                        <div className="space-y-2">
                                            <div>
                                                <label style={{ fontSize: '12px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '4px' }}>
                                                    {t('domains.customCertPath', 'Certificate Path')}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={formData.custom_ssl_cert}
                                                    onChange={e => setFormData({ ...formData, custom_ssl_cert: e.target.value })}
                                                    placeholder="/etc/orbitra/ssl/cloudflare_origin.crt"
                                                    className="form-input form-input-sm"
                                                    style={{ fontSize: '12px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '12px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '4px' }}>
                                                    {t('domains.customKeyPath', 'Private Key Path')}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={formData.custom_ssl_key}
                                                    onChange={e => setFormData({ ...formData, custom_ssl_key: e.target.value })}
                                                    placeholder="/etc/orbitra/ssl/cloudflare_origin.key"
                                                    className="form-input form-input-sm"
                                                    style={{ fontSize: '12px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                        </div>
                                        {(formData.custom_ssl_cert || formData.custom_ssl_key) && (
                                            <div className="flex items-center gap-2" style={{ fontSize: '11px', color: 'var(--color-warning)' }}>
                                                <AlertTriangle size={14} />
                                                <span>
                                                    {t('domains.customSslWarning', 'Certificate files must exist on the server. See documentation for setup instructions.')}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Footer с кнопками сохранения */}
                            <div className="modal-footer mt-6 pt-4 border-t flex items-center justify-between" style={{ borderColor: 'var(--color-border)' }}>
                                {!formData.id ? (
                                    <label className="inline-flex items-center gap-2 text-xs font-medium cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={addMore}
                                            onChange={e => setAddMore(e.target.checked)}
                                        />
                                        {t('domains.addMore')}
                                    </label>
                                ) : <div />}
                                
                                <div className="flex gap-2.5">
                                    <button type="button" onClick={() => setShowModal(false)} className="btn btn-secondary btn-sm">
                                        {t('common.cancel')}
                                    </button>
                                    <button type="submit" className="btn btn-primary btn-sm">
                                        {formData.id ? t('common.save') : t('common.add')}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Domain groups: create/delete from inside the Add/Edit modal */}
            {showGroupsModal && (
                <GroupsModal
                    type="domain"
                    onClose={() => {
                        setShowGroupsModal(false);
                        fetchDomainGroups();
                    }}
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
                            {ncAccounts.length > 1 && (
                                <div>
                                    <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                        {t('namecheap.chooseAccount', 'Аккаунт Namecheap')}
                                    </label>
                                    <select
                                        className="form-select"
                                        style={{ width: '100%' }}
                                        value={activeNcAccount?.id ?? ''}
                                        onChange={e => { setNcAccountId(Number(e.target.value)); setRegResult(null); setRegMessage(''); }}
                                    >
                                        {ncAccounts.map(a => (
                                            <option key={a.id} value={a.id}>{a.name} ({a.last_balance || '—'})</option>
                                        ))}
                                    </select>
                                </div>
                            )}
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
                            {ncAccounts.length > 1 && (
                                <div>
                                    <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                        {t('namecheap.chooseAccount', 'Аккаунт Namecheap')}
                                    </label>
                                    <select
                                        className="form-select"
                                        style={{ width: '100%' }}
                                        value={activeNcAccount?.id ?? ''}
                                        onChange={e => { setNcAccountId(Number(e.target.value)); openImport(Number(e.target.value)); }}
                                        disabled={ncImport.loading}
                                    >
                                        {ncAccounts.map(a => (
                                            <option key={a.id} value={a.id}>{a.name} ({a.last_balance || '—'})</option>
                                        ))}
                                    </select>
                                </div>
                            )}
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
                                        <div className="alert alert-danger flex items-start gap-2">
                                            <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
                                            <div className="flex-1">
                                                <div>{ncImport.message}</div>
                                                {ncImport.ipHint && (
                                                    <div className="mt-2 flex items-center gap-2 text-xs">
                                                        <span style={{ color: 'var(--color-text-secondary)' }}>
                                                            {t('namecheap.whitelistIp', 'Whitelist IP')}:
                                                        </span>
                                                        <code className="px-2 py-0.5 rounded font-mono" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-primary)' }}>
                                                            {ncImport.ipHint}
                                                        </code>
                                                        <button
                                                            onClick={() => {
                                                                navigator.clipboard?.writeText(ncImport.ipHint);
                                                                setCopiedIp(true);
                                                                setTimeout(() => setCopiedIp(false), 2000);
                                                            }}
                                                            className="hover:text-[var(--color-primary)] transition"
                                                            style={{ color: 'var(--color-text-secondary)' }}
                                                            title={t('common.copy')}
                                                        >
                                                            {copiedIp ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
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
                                @ &nbsp;&nbsp; IN &nbsp;&nbsp; <span className="font-bold text-[var(--color-primary)]">{serverIp}</span>
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

            {/* SSL Error Modal */}
            {showSslErrorModal && sslErrorDomain && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-lg" style={{ padding: 0 }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <ShieldAlert className="text-red-500" /> {t('domains.sslErrorTitle', 'SSL Certificate Error')}
                            </h3>
                            <button onClick={() => setShowSslErrorModal(false)} className="btn btn-ghost btn-icon">
                                <X size={20} />
                            </button>
                        </div>
                        <div className="p-6">
                            <div className="mb-4 pb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                                <div className="text-xs uppercase tracking-wider font-semibold mb-1" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('domains.domain', 'Domain')}
                                </div>
                                <div className="font-mono text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                    {sslErrorDomain.name}
                                </div>
                            </div>

                            <div className="mb-4">
                                <div className="text-xs uppercase tracking-wider font-semibold mb-2 flex items-center gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                    <ShieldAlert size={14} className="text-red-500" />
                                    {t('domains.sslStatus', 'SSL Status')}
                                </div>
                                <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium" style={{
                                    background: sslErrorDomain.ssl_status === 'failed'
                                        ? 'color-mix(in srgb, #ef4444 15%, transparent)'
                                        : sslErrorDomain.ssl_status === 'waiting_dns'
                                            ? 'color-mix(in srgb, #eab308 15%, transparent)'
                                            : 'color-mix(in srgb, #f97316 15%, transparent)',
                                    color: sslErrorDomain.ssl_status === 'failed'
                                        ? '#ef4444'
                                        : sslErrorDomain.ssl_status === 'waiting_dns'
                                            ? '#eab308'
                                            : '#f97316'
                                }}>
                                    {sslErrorDomain.ssl_status === 'failed' && <X size={12} />}
                                    {sslErrorDomain.ssl_status === 'waiting_dns' && <Clock size={12} />}
                                    {sslErrorDomain.ssl_status === 'failed' ? t('domains.sslFailed', 'Failed') : sslErrorDomain.ssl_status === 'waiting_dns' ? t('domains.sslWaitingDns', 'Waiting for DNS') : t('domains.sslNotWired', 'Not Wired')}
                                </div>
                            </div>

                            {sslErrorDomain.ssl_error && (
                                <div>
                                    <div className="text-xs uppercase tracking-wider font-semibold mb-2 flex items-center gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                        <AlertCircle size={14} className="text-red-500" />
                                        {t('domains.errorDetails', 'Error Details')}
                                    </div>
                                    <div className="rounded p-4 text-sm whitespace-pre-wrap font-mono" style={{
                                        background: 'var(--color-bg-soft)',
                                        border: '1px solid var(--color-border)',
                                        color: 'var(--color-text-primary)'
                                    }}>
                                        {describeSslError(sslErrorDomain.ssl_error)}
                                    </div>
                                </div>
                            )}

                            {sslErrorDomain.ssl_status === 'waiting_dns' && (
                                <div className="mt-4 p-3 rounded text-xs" style={{
                                    background: 'color-mix(in srgb, #eab308 10%, transparent)',
                                    border: '1px solid color-mix(in srgb, #eab308 25%, transparent)',
                                    color: 'var(--color-text-primary)'
                                }}>
                                    <div className="font-medium mb-1" style={{ color: '#eab308' }}>
                                        {t('domains.sslWaitingDns', 'Waiting for DNS')}
                                    </div>
                                    <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.5 }}>
                                        {t('domains.sslWaitingDnsHint', 'The certificate will be issued automatically once DNS propagates. This usually takes 1-5 minutes, but can take up to 24 hours.')}
                                    </div>
                                </div>
                            )}

                            {sslErrorDomain.ssl_status === 'failed' && (
                                <div className="mt-4 p-3 rounded text-xs" style={{
                                    background: 'color-mix(in srgb, #ef4444 10%, transparent)',
                                    border: '1px solid color-mix(in srgb, #ef4444 25%, transparent)',
                                    color: 'var(--color-text-primary)'
                                }}>
                                    <div className="font-medium mb-1" style={{ color: '#ef4444' }}>
                                        {t('domains.sslAutoRetry', 'Automatic Retry')}
                                    </div>
                                    <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.5 }}>
                                        {t('domains.sslAutoRetryHint', 'The system will automatically retry issuing the certificate. Check back in a few minutes or click "Issue SSL" to force an immediate attempt.')}
                                    </div>
                                </div>
                            )}
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowSslErrorModal(false)} className="btn btn-secondary">
                                {t('common.close', 'Close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Bulk Change Group Modal */}
            {bulkGroupModal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <Folder size={18} /> {t('domains.bulkChangeGroup', 'Change Group')}
                            </h3>
                            <button onClick={() => setBulkGroupModal(false)} className="btn btn-ghost btn-icon">
                                <X size={20} />
                            </button>
                        </div>
                        <div className="space-y-4">
                            <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('domains.bulkChangeGroupText', 'Change group for {count} selected domains').replace('{count}', String(selectedDomainIds.size))}
                            </p>
                            <div>
                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                    {t('domains.selectGroup', 'Select Group')}
                                </label>
                                <select
                                    className="form-select"
                                    style={{ width: '100%' }}
                                    value={bulkGroupId}
                                    onChange={(e) => setBulkGroupId(e.target.value)}
                                >
                                    <option value="">{t('domains.noGroup')}</option>
                                    {domainGroups.map(g => (
                                        <option key={g.id} value={String(g.id)}>{g.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex justify-end gap-2 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <button onClick={() => setBulkGroupModal(false)} className="btn btn-secondary">
                                    {t('common.cancel')}
                                </button>
                                <button
                                    onClick={handleBulkChangeGroup}
                                    disabled={!bulkGroupId}
                                    className="btn btn-primary"
                                >
                                    {t('domains.applyGroup', 'Apply')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Domains;
