import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { 
    Zap, Upload, FileArchive, CheckCircle2, AlertCircle, Trash2, Download, 
    ExternalLink, Layers, Globe, Shield, RefreshCw, Terminal, Eye, Sliders,
    Sparkles, ArrowRight, Check, X, FileCode, Smartphone, Link2, Copy, Play
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
    { code: 'IT', name: 'Italy (+39)', phoneCode: '+39', flag: '🇮🇹' },
    { code: 'ES', name: 'Spain (+34)', phoneCode: '+34', flag: '🇪🇸' },
    { code: 'DE', name: 'Germany (+49)', phoneCode: '+49', flag: '🇩🇪' },
    { code: 'FR', name: 'France (+33)', phoneCode: '+33', flag: '🇫🇷' },
    { code: 'PL', name: 'Poland (+48)', phoneCode: '+48', flag: '🇵🇱' },
    { code: 'RO', name: 'Romania (+40)', phoneCode: '+40', flag: '🇷🇴' },
    { code: 'GR', name: 'Greece (+30)', phoneCode: '+30', flag: '🇬🇷' },
    { code: 'RU', name: 'Russia (+7)', phoneCode: '+7', flag: '🇷🇺' },
    { code: 'UA', name: 'Ukraine (+380)', phoneCode: '+380', flag: '🇺🇦' },
    { code: 'KZ', name: 'Kazakhstan (+7)', phoneCode: '+7', flag: '🇰🇿' },
    { code: 'US', name: 'United States (+1)', phoneCode: '+1', flag: '🇺🇸' },
    { code: 'MX', name: 'Mexico (+52)', phoneCode: '+52', flag: '🇲🇽' },
    { code: 'CO', name: 'Colombia (+57)', phoneCode: '+57', flag: '🇨🇴' }
];

