import React, { useState, useEffect } from 'react';
import axios from 'axios';
import GeoSelector from './GeoSelector';
import HelpTooltip from './HelpTooltip';
import { ArrowLeft, Plus, Check, Link, Copy, Settings, Trash2, ChevronDown, ChevronUp, AlertCircle, X, Shield, Globe, MousePointerClick, TrendingUp, Activity, BarChart2, BarChart3, DollarSign, RefreshCw, FileText, MoreVertical, Play } from 'lucide-react';
import CampaignReports from './CampaignReports';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Generate random alias like Keitaro
const generateAlias = () => {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < 6; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
};

const CampaignEditor = ({ campaignId, onClose }) => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('general');
    const [loading, setLoading] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);

    // Modal states
    const [showLogModal, setShowLogModal] = useState(false);
    const [showCostModal, setShowCostModal] = useState(false);
    const [showClearModal, setShowClearModal] = useState(false);
    const [showReportsMenu, setShowReportsMenu] = useState(false);
    const [showReports, setShowReports] = useState(false);

    // Pixel states
    const [pixels, setPixels] = useState([]);
    const [editingPixel, setEditingPixel] = useState(null);
    const [pixelForm, setPixelForm] = useState({ type: '', pixel_id: '', token: '', events: 'PageView,Lead', is_active: 1 });

    // Log data
    const [clickLogs, setClickLogs] = useState([]);

    // Select options state
    const [groups, setGroups] = useState([]);
    const [sources, setSources] = useState([]);
    const [domains, setDomains] = useState([]);
    const [allOffers, setAllOffers] = useState([]);
    const [allLandings, setAllLandings] = useState([]);

    // Form State
    const [formData, setFormData] = useState({
        name: t('editor.newCampaign'),
        alias: generateAlias(),
        group_id: '',
        source_id: '',
        domain_id: '',
        cost_model: 'CPC',
        cost_value: 0.00,
        uniqueness_method: 'IP',
        uniqueness_hours: 24,
        notes: '',
        catch_404_stream_id: '',
        streams: [],
        postbacks: [],
        parameters: {}
    });

    // Stream Expansion state
    const [expandedStream, setExpandedStream] = useState(null);

    // Cost models
    const costModels = [
        { value: 'CPC', label: t('costModels.cpc') },
        { value: 'CPuC', label: t('costModels.cpuc') },
        { value: 'CPM', label: t('costModels.cpm') },
        { value: 'CPA', label: t('costModels.cpa') },
        { value: 'CPS', label: t('costModels.cps') },
        { value: 'RevShare', label: t('costModels.revShare') }
    ];

    // Available parameters
    const availableParameters = [
        { key: 'keyword', label: t('parameters.keyword') },
        { key: 'cost', label: t('parameters.cost') },
        { key: 'currency', label: t('parameters.currency') },
        { key: 'external_id', label: t('parameters.externalId') },
        { key: 'creative_id', label: t('parameters.creativeId') },
        { key: 'ad_campaign_id', label: t('parameters.adCampaignId') },
        { key: 'source', label: t('parameters.source') },
        ...Array.from({ length: 30 }, (_, i) => ({ key: 'sub_id_' + (i + 1), label: 'Sub ID ' + (i + 1) }))
    ];

    // Pixel platforms
    const pixelPlatforms = [
        { id: 'facebook', name: 'Facebook Pixel', icon: '📘', placeholder: '123456789012345' },
        { id: 'google_ads', name: 'Google Ads', icon: '🔎', placeholder: 'AW-123456789' },
        { id: 'tiktok', name: 'TikTok Pixel', icon: '🎵', placeholder: 'C1234567890' },
        { id: 'vk', name: 'VK Pixel', icon: '💬', placeholder: 'VK-RTRG-123456-abc' },
        { id: 'yandex', name: t('integrations.yandex'), icon: '🔍', placeholder: '12345678' }
    ];

    // Get campaign URL
    const getCampaignUrl = () => {
        const domain = domains.find(d => d.id == formData.domain_id);
        const baseUrl = domain ? `https://${domain.name}` : window.location.origin;
        return `${baseUrl}/${formData.alias}`;
    };

    // Copy URL to clipboard
    const copyUrl = () => {
        navigator.clipboard.writeText(getCampaignUrl());
        setSaveSuccess(true);
        setTimeout(() => setSaveSuccess(false), 2000);
    };

    // Fetch click logs
    const fetchClickLogs = async () => {
        if (!campaignId) return;
        try {
            const res = await axios.get(`${API_URL}?action=campaign_logs&campaign_id=${campaignId}`);
            if (res.data.status === 'success') {
                setClickLogs(res.data.data);
            }
        } catch (e) {
            console.error('Error fetching logs:', e);
        }
    };

    useEffect(() => {
        const fetchDeps = async () => {
            try {
                const [gRes, sRes, dRes, oRes, lRes] = await Promise.all([
                    axios.get(`${API_URL}?action=campaign_groups`),
                    axios.get(`${API_URL}?action=traffic_sources`),
                    axios.get(`${API_URL}?action=domains`),
                    axios.get(`${API_URL}?action=all_offers`),
                    axios.get(`${API_URL}?action=landings`)
                ]);
                if (gRes.data.status === 'success') setGroups(gRes.data.data);
                if (sRes.data.status === 'success') setSources(sRes.data.data);
                if (dRes.data.status === 'success') setDomains(dRes.data.data);
                if (oRes.data.status === 'success') setAllOffers(oRes.data.data);
                if (lRes.data.status === 'success') setAllLandings(lRes.data.data);
            } catch (err) {
                console.error(err);
            }
        };
        fetchDeps();

        if (campaignId) {
            setLoading(true);
            axios.get(`${API_URL}?action=get_campaign&id=${campaignId}`)
                .then(res => {
                    if (res.data.status === 'success') {
                        const data = res.data.data;
                        setFormData({
                            id: data.id,
                            name: data.name || '',
                            alias: data.alias || generateAlias(),
                            group_id: data.group_id || '',
                            source_id: data.source_id || '',
                            domain_id: data.domain_id || '',
                            cost_model: data.cost_model || 'CPC',
                            cost_value: data.cost_value || 0,
                            uniqueness_method: data.uniqueness_method || 'IP',
                            uniqueness_hours: data.uniqueness_hours || 24,
                            notes: data.notes || '',
                            catch_404_stream_id: data.catch_404_stream_id || '',
                            streams: (data.streams || []).map(s => ({
                                ...s,
                                schema_custom: s.schema_custom_json ? JSON.parse(s.schema_custom_json) : { landings: [], offers: [] }
                            })),
                            postbacks: data.postbacks || [],
                            parameters: data.parameters || {}
                        });
                    }
                })
                .finally(() => setLoading(false));
        }
    }, [campaignId]);

    const fetchPixels = async () => {
        if (!campaignId) return;
        try {
            const res = await axios.get(`${API_URL}?action=campaign_pixels&campaign_id=${campaignId}`);
            if (res.data.status === 'success') setPixels(res.data.data || []);
        } catch (err) { console.error(err); }
    };

    useEffect(() => { if (campaignId) fetchPixels(); }, [campaignId]);

    const handleSave = async () => {
        if (!formData.name || !formData.alias) {
            alert(t('editor.fillNameAndAlias'));
            return;
        }
        try {
            setLoading(true);
            const res = await axios.post(`${API_URL}?action=save_campaign`, formData);
            if (res.data.status === 'success') {
                setSaveSuccess(true);
                setTimeout(() => {
                    setSaveSuccess(false);
                    if (onClose) onClose(true);
                }, 1000);
            } else {
                alert(`${t('common.error')}: ${res.data.message}`);
            }
        } catch (err) {
            alert(t('common.networkError'));
        } finally {
            setLoading(false);
        }
    };

    const clearStats = async () => {
        if (!campaignId) return;
        if (!window.confirm(t('campaigns.clearStatsWarning'))) return;
        try {
            const res = await axios.post(`${API_URL}?action=clear_campaign_stats`, { campaign_id: campaignId });
            if (res.data.status === 'success') {
                alert(t('editor.saved'));
                setShowClearModal(false);
                if (onClose) onClose(true);
            }
        } catch (e) {
            alert(t('common.clearError'));
        }
    };

    // Stream management
    const addStream = (type) => {
        const newStream = {
            id: "temp_" + Date.now(),
            type: type,
            name: t('editor.newStream'),
            position: formData.streams.length + 1,
            is_active: 1,
            schema_type: 'redirect',
            offer_id: 0,
            action_payload: '',
            filters: [],
            schema_custom: { landings: [], offers: [] }
        };
        setFormData(prev => ({ ...prev, streams: [...prev.streams, newStream] }));
    };

    const updateStream = (index, field, value) => {
        const s = [...formData.streams];
        s[index][field] = value;
        setFormData({ ...formData, streams: s });
    };

    const removeStream = (index) => {
        const s = [...formData.streams];
        s.splice(index, 1);
        setFormData({ ...formData, streams: s });
    };

    const duplicateStream = (index) => {
        const sourceStream = formData.streams[index];
        const newStream = JSON.parse(JSON.stringify(sourceStream));
        newStream.id = "temp_" + Date.now();
        newStream.name = `${sourceStream.name} (${t('editor.copy')})`;

        const s = [...formData.streams];
        s.splice(index + 1, 0, newStream);

        // Re-calculate positions
        s.forEach((stream, i) => { stream.position = i + 1; });
        setFormData({ ...formData, streams: s });
    };

    const moveStreamUp = (index) => {
        if (index === 0) return;
        const s = [...formData.streams];
        const temp = s[index - 1];
        s[index - 1] = s[index];
        s[index] = temp;
        // Re-calculate positions
        s.forEach((stream, i) => { stream.position = i + 1; });
        setFormData({ ...formData, streams: s });
    };

    const moveStreamDown = (index) => {
        if (index === formData.streams.length - 1) return;
        const s = [...formData.streams];
        const temp = s[index + 1];
        s[index + 1] = s[index];
        s[index] = temp;
        // Re-calculate positions
        s.forEach((stream, i) => { stream.position = i + 1; });
        setFormData({ ...formData, streams: s });
    };

    // Postback management
    const addPostback = () => {
        setFormData({
            ...formData,
            postbacks: [...formData.postbacks, { url: '', method: 'GET', statuses: 'lead,sale,rejected' }]
        });
    };
    const updatePostback = (index, field, value) => {
        const p = [...formData.postbacks];
        p[index][field] = value;
        setFormData({ ...formData, postbacks: p });
    };
    const removePostback = (index) => {
        const p = [...formData.postbacks];
        p.splice(index, 1);
        setFormData({ ...formData, postbacks: p });
    };

    // Schema item management
    const addSchemaItem = (streamIdx, type) => {
        const s = [...formData.streams];
        if (!s[streamIdx].schema_custom) s[streamIdx].schema_custom = { landings: [], offers: [] };
        s[streamIdx].schema_custom[type].push({ id: '', weight: 100 });
        setFormData({ ...formData, streams: s });
    };

    const updateSchemaItem = (streamIdx, type, itemIdx, field, value) => {
        const s = [...formData.streams];
        s[streamIdx].schema_custom[type][itemIdx][field] = value;
        setFormData({ ...formData, streams: s });
    };

    const removeSchemaItem = (streamIdx, type, itemIdx) => {
        const s = [...formData.streams];
        s[streamIdx].schema_custom[type].splice(itemIdx, 1);
        setFormData({ ...formData, streams: s });
    };

    // Filter management
    const [filterModal, setFilterModal] = useState({ open: false, streamIdx: null });
    const [newFilter, setNewFilter] = useState({ name: 'Country', mode: 'include', payload: '' });

    const availableFilters = [
        { name: 'Country', label: t('filters.country'), placeholder: 'RU, US, UK...' },
        { name: 'Device', label: t('filters.device'), placeholder: 'mobile, desktop, tablet...' },
        { name: 'OS', label: t('filters.os'), placeholder: 'windows, macos, ios, android...' },
        { name: 'Browser', label: t('filters.browser'), placeholder: 'chrome, firefox, safari...' },
        { name: 'Language', label: t('filters.language'), placeholder: 'ru, en, de...' },
        { name: 'ISP', label: t('filters.isp'), placeholder: t('filters.ispPlaceholder') },
        { name: 'Connection', label: t('filters.connection'), placeholder: 'mobile, wifi, cable...' },
        { name: 'IP', label: t('filters.ip'), placeholder: '192.168.1.1, 10.0.0.*...' },
        { name: 'Keyword', label: t('filters.keyword'), placeholder: 'keyword1, keyword2...' },
        { name: 'Referer', label: t('filters.referer'), placeholder: 'google.com, facebook.com...' },
        { name: 'Weekday', label: t('filters.weekday'), placeholder: 'monday, tuesday...' },
        { name: 'Time', label: t('filters.time'), placeholder: '9-18, 10:00-20:00...' },
    ];

    const openFilterModal = (streamIdx) => {
        setFilterModal({ open: true, streamIdx });
        setNewFilter({ name: 'Country', mode: 'include', payload: '' });
    };

    const addFilter = () => {
        if (!newFilter.payload.trim()) return;
        const s = [...formData.streams];
        if (!s[filterModal.streamIdx].filters) s[filterModal.streamIdx].filters = [];
        s[filterModal.streamIdx].filters.push({
            name: newFilter.name,
            mode: newFilter.mode,
            payload: newFilter.payload.split(',').map(p => p.trim()).filter(p => p)
        });
        setFormData({ ...formData, streams: s });
        setFilterModal({ open: false, streamIdx: null });
    };

    const removeFilter = (streamIdx, filterIdx) => {
        const s = [...formData.streams];
        s[streamIdx].filters.splice(filterIdx, 1);
        setFormData({ ...formData, streams: s });
    };

    // Generate landing code
    const generateLandingCode = () => {
        const uid = formData.alias + '-' + Date.now().toString(36);
        return `<span id="${uid}"></span>
<script type="application/javascript">
document.getElementById('${uid}').innerHTML = '<a href="${getCampaignUrl()}?&se_referrer=' + encodeURIComponent(document.referrer) + '&default_keyword=' + encodeURIComponent(document.title) + '&'+window.location.search.replace('?', '&')+'">Link</a>';
</script>`;
    };

    return (
        <>
            <div className="h-[calc(100vh-80px)] w-full flex flex-col overflow-hidden rounded-[24px] shadow-lg" style={{ backgroundColor: 'var(--color-bg-card)', border: 'none' }}>
                {/* Header */}
                <div className="flex justify-between items-center px-6 py-4 flex-shrink-0" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    <div className="flex items-center gap-4">
                        <h2 className="text-xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                            {campaignId ? `${t('editor.campaign')}: ${formData.name}` : t('editor.createCampaign')}
                        </h2>
                        {formData.alias && (
                            <span className="text-sm font-mono px-2 py-1 rounded-lg" style={{ color: 'var(--color-text-muted)', backgroundColor: 'var(--color-bg-hover)' }}>
                                /{formData.alias}
                            </span>
                        )}
                    </div>

                    <div className="flex items-center space-x-2">
                        {/* Save button */}
                        <button
                            onClick={handleSave}
                            disabled={loading}
                            className="btn btn-primary"
                            style={saveSuccess ? { backgroundColor: 'var(--color-success)' } : {}}
                        >
                            {saveSuccess ? <Check className="w-4 h-4" /> : <Settings className="w-4 h-4" />}
                            {saveSuccess ? t('editor.saved') : t('editor.save')}
                        </button>

                        {/* Copy URL */}
                        <button onClick={copyUrl} className="btn btn-ghost btn-icon" title={t('editor.copyUrl')}>
                            <Copy className="w-5 h-5" />
                        </button>

                        {/* Log button */}
                        <button onClick={() => { fetchClickLogs(); setShowLogModal(true); }} className="btn btn-ghost btn-icon" title={t('campaignEditor.clickLog')}>
                            <FileText className="w-5 h-5" />
                        </button>

                        {/* More menu */}
                        <div className="relative">
                            <button onClick={() => setShowReportsMenu(!showReportsMenu)} className="btn btn-ghost btn-icon">
                                <MoreVertical className="w-5 h-5" />
                            </button>

                            {showReportsMenu && (
                                <div className="absolute right-0 top-full mt-1 w-56 rounded-2xl shadow-xl z-50 py-2" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                    <div className="px-3 py-2 text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>{t('campaignEditor.reports')}</div>
                                    <button
                                        onClick={() => { setShowReportsMenu(false); setShowReports(true); }}
                                        className="w-full text-left px-4 py-2 text-sm flex items-center gap-2"
                                        style={{ color: 'var(--color-success)' }}
                                    >
                                        <BarChart3 className="w-4 h-4" /> {t('editor.fullReportCsv')}
                                    </button>
                                    <button
                                        onClick={loadConversionLogs}
                                        className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <Activity className="w-4 h-4" /> {t('editor.conversionsLog')}
                                    </button>
                                    <div className="my-1" style={{ borderTop: '1px solid var(--color-border)' }}></div>
                                    <div className="px-3 py-2 text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>{t('common.actions')}</div>
                                    {/* <div className="h-px mx-2 my-1" style={{ backgroundColor: 'var(--color-border)' }}></div> */}
                                    <button
                                        onClick={() => { setShowReportsMenu(false); setShowCostModal(true); }}
                                        className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <DollarSign className="w-4 h-4" /> {t('campaigns.updateCosts')}
                                    </button>
                                    <button className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                        <Play className="w-4 h-4" /> {t('editor.trafficSimulation')}
                                    </button>
                                    <button
                                        onClick={() => { setShowReportsMenu(false); setShowClearModal(true); }}
                                        className="w-full text-left px-4 py-2 text-sm flex items-center gap-2"
                                        style={{ color: 'var(--color-danger)' }}
                                    >
                                        <Trash2 className="w-4 h-4" /> {t('common.clearStats')}
                                    </button>
                                </div>
                            )}
                        </div>

                    </div>
                    {/* Close button */}
                    <button onClick={() => onClose(true)} className="btn btn-secondary">
                        <X className="w-5 h-5 mr-2" />
                        {t('common.close')}
                    </button>
                </div>

                {/* Main Content: Left tabs + Right streams */}
                <div className="flex-1 flex overflow-hidden">
                    {/* Left sidebar with tabs */}
                    <div className="w-[30%] min-w-[300px] flex flex-col" style={{ borderRight: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                        {/* Tabs */}
                        <div className="flex px-2 pt-2 overflow-x-auto no-scrollbar" style={{ borderBottom: '1px solid var(--color-border)' }}>
                            {[
                                { key: 'general', label: t('editor.general') },
                                { key: 'finance', label: t('editor.finance') },
                                { key: 'params', label: t('editor.params') },
                                { key: 'integrations', label: t('editor.integrations') },
                                { key: 'postbacks', label: 'S2S Postbacks' },
                                { key: 'notes', label: t('editor.notes') }
                            ].map(tab => (
                                <button
                                    key={tab.key}
                                    onClick={() => setActiveTab(tab.key)}
                                    className="px-4 py-2 text-sm font-medium border-b-2 transition whitespace-nowrap rounded-t-lg"
                                    style={{
                                        borderColor: activeTab === tab.key ? 'var(--color-primary)' : 'transparent',
                                        color: activeTab === tab.key ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                        backgroundColor: activeTab === tab.key ? 'var(--color-bg-card)' : 'transparent'
                                    }}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        {/* Tab content */}
                        <div className="flex-1 overflow-y-auto p-5" style={{ backgroundColor: 'var(--color-bg-card)' }}>
                            {loading ? (
                                <div className="text-center py-10 flex flex-col items-center" style={{ color: 'var(--color-text-muted)' }}>
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 mb-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                                    {t('common.loading')}
                                </div>
                            ) : (
                                <>
                                    {/* General Tab */}
                                    {activeTab === 'general' && (
                                        <div className="space-y-4">
                                            <div className="md:col-span-1 border rounded-xl overflow-hidden" style={{ borderColor: 'var(--color-border)' }}>
                                                <div className="px-4 py-2" style={{ backgroundColor: 'var(--color-bg-hover)', borderBottom: '1px solid var(--color-border)' }}>
                                                    <h3 className="font-semibold text-sm" style={{ color: 'var(--color-text-primary)' }}>{t('editor.general')}</h3>
                                                </div>
                                                <div className="p-4 space-y-4">
                                                    <div>
                                                        <label className="form-label">{t('editor.name')}</label>
                                                        <input
                                                            type="text"
                                                            value={formData.name}
                                                            onChange={e => setFormData({ ...formData, name: e.target.value })}
                                                            className="form-input"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="form-label">{t('editor.alias')} <HelpTooltip textKey="help.aliasTooltip" /></label>
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="text"
                                                                value={formData.alias}
                                                                onChange={e => setFormData({ ...formData, alias: e.target.value })}
                                                                className="form-input font-mono text-sm"
                                                            />
                                                            <button
                                                                onClick={() => setFormData({ ...formData, alias: generateAlias() })}
                                                                className="btn btn-secondary"
                                                                title={t('editor.generateRandom')}
                                                            >
                                                                🎲
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="form-label">{t('campaigns.group')}</label>
                                                        <div className="flex gap-2">
                                                            <select
                                                                value={formData.group_id}
                                                                onChange={e => setFormData({ ...formData, group_id: e.target.value })}
                                                                className="form-select"
                                                            >
                                                                <option value="">{t('editor.noGroup')}</option>
                                                                {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                                                            </select>
                                                            <button className="btn btn-secondary btn-icon">
                                                                <Plus className="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="md:col-span-1 border rounded-xl overflow-hidden" style={{ borderColor: 'var(--color-border)' }}>
                                                <div className="px-4 py-2" style={{ backgroundColor: 'var(--color-bg-hover)', borderBottom: '1px solid var(--color-border)' }}>
                                                    <h3 className="font-semibold text-sm" style={{ color: 'var(--color-text-primary)' }}>{t('editor.domain')} & {t('editor.trafficSource')}</h3>
                                                </div>
                                                <div className="p-4 space-y-4">
                                                    <div>
                                                        <label className="form-label">{t('editor.domain')}</label>
                                                        <select
                                                            value={formData.domain_id}
                                                            onChange={e => setFormData({ ...formData, domain_id: e.target.value })}
                                                            className="form-select"
                                                        >
                                                            <option value="">{t('editor.indexDomain')}</option>
                                                            {domains.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label className="form-label">{t('editor.trafficSource')}</label>
                                                        <div className="flex gap-2">
                                                            <select
                                                                value={formData.source_id}
                                                                onChange={e => setFormData({ ...formData, source_id: e.target.value })}
                                                                className="form-select"
                                                            >
                                                                <option value="">{t('editor.organicTraffic')}</option>
                                                                {sources.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                                                            </select>
                                                            <button className="btn btn-secondary btn-icon">
                                                                <Plus className="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="pt-4 flex flex-col gap-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                <div>
                                                    <label className="form-label">{t('editor.uniqueness')} <HelpTooltip textKey="help.uniquenessTooltip" /></label>
                                                    <select
                                                        value={formData.uniqueness_method}
                                                        onChange={e => setFormData({ ...formData, uniqueness_method: e.target.value })}
                                                        className="form-select"
                                                    >
                                                        <option value="IP">{t('editor.uniquenessIp')}</option>
                                                        <option value="IP_UA">{t('editor.uniquenessIpUa')}</option>
                                                        <option value="Cookies">{t('editor.uniquenessCookies')}</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label className="form-label">{t('editor.uniquenessHours')}</label>
                                                    <input
                                                        type="number" min="1" max="8760"
                                                        value={formData.uniqueness_hours}
                                                        onChange={e => setFormData({ ...formData, uniqueness_hours: e.target.value })}
                                                        className="form-input"
                                                    />
                                                </div>
                                            </div>

                                            <div className="pt-4 mt-4" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                <label className="form-label">{t('editor.campaignUrl')}</label>
                                                <div className="flex gap-2">
                                                    <input
                                                        type="text"
                                                        value={getCampaignUrl()}
                                                        readOnly
                                                        className="form-input text-xs"
                                                        style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}
                                                    />
                                                    <button onClick={copyUrl} className="btn btn-secondary btn-icon">
                                                        <Copy className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Finance Tab */}
                                    {activeTab === 'finance' && (
                                        <div className="space-y-4">
                                            <div>
                                                <label className="form-label">{t('editor.rewardModel')} <HelpTooltip textKey="help.costModelTooltip" /></label>
                                                <select
                                                    value={formData.cost_model}
                                                    onChange={e => setFormData({ ...formData, cost_model: e.target.value })}
                                                    className="form-select"
                                                >
                                                    {costModels.map(m => (
                                                        <option key={m.value} value={m.value}>{m.label}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="form-label">{t('editor.costValue')}</label>
                                                <input
                                                    type="number" step="0.01"
                                                    value={formData.cost_value}
                                                    onChange={e => setFormData({ ...formData, cost_value: e.target.value })}
                                                    className="form-input"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {/* Parameters Tab */}
                                    {activeTab === 'params' && (
                                        <div className="space-y-2">
                                            <p className="text-xs mb-4" style={{ color: 'var(--color-text-secondary)' }}>{t('editor.setupParams')}</p>
                                            {availableParameters.map(param => (
                                                <div key={param.key} className="flex items-center gap-2">
                                                    <span className="w-24 text-xs truncate" style={{ color: 'var(--color-text-secondary)' }}>{param.label}</span>
                                                    <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{param.key}</span>
                                                    <input
                                                        type="text"
                                                        placeholder="="
                                                        value={formData.parameters[param.key] || ''}
                                                        onChange={e => setFormData({
                                                            ...formData,
                                                            parameters: { ...formData.parameters, [param.key]: e.target.value }
                                                        })}
                                                        className="form-input text-xs"
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Integrations Tab */}
                                    {activeTab === 'integrations' && (
                                        <div className="space-y-4">
                                            <p className="text-xs mb-2" style={{ color: 'var(--color-text-secondary)' }}>{t('pixels.selectPlatform')}</p>

                                            {/* Existing pixels */}
                                            {pixels.map(px => {
                                                const platform = pixelPlatforms.find(p => p.id === px.type) || { name: px.type, icon: '📊' };
                                                return (
                                                    <div key={px.id} style={{
                                                        border: '1px solid var(--color-border)',
                                                        borderRadius: '16px',
                                                        padding: '14px 16px',
                                                        background: px.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                                        opacity: px.is_active ? 1 : 0.7
                                                    }}>
                                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                                                <span className="text-xl">{platform.icon}</span>
                                                                <span style={{ fontWeight: 600, fontSize: '14px' }}>{platform.name}</span>
                                                                <span style={{ fontSize: '12px', color: 'var(--color-text-muted)', fontFamily: 'monospace' }}>{px.pixel_id}</span>
                                                            </div>
                                                            <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                                                                <label style={{ display: 'flex', alignItems: 'center', gap: '6px', cursor: 'pointer', fontSize: '12px', color: 'var(--color-text-secondary)' }}>
                                                                    <input type="checkbox" checked={!!px.is_active} onChange={async (e) => {
                                                                        try {
                                                                            await axios.post(`${API_URL}?action=save_campaign_pixel`, { ...px, is_active: e.target.checked ? 1 : 0 });
                                                                            fetchPixels();
                                                                        } catch (err) { console.error(err); }
                                                                    }} />
                                                                    {t('pixels.active')}
                                                                </label>
                                                                <button onClick={() => {
                                                                    if (confirm(t('pixels.confirmDelete'))) {
                                                                        axios.post(`${API_URL}?action=delete_campaign_pixel`, { id: px.id }).then(() => fetchPixels());
                                                                    }
                                                                }} className="action-btn text-red" style={{ padding: '4px' }}>
                                                                    <Trash2 size={14} />
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                            {t('pixels.events')}: {px.events}
                                                            {px.token && <span> • Token: ••••{px.token.slice(-4)}</span>}
                                                        </div>
                                                    </div>
                                                );
                                            })}

                                            {/* Add pixel form */}
                                            {editingPixel ? (
                                                <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '16px', background: 'var(--color-primary-light)' }}>
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '12px' }}>
                                                        <span className="text-xl">{pixelPlatforms.find(p => p.id === pixelForm.type)?.icon}</span>
                                                        <span style={{ fontWeight: 600, fontSize: '14px' }}>{pixelPlatforms.find(p => p.id === pixelForm.type)?.name}</span>
                                                    </div>
                                                    <div className="space-y-3">
                                                        <div>
                                                            <label className="form-label">{t('pixels.pixelId')}</label>
                                                            <input
                                                                type="text"
                                                                value={pixelForm.pixel_id}
                                                                onChange={e => setPixelForm({ ...pixelForm, pixel_id: e.target.value })}
                                                                placeholder={pixelPlatforms.find(p => p.id === pixelForm.type)?.placeholder}
                                                                className="form-input font-mono text-sm"
                                                            />
                                                        </div>
                                                        <div>
                                                            <label className="form-label">{t('pixels.apiToken')}</label>
                                                            <input
                                                                type="text"
                                                                value={pixelForm.token}
                                                                onChange={e => setPixelForm({ ...pixelForm, token: e.target.value })}
                                                                placeholder="EAAxxxx..."
                                                                className="form-input font-mono text-sm"
                                                            />
                                                        </div>
                                                        <div>
                                                            <label className="form-label">{t('pixels.events')}</label>
                                                            <input
                                                                type="text"
                                                                value={pixelForm.events}
                                                                onChange={e => setPixelForm({ ...pixelForm, events: e.target.value })}
                                                                className="form-input text-sm"
                                                            />
                                                            <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginTop: '4px' }}>{t('pixels.eventsHint')}</p>
                                                        </div>
                                                        <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                                                            <button onClick={() => setEditingPixel(null)} className="btn btn-secondary btn-sm">
                                                                <X size={14} /> {t('common.cancel')}
                                                            </button>
                                                            <button
                                                                onClick={async () => {
                                                                    if (!pixelForm.pixel_id) return;
                                                                    try {
                                                                        await axios.post(`${API_URL}?action=save_campaign_pixel`, {
                                                                            campaign_id: campaignId,
                                                                            ...pixelForm
                                                                        });
                                                                        setEditingPixel(null);
                                                                        setPixelForm({ type: '', pixel_id: '', token: '', events: 'PageView,Lead', is_active: 1 });
                                                                        fetchPixels();
                                                                    } catch (err) { console.error(err); }
                                                                }}
                                                                className="btn btn-primary btn-sm"
                                                                disabled={!pixelForm.pixel_id}
                                                            >
                                                                <Check size={14} /> {t('common.save')}
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: '8px' }}>
                                                    {pixelPlatforms.map(platform => (
                                                        <button
                                                            key={platform.id}
                                                            onClick={() => {
                                                                setPixelForm({ type: platform.id, pixel_id: '', token: '', events: 'PageView,Lead', is_active: 1 });
                                                                setEditingPixel('new');
                                                            }}
                                                            className="w-full flex items-center gap-3 p-3 rounded-2xl text-left transition"
                                                            style={{ border: '1px solid var(--color-border)', cursor: 'pointer' }}
                                                            onMouseOver={e => e.currentTarget.style.borderColor = 'var(--color-primary)'}
                                                            onMouseOut={e => e.currentTarget.style.borderColor = 'var(--color-border)'}
                                                        >
                                                            <span className="text-xl">{platform.icon}</span>
                                                            <span className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>{platform.name}</span>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}

                                            {pixels.length === 0 && !editingPixel && (
                                                <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '20px 0' }}>
                                                    {t('pixels.noPixelsDesc')}
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    {/* Postbacks Tab */}
                                    {activeTab === 'postbacks' && (
                                        <div className="space-y-4">
                                            <button
                                                onClick={addPostback}
                                                className="w-full py-2 border-2 border-dashed rounded-2xl text-sm"
                                                style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}
                                            >
                                                {t('editor.addPostback')}
                                            </button>
                                            {formData.postbacks.map((pb, idx) => (
                                                <div key={idx} className="rounded-2xl p-3 space-y-2" style={{ border: '1px solid var(--color-border)' }}>
                                                    <input
                                                        type="text"
                                                        value={pb.url}
                                                        onChange={e => updatePostback(idx, 'url', e.target.value)}
                                                        placeholder="URL"
                                                        className="form-input text-xs"
                                                    />
                                                    <div className="flex gap-2">
                                                        <select
                                                            value={pb.method}
                                                            onChange={e => updatePostback(idx, 'method', e.target.value)}
                                                            className="form-select text-xs"
                                                            style={{ width: 'auto' }}
                                                        >
                                                            <option value="GET">GET</option>
                                                            <option value="POST">POST</option>
                                                        </select>
                                                        <input
                                                            type="text"
                                                            value={pb.statuses}
                                                            onChange={e => updatePostback(idx, 'statuses', e.target.value)}
                                                            placeholder={t('editor.statuses')}
                                                            className="form-input text-xs"
                                                        />
                                                        <button onClick={() => removePostback(idx)} className="action-btn text-red">
                                                            <Trash2 className="w-4 h-4" />
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Notes Tab */}
                                    {activeTab === 'notes' && (
                                        <div>
                                            <textarea
                                                value={formData.notes}
                                                onChange={e => setFormData({ ...formData, notes: e.target.value })}
                                                placeholder={t('editor.yourNotes')}
                                                className="form-input h-64 resize-none"
                                            />
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Right side: Streams Area (70%) */}
                    <div className="flex-1 flex flex-col overflow-hidden" style={{ backgroundColor: 'var(--color-bg-main)' }}>
                        <div className="p-4 flex justify-between items-center shadow-sm z-10" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                            <h3 className="font-bold text-lg" style={{ color: 'var(--color-text-primary)' }}>
                                {t('editor.streams')}
                                <span className="font-normal text-sm ml-1" style={{ color: 'var(--color-text-muted)' }}>({formData.streams.length})</span>
                            </h3>

                            <div className="relative group">
                                <button className="btn btn-primary">
                                    <Plus className="w-4 h-4" />
                                    {t('editor.createStream')}
                                    <ChevronDown className="w-4 h-4 ml-1 opacity-70" />
                                </button>
                                <div className="absolute right-0 top-full mt-1 w-48 rounded-2xl shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 py-2" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                    <button onClick={() => addStream('intercepting')} className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                        <div className="w-2 h-2 rounded-full bg-orange-500"></div>
                                        {t('editor.streamIntercepting')}
                                    </button>
                                    <button onClick={() => addStream('regular')} className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                        <div className="w-2 h-2 rounded-full bg-blue-500"></div>
                                        {t('editor.streamRegular')}
                                    </button>
                                    {!formData.streams.find(s => s.type === 'fallback') && (
                                        <button onClick={() => addStream('fallback')} className="w-full text-left px-4 py-2 text-sm flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                            <div className="w-2 h-2 rounded-full" style={{ backgroundColor: 'var(--color-text-muted)' }}></div>
                                            {t('editor.streamFallback')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="flex-1 overflow-y-auto p-6">
                            {formData.streams.length === 0 ? (
                                <div className="h-full flex flex-col items-center justify-center text-center max-w-sm mx-auto">
                                    <div className="w-20 h-20 rounded-full flex items-center justify-center mb-6" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                        <Plus className="w-8 h-8" style={{ color: 'var(--color-text-muted)' }} />
                                    </div>
                                    <h4 className="text-lg font-bold mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('editor.noStreamsTitle')}</h4>
                                    <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('editor.noStreamsDesc')}
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {formData.streams.map((stream, idx) => (
                                        <div key={stream.id || idx} className="rounded-2xl overflow-hidden shadow-sm" style={{
                                            backgroundColor: 'var(--color-bg-card)',
                                            border: '1px solid var(--color-border)',
                                            borderLeftWidth: '4px',
                                            borderLeftColor: stream.type === 'intercepting' ? '#f97316' : stream.type === 'fallback' ? 'var(--color-text-muted)' : '#3b82f6'
                                        }}>

                                            <div className="flex items-center justify-between px-4 py-2" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                                                <div className="flex items-center gap-3">
                                                    <span className="text-xs font-bold uppercase" style={{
                                                        color: stream.type === 'intercepting' ? '#f97316' : stream.type === 'fallback' ? 'var(--color-text-muted)' : '#3b82f6'
                                                    }}>
                                                        {stream.type === 'intercepting' ? t('editor.streamInterceptingShort') : stream.type === 'fallback' ? t('editor.streamFallbackShort') : t('editor.streamRegularShort')}
                                                    </span>
                                                    <input
                                                        type="text"
                                                        value={stream.name || ''}
                                                        onChange={e => updateStream(idx, 'name', e.target.value)}
                                                        className="bg-transparent border-none font-semibold px-0 w-48"
                                                        style={{ color: 'var(--color-text-primary)' }}
                                                        placeholder={t('editor.streamName')}
                                                    />
                                                </div>
                                                <div className="flex items-center gap-4">
                                                    <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
                                                        <input
                                                            type="checkbox"
                                                            checked={stream.is_active}
                                                            onChange={e => updateStream(idx, 'is_active', e.target.checked ? 1 : 0)}
                                                            className="rounded"
                                                        />
                                                        {t('editor.on')}
                                                    </label>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        onClick={() => moveStreamUp(idx)}
                                                        disabled={idx === 0}
                                                        className="action-btn"
                                                        title={t('editor.moveUp')}
                                                    >
                                                        <ChevronUp className="w-5 h-5" />
                                                    </button>
                                                    <button
                                                        onClick={() => moveStreamDown(idx)}
                                                        disabled={idx === formData.streams.length - 1}
                                                        className="action-btn"
                                                        title={t('editor.moveDown')}
                                                    >
                                                        <ChevronDown className="w-5 h-5" />
                                                    </button>

                                                    <div className="w-px h-6" style={{ backgroundColor: 'var(--color-border)' }}></div>

                                                    <button
                                                        onClick={() => setExpandedStream(expandedStream === idx ? null : idx)}
                                                        className="action-btn"
                                                    >
                                                        <ChevronDown className={`w-5 h-5 transition-transform duration-200 ${expandedStream === idx ? 'rotate-180' : ''}`} />
                                                    </button>
                                                    <button onClick={() => duplicateStream(idx)} className="action-btn text-blue" title={t('editor.duplicate')}>
                                                        <Copy className="w-4 h-4" />
                                                    </button>
                                                    <button onClick={() => removeStream(idx)} className="action-btn text-red" title={t('common.delete')}>
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="p-4 space-y-4">
                                                {/* Schema */}
                                                <div>
                                                    <label className="text-xs font-semibold uppercase mb-2 block" style={{ color: 'var(--color-text-muted)' }}>{t('editor.schema')}</label>
                                                    <select
                                                        value={stream.schema_type}
                                                        onChange={e => updateStream(idx, 'schema_type', e.target.value)}
                                                        className="form-select"
                                                    >
                                                        <option value="redirect">{t('editor.directLinking')}</option>
                                                        <option value="landing_offer">{t('editor.landingOffer')}</option>
                                                        <option value="action">{t('editor.action')}</option>
                                                    </select>
                                                </div>

                                                {stream.schema_type === 'action' && (
                                                    <select
                                                        value={stream.action_payload}
                                                        onChange={e => updateStream(idx, 'action_payload', e.target.value)}
                                                        className="form-select"
                                                        style={{ backgroundColor: 'var(--color-bg-soft)' }}
                                                    >
                                                        <option value="">{t('editor.selectAction')}</option>
                                                        <option value="do_nothing">{t('editor.doNothing')}</option>
                                                        <option value="not_found">{t('editor.show404')}</option>
                                                        <option value="show_html">{t('editor.showHtml')}</option>
                                                    </select>
                                                )}

                                                {stream.schema_type === 'landing_offer' && (
                                                    <div className="space-y-3 rounded-2xl p-3" style={{ border: '1px solid var(--color-border)', backgroundColor: 'rgba(59, 130, 246, 0.05)' }}>
                                                        <div>
                                                            <div className="flex justify-between mb-2">
                                                                <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.landings')}</span>
                                                                <button onClick={() => addSchemaItem(idx, 'landings')} className="text-xs" style={{ color: 'var(--color-primary)' }}>{t('editor.add')}</button>
                                                            </div>
                                                            {(stream.schema_custom?.landings || []).map((l, lIdx, list) => (
                                                                <div key={lIdx} className="flex gap-2 mb-2">
                                                                    <select
                                                                        value={l.id}
                                                                        onChange={e => updateSchemaItem(idx, 'landings', lIdx, 'id', parseInt(e.target.value))}
                                                                        className="form-select text-sm"
                                                                    >
                                                                        <option value="">{t('editor.landingInfo')}</option>
                                                                        {allLandings.map(al => <option key={al.id} value={al.id}>{al.name}</option>)}
                                                                    </select>
                                                                    <div className="flex items-center gap-1">
                                                                        <input
                                                                            type="number"
                                                                            value={list.length === 1 ? 100 : l.weight}
                                                                            disabled={list.length === 1}
                                                                            onChange={e => updateSchemaItem(idx, 'landings', lIdx, 'weight', parseInt(e.target.value))}
                                                                            className="w-16 text-center rounded-lg px-1 py-1 text-sm"
                                                                            style={{ backgroundColor: list.length === 1 ? 'var(--color-bg-soft)' : 'var(--color-bg-card)', border: '1px solid var(--color-border)', color: list.length === 1 ? 'var(--color-text-muted)' : 'var(--color-text-primary)' }}
                                                                            title={t('editor.weight')}
                                                                        />
                                                                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>%</span>
                                                                    </div>
                                                                    <button onClick={() => removeSchemaItem(idx, 'landings', lIdx)} className="action-btn text-red">
                                                                        <X className="w-4 h-4" />
                                                                    </button>
                                                                </div>
                                                            ))}
                                                        </div>
                                                        <div className="pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                            <div className="flex justify-between mb-2">
                                                                <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.offers')}</span>
                                                                <button onClick={() => addSchemaItem(idx, 'offers')} className="text-xs" style={{ color: 'var(--color-primary)' }}>{t('editor.add')}</button>
                                                            </div>
                                                            {(stream.schema_custom?.offers || []).map((o, oIdx, list) => (
                                                                <div key={oIdx} className="flex gap-2 mb-2">
                                                                    <select
                                                                        value={o.id}
                                                                        onChange={e => updateSchemaItem(idx, 'offers', oIdx, 'id', parseInt(e.target.value))}
                                                                        className="form-select text-sm"
                                                                    >
                                                                        <option value="">{t('editor.offerInfo')}</option>
                                                                        {allOffers.map(ao => <option key={ao.id} value={ao.id}>{ao.name}</option>)}
                                                                    </select>
                                                                    <div className="flex items-center gap-1">
                                                                        <input
                                                                            type="number"
                                                                            value={list.length === 1 ? 100 : o.weight}
                                                                            disabled={list.length === 1}
                                                                            onChange={e => updateSchemaItem(idx, 'offers', oIdx, 'weight', parseInt(e.target.value))}
                                                                            className="w-16 text-center rounded-lg px-1 py-1 text-sm"
                                                                            style={{ backgroundColor: list.length === 1 ? 'var(--color-bg-soft)' : 'var(--color-bg-card)', border: '1px solid var(--color-border)', color: list.length === 1 ? 'var(--color-text-muted)' : 'var(--color-text-primary)' }}
                                                                            title={t('editor.weight')}
                                                                        />
                                                                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>%</span>
                                                                    </div>
                                                                    <button onClick={() => removeSchemaItem(idx, 'offers', oIdx)} className="action-btn text-red">
                                                                        <X className="w-4 h-4" />
                                                                    </button>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}

                                                {stream.schema_type === 'redirect' && (
                                                    <div className="space-y-3 rounded-2xl p-3" style={{ border: '1px solid var(--color-border)', backgroundColor: 'rgba(59, 130, 246, 0.05)' }}>
                                                        <div className="flex justify-between mb-2 items-center">
                                                            <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.offers')}</span>
                                                            <button onClick={() => addSchemaItem(idx, 'offers')} className="text-xs" style={{ color: 'var(--color-primary)' }}>{t('editor.add')}</button>
                                                        </div>

                                                        {(() => {
                                                            const offers = stream.schema_custom?.offers || [];
                                                            const totalWeight = offers.reduce((sum, o) => sum + (parseInt(o.weight) || 0), 0);
                                                            const isOverWeight = totalWeight > 100 && offers.length > 1;

                                                            return (
                                                                <>
                                                                    {offers.length === 0 && (
                                                                        <div className="text-xs text-center py-2" style={{ color: 'var(--color-text-muted)' }}>{t('editor.addOffersHelp')}</div>
                                                                    )}
                                                                    {isOverWeight && (
                                                                        <div className="text-xs rounded-lg p-2" style={{ color: 'var(--color-warning)', backgroundColor: 'var(--color-warning-bg)', border: '1px solid var(--color-warning)' }}>
                                                                            {t('editor.weightWarning')} {totalWeight}{t('editor.weightWarningEnd')}
                                                                        </div>
                                                                    )}
                                                                    {offers.map((o, oIdx, list) => (
                                                                        <div key={oIdx} className="flex gap-2 mb-2">
                                                                            <select
                                                                                value={o.id}
                                                                                onChange={e => updateSchemaItem(idx, 'offers', oIdx, 'id', parseInt(e.target.value))}
                                                                                className="form-select text-sm"
                                                                            >
                                                                                <option value="">{t('editor.offerInfo')}</option>
                                                                                {allOffers.map(ao => <option key={ao.id} value={ao.id}>{ao.name}</option>)}
                                                                            </select>
                                                                            <div className="flex items-center gap-1">
                                                                                <input
                                                                                    type="number"
                                                                                    value={list.length === 1 ? 100 : o.weight}
                                                                                    disabled={list.length === 1}
                                                                                    onChange={e => updateSchemaItem(idx, 'offers', oIdx, 'weight', parseInt(e.target.value))}
                                                                                    className="w-16 text-center rounded-lg px-1 py-1 text-sm"
                                                                                    style={{
                                                                                        backgroundColor: list.length === 1 ? 'var(--color-bg-soft)' : 'var(--color-bg-card)',
                                                                                        border: `1px solid ${isOverWeight ? 'var(--color-warning)' : 'var(--color-border)'}`,
                                                                                        color: list.length === 1 ? 'var(--color-text-muted)' : 'var(--color-text-primary)'
                                                                                    }}
                                                                                    title={t('editor.weight')}
                                                                                    max="100"
                                                                                    min="1"
                                                                                />
                                                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>%</span>
                                                                            </div>
                                                                            <button onClick={() => removeSchemaItem(idx, 'offers', oIdx)} className="action-btn text-red">
                                                                                <X className="w-4 h-4" />
                                                                            </button>
                                                                        </div>
                                                                    ))}
                                                                </>
                                                            );
                                                        })()}
                                                    </div>
                                                )}

                                                {/* Filters */}
                                                {stream.type !== 'fallback' && (
                                                    <div>
                                                        <div className="flex justify-between mb-2">
                                                            <span className="text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>{t('editor.filters')}</span>
                                                            <button onClick={() => openFilterModal(idx)} className="text-xs" style={{ color: 'var(--color-primary)' }}>{t('editor.addFilter')}</button>
                                                        </div>
                                                        {stream.filters && stream.filters.length > 0 ? (
                                                            <div className="space-y-1">
                                                                {stream.filters.map((f, fIdx) => (
                                                                    <div key={fIdx} className="flex rounded-lg text-sm overflow-hidden" style={{ border: '1px solid var(--color-border)' }}>
                                                                        <div className="px-2 py-1 font-semibold" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-primary)' }}>{f.name}</div>
                                                                        <div className="px-2 py-1 font-bold" style={{ color: f.mode === 'include' ? 'var(--color-success)' : 'var(--color-danger)' }}>
                                                                            {f.mode === 'include' ? '✓' : '✗'}
                                                                        </div>
                                                                        <div className="flex-1 px-2 py-1 truncate" style={{ color: 'var(--color-text-secondary)' }}>{f.payload.join(', ')}</div>
                                                                        <button onClick={() => removeFilter(idx, fIdx)} className="px-2" style={{ color: 'var(--color-danger)' }}>
                                                                            <X className="w-3 h-3" />
                                                                        </button>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <div className="text-xs rounded-lg p-3 text-center border-2 border-dashed" style={{ color: 'var(--color-text-muted)', backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                                                {t('editor.noFilters')}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Filter Modal */}
            {filterModal.open && (
                <div className="modal-overlay">
                    <div className="modal-content" style={{ maxWidth: '600px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('editor.addFilter')}</h3>
                            <button onClick={() => setFilterModal({ open: false, streamIdx: null })} className="action-btn">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-4">
                            <div>
                                <label className="form-label">{t('editor.filterType')}</label>
                                <select
                                    value={newFilter.name}
                                    onChange={e => setNewFilter({ ...newFilter, name: e.target.value })}
                                    className="form-select"
                                >
                                    {availableFilters.map(f => (
                                        <option key={f.name} value={f.name}>{f.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('editor.filterMode')}</label>
                                <div className="flex gap-4">
                                    <label className="flex items-center cursor-pointer">
                                        <input
                                            type="radio"
                                            checked={newFilter.mode === 'include'}
                                            onChange={() => setNewFilter({ ...newFilter, mode: 'include' })}
                                            className="mr-2"
                                        />
                                        <span className="font-medium" style={{ color: 'var(--color-success)' }}>{t('editor.allow')}</span>
                                    </label>
                                    <label className="flex items-center cursor-pointer">
                                        <input
                                            type="radio"
                                            checked={newFilter.mode === 'exclude'}
                                            onChange={() => setNewFilter({ ...newFilter, mode: 'exclude' })}
                                            className="mr-2"
                                        />
                                        <span className="font-medium" style={{ color: 'var(--color-danger)' }}>{t('editor.deny')}</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('editor.values')}</label>
                                {newFilter.name === 'Country' ? (
                                    <GeoSelector
                                        value={newFilter.payload}
                                        onChange={payload => setNewFilter({ ...newFilter, payload })}
                                        placeholder={t('editor.geoPlaceholder')}
                                    />
                                ) : (
                                    <input
                                        type="text"
                                        value={newFilter.payload}
                                        onChange={e => setNewFilter({ ...newFilter, payload: e.target.value })}
                                        className="form-input"
                                        placeholder={availableFilters.find(f => f.name === newFilter.name)?.placeholder}
                                    />
                                )}
                            </div>
                        </div>

                        <div className="modal-footer">
                            <button onClick={() => setFilterModal({ open: false, streamIdx: null })} className="btn btn-secondary">{t('common.cancel')}</button>
                            <button onClick={addFilter} disabled={!newFilter.payload?.trim()} className="btn btn-primary">{t('common.add')}</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Click Log Modal */}
            {showLogModal && (
                <div className="modal-overlay">
                    <div className="modal-content" style={{ maxWidth: '800px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('editor.clickLog')}</h3>
                            <button onClick={() => setShowLogModal(false)} className="action-btn">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="overflow-y-auto" style={{ maxHeight: '60vh' }}>
                            {clickLogs.length === 0 ? (
                                <div className="text-center py-10" style={{ color: 'var(--color-text-muted)' }}>{t('editor.noLogs')}</div>
                            ) : (
                                <div className="space-y-4">
                                    {clickLogs.map((log, idx) => (
                                        <div key={idx} className="rounded-2xl p-4 text-xs font-mono" style={{ border: '1px solid var(--color-border)' }}>
                                            <div className="mb-2" style={{ color: 'var(--color-text-secondary)' }}>{log.created_at}</div>
                                            <pre className="whitespace-pre-wrap" style={{ color: 'var(--color-text-primary)' }}>{log.log_text}</pre>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Update Cost Modal */}
            {showCostModal && (
                <div className="modal-overlay">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h3 className="modal-title">{t('campaigns.updateCosts')}</h3>
                            <button onClick={() => setShowCostModal(false)} className="action-btn">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-4">
                            <div>
                                <label className="form-label">{t('editor.timePeriod')}</label>
                                <div className="flex gap-2">
                                    <input type="date" className="form-input" style={{ flex: 1 }} />
                                    <input type="time" className="form-input" style={{ width: '100px' }} />
                                    <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                                    <input type="time" className="form-input" style={{ width: '100px' }} />
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('editor.costAmount')}</label>
                                <div className="flex gap-2">
                                    <input type="number" step="0.01" className="form-input" placeholder="0.00" />
                                    <select className="form-select" style={{ width: 'auto' }}>
                                        <option>USD</option>
                                        <option>EUR</option>
                                        <option>RUB</option>
                                    </select>
                                </div>
                            </div>
                            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                <input type="checkbox" className="rounded" />
                                {t('editor.onlyUniqueClicks')}
                            </label>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowCostModal(false)} className="btn btn-secondary">{t('common.cancel')}</button>
                            <button className="btn btn-primary">{t('common.update')}</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Clear Stats Modal */}
            {showClearModal && (
                <div className="modal-overlay">
                    <div className="modal-content">
                        <div className="flex items-center gap-3" style={{ color: 'var(--color-danger)' }}>
                            <AlertCircle className="w-8 h-8" />
                            <div>
                                <h3 className="modal-title" style={{ color: 'var(--color-text-primary)' }}>{t('common.clearStats')}</h3>
                                <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('campaigns.clearStatsWarning')}</p>
                            </div>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowClearModal(false)} className="btn btn-secondary">{t('common.cancel')}</button>
                            <button onClick={clearStats} className="btn btn-danger">{t('common.clear')}</button>
                        </div>
                    </div>
                </div>
            )}

            {showReports && campaignId && (
                <CampaignReports
                    campaignId={campaignId}
                    campaignName={formData.name}
                    onClose={() => setShowReports(false)}
                />
            )}
        </>
    );
};

export default CampaignEditor;
