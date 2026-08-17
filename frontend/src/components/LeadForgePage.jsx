import React, { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';
import {
    Zap, Upload, FileArchive, CheckCircle2, AlertCircle, AlertTriangle, Download,
    Layers, Globe, RefreshCw, Terminal, Sliders, ArrowRight, X, ScanSearch, Rocket,
    Wifi, ShieldCheck, Scissors, Repeat
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const CPA_NETWORKS = [
    { id: 'drcash', name: 'Dr.Cash', defaultCurrency: 'USD', defaultPayout: 25, placeholder: 'Stream Code (e.g. abcd1234)' },
    { id: 'lemonad', name: 'LemonAD', defaultCurrency: 'USD', defaultPayout: 28, placeholder: 'Offer ID (e.g. 10452)' },
    { id: 'webvork', name: 'Webvork', defaultCurrency: 'EUR', defaultPayout: 32, placeholder: 'Offer ID (e.g. 892)' },
    { id: 'leadbit', name: 'Leadbit', defaultCurrency: 'USD', defaultPayout: 22, placeholder: 'Flow Hash (e.g. a8b9c0d1)' },
    { id: 'everad', name: 'Everad', defaultCurrency: 'USD', defaultPayout: 26, placeholder: 'Campaign ID (e.g. 54201)' },
    { id: 'kma', name: 'KMA.biz', defaultCurrency: 'RUB', defaultPayout: 1200, placeholder: 'Channel / Offer ID (e.g. 7412)' },
    { id: 'terraleads', name: 'TerraLeads', defaultCurrency: 'USD', defaultPayout: 24, placeholder: 'Offer ID (e.g. 1290)' },
    { id: 'trafficlight', name: 'Traffic Light', defaultCurrency: 'RUB', defaultPayout: 1100, placeholder: 'Offer ID (e.g. 3310)' },
    { id: 'adcombo', name: 'AdCombo', defaultCurrency: 'USD', defaultPayout: 20, placeholder: 'Offer ID (e.g. 29314)' },
    { id: 'm1', name: 'M1-Shop', defaultCurrency: 'RUB', defaultPayout: 950, placeholder: 'Product ID (e.g. 642)' },
    { id: 'monsterleads', name: 'MonsterLeads', defaultCurrency: 'USD', defaultPayout: 21, placeholder: 'Offer ID (e.g. 1102)' },
    { id: 'custom', name: 'Custom API / Webhook', defaultCurrency: 'USD', defaultPayout: 20, placeholder: 'https://api.domain.com/lead/create' }
];

const GEO_PRESETS = [
    { code: 'IT', name: 'Italy (+39)', flag: '🇮🇹' },
    { code: 'ES', name: 'Spain (+34)', flag: '🇪🇸' },
    { code: 'DE', name: 'Germany (+49)', flag: '🇩🇪' },
    { code: 'FR', name: 'France (+33)', flag: '🇫🇷' },
    { code: 'PL', name: 'Poland (+48)', flag: '🇵🇱' },
    { code: 'RO', name: 'Romania (+40)', flag: '🇷🇴' },
    { code: 'GR', name: 'Greece (+30)', flag: '🇬🇷' },
    { code: 'RU', name: 'Russia (+7)', flag: '🇷🇺' },
    { code: 'UA', name: 'Ukraine (+380)', flag: '🇺🇦' },
    { code: 'KZ', name: 'Kazakhstan (+7)', flag: '🇰🇿' },
    { code: 'US', name: 'United States (+1)', flag: '🇺🇸' },
    { code: 'MX', name: 'Mexico (+52)', flag: '🇲🇽' },
    { code: 'CO', name: 'Colombia (+57)', flag: '🇨🇴' }
];

const MODES = [
    { id: 'auto', label: 'Auto', sub: 'Detect + route', icon: ScanSearch },
    { id: 'cross-network', label: 'Cross', sub: 'Network swap', icon: Repeat },
    { id: 'raw', label: 'Raw', sub: 'Clone patch', icon: Scissors },
];

const LeadForgePage = ({ setActiveTab, refreshData }) => {
    const { t } = useLanguage();
    const fileInputRef = useRef(null);
    const consoleEndRef = useRef(null);

    // Stage 1 state: raw uploads → analyzed bundle cards
    const [pendingFiles, setPendingFiles] = useState([]);
    const [bundles, setBundles] = useState([]);
    const [isDragging, setIsDragging] = useState(false);
    const [analyzing, setAnalyzing] = useState(false);
    const [building, setBuilding] = useState(false);
    const [logs, setLogs] = useState([]);
    const [landingGroups, setLandingGroups] = useState([]);

    // Integration config
    const [mode, setMode] = useState(() => localStorage.getItem('orbitra_lf_mode') || 'auto');
    const [selectedNetwork, setSelectedNetwork] = useState(() => localStorage.getItem('orbitra_lf_network') || 'drcash');
    const [apiKey, setApiKey] = useState(() => localStorage.getItem(`orbitra_lf_key_${localStorage.getItem('orbitra_lf_network') || 'drcash'}`) || '');
    const [offerId, setOfferId] = useState('');
    const [selectedGeo, setSelectedGeo] = useState('IT');
    const [currency, setCurrency] = useState('USD');
    const [payout, setPayout] = useState('25');
    const [selectedGroupId, setSelectedGroupId] = useState('');

    // Toggles (per ТЗ: CRM Sync + Auto QA drive the build)
    const [crmEnabled, setCrmEnabled] = useState(true);
    const [autoQa, setAutoQa] = useState(true);

    const [options, setOptions] = useState({
        injectOfferMacro: true,
        injectJsAdapter: true,
        addPhoneMask: true,
        generateThankYou: true,
        generateOrderPhp: true,
        autoSaveTracker: true,
        autoCreateOffer: false
    });

    const isRaw = mode === 'raw';

    useEffect(() => {
        axios.get(`${API_URL}?action=landing_groups`)
            .then(res => {
                if (res.data?.status === 'success') {
                    setLandingGroups(res.data.data || []);
                }
            })
            .catch(() => {});
    }, []);

    useEffect(() => {
        if (consoleEndRef.current) {
            consoleEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [logs]);

    const addLog = (msg, type = 'info') => {
        const time = new Date().toLocaleTimeString();
        setLogs(prev => [...prev, { id: Math.random(), time, msg, type }]);
    };

    const handleNetworkChange = (netId) => {
        setSelectedNetwork(netId);
        localStorage.setItem('orbitra_lf_network', netId);
        setApiKey(localStorage.getItem(`orbitra_lf_key_${netId}`) || '');
        const netObj = CPA_NETWORKS.find(n => n.id === netId);
        if (netObj) {
            setCurrency(netObj.defaultCurrency);
            setPayout(String(netObj.defaultPayout));
        }
    };

    const handleApiKeyChange = (val) => {
        setApiKey(val);
        localStorage.setItem(`orbitra_lf_key_${selectedNetwork}`, val);
    };

    const handleModeChange = (m) => {
        setMode(m);
        localStorage.setItem('orbitra_lf_mode', m);
    };

    // ---- Upload handling -------------------------------------------------
    const handleAddFiles = (files) => {
        const zipFiles = Array.from(files).filter(f =>
            f.name.endsWith('.zip') || f.type.includes('zip') ||
            /\.html?$/i.test(f.name) || /\.php$/i.test(f.name)
        ).slice(0, 15);
        if (zipFiles.length === 0) return;
        setPendingFiles(prev => [...prev, ...zipFiles].slice(0, 15));
        addLog(t('leadforge.logAdded', `📦 Added ${zipFiles.length} file(s). Run Analyze to inspect them.`, { count: zipFiles.length }), 'info');
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        if (e.dataTransfer?.files) handleAddFiles(e.dataTransfer.files);
    };

    // ---- Stage 1: Analyze -------------------------------------------------
    const handleAnalyze = async () => {
        if (pendingFiles.length === 0 || analyzing) return;
        setAnalyzing(true);
        addLog(t('leadforge.logAnalyzeStart', `🔬 Analyzing ${pendingFiles.length} bundle(s)...`, { count: pendingFiles.length }), 'info');
        try {
            const fd = new FormData();
            pendingFiles.forEach(f => fd.append('files[]', f, f.name));
            const res = await axios.post(`${API_URL}?action=leadforge_analyze`, fd);
            if (res.data?.status !== 'success') {
                addLog(`❌ ${res.data?.message || 'Analyze failed'}`, 'error');
                return;
            }
            const cards = (res.data.results || []).map(r => {
                if (r.error) {
                    return { ...r, status: 'error', selected: false, landingName: r.file_name };
                }
                const rawName = (r.file_name || '').replace(/\.(zip|html?|php)$/i, '');
                return {
                    ...r,
                    status: 'analyzed',
                    selected: !!r.ready_for_build,
                    landingName: rawName.replace(/[-_]+/g, ' ').replace(/^./, c => c.toUpperCase()),
                };
            });
            setBundles(prev => [...prev, ...cards]);
            setPendingFiles([]);
            cards.forEach(c => {
                if (c.error) {
                    addLog(`❌ ${c.file_name}: ${c.error}`, 'error');
                } else {
                    const net = c.detected ? (CPA_NETWORKS.find(n => n.id === c.network)?.name || c.network) : t('leadforge.notDetected', 'No network detected');
                    addLog(t('leadforge.logAnalyzed', `🗂 ${c.file_name}: ${net} · ${c.forms_count} form(s) · ${c.ready_for_build ? 'READY' : 'NOT READY'}`, { name: c.file_name, network: net, forms: c.forms_count, ready: c.ready_for_build }), c.ready_for_build ? 'success' : 'step');
                }
            });
            // Auto-route hint: first detected network/geo pre-fills the config.
            const firstDetected = cards.find(c => c.detected && c.network && c.network !== 'custom');
            if (firstDetected?.network && CPA_NETWORKS.some(n => n.id === firstDetected.network)) {
                handleNetworkChange(firstDetected.network);
                addLog(t('leadforge.logAutoRoute', `🧭 Auto: suggested network preset → ${firstDetected.network}`), 'step');
            }
            const firstGeo = cards.map(c => c.detected_geo).find(g => !!g);
            if (firstGeo) setSelectedGeo(firstGeo);
        } catch (err) {
            addLog(`❌ ${err.response?.data?.message || err.message}`, 'error');
        } finally {
            setAnalyzing(false);
        }
    };

    // ---- Stage 2: Build ----------------------------------------------------
    const selectedBundles = bundles.filter(b => b.selected && b.token);

    const handleBuild = async () => {
        if (selectedBundles.length === 0 || building) return;
        setBuilding(true);
        addLog(t('leadforge.logBuildStart', `🚀 Building ${selectedBundles.length} bundle(s) in ${mode.toUpperCase()} mode...`, { count: selectedBundles.length, mode: mode.toUpperCase() }), 'info');

        const fd = new FormData();
        const names = {};
        selectedBundles.forEach(b => {
            fd.append('tokens[]', b.token);
            names[b.token] = b.landingName;
        });
        fd.append('names', JSON.stringify(names));
        fd.append('mode', mode);
        fd.append('network', selectedNetwork);
        fd.append('api_key', apiKey);
        fd.append('offer_id', offerId);
        fd.append('geo', selectedGeo);
        fd.append('payout', payout);
        fd.append('currency', currency);
        if (selectedGroupId) fd.append('group_id', selectedGroupId);
        fd.append('crm_enabled', (!isRaw && crmEnabled) ? '1' : '0');
        fd.append('auto_qa', (!isRaw && autoQa) ? '1' : '0');
        fd.append('inject_offer_macro', options.injectOfferMacro ? '1' : '0');
        fd.append('inject_js_adapter', options.injectJsAdapter ? '1' : '0');
        fd.append('add_phone_mask', options.addPhoneMask ? '1' : '0');
        fd.append('generate_thank_you', (!isRaw && options.generateThankYou) ? '1' : '0');
        fd.append('generate_order_php', (!isRaw && options.generateOrderPhp) ? '1' : '0');
        fd.append('auto_save_tracker', options.autoSaveTracker ? '1' : '0');
        fd.append('auto_create_offer', options.autoCreateOffer ? '1' : '0');

        setBundles(prev => prev.map(b => (b.selected && b.token ? { ...b, status: 'building' } : b)));

        try {
            const res = await axios.post(`${API_URL}?action=leadforge_build_batch`, fd, { timeout: 300000 });
            if (res.data?.status !== 'success') {
                addLog(`❌ ${res.data?.message || 'Build failed'}`, 'error');
                setBundles(prev => prev.map(b => (b.status === 'building' ? { ...b, status: 'analyzed' } : b)));
                return;
            }
            (res.data.results || []).forEach(r => {
                (r.logs || []).forEach(line => {
                    const type = line.startsWith('[QA PASS]') ? 'success'
                        : line.startsWith('[QA FAIL') ? 'error'
                        : line.startsWith('[QA SKIP') ? 'step'
                        : 'info';
                    addLog(line, type);
                });
                if (!r.ok) {
                    addLog(`❌ Bundle ${r.token?.slice(0, 8)}…: ${r.message}`, 'error');
                }
                setBundles(prev => prev.map(b => (b.token === r.token
                    ? { ...b, status: r.ok ? 'built' : 'error', result: r.result, qa: r.qa, error: r.ok ? null : r.message }
                    : b)));
            });
            addLog(t('leadforge.logBuildDone', '🎉 Build pass finished. Landings are in the library, ready for campaigns.'), 'success');
            if (refreshData) refreshData();
        } catch (err) {
            addLog(`❌ ${err.response?.data?.message || err.message}`, 'error');
            setBundles(prev => prev.map(b => (b.status === 'building' ? { ...b, status: 'analyzed' } : b)));
        } finally {
            setBuilding(false);
        }
    };

    const handleRerunQa = async (bundle) => {
        if (!bundle.result?.landing_id) return;
        addLog(t('leadforge.logQaRerun', `🔁 Re-running Live QA for landing #${bundle.result.landing_id}...`, { id: bundle.result.landing_id }), 'info');
        try {
            const fd = new FormData();
            fd.append('landing_id', bundle.result.landing_id);
            fd.append('geo', bundle.result.geo || selectedGeo);
            fd.append('crm_enabled', crmEnabled ? '1' : '0');
            const res = await axios.post(`${API_URL}?action=leadforge_live_qa`, fd, { timeout: 120000 });
            const qa = res.data?.data;
            if (!qa) {
                addLog(`❌ ${res.data?.message || 'QA failed'}`, 'error');
                return;
            }
            (qa.log || []).forEach(line => addLog(line, 'step'));
            addLog(qa.passed
                ? `[QA PASS] confidence ${qa.confidence}%`
                : `[QA FAIL: ${qa.fail_reason}] confidence ${qa.confidence}%`, qa.passed ? 'success' : 'error');
            setBundles(prev => prev.map(b => (b.token === bundle.token ? { ...b, qa } : b)));
        } catch (err) {
            addLog(`❌ ${err.response?.data?.message || err.message}`, 'error');
        }
    };

    const toggleBundle = (token) => {
        setBundles(prev => prev.map(b => (b.token === token ? { ...b, selected: !b.selected } : b)));
    };
    const toggleAll = () => {
        const allSelected = bundles.filter(b => b.status === 'analyzed' || b.status === 'built').every(b => b.selected);
        setBundles(prev => prev.map(b => ((b.status === 'analyzed' || b.status === 'built') ? { ...b, selected: !allSelected } : b)));
    };
    const handleUpdateLandingName = (token, newName) => {
        setBundles(prev => prev.map(b => (b.token === token ? { ...b, landingName: newName } : b)));
    };
    const removeBundle = (token) => {
        setBundles(prev => prev.filter(b => b.token !== token));
    };

    const modeHint = {
        'auto': t('leadforge.modeAutoHint', 'Detect + route: the source network is identified automatically and the bundle is rebuilt for it. Forms are re-wired to order.php, the ClickID bridge is injected.'),
        'cross-network': t('leadforge.modeCrossHint', 'Network swap: the old network\'s handlers (order.php, send.php, api.php…) and its hardcoded keys are cut out, and the landing is re-seated on the target network you pick below.'),
        'raw': t('leadforge.modeRawHint', 'Clone patch: foreign counters (FB/TikTok/GA/Yandex) and hostile scripts are stripped, the ClickID bridge and {offer} macros are injected — no server-side order.php is generated.'),
    }[mode];

    return (
        <div className="space-y-6 max-w-7xl mx-auto pb-12">
            {/* Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl border bg-[var(--color-bg-card)] border-[var(--color-border)]" style={{ boxShadow: 'var(--shadow-main)' }}>
                <div className="flex items-center gap-4">
                    <div
                        className="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0"
                        style={{
                            background: 'color-mix(in srgb, var(--color-primary) 14%, transparent)',
                            color: 'var(--color-primary)',
                            boxShadow: 'inset 0 0 0 1px color-mix(in srgb, var(--color-primary) 22%, transparent)',
                        }}
                    >
                        <Zap size={26} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--color-text-primary)' }}>
                            LeadForge
                        </h1>
                        <p className="text-sm mt-0.5" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('leadforge.subtitle2', 'Auto / Cross / Raw landing compiler with CRM vault sync and Live Auto QA')}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setActiveTab('landings')}
                        className="btn-secondary !py-2.5 !px-4 text-sm flex items-center gap-2 cursor-pointer"
                    >
                        <Globe size={16} />
                        <span>{t('leadforge.openInTracker', 'Landings Library')}</span>
                    </button>
                    {bundles.some(b => b.status === 'built') && (
                        <button
                            type="button"
                            onClick={() => setActiveTab('campaigns')}
                            className="btn-primary !py-2.5 !px-4 text-sm flex items-center gap-2 cursor-pointer"
                        >
                            <ArrowRight size={16} />
                            <span>{t('nav.campaigns', 'Go to Campaigns')}</span>
                        </button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                {/* Left column: dropzone + bundle cards */}
                <div className="lg:col-span-7 space-y-6">
                    {/* Dropzone */}
                    <div
                        onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                        onDragLeave={() => setIsDragging(false)}
                        onDrop={handleDrop}
                        onClick={() => fileInputRef.current?.click()}
                        className={`border-2 border-dashed rounded-2xl p-6 text-center cursor-pointer transition-all flex flex-col items-center justify-center ${
                            isDragging
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]/20 scale-[0.99]'
                                : 'border-[var(--color-border)] bg-[var(--color-bg-card)] hover:border-[var(--color-primary)] hover:bg-[var(--color-bg-hover)]'
                        }`}
                    >
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept=".zip,application/zip,.html,.htm,.php"
                            className="hidden"
                            onChange={(e) => {
                                if (e.target.files) handleAddFiles(e.target.files);
                                e.target.value = '';
                            }}
                        />
                        <div
                            className="w-14 h-14 rounded-2xl flex items-center justify-center mb-3"
                            style={{
                                background: 'color-mix(in srgb, var(--color-primary) 12%, transparent)',
                                color: 'var(--color-primary)',
                            }}
                        >
                            <Upload size={26} />
                        </div>
                        <h3 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>
                            {t('leadforge.dropzoneTitle', 'Drag & Drop landing ZIP archives here')}
                        </h3>
                        <p className="text-xs mt-1 max-w-md" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('leadforge.dropzoneSub', 'Up to 15 ZIP / HTML / PHP bundles per Analyze pass, from any affiliate network')}
                        </p>
                    </div>

                    {/* Stage actions */}
                    <div className="flex flex-col sm:flex-row gap-3">
                        <button
                            type="button"
                            disabled={pendingFiles.length === 0 || analyzing || building}
                            onClick={handleAnalyze}
                            className={`btn-secondary flex-1 text-sm font-semibold flex items-center justify-center gap-2 ${pendingFiles.length === 0 || analyzing || building ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                        >
                            {analyzing ? <RefreshCw size={16} className="animate-spin" /> : <ScanSearch size={16} />}
                            <span>{analyzing ? t('leadforge.analyzing', 'Analyzing…') : t('leadforge.analyzeButton', 'Analyze bundles')}</span>
                            {pendingFiles.length > 0 && !analyzing && <span className="opacity-70">({pendingFiles.length})</span>}
                        </button>
                        <button
                            type="button"
                            disabled={selectedBundles.length === 0 || building || analyzing}
                            onClick={handleBuild}
                            className={`btn-primary flex-1 text-sm font-bold flex items-center justify-center gap-2 ${selectedBundles.length === 0 || building || analyzing ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                        >
                            {building ? <RefreshCw size={16} className="animate-spin" /> : <Rocket size={16} />}
                            <span>{building ? t('leadforge.building', 'Building…') : t('leadforge.buildButton', 'Build all selected bundles')}</span>
                            {selectedBundles.length > 0 && !building && <span className="opacity-80">({selectedBundles.length})</span>}
                        </button>
                    </div>

                    {/* Bundle cards */}
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-5 shadow-sm space-y-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Layers size={18} className="text-[var(--color-primary)]" />
                                <h3 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.bundlesTitle', 'Analyzed bundles')} ({bundles.length})
                                </h3>
                            </div>
                            {bundles.some(b => b.status === 'analyzed' || b.status === 'built') && (
                                <button type="button" onClick={toggleAll} className="text-xs font-medium text-[var(--color-primary)] hover:opacity-80 cursor-pointer">
                                    {t('leadforge.selectAll', 'Select / deselect all')}
                                </button>
                            )}
                        </div>

                        {bundles.length === 0 && pendingFiles.length === 0 && (
                            <div className="py-8 text-center" style={{ color: 'var(--color-text-muted)' }}>
                                <FileArchive size={32} className="mx-auto mb-2 opacity-40" />
                                <p className="text-xs">{t('leadforge.noArchives', 'No archives selected. Drop ZIP archives above to start batch preparation.')}</p>
                            </div>
                        )}
                        {pendingFiles.length > 0 && (
                            <div className="text-xs px-3 py-2 rounded-xl bg-[var(--color-bg-main)] border border-[var(--color-border)]" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('leadforge.pendingFiles', 'Waiting for Analyze')}: {pendingFiles.map(f => f.name).join(', ')}
                            </div>
                        )}

                        <div className="space-y-2.5 max-h-[460px] overflow-y-auto pr-1">
                            {bundles.map((b, idx) => (
                                <div
                                    key={b.token || `err_${idx}`}
                                    className={`p-3 rounded-xl border bg-[var(--color-bg-main)] gap-3 ${
                                        b.status === 'built' ? 'border-emerald-300 dark:border-emerald-800' : 'border-[var(--color-border)]'
                                    } ${b.status === 'error' ? 'border-rose-300 dark:border-rose-900' : ''}`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-2.5 min-w-0 flex-1">
                                            {(b.status === 'analyzed' || b.status === 'built') && (
                                                <input
                                                    type="checkbox"
                                                    checked={b.selected}
                                                    onChange={() => toggleBundle(b.token)}
                                                    disabled={building}
                                                    className="mt-1.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] cursor-pointer"
                                                />
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <input
                                                    type="text"
                                                    value={b.landingName || ''}
                                                    disabled={building || b.status === 'building'}
                                                    onChange={(e) => handleUpdateLandingName(b.token, e.target.value)}
                                                    className="w-full font-semibold text-sm bg-transparent border-b border-transparent hover:border-[var(--color-border)] focus:border-[var(--color-primary)] focus:outline-none px-1 py-0.5 truncate"
                                                    style={{ color: 'var(--color-text-primary)' }}
                                                />
                                                <div className="flex flex-wrap items-center gap-1.5 mt-1.5 text-[11px]">
                                                    <span className="text-[var(--color-text-secondary)] px-1">{b.file_name}</span>
                                                    {b.detected ? (
                                                        <span className="px-2 py-0.5 rounded-full font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-950/70 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                                            {CPA_NETWORKS.find(n => n.id === b.network)?.name || b.network}
                                                        </span>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded-full font-medium bg-[var(--color-bg-hover)] text-[var(--color-text-secondary)]">
                                                            {t('leadforge.notDetected', 'No network detected')}
                                                        </span>
                                                    )}
                                                    {b.forms_count > 0 && (
                                                        <span className="px-2 py-0.5 rounded-full bg-[var(--color-bg-hover)] text-[var(--color-text-secondary)]">
                                                            {b.forms_count} {t('leadforge.formsWord', 'form(s)')}
                                                        </span>
                                                    )}
                                                    {b.detected_geo && (
                                                        <span className="px-2 py-0.5 rounded-full bg-[var(--color-bg-hover)] text-[var(--color-text-secondary)]">{b.detected_geo}</span>
                                                    )}
                                                    {(b.foreign_scripts_detected || []).map(fs => (
                                                        <span key={fs} className="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 border border-amber-200 dark:border-amber-800" title={t('leadforge.foreignScript', 'Foreign script — will be stripped in Raw/Cross')}>
                                                            {fs}
                                                        </span>
                                                    ))}
                                                    {b.encoding && !['UTF-8', 'ASCII'].includes(b.encoding) && (
                                                        <span className="px-2 py-0.5 rounded-full bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                                            {b.encoding}
                                                        </span>
                                                    )}
                                                </div>
                                                {(b.detected_inputs || []).length > 0 && (
                                                    <div className="text-[11px] text-[var(--color-text-muted)] px-1 mt-1 truncate">
                                                        {t('leadforge.inputsLabel', 'inputs')}: {(b.detected_inputs || []).join(', ')}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2 shrink-0">
                                            {b.status === 'error' && (
                                                <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-red-100 text-red-800 dark:bg-red-950/70 dark:text-red-300 flex items-center gap-1" title={b.error || ''}>
                                                    <AlertCircle size={12} /> {t('leadforge.errorBadge', 'Error')}
                                                </span>
                                            )}
                                            {b.status === 'building' && (
                                                <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 flex items-center gap-1.5 animate-pulse">
                                                    <RefreshCw size={11} className="animate-spin" /> {t('leadforge.buildingBadge', 'Building…')}
                                                </span>
                                            )}
                                            {b.status === 'built' && b.result && (
                                                <>
                                                    <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 flex items-center gap-1">
                                                        <CheckCircle2 size={12} /> #{b.result.landing_id} · /lander/{b.result.slug}/
                                                    </span>
                                                    {b.qa?.performed && (
                                                        <span
                                                            className={`px-2.5 py-1 rounded-full text-[11px] font-semibold flex items-center gap-1 ${
                                                                b.qa.passed
                                                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800'
                                                                    : 'bg-rose-100 text-red-800 dark:bg-rose-950/70 dark:text-red-300 border border-rose-300 dark:border-rose-800'
                                                            }`}
                                                            title={Object.values(b.qa.checks || {}).map(c => `${c.passed ? '✔' : '✘'} ${c.details}`).join('\n')}
                                                        >
                                                            {b.qa.passed ? <ShieldCheck size={12} /> : <AlertTriangle size={12} />}
                                                            QA {b.qa.confidence}%
                                                        </span>
                                                    )}
                                                    {b.result.download_url && (
                                                        <a
                                                            href={b.result.download_url}
                                                            download
                                                            className="p-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)]"
                                                            title={t('leadforge.downloadZip', 'Download Processed ZIP')}
                                                        >
                                                            <Download size={13} />
                                                        </a>
                                                    )}
                                                    {b.result.landing_id && b.result.mode !== 'raw' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRerunQa(b)}
                                                            disabled={building}
                                                            className="p-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-primary)]"
                                                            title={t('leadforge.rerunQa', 'Re-run Live QA')}
                                                        >
                                                            <Repeat size={13} />
                                                        </button>
                                                    )}
                                                </>
                                            )}
                                            {!building && (
                                                <button
                                                    type="button"
                                                    onClick={() => removeBundle(b.token)}
                                                    className="p-1 text-[var(--color-text-muted)] hover:text-red-500 transition cursor-pointer"
                                                >
                                                    <X size={14} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    {b.status === 'error' && b.error && (
                                        <div className="text-[11px] text-rose-600 dark:text-rose-400 px-1">{b.error}</div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                {/* Right column: Integration panel */}
                <div className="lg:col-span-5 space-y-6">
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-6 shadow-sm space-y-5">
                        <div className="flex items-center gap-2 pb-3 border-b border-[var(--color-border)]">
                            <Sliders size={18} className="text-[var(--color-primary)]" />
                            <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('leadforge.integrationTitle', 'Integration')}
                            </h3>
                        </div>

                        {/* Mode switch */}
                        <div className="space-y-2">
                            <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('leadforge.modeLabel', 'Build mode')}
                            </label>
                            <div className="grid grid-cols-3 gap-2">
                                {MODES.map(m => {
                                    const Icon = m.icon;
                                    const active = mode === m.id;
                                    return (
                                        <button
                                            key={m.id}
                                            type="button"
                                            onClick={() => handleModeChange(m.id)}
                                            className={`py-2.5 px-2 rounded-xl border text-center transition cursor-pointer ${
                                                active
                                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)] shadow-sm'
                                                    : 'border-[var(--color-border)] bg-[var(--color-bg-main)] hover:bg-[var(--color-bg-hover)]'
                                            }`}
                                            style={active
                                                ? { color: 'var(--color-text-inverse, white)', boxShadow: 'var(--color-primary-shadow)' }
                                                : { color: 'var(--color-text-primary)' }}
                                        >
                                            <Icon size={16} className="mx-auto mb-1" />
                                            <div className="text-xs font-bold leading-none">{m.label}</div>
                                            <div className={`text-[10px] mt-0.5 leading-none ${active ? 'opacity-75' : 'text-[var(--color-text-muted)]'}`}>{m.sub}</div>
                                        </button>
                                    );
                                })}
                            </div>
                            <p className="text-[11px] leading-relaxed rounded-xl p-2.5 bg-[var(--color-bg-main)] border border-[var(--color-border)]" style={{ color: 'var(--color-text-secondary)' }}>
                                {modeHint}
                            </p>
                        </div>

                        {/* Network config (hidden for raw) */}
                        {!isRaw && (
                            <>
                                <div className="space-y-2">
                                    <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('leadforge.networkApi', 'CPA Affiliate Network')}
                                    </label>
                                    <select
                                        value={selectedNetwork}
                                        onChange={(e) => handleNetworkChange(e.target.value)}
                                        className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    >
                                        {CPA_NETWORKS.map(net => (
                                            <option key={net.id} value={net.id}>{net.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="space-y-2">
                                    <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('leadforge.apiKey', 'API Key / Client Token')}
                                    </label>
                                    <input
                                        type="text"
                                        value={apiKey}
                                        onChange={(e) => handleApiKeyChange(e.target.value)}
                                        placeholder={t('leadforge.apiKeyPlaceholder', 'Paste CPA network API Key / Token…')}
                                        className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    />
                                    <p className="text-[11px] text-[var(--color-text-muted)]">
                                        {t('leadforge.apiKeyNote', 'Saved automatically per network in browser storage.')}
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('leadforge.offerId', 'Offer ID / Flow Token')}
                                    </label>
                                    <input
                                        type="text"
                                        value={offerId}
                                        onChange={(e) => setOfferId(e.target.value)}
                                        placeholder={CPA_NETWORKS.find(n => n.id === selectedNetwork)?.placeholder || 'Offer ID / Stream Token'}
                                        className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="space-y-2">
                                        <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('leadforge.targetGeo', 'Target GEO')}
                                        </label>
                                        <select
                                            value={selectedGeo}
                                            onChange={(e) => setSelectedGeo(e.target.value)}
                                            className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                            style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                        >
                                            {GEO_PRESETS.map(geo => (
                                                <option key={geo.code} value={geo.code}>{geo.flag} {geo.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('leadforge.payout', 'Default Payout')}
                                        </label>
                                        <div className="flex gap-2">
                                            <input
                                                type="number"
                                                step="0.1"
                                                value={payout}
                                                onChange={(e) => setPayout(e.target.value)}
                                                className="w-full px-3 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            />
                                            <input
                                                type="text"
                                                value={currency}
                                                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                                                className="w-16 px-2 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-bold text-center focus:outline-none"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {landingGroups.length > 0 && (
                                    <div className="space-y-2">
                                        <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                            {t('leadforge.landingGroup', 'Landing Group')}
                                        </label>
                                        <select
                                            value={selectedGroupId}
                                            onChange={(e) => setSelectedGroupId(e.target.value)}
                                            className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-medium focus:outline-none"
                                            style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                        >
                                            <option value="">{t('leadforge.noGroup', 'No Group')}</option>
                                            {landingGroups.map(g => (
                                                <option key={g.id} value={g.id}>{g.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                            </>
                        )}

                        {/* CRM Sync + Auto QA toggles */}
                        <div className="space-y-3 pt-3 border-t border-[var(--color-border)]">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)]">
                                {t('leadforge.safetyTitle', 'Lead Safety')}
                            </h4>

                            <label
                                className={`flex items-start gap-3 cursor-pointer text-xs select-none rounded-xl p-3 border transition ${!isRaw ? 'bg-[var(--color-bg-main)]' : 'opacity-50 cursor-not-allowed'}`}
                                style={{
                                    borderColor: (!isRaw && crmEnabled) ? 'color-mix(in srgb, var(--color-primary) 45%, transparent)' : 'var(--color-border)',
                                    boxShadow: (!isRaw && crmEnabled) ? 'inset 3px 0 0 var(--color-primary)' : 'none',
                                }}
                            >
                                <input
                                    type="checkbox"
                                    checked={!isRaw && crmEnabled}
                                    disabled={isRaw}
                                    onChange={(e) => setCrmEnabled(e.target.checked)}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span>
                                    <span className="font-bold flex items-center gap-1.5" style={{ color: 'var(--color-text-primary)' }}>
                                        <Wifi size={13} /> {t('leadforge.crmSync', 'CRM sync')}
                                    </span>
                                    <span className="block mt-0.5 text-[var(--color-text-secondary)] leading-relaxed">
                                        {crmEnabled && !isRaw
                                            ? t('leadforge.crmSyncOn', 'On — send every lead to the CRM vault and keep the local failsafe log (raw phone, network request/response).')
                                            : t('leadforge.crmSyncOff', 'Off — the lead goes to the CPA network only; the tracker gets the standard conversion pixel.')}
                                    </span>
                                </span>
                            </label>

                            <label
                                className={`flex items-start gap-3 cursor-pointer text-xs select-none rounded-xl p-3 border transition ${!isRaw ? 'bg-[var(--color-bg-main)]' : 'opacity-50 cursor-not-allowed'}`}
                                style={{
                                    borderColor: (!isRaw && autoQa) ? 'color-mix(in srgb, var(--color-primary) 45%, transparent)' : 'var(--color-border)',
                                    boxShadow: (!isRaw && autoQa) ? 'inset 3px 0 0 var(--color-primary)' : 'none',
                                }}
                            >
                                <input
                                    type="checkbox"
                                    checked={!isRaw && autoQa}
                                    disabled={isRaw}
                                    onChange={(e) => setAutoQa(e.target.checked)}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span>
                                    <span className="font-bold flex items-center gap-1.5" style={{ color: 'var(--color-text-primary)' }}>
                                        <ShieldCheck size={13} /> {t('leadforge.autoQa', 'Auto QA')}
                                    </span>
                                    <span className="block mt-0.5 text-[var(--color-text-secondary)] leading-relaxed">
                                        {autoQa && !isRaw
                                            ? t('leadforge.autoQaOn', 'On — after each build a QA-Test-Lead is posted end-to-end (order.php → vault → thank-you) and scored 0–100%.')
                                            : t('leadforge.autoQaOff', 'Off — build first, verify manually.')}
                                    </span>
                                </span>
                            </label>
                        </div>

                        {/* Advanced options */}
                        <div className="space-y-3 pt-3 border-t border-[var(--color-border)]">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)]">
                                {t('leadforge.optionsTitle', 'Automation & Injection Options')}
                            </h4>

                            <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                <input
                                    type="checkbox"
                                    checked={options.injectOfferMacro}
                                    onChange={(e) => setOptions({ ...options, injectOfferMacro: e.target.checked })}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.optInjectMacro', 'Auto-inject {offer} macro into CTA buttons & links')}
                                </span>
                            </label>

                            <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                <input
                                    type="checkbox"
                                    checked={options.injectJsAdapter}
                                    onChange={(e) => setOptions({ ...options, injectJsAdapter: e.target.checked })}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.optInjectAdapter', 'Inject Orbitra JS Adapter & ClickID Bridge')}
                                </span>
                            </label>

                            <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                <input
                                    type="checkbox"
                                    checked={options.addPhoneMask}
                                    onChange={(e) => setOptions({ ...options, addPhoneMask: e.target.checked })}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.optPhoneMask', 'Add GEO Phone Mask & Real-time Regex Validator')}
                                </span>
                            </label>

                            {!isRaw && (
                                <>
                                    <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                        <input
                                            type="checkbox"
                                            checked={options.generateThankYou}
                                            onChange={(e) => setOptions({ ...options, generateThankYou: e.target.checked })}
                                            className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                        />
                                        <span style={{ color: 'var(--color-text-primary)' }}>
                                            {t('leadforge.optThankYou', 'Generate Universal Localized Thank You Page')}
                                        </span>
                                    </label>

                                    <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                        <input
                                            type="checkbox"
                                            checked={options.generateOrderPhp}
                                            onChange={(e) => setOptions({ ...options, generateOrderPhp: e.target.checked })}
                                            className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                        />
                                        <span style={{ color: 'var(--color-text-primary)' }}>
                                            {t('leadforge.optOrderPhp', 'Generate Secure order.php CPA API Bridge')}
                                        </span>
                                    </label>
                                </>
                            )}

                            <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                <input
                                    type="checkbox"
                                    checked={options.autoSaveTracker}
                                    onChange={(e) => setOptions({ ...options, autoSaveTracker: e.target.checked })}
                                    className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.optSaveTracker', 'Auto-save to Tracker Landings library')}
                                </span>
                            </label>

                            {options.autoSaveTracker && (
                                <label className="flex items-start gap-2.5 cursor-pointer text-xs select-none">
                                    <input
                                        type="checkbox"
                                        checked={options.autoCreateOffer}
                                        onChange={(e) => setOptions({ ...options, autoCreateOffer: e.target.checked })}
                                        className="mt-0.5 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                    />
                                    <span style={{ color: 'var(--color-text-primary)' }}>
                                        {t('leadforge.optCreateOffer', 'Auto-create a matching offer in the tracker')}
                                    </span>
                                </label>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Execution console */}
            {(logs.length > 0 || analyzing || building) && (
                <div className="bg-slate-950 text-slate-200 border border-slate-800 rounded-2xl p-5 font-mono text-xs shadow-xl space-y-3">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3 text-slate-400">
                        <div className="flex items-center gap-2">
                            <Terminal size={15} style={{ color: 'var(--color-primary)' }} className="brightness-150" />
                            <span className="font-semibold text-slate-200">{t('leadforge.consoleTitle', 'LeadForge Execution Console')}</span>
                        </div>
                        {(analyzing || building) && (
                            <span className="text-[11px] font-bold animate-pulse" style={{ color: 'var(--color-primary)' }}>
                                {analyzing ? t('leadforge.analyzing', 'Analyzing…') : t('leadforge.building', 'Building…')}
                            </span>
                        )}
                    </div>
                    <div className="max-h-72 overflow-y-auto space-y-1 pr-2">
                        {logs.map((log) => (
                            <div key={log.id} className="flex items-start gap-2">
                                <span className="text-slate-500 shrink-0">[{log.time}]</span>
                                <span className={`break-all whitespace-pre-wrap ${
                                    log.type === 'error' ? 'text-rose-400 font-semibold' :
                                    log.type === 'success' ? 'text-emerald-400 font-semibold' :
                                    log.type === 'step' ? 'text-amber-300' :
                                    'text-slate-300'
                                }`}>
                                    {log.msg}
                                </span>
                            </div>
                        ))}
                        <div ref={consoleEndRef} />
                    </div>
                </div>
            )}
        </div>
    );
};

export default LeadForgePage;