const LeadForgePage = ({ setActiveTab, refreshData }) => {
    const { t } = useLanguage();
    const fileInputRef = useRef(null);
    const consoleEndRef = useRef(null);

    // Queue of uploaded archives
    const [queue, setQueue] = useState([]);
    const [isDragging, setIsDragging] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [progress, setProgress] = useState({ current: 0, total: 0, percent: 0 });
    const [logs, setLogs] = useState([]);
    const [landingGroups, setLandingGroups] = useState([]);

    // Configuration state
    const [selectedNetwork, setSelectedNetwork] = useState(() => localStorage.getItem('orbitra_lf_network') || 'drcash');
    const [apiKey, setApiKey] = useState(() => localStorage.getItem(`orbitra_lf_key_${selectedNetwork}`) || '');
    const [offerId, setOfferId] = useState('');
    const [selectedGeo, setSelectedGeo] = useState('IT');
    const [currency, setCurrency] = useState('USD');
    const [payout, setPayout] = useState('25');
    const [selectedGroupId, setSelectedGroupId] = useState('');

    // Checklist Options
    const [options, setOptions] = useState({
        injectOfferMacro: true,
        injectJsAdapter: true,
        addPhoneMask: true,
        generateThankYou: true,
        generateOrderPhp: true,
        autoSaveTracker: true,
        autoCreateOffer: false
    });

    // Load landing groups
    useEffect(() => {
        axios.get(`${API_URL}?action=landing_groups`)
            .then(res => {
                if (res.data?.status === 'success') {
                    setLandingGroups(res.data.data || []);
                }
            })
            .catch(() => {});
    }, []);

    // Save network selection & restore API key
    const handleNetworkChange = (netId) => {
        setSelectedNetwork(netId);
        localStorage.setItem('orbitra_lf_network', netId);
        const savedKey = localStorage.getItem(`orbitra_lf_key_${netId}`) || '';
        setApiKey(savedKey);
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

    const addLog = (msg, type = 'info') => {
        const time = new Date().toLocaleTimeString();
        setLogs(prev => [...prev, { id: Math.random(), time, msg, type }]);
    };

    useEffect(() => {
        if (consoleEndRef.current) {
            consoleEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [logs]);

    // Handle files upload
    const handleAddFiles = (files) => {
        const zipFiles = Array.from(files).filter(f => f.name.endsWith('.zip') || f.type.includes('zip'));
        if (zipFiles.length === 0) return;

        const newItems = zipFiles.map((file, idx) => {
            const rawName = file.name.replace(/\.zip$/i, '');
            const cleanName = rawName.replace(/[-_]+/g, ' ').trim();
            const titleCase = cleanName.charAt(0).toUpperCase() + cleanName.slice(1);
            return {
                id: `queue_${Date.now()}_${idx}_${Math.random().toString(36).substr(2, 5)}`,
                file,
                fileName: file.name,
                fileSize: (file.size / (1024 * 1024)).toFixed(2) + ' MB',
                landingName: titleCase,
                status: 'pending', // pending, processing, success, error
                result: null,
                error: null
            };
        });

        setQueue(prev => [...prev, ...newItems]);
        addLog(`📦 Added ${newItems.length} archive(s) to LeadForge queue.`, 'info');
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        if (e.dataTransfer?.files) {
            handleAddFiles(e.dataTransfer.files);
        }
    };

    const handleRemoveQueueItem = (id) => {
        setQueue(prev => prev.filter(item => item.id !== id));
    };

    const handleClearQueue = () => {
        if (isProcessing) return;
        setQueue([]);
        setLogs([]);
        setProgress({ current: 0, total: 0, percent: 0 });
    };

    // Update landing name in queue
    const handleUpdateLandingName = (id, newName) => {
        setQueue(prev => prev.map(item => item.id === id ? { ...item, landingName: newName } : item));
    };

    // Execute Batch Forge
    const handleStartForge = async () => {
        if (queue.length === 0 || isProcessing) return;

        setIsProcessing(true);
        setLogs([]);
        addLog(`⚡ Starting batch forge process for ${queue.length} landing archives...`, 'info');

        let completed = 0;
        const total = queue.length;
        setProgress({ current: 0, total, percent: 0 });

        for (let i = 0; i < total; i++) {
            const item = queue[i];
            
            // Mark item processing
            setQueue(prev => prev.map(q => q.id === item.id ? { ...q, status: 'processing' } : q));
            addLog(`\n[${i + 1}/${total}] 🚀 Forging: "${item.fileName}" (${item.landingName})`, 'info');

            try {
                const formData = new FormData();
                formData.append('file', item.file);
                formData.append('name', item.landingName);
                formData.append('network', selectedNetwork);
                formData.append('api_key', apiKey);
                formData.append('offer_id', offerId);
                formData.append('geo', selectedGeo);
                formData.append('payout', payout);
                formData.append('currency', currency);
                if (selectedGroupId) formData.append('group_id', selectedGroupId);
                formData.append('inject_offer_macro', options.injectOfferMacro ? '1' : '0');
                formData.append('inject_js_adapter', options.injectJsAdapter ? '1' : '0');
                formData.append('add_phone_mask', options.addPhoneMask ? '1' : '0');
                formData.append('generate_thank_you', options.generateThankYou ? '1' : '0');
                formData.append('generate_order_php', options.generateOrderPhp ? '1' : '0');
                formData.append('auto_save_tracker', options.autoSaveTracker ? '1' : '0');
                formData.append('auto_create_offer', options.autoCreateOffer ? '1' : '0');

                addLog(`   💉 Injecting Orbitra JS Adapter & Macro parameters...`, 'step');
                if (options.addPhoneMask) addLog(`   📞 Attaching ${selectedGeo} Phone Mask & Validation Rules...`, 'step');
                if (options.generateOrderPhp) addLog(`   🛡️ Generating order.php API Bridge for ${CPA_NETWORKS.find(n => n.id === selectedNetwork)?.name || selectedNetwork}...`, 'step');
                if (options.generateThankYou) addLog(`   🎁 Creating localized thank_you.php page for [${selectedGeo}]...`, 'step');

                const res = await axios.post(`${API_URL}?action=leadforge_forge_landing`, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                if (res.data?.status === 'success') {
                    const resultData = res.data.data;
                    setQueue(prev => prev.map(q => q.id === item.id ? { ...q, status: 'success', result: resultData } : q));
                    addLog(`   ✅ Success: Registered in Orbitra (Landing ID #${resultData.landing_id}, Slug: /lander/${resultData.slug}/)`, 'success');
                } else {
                    const errMsg = res.data?.message || 'Unknown forge error';
                    setQueue(prev => prev.map(q => q.id === item.id ? { ...q, status: 'error', error: errMsg } : q));
                    addLog(`   ❌ Error: ${errMsg}`, 'error');
                }
            } catch (err) {
                const errMsg = err.response?.data?.message || err.message;
                setQueue(prev => prev.map(q => q.id === item.id ? { ...q, status: 'error', error: errMsg } : q));
                addLog(`   ❌ Exception: ${errMsg}`, 'error');
            }

            completed++;
            const percent = Math.round((completed / total) * 100);
            setProgress({ current: completed, total, percent });
        }

        setIsProcessing(false);
        addLog(`\n🎉 All ${total} archives processed! You can now use them in your Campaigns.`, 'success');
        if (refreshData) refreshData();
    };

    const successfulCount = queue.filter(q => q.status === 'success').length;

    return (
        <div className="space-y-6 max-w-7xl mx-auto pb-12">
            {/* Header / Hero Banner */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl border bg-[var(--color-bg-card)] border-[var(--color-border)] shadow-sm">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-2xl flex items-center justify-center bg-gradient-to-br from-amber-500 to-orange-600 text-white shadow-lg shadow-orange-500/20">
                        <Zap size={28} className="animate-pulse" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--color-text-primary)' }}>
                                LeadForge
                            </h1>
                            <span className="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-orange-100 text-orange-800 dark:bg-orange-950/80 dark:text-orange-300 border border-orange-200 dark:border-orange-800">
                                3-in-1 Suite Engine
                            </span>
                        </div>
                        <p className="text-sm mt-0.5" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('leadforge.subtitle', 'Batch Landing & Offer Auto-Preparation Engine')}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setActiveTab('landings')}
                        className="px-4 py-2 rounded-xl text-sm font-medium border flex items-center gap-2 hover:bg-[var(--color-bg-hover)] transition cursor-pointer"
                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                    >
                        <Globe size={16} />
                        <span>{t('leadforge.openInTracker', 'Landings Library')}</span>
                    </button>
                    {successfulCount > 0 && (
                        <button
                            type="button"
                            onClick={() => setActiveTab('campaigns')}
                            className="px-4 py-2 rounded-xl text-sm font-semibold text-white flex items-center gap-2 bg-[var(--color-primary)] hover:opacity-90 transition shadow-sm cursor-pointer"
                        >
                            <ArrowRight size={16} />
                            <span>{t('nav.campaigns', 'Go to Campaigns')}</span>
                        </button>
                    )}
                </div>
            </div>

            {/* Main Workspace Layout */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                {/* Left Column: Dropzone & Queue (7 Cols) */}
                <div className="lg:col-span-7 space-y-6">
                    {/* Dropzone */}
                    <div
                        onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                        onDragLeave={() => setIsDragging(false)}
                        onDrop={handleDrop}
                        onClick={() => fileInputRef.current?.click()}
                        className={`border-2 border-dashed rounded-2xl p-8 text-center cursor-pointer transition-all flex flex-col items-center justify-center ${
                            isDragging
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]/20 scale-[0.99]'
                                : 'border-[var(--color-border)] bg-[var(--color-bg-card)] hover:border-[var(--color-primary)] hover:bg-[var(--color-bg-hover)]'
                        }`}
                    >
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept=".zip,application/zip"
                            className="hidden"
                            onChange={(e) => {
                                if (e.target.files) handleAddFiles(e.target.files);
                                e.target.value = '';
                            }}
                        />
                        <div className="w-16 h-16 rounded-2xl bg-[var(--color-bg-hover)] flex items-center justify-center text-[var(--color-primary)] mb-3">
                            <Upload size={28} />
                        </div>
                        <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                            {t('leadforge.dropzoneTitle', 'Drag & Drop landing ZIP archives here')}
                        </h3>
                        <p className="text-xs mt-1 max-w-md" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('leadforge.dropzoneSub', 'Supports 1 to 50+ ZIP archives simultaneously from any affiliate network')}
                        </p>
                        <button
                            type="button"
                            className="mt-4 px-4 py-1.5 rounded-full text-xs font-semibold bg-[var(--color-primary-light)] text-[var(--color-primary)] hover:opacity-80 transition"
                        >
                            {t('leadforge.browseFiles', 'Browse ZIP Files')}
                        </button>
                    </div>

                    {/* Archives Queue List */}
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-5 shadow-sm space-y-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <FileArchive size={18} className="text-[var(--color-primary)]" />
                                <h3 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                    {t('leadforge.selectedFiles', 'Archives in queue')} ({queue.length})
                                </h3>
                            </div>
                            {queue.length > 0 && !isProcessing && (
                                <button
                                    type="button"
                                    onClick={handleClearQueue}
                                    className="text-xs text-red-500 hover:text-red-700 flex items-center gap-1 font-medium transition cursor-pointer"
                                >
                                    <Trash2 size={13} />
                                    <span>{t('leadforge.clearQueue', 'Clear Queue')}</span>
                                </button>
                            )}
                        </div>

                        {queue.length === 0 ? (
                            <div className="py-8 text-center" style={{ color: 'var(--color-text-muted)' }}>
                                <FileArchive size={32} className="mx-auto mb-2 opacity-40" />
                                <p className="text-xs">{t('leadforge.noArchives', 'No archives selected. Drop ZIP archives above to start batch preparation.')}</p>
                            </div>
                        ) : (
                            <div className="space-y-2.5 max-h-[380px] overflow-y-auto pr-1">
                                {queue.map((item, idx) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center justify-between p-3 rounded-xl border bg-[var(--color-bg-main)] border-[var(--color-border)] gap-3 text-xs"
                                    >
                                        <div className="flex items-center gap-3 min-w-0 flex-1">
                                            <div className="w-8 h-8 rounded-lg bg-[var(--color-bg-card)] border border-[var(--color-border)] flex items-center justify-center font-bold text-[var(--color-text-muted)] shrink-0">
                                                #{idx + 1}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <input
                                                    type="text"
                                                    value={item.landingName}
                                                    disabled={isProcessing}
                                                    onChange={(e) => handleUpdateLandingName(item.id, e.target.value)}
                                                    className="w-full font-semibold bg-transparent border-b border-transparent hover:border-[var(--color-border)] focus:border-[var(--color-primary)] focus:outline-none px-1 py-0.5 truncate"
                                                    style={{ color: 'var(--color-text-primary)' }}
                                                    title="Click to rename landing"
                                                />
                                                <div className="flex items-center gap-2 text-[11px] text-[var(--color-text-secondary)] px-1">
                                                    <span>{item.fileName}</span>
                                                    <span>•</span>
                                                    <span>{item.fileSize}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2 shrink-0">
                                            {/* Status Badges */}
                                            {item.status === 'pending' && (
                                                <span className="px-2.5 py-1 rounded-full text-[11px] font-medium bg-[var(--color-bg-hover)] text-[var(--color-text-secondary)]">
                                                    Pending
                                                </span>
                                            )}
                                            {item.status === 'processing' && (
                                                <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 flex items-center gap-1.5 animate-pulse">
                                                    <RefreshCw size={11} className="animate-spin" /> Forging...
                                                </span>
                                            )}
                                            {item.status === 'success' && (
                                                <div className="flex items-center gap-1">
                                                    <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 flex items-center gap-1">
                                                        <CheckCircle2 size={12} /> Ready (#{item.result?.landing_id})
                                                    </span>
                                                    {item.result?.download_url && (
                                                        <a
                                                            href={item.result.download_url}
                                                            download
                                                            className="p-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)]"
                                                            title="Download Processed ZIP"
                                                        >
                                                            <Download size={13} />
                                                        </a>
                                                    )}
                                                </div>
                                            )}
                                            {item.status === 'error' && (
                                                <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-red-100 text-red-800 dark:bg-red-950/70 dark:text-red-300 flex items-center gap-1" title={item.error}>
                                                    <AlertCircle size={12} /> Error
                                                </span>
                                            )}

                                            {!isProcessing && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleRemoveQueueItem(item.id)}
                                                    className="p-1 text-[var(--color-text-muted)] hover:text-red-500 transition cursor-pointer"
                                                >
                                                    <X size={14} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Right Column: CPA Network API & Options Configurator (5 Cols) */}
                <div className="lg:col-span-5 space-y-6">
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-6 shadow-sm space-y-5">
                        <div className="flex items-center gap-2 pb-3 border-b border-[var(--color-border)]">
                            <Sliders size={18} className="text-[var(--color-primary)]" />
                            <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('leadforge.networkApi', 'CPA Affiliate Network')}
                            </h3>
                        </div>

                        {/* CPA Network Selector */}
                        <div className="space-y-2">
                            <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                CPA Network Preset
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

                        {/* API Key / Token */}
                        <div className="space-y-2">
                            <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('leadforge.apiKey', 'API Key / Client Token')}
                            </label>
                            <input
                                type="text"
                                value={apiKey}
                                onChange={(e) => handleApiKeyChange(e.target.value)}
                                placeholder="Paste CPA network API Key / Token..."
                                className="w-full px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                            />
                            <p className="text-[11px] text-[var(--color-text-muted)]">
                                Saved automatically per network in browser storage.
                            </p>
                        </div>

                        {/* Offer ID / Flow Token */}
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

                        {/* Target GEO and Currency */}
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

                        {/* Optional Group Selector */}
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
                                    <option value="">No Group</option>
                                    {landingGroups.map(g => (
                                        <option key={g.id} value={g.id}>{g.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        {/* Automation Options Checklist */}
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
                        </div>

                        {/* Forge Action Button */}
                        <div className="pt-2">
                            <button
                                type="button"
                                disabled={queue.length === 0 || isProcessing}
                                onClick={handleStartForge}
                                className={`w-full py-3.5 px-6 rounded-xl font-bold text-sm text-white shadow-lg flex items-center justify-center gap-2 transition cursor-pointer ${
                                    queue.length === 0 || isProcessing
                                        ? 'opacity-50 cursor-not-allowed bg-gray-500'
                                        : 'bg-gradient-to-r from-orange-500 via-amber-500 to-amber-600 hover:opacity-95 shadow-orange-500/20 active:scale-[0.98]'
                                }`}
                            >
                                {isProcessing ? (
                                    <>
                                        <RefreshCw size={18} className="animate-spin" />
                                        <span>{t('leadforge.forging', '⚡ Forging Landings...')} ({progress.current}/{progress.total})</span>
                                    </>
                                ) : (
                                    <>
                                        <Zap size={18} className="fill-white" />
                                        <span>{t('leadforge.forgeButton', '⚡ Forge & Save to Tracker')} ({queue.length})</span>
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* Live Forge Console Stream */}
            {(logs.length > 0 || isProcessing) && (
                <div className="bg-slate-950 text-slate-200 border border-slate-800 rounded-2xl p-5 font-mono text-xs shadow-xl space-y-3">
                    <div className="flex items-center justify-between border-b border-slate-800 pb-3 text-slate-400">
                        <div className="flex items-center gap-2">
                            <Terminal size={15} className="text-amber-400" />
                            <span className="font-semibold text-slate-200">{t('leadforge.consoleTitle', 'LeadForge Execution Console')}</span>
                        </div>
                        {isProcessing && (
                            <div className="flex items-center gap-3">
                                <span className="text-[11px] text-amber-400 font-bold">{progress.percent}%</span>
                                <div className="w-32 h-2 bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className="h-full bg-gradient-to-r from-amber-500 to-orange-500 transition-all duration-300"
                                        style={{ width: `${progress.percent}%` }}
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="max-h-64 overflow-y-auto space-y-1 pr-2">
                        {logs.map((log) => (
                            <div key={log.id} className="flex items-start gap-2">
                                <span className="text-slate-500 shrink-0">[{log.time}]</span>
                                <span className={`break-all ${
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
