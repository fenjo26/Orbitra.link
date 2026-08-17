import React, { useState, useEffect, useMemo, useRef } from 'react';
import GeoSelector from './GeoSelector';
import HelpTooltip from './HelpTooltip';
import { ArrowLeft, Plus, Check, Link, Copy, Settings, Trash2, ChevronDown, ChevronUp, AlertCircle, X, Shield, Globe, MousePointerClick, TrendingUp, Activity, BarChart2, BarChart3, DollarSign, RefreshCw, FileText, MoreVertical, Play, Code, Edit3, Eye, Info } from 'lucide-react';
import CampaignReports from './CampaignReports';
import ConversionsLog from './ConversionsLog';
import LandingEditor from './LandingEditor';
import OfferEditor from './OfferEditor';
import EntitySelectorModal from './EntitySelectorModal';
import GroupsModal from './GroupsModal';
import TrafficSourceEditor from './TrafficSourceEditor';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost, invalidateCache } from '../utils/apiCache';
import { getStayInEditorAfterSave } from '../utils/editorPreferences';
import { buildSnippet, COUNTDOWN_THEMES, EXIT_BUTTON_COLORS, METHOD_INSTALL_HINTS } from '../utils/integrationSnippets';
import ProxyInput from './common/ProxyInput';
import PixelPicker from './common/PixelPicker';

const CAMPAIGN_SUB_ID_KEYS = Array.from({ length: 30 }, (_, index) => `sub_id_${index + 1}`);

/**
 * Keitaro-style split button: the main part opens the entity picker, the
 * chevron opens a one-item menu to create a new landing/offer without leaving
 * the stream. The transparent fixed layer behind the menu closes it on any
 * outside click.
 */
const AddDropdownButton = ({ label, createLabel, onMain, onCreate }) => {
    const [open, setOpen] = useState(false);
    return (
        <div className="relative inline-block">
            <div className="flex">
                <button
                    type="button"
                    onClick={onMain}
                    className="btn btn-secondary btn-sm rounded-r-none flex items-center gap-1.5"
                >
                    <Plus className="w-3.5 h-3.5" />
                    {label}
                </button>
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="btn btn-secondary btn-sm rounded-l-none border-l-0 px-1.5"
                    title={createLabel}
                >
                    <ChevronDown className="w-3.5 h-3.5" />
                </button>
            </div>
            {open && (
                <>
                    <div className="fixed inset-0" style={{ zIndex: 40 }} onClick={() => setOpen(false)} />
                    <div
                        className="absolute right-0 mt-1 rounded-xl py-1 shadow-lg min-w-[200px]"
                        style={{ zIndex: 41, backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}
                    >
                        <button
                            type="button"
                            className="w-full text-left px-3 py-2 text-sm flex items-center gap-2 transition-colors hover:bg-[var(--color-bg-soft)]"
                            style={{ color: 'var(--color-text-primary)' }}
                            onClick={() => { setOpen(false); onCreate(); }}
                        >
                            <Plus className="w-3.5 h-3.5" />
                            {createLabel}
                        </button>
                    </div>
                </>
            )}
        </div>
    );
};

// Generate random alias like Keitaro (8 chars: a-z0-9)
const generateAlias = () => {    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < 8; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
};

// Generate random API token (32 hex chars)
const generateToken = () => {
    const chars = '0123456789abcdef';
    let result = '';
    for (let i = 0; i < 32; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
};

// Live previews for the Tracking tab's visual widgets. They read the same
// option values (and COUNTDOWN_THEMES entries) as utils/integrationSnippets.js,
// so what the operator previews is exactly what the generated snippet ships.
const CountdownPreview = ({ hours, minutes, headerText, buttonText, theme, expireAction }) => {
    const themeDef = COUNTDOWN_THEMES[theme] || COUNTDOWN_THEMES.purple;
    const total = Math.max(60, (parseInt(hours, 10) || 0) * 3600 + (parseInt(minutes, 10) || 0) * 60);
    const [left, setLeft] = useState(total);

    useEffect(() => {
        setLeft(total);
        const iv = setInterval(() => setLeft(l => (l > 0 ? l - 1 : 0)), 1000);
        return () => clearInterval(iv);
    }, [total]);

    const hh = String(Math.floor(left / 3600)).padStart(2, '0');
    const mm = String(Math.floor((left % 3600) / 60)).padStart(2, '0');
    const ss = String(left % 60).padStart(2, '0');

    return (
        <div style={{ background: themeDef.gradient, color: '#fff', borderRadius: 12, padding: 20, textAlign: 'center', maxWidth: 400, margin: '0 auto', fontFamily: 'sans-serif', boxShadow: '0 10px 40px rgba(0,0,0,0.2)' }}>
            {left <= 0 ? (
                <h2 style={{ margin: 0, fontSize: 22, letterSpacing: 2 }}>
                    {expireAction === 'redirect' ? '→ REDIRECT' : 'OFFER EXPIRED'}
                </h2>
            ) : (
                <>
                    <div style={{ fontSize: 13, textTransform: 'uppercase', letterSpacing: 2, marginBottom: 10 }}>
                        {headerText || 'OFFER EXPIRES IN'}
                    </div>
                    <div style={{ fontSize: 42, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                        {hh}:{mm}:{ss}
                    </div>
                    <span style={{ display: 'inline-block', marginTop: 16, padding: '12px 36px', background: themeDef.cta, color: '#fff', borderRadius: 8, fontWeight: 600, fontSize: 15 }}>
                        {buttonText || 'GET SPECIAL OFFER'}
                    </span>
                </>
            )}
        </div>
    );
};

const ExitIntentPreview = ({ heading, text, buttonText, buttonColor }) => (
    <div style={{ background: 'rgba(0,0,0,0.7)', borderRadius: 12, padding: 28, display: 'flex', justifyContent: 'center' }}>
        <div style={{ background: '#fff', padding: 28, borderRadius: 16, maxWidth: 380, textAlign: 'center', position: 'relative', fontFamily: 'sans-serif', color: '#111' }}>
            <span style={{ position: 'absolute', top: 8, right: 14, fontSize: 22, color: '#999' }}>&times;</span>
            <h2 style={{ margin: '0 0 12px', fontSize: 22 }}>{heading || 'Wait! Special Offer!'}</h2>
            <p style={{ fontSize: 14, color: '#666', margin: '0 0 8px' }}>{text || "Don't miss this exclusive deal just for you!"}</p>
            <span style={{ display: 'inline-block', marginTop: 12, padding: '13px 32px', background: buttonColor, color: '#fff', borderRadius: 8, fontWeight: 600, fontSize: 15 }}>
                {buttonText || 'CLAIM 50% OFF'}
            </span>
        </div>
    </div>
);

const CampaignEditor = ({ campaignId, onClose }) => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('general');
    const [loading, setLoading] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);
    const [copySuccess, setCopySuccess] = useState(false);

    // Modal states
    const [showLogModal, setShowLogModal] = useState(false);
    const [showCostModal, setShowCostModal] = useState(false);
    const [showClearModal, setShowClearModal] = useState(false);
    const [showReportsMenu, setShowReportsMenu] = useState(false);
    const [showReports, setShowReports] = useState(false);
    const [showConversionsLog, setShowConversionsLog] = useState(false);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [showSourceEditor, setShowSourceEditor] = useState(false);

    // Tracking tab: chosen method + per-method options the snippets are built from
    const [trackingMethod, setTrackingMethod] = useState('kclient_js');
    const [snippetCopied, setSnippetCopied] = useState(false);
    const [showWidgetPreview, setShowWidgetPreview] = useState(false);
    const [trackOpts, setTrackOpts] = useState({
        // Countdown Timer
        hours: 2,
        minutes: 30,
        headerText: '',
        buttonText: '',
        offerUrl: '',
        theme: 'purple',            // purple | emerald | fire | dark
        expireAction: 'expired',    // expired | redirect
        expireUrl: '',
        // Back Button Trap
        trapUrl: '',
        logClick: true,
        delay: 0,
        // Exit Intent Popup
        heading: '',
        text: '',
        popupButtonText: '',
        buttonColor: '#22c55e',
        showDelay: 0,
        closeOnBackdrop: true,
        // Banner blocks
        width: 300,
        height: 250,
        // KClient
        base64: false,
        sendParams: true,           // PHP only: kclient.js always passes page params
        phpMode: 'redirect',        // redirect | show_html | get_link
        // Tracking Pixel
        pixelType: 'click',         // click | conversion
        convStatus: 'lead',
        payout: '',
        subidParam: '{subid}',
    });

    // Cost Sync (campaign's Integrations tab): connections + match diagnostics
    const [costConns, setCostConns] = useState([]);
    const [costMatch, setCostMatch] = useState(null);
    const [syncingConnId, setSyncingConnId] = useState(null);
    const [syncResult, setSyncResult] = useState(null);
    const [showAddCostConnModal, setShowAddCostConnModal] = useState(false);
    const [costConnForm, setCostConnForm] = useState({
        engine: 'facebook',
        name: '',
        account_id: '',
        access_token: '',
        proxy_url: ''
    });
    const [savingCostConn, setSavingCostConn] = useState(false);

    // Campaign list — the "Send to campaign" stream action picks a target here.
    const [allCampaigns, setAllCampaigns] = useState([]);
    const [showTrafficSimModal, setShowTrafficSimModal] = useState(false);
    const [trafficSimResult, setTrafficSimResult] = useState(null);
    const [trafficSimLoading, setTrafficSimLoading] = useState(false);

    // Traffic simulation form state
    const [trafficSimForm, setTrafficSimForm] = useState({
        ip: '127.0.0.1',
        user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        country: 'US',
        device_type: 'desktop',
        language: 'en',
        asn: '',
        isp: '',
        js_executed: true,
        webdriver: false
    });

    // Pixel states
    const [pixels, setPixels] = useState([]);
    const [editingPixel, setEditingPixel] = useState(null);
    const [pixelForm, setPixelForm] = useState({ type: '', pixel_id: '', token: '', events: 'PageView,Lead', event_source_url: '', is_active: 1, mapping: {}, test_event_code: '', proxy_url: '' });
    const [capiMeta, setCapiMeta] = useState({ default_mapping: {}, available_events: [] });
    const [capiTest, setCapiTest] = useState(null);
    const [capiTesting, setCapiTesting] = useState(false);
    const [pixelProfiles, setPixelProfiles] = useState([]);
    const [pixelProfileAttaching, setPixelProfileAttaching] = useState(false);
    const [pixelProfileMessage, setPixelProfileMessage] = useState(null);

    const emptyPixelForm = { type: '', pixel_id: '', token: '', events: 'PageView,Lead', event_source_url: '', is_active: 1, mapping: {}, test_event_code: '', proxy_url: '' };

    // Log data
    const [clickLogs, setClickLogs] = useState([]);

    // Select options state
    const [groups, setGroups] = useState([]);
    const [sources, setSources] = useState([]);
    const [domains, setDomains] = useState([]);
    const [allOffers, setAllOffers] = useState([]);
    const [allLandings, setAllLandings] = useState([]);
    // Creating a landing or offer without leaving the stream you are wiring up.
    // Going to another page and back used to mean losing the unsaved campaign.
    // Landing groups, the campaign list, the postback key and the offer-link
    // hint state used to live here too, purely to feed a second copy of the
    // landing form; LandingEditor fetches what it needs itself.
    const [quickCreate, setQuickCreate] = useState(null);
    // The Keitaro-style picker a stream's "Add ..." split button opens.
    const [pickerState, setPickerState] = useState({ open: false, streamIdx: null, type: null });

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
        rotation_type: 'position',
        token: generateToken(), // Generate token immediately for new campaigns
        notes: '',
        catch_404_stream_id: '',
        streams: [],
        postbacks: [],
        parameters: {},
        challenge_type: 'none',
        challenge_custom_code: ''
    });
    const activeCampaignId = formData.id || campaignId;

    // Stream Expansion state
    const [expandedStream, setExpandedStream] = useState(null);

    // Unsaved-change tracking: baselineRef holds the serialized formData the
    // editor started from (loaded campaign or the new-campaign template);
    // isDirty is any deviation from it at close time.
    const [isDirty, setIsDirty] = useState(false);
    const baselineRef = useRef(null);
    // Latest-render closures for the mount-only history effect below.
    const latestRef = useRef({});
    latestRef.current = { onClose, t, isDirty };
    // Set when the editor closes through its own UI (Back / ✕ / Save) rather
    // than the browser Back button, which consumes the history entry itself.
    const uiCloseRef = useRef(false);

    // Recompute the dirty flag on every form change once a baseline exists.
    useEffect(() => {
        if (baselineRef.current === null) return;
        setIsDirty(JSON.stringify(formData) !== baselineRef.current);
    }, [formData]);

    // Warn before the tab itself is closed or reloaded with unsaved edits.
    useEffect(() => {
        if (!isDirty) return;
        const warn = (e) => { e.preventDefault(); e.returnValue = ''; };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [isDirty]);

    // The editor is a plain SPA tab with no history integration, so a browser
    // Back used to leave the tracker entirely and burn unsaved edits. Push one
    // history entry per editor session: browser Back then closes the editor
    // (after a dirty check), while a UI close pops the entry again so the
    // history stack stays clean. The state-flag dedupe keeps StrictMode's
    // double-invoked effects from stacking entries.
    useEffect(() => {
        const HISTORY_KEY = 'orbitraCampaignEditor';
        if (!window.history.state?.[HISTORY_KEY]) {
            window.history.pushState({ ...window.history.state, [HISTORY_KEY]: true }, '');
        }

        const onPopState = () => {
            const { onClose: close, t: translate, isDirty: dirty } = latestRef.current;
            if (dirty && !window.confirm(translate('editor.unsavedChanges'))) {
                // User chose to stay: restore the entry Back just consumed.
                window.history.pushState({ ...window.history.state, [HISTORY_KEY]: true }, '');
                return;
            }
            close(true);
        };

        window.addEventListener('popstate', onPopState);
        return () => {
            window.removeEventListener('popstate', onPopState);
            if (uiCloseRef.current && window.history.state?.[HISTORY_KEY]) {
                window.history.back();
            }
        };
    }, []);

    // All editor exits funnel through here so the pushed history entry is
    // consumed exactly once (browser Back consumes it by itself).
    const closeEditor = (saved) => {
        uiCloseRef.current = true;
        if (onClose) onClose(saved);
    };

    const requestClose = () => {
        if (isDirty && !window.confirm(t('editor.unsavedChanges'))) return;
        closeEditor(true);
    };

    // Cost models
    const costModels = [
        { value: 'CPC', label: t('costModels.cpc') },
        { value: 'CPuC', label: t('costModels.cpuc') },
        { value: 'CPM', label: t('costModels.cpm') },
        { value: 'CPA', label: t('costModels.cpa') },
        { value: 'CPS', label: t('costModels.cps') },
        { value: 'RevShare', label: t('costModels.revShare') }
    ];

    const activeSource = sources.find(source => source.id == formData.source_id);
    const displayParameters = useMemo(() => {
        const standardParameters = [
            { key: 'utm_placement', label: t('parameters.placement', 'Placement (utm_placement)') },
            { key: 'ad_id', label: t('parameters.adId', 'Ad ID') },
            { key: 'adset_id', label: t('parameters.adsetId', 'Adset ID') },
            { key: 'campaign_id', label: t('parameters.campaignId', 'Campaign ID') },
            { key: 'ad_name', label: t('parameters.adName', 'Ad name') },
            { key: 'adset_name', label: t('parameters.adsetName', 'Adset name') },
            { key: 'campaign_name', label: t('parameters.campaignName', 'Campaign name') },
            { key: 'source', label: t('parameters.source', 'Source') },
            { key: 'site', label: t('parameters.site', 'Site') },
            { key: 'keyword', label: t('parameters.keyword', 'Keyword') },
            { key: 'cost', label: t('parameters.cost', 'Cost') },
            { key: 'currency', label: t('parameters.currency', 'Currency') },
            { key: 'external_id', label: t('parameters.externalId', 'External ID') },
            { key: 'creative_id', label: t('parameters.creativeId', 'Creative ID') },
            { key: 'ad_campaign_id', label: t('parameters.adCampaignId', 'Ad campaign ID') },
            { key: 'ttclid', label: 'ttclid (TikTok)' },
            { key: 'utm_source', label: 'utm_source' },
            { key: 'utm_medium', label: 'utm_medium' },
            { key: 'utm_campaign', label: 'utm_campaign' },
            { key: 'utm_content', label: 'utm_content' },
            { key: 'utm_term', label: 'utm_term' },
        ];
        const standardByKey = new Map(standardParameters.map(parameter => [parameter.key, parameter]));
        const list = [];
        const seenKeys = new Set();

        if (activeSource && Array.isArray(activeSource.parameters)) {
            activeSource.parameters.forEach(parameter => {
                const key = String(parameter?.param || '').trim();
                if (!key || seenKeys.has(key) || CAMPAIGN_SUB_ID_KEYS.includes(key)) return;
                seenKeys.add(key);
                list.push({
                    key,
                    label: standardByKey.get(key)?.label || parameter.alias || key,
                    isFromSource: true,
                    isCustom: false,
                });
            });
        }

        Object.keys(formData.parameters || {}).forEach(key => {
            if (seenKeys.has(key) || CAMPAIGN_SUB_ID_KEYS.includes(key)) return;
            const standard = standardByKey.get(key);
            seenKeys.add(key);
            list.push({
                key,
                label: standard?.label || key,
                isFromSource: false,
                isCustom: !standard,
            });
        });

        standardParameters.forEach(parameter => {
            if (seenKeys.has(parameter.key)) return;
            seenKeys.add(parameter.key);
            list.push({ ...parameter, isFromSource: false, isCustom: false });
        });

        return list;
    }, [activeSource, formData.parameters, t]);

    const addCustomParameter = () => {
        const rawKey = window.prompt(t('parameters.enterParamKey', 'Enter parameter key (e.g. utm_placement, placement, custom_id):'));
        if (!rawKey) return;
        const cleanKey = rawKey.trim().replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 100);
        if (!cleanKey) return;

        setFormData(prev => {
            if (Object.prototype.hasOwnProperty.call(prev.parameters || {}, cleanKey)) return prev;
            return {
                ...prev,
                parameters: { ...(prev.parameters || {}), [cleanKey]: '' }
            };
        });
    };

    const clearParameter = (key) => {
        setFormData(prev => {
            const parameters = { ...(prev.parameters || {}) };
            delete parameters[key];
            return { ...prev, parameters };
        });
    };

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
        // Non-empty parameter values (macros like {{ad.id}}) become the query
        // string the user pastes into the ad network — Keitaro's Campaign URL.
        const pairs = Object.entries(formData.parameters || {})
            .filter(([, v]) => String(v ?? '').trim() !== '')
            .map(([k, v]) => {
                // Ad networks substitute {{ad.id}}, {keyword}, __CID__ etc. in the
                // URL they are handed — encoded braces (%7B%7B) match no macro
                // table, so every value would arrive empty. Keep braces and colons
                // raw (both legal in a query string); the rest stays encoded.
                const safeVal = encodeURIComponent(String(v).trim())
                    .replace(/%7B/gi, '{')
                    .replace(/%7D/gi, '}')
                    .replace(/%3A/gi, ':');
                return `${encodeURIComponent(k)}=${safeVal}`;
            });
        return pairs.length ? `${baseUrl}/${formData.alias}?${pairs.join('&')}` : `${baseUrl}/${formData.alias}`;
    };

    // Map a traffic source's [{alias, param, macro}] into the campaign's
    // {paramKey: macro} parameter map used by the "Параметры" tab and the URL.
    const sourceToParameters = (source) => {
        const params = {};
        if (source && Array.isArray(source.parameters)) {
            source.parameters.forEach(p => {
                if (p && p.param && String(p.macro ?? '').trim() !== '') {
                    params[p.param] = String(p.macro).trim();
                }
            });
        }
        return params;
    };

    // Keitaro parity: switching the traffic source REPLACES the campaign's
    // parameter set with the parameters of the newly selected source.
    const handleSourceChange = (sourceId) => {
        const source = sources.find(s => s.id == sourceId);
        setFormData(prev => ({
            ...prev,
            source_id: sourceId,
            parameters: source ? sourceToParameters(source) : {}
        }));
    };

    // Called after a source created from the editor's "+" button is saved:
    // refresh the list and select the new source with its parameters applied.
    const handleSourceCreated = async (saved, shouldClose = true) => {
        if (shouldClose) setShowSourceEditor(false);
        invalidateCache('traffic_sources');
        try {
            const res = await cachedGet('traffic_sources', {}, 300000);
            if (res.data.status === 'success') {
                setSources(res.data.data);
                const created = res.data.data.find(s => s.id == (saved?.id ?? -1));
                if (created) {
                    setFormData(prev => ({
                        ...prev,
                        source_id: String(created.id),
                        parameters: sourceToParameters(created)
                    }));
                }
            }
        } catch (err) {
            console.error(err);
        }
    };

    // The campaign context every integration snippet is built from — the
    // Tracking tab's single source of truth is utils/integrationSnippets.js.
    const trackerUrl = window.location.origin;
    const snippetCtx = () => ({
        trackerUrl,
        campaign: {
            id: formData.id || campaignId || '',
            alias: formData.alias || '',
            token: formData.token || '',
            url: getCampaignUrl(),
        }
    });

    const copyIntegrationSnippet = async (text) => {
        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); copied = true; } catch (e) { copied = false; }
        }
        if (!copied) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                copied = document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch (e) { copied = false; }
        }
        if (!copied) alert(t('common.error'));
    };

    // Copy URL to clipboard with fallback for non-secure contexts / older browsers
    const copyUrl = async () => {
        const url = getCampaignUrl();
        let copied = false;

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(url);
                copied = true;
            } catch (e) {
                copied = false;
            }
        }

        if (!copied) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                copied = document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch (e) {
                copied = false;
            }
        }

        if (copied) {
            setCopySuccess(true);
            setTimeout(() => setCopySuccess(false), 1500);
        } else {
            alert(t('common.error'));
        }
    };

    // Fetch click logs
    const fetchClickLogs = async () => {
        if (!activeCampaignId) return;
        try {
            const { data } = await cachedGet('campaign_logs', { campaign_id: activeCampaignId });
            if (data.status === 'success') {
                setClickLogs(data.data);
            }
        } catch (e) {
            console.error('Error fetching logs:', e);
        }
    };

    useEffect(() => {
        const fetchDeps = async () => {
            try {
                // Cache dropdown data for 5 minutes - rarely changes
                const TTL = 300000;
                const [gRes, sRes, dRes, oRes, lRes, cRes] = await Promise.all([
                    cachedGet('campaign_groups', {}, TTL),
                    cachedGet('traffic_sources', {}, TTL),
                    cachedGet('domains', {}, TTL),
                    cachedGet('all_offers', {}, TTL),
                    cachedGet('landings_simple', {}, TTL), // Use optimized endpoint without heavy joins
                    cachedGet('campaigns', {}, TTL)
                ]);
                if (gRes.data.status === 'success') setGroups(gRes.data.data);
                if (sRes.data.status === 'success') setSources(sRes.data.data);
                if (dRes.data.status === 'success') setDomains(dRes.data.data);
                if (oRes.data.status === 'success') setAllOffers(oRes.data.data);
                if (lRes.data.status === 'success') setAllLandings(lRes.data.data);
                if (cRes.data.status === 'success') setAllCampaigns(cRes.data.data);
            } catch (err) {
                console.error(err);
            }
        };
        fetchDeps();

        if (campaignId) {
            setLoading(true);
            cachedGet('get_campaign', { id: campaignId })
                .then(res => {
                    if (res.data.status === 'success') {
                        const data = res.data.data;
                        const loaded = {
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
                            rotation_type: data.rotation_type || 'position',
                            token: data.token || '',
                            notes: data.notes || '',
                            catch_404_stream_id: data.catch_404_stream_id || '',
                            streams: (data.streams || []).map(s => ({
                                ...s,
                                // Persisted stream filters live in filters_json in DB; editor works with stream.filters array.
                                filters: (() => {
                                    try {
                                        if (Array.isArray(s.filters)) return s.filters;
                                        if (!s.filters_json) return [];
                                        const parsed = JSON.parse(s.filters_json);
                                        return Array.isArray(parsed) ? parsed : [];
                                    } catch (e) {
                                        return [];
                                    }
                                })(),
                                schema_custom: s.schema_custom_json ? JSON.parse(s.schema_custom_json) : { landings: [], offers: [] }
                            })),
                            postbacks: data.postbacks || [],
                            parameters: data.parameters || {},
                            challenge_type: data.challenge_type || 'none',
                            challenge_custom_code: data.challenge_custom_code || ''
                        };
                        setFormData(loaded);
                        // The loaded campaign is the clean baseline for the dirty check.
                        baselineRef.current = JSON.stringify(loaded);
                    }
                })
                .finally(() => setLoading(false));
        } else {
            // New campaign: the template the form starts from is the baseline.
            // (Deps stay [campaignId] on purpose: tracking formData here would
            // reset the baseline on every keystroke and break dirty tracking.)
            baselineRef.current = JSON.stringify(formData);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [campaignId]);

    // Prefill URL parameters from the campaign's traffic source when the editor
    // opens on a campaign that never had any (saved before parameters persisted).
    // Only fires while the map is empty, so manual edits are never overwritten.
    useEffect(() => {
        if (!formData.source_id || !sources.length) return;
        if (formData.parameters && Object.keys(formData.parameters).length > 0) return;
        const source = sources.find(s => s.id == formData.source_id);
        if (!source) return;
        const prefilled = sourceToParameters(source);
        if (Object.keys(prefilled).length === 0) return;
        setFormData(prev => {
            const next = { ...prev, parameters: prefilled };
            // Prefill is normalization rather than a user edit — count it as clean.
            baselineRef.current = JSON.stringify(next);
            return next;
        });
    }, [sources, formData.source_id]);

    // Cost Sync (campaign's Integrations tab): the spend connections that can
    // feed this campaign + whether its clicks carry the IDs they match on.
    useEffect(() => {
        if (!activeCampaignId) return;
        axios.get('/api.php?action=aggregator_connections')
            .then(res => {
                if (res.data.status === 'success') {
                    setCostConns((res.data.data || []).filter(c => ['facebook', 'google_ads', 'tiktok'].includes(c.engine)));
                }
            })
            .catch(() => {});
        cachedGet('campaign_cost_match', { campaign_id: activeCampaignId }, 60000)
            .then(({ data }) => { if (data.status === 'success') setCostMatch(data.data); })
            .catch(() => {});
    }, [activeCampaignId]);

    // Manual 7-day spend pull for one connection — the same action the
    // Integrations page's "Update spend" button runs.
    const syncCostConnection = async (connId) => {
        setSyncingConnId(connId);
        setSyncResult(null);
        try {
            const dateFrom = new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);
            const dateTo = new Date().toISOString().slice(0, 10);
            const res = await axios.post('/api.php?action=aggregator_sync', { connection_id: connId, date_from: dateFrom, date_to: dateTo });
            if (res.data.status === 'success') {
                const d = res.data.data || {};
                setSyncResult(`✓ ${t('costSync.synced', 'Synced')}: fetched ${d.fetched ?? 0}, matched ${d.matched ?? 0}, new ${d.new ?? 0}`);
                invalidateCache('campaign_cost_match');
                cachedGet('campaign_cost_match', { campaign_id: activeCampaignId }, 60000)
                    .then(({ data }) => { if (data.status === 'success') setCostMatch(data.data); })
                    .catch(() => {});
            } else {
                setSyncResult(`⚠ ${res.data.message || t('common.error')}`);
            }
        } catch (err) {
            setSyncResult(`⚠ ${t('common.networkError')}`);
        } finally {
            setSyncingConnId(null);
        }
    };

    const handleSaveCostConnection = async (e) => {
        if (e && e.preventDefault) e.preventDefault();
        setSavingCostConn(true);
        try {
            const res = await axios.post('/api.php?action=save_aggregator_connection', costConnForm);
            if (res.data.status === 'success') {
                setShowAddCostConnModal(false);
                setCostConnForm({ engine: 'facebook', name: '', account_id: '', access_token: '', proxy_url: '' });
                const cRes = await axios.get('/api.php?action=aggregator_connections');
                if (cRes.data.status === 'success') {
                    setCostConns((cRes.data.data || []).filter(c => ['facebook', 'google_ads', 'tiktok'].includes(c.engine)));
                }
            } else {
                alert(res.data.message || 'Error saving cost connection');
            }
        } catch (err) {
            alert('Error saving cost connection');
        } finally {
            setSavingCostConn(false);
        }
    };

    const fetchPixels = async () => {
        if (!activeCampaignId) return;
        try {
            const { data } = await cachedGet('campaign_pixels', { campaign_id: activeCampaignId });
            if (data.status === 'success') setPixels(data.data || []);
        } catch (err) { console.error(err); }
    };

    useEffect(() => { if (activeCampaignId) fetchPixels(); }, [activeCampaignId]);

    useEffect(() => {
        cachedGet('pixel_profiles_list', {}, 60000)
            .then(({ data }) => { if (data.status === 'success') setPixelProfiles(data.data || []); })
            .catch(() => setPixelProfiles([]));
    }, []);

    const groupedPixelProfiles = useMemo(() => pixelProfiles.reduce((groups, profile) => {
        const niche = profile.niche || 'General';
        if (!groups[niche]) groups[niche] = [];
        groups[niche].push(profile);
        return groups;
    }, {}), [pixelProfiles]);

    const selectedPixelProfileId = pixels.find(pixel => pixel.pixel_profile_id)?.pixel_profile_id || '';

    const handleSelectPixelProfile = async (profileId) => {
        if (!activeCampaignId || pixelProfileAttaching) return;
        setPixelProfileAttaching(true);
        setPixelProfileMessage(null);
        try {
            const { data } = await cachedPost('attach_pixel_profile', {
                campaign_id: activeCampaignId,
                pixel_profile_id: profileId || null,
            });
            if (data.status === 'success') {
                setPixelProfileMessage({ type: 'success', text: profileId ? t('pixelVault.attached') : t('pixelVault.detached') });
                await fetchPixels();
            } else {
                setPixelProfileMessage({ type: 'error', text: data.message || t('common.error') });
            }
        } catch (err) {
            setPixelProfileMessage({ type: 'error', text: err.response?.data?.message || err.message });
        } finally {
            setPixelProfileAttaching(false);
        }
    };

    // Status→event map and the Meta event list come from the backend so the two
    // cannot drift: FacebookConversions.php is the single source of truth.
    useEffect(() => {
        cachedGet('facebook_capi_meta')
            .then(({ data }) => { if (data.status === 'success') setCapiMeta(data.data); })
            .catch(() => { /* mapping UI falls back to the defaults below */ });
    }, []);

    const openPixelForEdit = (px) => {
        let mapping = {};
        if (px.mapping_json) {
            try { mapping = JSON.parse(px.mapping_json) || {}; } catch { mapping = {}; }
        }
        setPixelForm({
            id: px.id,
            type: px.type,
            pixel_id: px.pixel_id || '',
            token: px.token || '',
            events: px.events || 'PageView,Lead',
            is_active: px.is_active ? 1 : 0,
            mapping,
            event_source_url: px.event_source_url || '',
            test_event_code: px.test_event_code || '',
            proxy_url: px.proxy_url || '',
        });
        setCapiTest(null);
        setEditingPixel(px.id);
    };

    const sendCapiTest = async () => {
        setCapiTesting(true);
        setCapiTest(null);
        try {
            const { data } = await cachedPost('facebook_capi_test', {
                id: pixelForm.id,
                campaign_id: activeCampaignId,
                pixel_id: pixelForm.pixel_id,
                token: pixelForm.token,
                test_event_code: pixelForm.test_event_code,
                proxy_url: pixelForm.proxy_url,
                event_source_url: pixelForm.event_source_url,
                event_name: 'Lead',
            });
            setCapiTest({ ok: data.status === 'success', message: data.message, usedRealClick: data.data?.used_real_click });
        } catch (err) {
            setCapiTest({ ok: false, message: String(err?.message || err) });
        } finally {
            setCapiTesting(false);
        }
    };

    const handleSave = async (forceClose = false) => {
        if (loading || saveSuccess) return;
        if (!formData.name || !formData.alias) {
            alert(t('editor.fillNameAndAlias'));
            return;
        }
        try {
            setLoading(true);
            // Token is managed server-side / via a dedicated action.
            // Do not send it from the editor by default to avoid accidental wipes during edits/migrations.
            const payload = { ...formData };
            delete payload.token;
            const res = await cachedPost('save_campaign', payload);
            if (res.data.status === 'success') {
                const saved = res.data.data || {};
                const nextFormData = {
                    ...formData,
                    id: saved.id || formData.id || campaignId,
                    token: saved.token || formData.token,
                    rotation_type: saved.rotation_type || formData.rotation_type
                };
                setFormData(nextFormData);
                baselineRef.current = JSON.stringify(nextFormData);
                setIsDirty(false);
                setSaveSuccess(true);
                setTimeout(() => {
                    setSaveSuccess(false);
                    if (forceClose || !getStayInEditorAfterSave()) {
                        closeEditor(true);
                    }
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

    const [tokenCopySuccess, setTokenCopySuccess] = useState(false);
    const [tokenBusy, setTokenBusy] = useState(false);

    const copyToken = async () => {
        const val = (formData.token || '').trim();
        if (!val) return;
        let copied = false;

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(val);
                copied = true;
            } catch (e) {
                copied = false;
            }
        }

        if (!copied) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = val;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                copied = document.execCommand('copy');
                document.body.removeChild(textarea);
            } catch (e) {
                copied = false;
            }
        }

        if (copied) {
            setTokenCopySuccess(true);
            setTimeout(() => setTokenCopySuccess(false), 1500);
        } else {
            alert(t('common.error'));
        }
    };

    const regenerateToken = async () => {
        const id = activeCampaignId;
        if (!id) return;
        if (!window.confirm(t('editor.regenerateTokenConfirm'))) return;
        try {
            setTokenBusy(true);
            const res = await cachedPost('regenerate_campaign_token', { campaign_id: id });
            if (res.data.status === 'success') {
                const token = res.data.data?.token || '';
                // The token is persisted by its own endpoint right away, so the
                // local refresh must not count as an unsaved edit.
                baselineRef.current = JSON.stringify({ ...formData, token });
                setFormData(prev => ({ ...prev, token }));
            } else {
                alert(`${t('common.error')}: ${res.data.message || 'Unknown error'}`);
            }
        } catch (e) {
            alert(t('common.networkError'));
        } finally {
            setTokenBusy(false);
        }
    };

    const clearStats = async () => {
        if (!activeCampaignId) return;
        if (!window.confirm(t('campaigns.clearStatsWarning'))) return;
        try {
            const res = await cachedPost('clear_campaign_stats', { campaign_id: activeCampaignId });
            if (res.data.status === 'success') {
                alert(t('editor.saved'));
                setShowClearModal(false);
                closeEditor(true);
            }
        } catch (e) {
            alert(t('common.clearError'));
        }
    };

    const loadConversionLogs = () => {
        setShowReportsMenu(false);
        setShowConversionsLog(true);
    };

    const openTrafficSimulation = () => {
        setShowReportsMenu(false);
        setShowTrafficSimModal(true);
        setTrafficSimResult(null);
    };

    const runTrafficSimulation = async () => {
        if (!activeCampaignId) return;
        setTrafficSimLoading(true);
        setTrafficSimResult(null);
        try {
            const res = await cachedPost('simulate_traffic', {
                campaign_id: activeCampaignId,
                ...trafficSimForm
            });
            setTrafficSimResult(res.data);
        } catch (e) {
            setTrafficSimResult({ status: 'error', message: t('common.networkError') });
        } finally {
            setTrafficSimLoading(false);
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
            collect_clicks: 1,
            schema_type: 'redirect',
            offer_id: 0,
            action_payload: '',
            filters: [],
            filters_logic: 'and',
            schema_custom: { landings: [], offers: [] },
            offer_selection: 'before'
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

    // Weighted rotation: total weight across active regular streams and a
    // one-click even split (floor(100/N) + remainder on the first stream).
    const totalStreamWeight = useMemo(() => {
        return (formData.streams || [])
            .filter(s => s.type === 'regular' && (s.is_active ?? 1))
            .reduce((sum, s) => sum + (parseInt(s.weight, 10) || 0), 0);
    }, [formData.streams]);

    const handleEqualizeStreamWeights = () => {
        const activeCount = (formData.streams || []).filter(s => s.type === 'regular' && (s.is_active ?? 1)).length;
        if (!activeCount) return;
        const base = Math.floor(100 / activeCount);
        const remainder = 100 - base * activeCount;
        let seen = 0;
        setFormData({
            ...formData,
            streams: formData.streams.map(s => {
                if (s.type !== 'regular' || !(s.is_active ?? 1)) return s;
                const w = base + (seen === 0 ? remainder : 0);
                seen += 1;
                return { ...s, weight: w };
            })
        });
    };

    // Schema item management
    /**
     * Add landings/offers to a stream's rotation and split the weights evenly:
     * floor(100/N) each with the remainder on the first item (2 → 50/50,
     * 3 → 34/33/33), so the total always stays 100%. Used by the picker's Add
     * button and by both quick-create attach paths.
     */
    const addEntitiesToStream = (streamIdx, type, ids) => {
        const numeric = (ids || []).map(id => parseInt(id, 10)).filter(id => !!id);
        if (!numeric.length) return;
        const s = [...formData.streams];
        if (!s[streamIdx]) return;
        if (!s[streamIdx].schema_custom) s[streamIdx].schema_custom = { landings: [], offers: [] };
        const list = (s[streamIdx].schema_custom[type] || []).map(x => ({ ...x }));
        numeric.forEach(id => {
            if (!list.some(x => parseInt(x.id, 10) === id)) list.push({ id, weight: 100 });
        });
        if (list.length > 1) {
            const base = Math.floor(100 / list.length);
            list.forEach((item, i) => { item.weight = base + (i === 0 ? 100 - base * list.length : 0); });
        } else if (list.length === 1) {
            list[0].weight = 100;
        }
        s[streamIdx].schema_custom[type] = list;
        setFormData({ ...formData, streams: s });
    };

    const openEntityPicker = (streamIdx, type) => setPickerState({ open: true, streamIdx, type });

    /**
     * An offer was created inside the stream's embedded OfferEditor — refresh
     * the picker's list and, when it is new, wire it into this stream's
     * rotation. Mirrors attachLandingToStream below.
     */
    const attachOfferToStream = async (newId) => {
        const id = parseInt(newId, 10);
        if (!id) return;
        try {
            const listRes = await cachedGet('all_offers', { _: Date.now() }, 0);
            if (listRes.data.status === 'success') {
                setAllOffers(listRes.data.data);
            }
        } catch (e) {
            // A stale list is survivable; the offer itself is already saved.
        }

        if (quickCreate?.editingId) return;
        const streamIdx = quickCreate?.streamIdx;
        if (streamIdx === undefined || streamIdx === null) return;
        addEntitiesToStream(streamIdx, 'offers', [id]);
    };

    /**
     * Open an existing offer in the shared editor, straight from a stream row.
     */
    const openOfferEdit = (offerId, streamIdx) => {
        setQuickCreate({ kind: 'offers', streamIdx, editingId: offerId });
    };

    /**
     * A landing was saved inside the stream's modal — refresh the dropdown and,
     * when it is new, wire it into this stream's rotation.
     *
     * Called on every save, including the one that creates the landing before its
     * archive is uploaded, so the stream holds the id from the first moment. The
     * guard against duplicates matters because the editor stays open after
     * creating: saving twice must not add the landing to the rotation twice.
     */
    const attachLandingToStream = async (newId) => {
        const id = parseInt(newId, 10);
        if (!id) return;
        try {
            const listRes = await cachedGet('landings_simple', { _: Date.now() }, 0);
            if (listRes.data.status === 'success') {
                setAllLandings(listRes.data.data);
            }
        } catch (e) {
            // A stale dropdown is survivable; the landing itself is already saved.
        }

        if (quickCreate?.editingId) return;

        const streamIdx = quickCreate?.streamIdx;
        if (streamIdx === undefined || streamIdx === null) return;
        addEntitiesToStream(streamIdx, 'landings', [id]);
    };

    /**
     * Open an existing landing in the shared editor. Nothing to prefetch: the
     * editor loads the full row itself from the id, which is the whole point of
     * reusing it instead of copying its fields into local state.
     */
    const openLandingEdit = (landingId, streamIdx) => {
        setQuickCreate({ kind: 'landings', streamIdx, editingId: landingId });
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

    // Rows below the "Add ..." split buttons. They used to be raw <select>s;
    // entities are now picked through EntitySelectorModal, so a row shows the
    // resolved name plus badges instead of a dropdown, with the weight, edit
    // and delete controls kept inline.
    const schemaBadge = (label) => (
        <span key={label} className="text-[10.5px] leading-none px-1.5 py-1 rounded-md" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
            {label}
        </span>
    );

    const schemaWeightInput = (streamIdx, type, item, itemIdx, list) => (
        <div className="flex items-center gap-1 flex-shrink-0">
            <input
                type="number"
                value={list.length === 1 ? 100 : item.weight}
                disabled={list.length === 1}
                onChange={e => updateSchemaItem(streamIdx, type, itemIdx, 'weight', parseInt(e.target.value))}
                className="w-14 text-center rounded-lg px-1 py-1 text-xs"
                style={{
                    backgroundColor: list.length === 1 ? 'var(--color-bg-soft)' : 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: list.length === 1 ? 'var(--color-text-muted)' : 'var(--color-text-primary)'
                }}
                title={t('editor.weight')}
            />
            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>%</span>
        </div>
    );

    const renderLandingRow = (idx, l, lIdx, list) => {
        const info = allLandings.find(al => al.id === parseInt(l.id, 10));
        // A row without an id is legacy junk from the old add-a-blank-select flow.
        // It reads as a clickable placeholder: clicking drops the empty row and
        // opens the picker, so the slot is only ever filled with a real landing.
        const empty = !info && !l.id;
        const name = info ? info.name : (l.id ? `#${l.id}` : t('editor.selectLandingPlaceholder'));
        const typeLabels = {
            local: t('landingEditor.typeLocal'),
            redirect: t('landingEditor.typeRedirect'),
            preload: t('landingEditor.typePreload'),
            action: t('landingEditor.typeAction'),
        };
        return (
            <div
                key={lIdx}
                className="flex items-center gap-2 px-3 py-2 rounded-xl"
                style={{
                    backgroundColor: 'var(--color-bg-card)',
                    border: empty ? '1px dashed var(--color-border)' : '1px solid var(--color-border)',
                    cursor: empty ? 'pointer' : 'default'
                }}
                onClick={empty ? () => { removeSchemaItem(idx, 'landings', lIdx); openEntityPicker(idx, 'landings'); } : undefined}
            >
                <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate" style={{ color: empty ? 'var(--color-warning)' : 'var(--color-text-primary)' }} title={name}>{name}</div>
                    {info && (
                        <div className="flex flex-wrap gap-1 mt-1">
                            {schemaBadge(typeLabels[info.type] || info.type)}
                            {info.group_name && schemaBadge(info.group_name)}
                        </div>
                    )}
                </div>
                {schemaWeightInput(idx, 'landings', l, lIdx, list)}
                <button
                    onClick={() => l.id && openLandingEdit(l.id, idx)}
                    disabled={!l.id}
                    className="action-btn"
                    style={{ color: 'var(--color-primary)', opacity: l.id ? 1 : 0.4 }}
                    title={t('editor.editLanding')}
                >
                    <Edit3 className="w-3.5 h-3.5" />
                </button>
                <button onClick={() => removeSchemaItem(idx, 'landings', lIdx)} className="action-btn text-red" title={t('common.delete')}>
                    <X className="w-3.5 h-3.5" />
                </button>
            </div>
        );
    };

    const renderOfferRow = (idx, o, oIdx, list) => {
        const info = allOffers.find(ao => ao.id === parseInt(o.id, 10));
        // Same legacy-no-id handling as landing rows: clickable placeholder that
        // swaps itself for a real pick.
        const empty = !info && !o.id;
        const name = info ? info.name : (o.id ? `#${o.id}` : t('editor.selectOfferPlaceholder'));
        return (
            <div
                key={oIdx}
                className="flex items-center gap-2 px-3 py-2 rounded-xl"
                style={{
                    backgroundColor: 'var(--color-bg-card)',
                    border: empty ? '1px dashed var(--color-border)' : '1px solid var(--color-border)',
                    cursor: empty ? 'pointer' : 'default'
                }}
                onClick={empty ? () => { removeSchemaItem(idx, 'offers', oIdx); openEntityPicker(idx, 'offers'); } : undefined}
            >
                <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate" style={{ color: empty ? 'var(--color-warning)' : 'var(--color-text-primary)' }} title={name}>{name}</div>
                    {info && (
                        <div className="flex flex-wrap gap-1 mt-1">
                            {schemaBadge(info.is_local ? t('offers.local') : t('offers.redirect'))}
                            {info.affiliate_network_name && schemaBadge(info.affiliate_network_name)}
                            {info.geo && schemaBadge(`GEO: ${info.geo}`)}
                            {parseFloat(info.payout_value) > 0 && schemaBadge(`${info.payout_value}$ · ${String(info.payout_type || 'cpa').toUpperCase()}`)}
                            {info.group_name && schemaBadge(info.group_name)}
                        </div>
                    )}
                </div>
                {schemaWeightInput(idx, 'offers', o, oIdx, list)}
                <button
                    onClick={() => o.id && openOfferEdit(o.id, idx)}
                    disabled={!o.id}
                    className="action-btn"
                    style={{ color: 'var(--color-primary)', opacity: o.id ? 1 : 0.4 }}
                    title={t('common.edit')}
                >
                    <Edit3 className="w-3.5 h-3.5" />
                </button>
                <button onClick={() => removeSchemaItem(idx, 'offers', oIdx)} className="action-btn text-red" title={t('common.delete')}>
                    <X className="w-3.5 h-3.5" />
                </button>
            </div>
        );
    };

    // Filter management
    const [filterModal, setFilterModal] = useState({ open: false, streamIdx: null });
    const [newFilter, setNewFilter] = useState({ name: 'Country', mode: 'include', payload: '' });

    const availableFilters = [
        { name: 'Country', label: t('filters.country'), placeholder: 'RU, US, UK...' },
        { name: 'Device', label: t('filters.device'), placeholder: 'mobile, desktop, tablet...' },
        { name: 'OS', label: t('filters.os'), placeholder: 'windows, macos, ios, android...' },
        { name: 'Browser', label: t('filters.browser'), placeholder: 'chrome, firefox, safari...' },
        { name: 'Bot', label: t('filters.bot'), placeholder: t('filters.botYes') },
        { name: 'Language', label: t('filters.language'), placeholder: 'ru, en, de (from browser header)' },
        { name: 'ISP', label: t('filters.isp'), placeholder: t('filters.ispPlaceholder') },
        { name: 'Connection', label: t('filters.connection'), placeholder: 'mobile, wifi, cable...' },
        { name: 'IP', label: t('filters.ip'), placeholder: '192.168.1.1, 10.0.0.*...' },
        { name: 'Keyword', label: t('filters.keyword'), placeholder: 'keyword1, keyword2...' },
        { name: 'Referer', label: t('filters.referer'), placeholder: 'google.com, facebook.com...' },
        { name: 'Weekday', label: t('filters.weekday'), placeholder: 'monday, tuesday...' },
        { name: 'Time', label: t('filters.time'), placeholder: '9-18, 10:00-20:00...' },
    ];

    const openFilterModal = (streamIdx, filterIdx = null) => {
        if (filterIdx !== null && filterIdx >= 0) {
            const f = formData.streams[streamIdx]?.filters?.[filterIdx];
            if (f) {
                setFilterModal({ open: true, streamIdx, filterIdx });
                setNewFilter({
                    name: f.name || 'Country',
                    mode: f.mode || 'include',
                    payload: Array.isArray(f.payload) ? f.payload.join(', ') : (f.payload || '')
                });
                return;
            }
        }
        setFilterModal({ open: true, streamIdx, filterIdx: null });
        setNewFilter({ name: 'Country', mode: 'include', payload: '' });
    };

    const saveFilter = () => {
        if (!newFilter.payload?.trim()) return;
        const s = [...formData.streams];
        if (!s[filterModal.streamIdx].filters) s[filterModal.streamIdx].filters = [];
        const filterObj = {
            name: newFilter.name,
            mode: newFilter.mode,
            payload: newFilter.payload.split(',').map(p => p.trim()).filter(p => p)
        };
        if (filterModal.filterIdx !== null && filterModal.filterIdx >= 0) {
            s[filterModal.streamIdx].filters[filterModal.filterIdx] = filterObj;
        } else {
            s[filterModal.streamIdx].filters.push(filterObj);
        }
        setFormData({ ...formData, streams: s });
        setFilterModal({ open: false, streamIdx: null, filterIdx: null });
    };

    const removeFilter = (streamIdx, filterIdx) => {
        const s = [...formData.streams];
        s[streamIdx].filters.splice(filterIdx, 1);
        setFormData({ ...formData, streams: s });
    };

    return (
        <>
            <div className="h-[calc(100vh-80px)] w-full flex flex-col overflow-hidden rounded-[24px] shadow-lg" style={{ backgroundColor: 'var(--color-bg-card)', border: 'none' }}>
                {/* Header */}
                <div className="flex justify-between items-center px-6 py-4 flex-shrink-0" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    <div className="flex items-center gap-4">
                        <button
                            onClick={requestClose}
                            className="btn btn-secondary btn-icon"
                            title={t('common.back')}
                            aria-label={t('common.back')}
                        >
                            <ArrowLeft className="w-5 h-5" />
                        </button>
                        <h2 className="text-xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                            {activeCampaignId ? `${t('editor.campaign')}: ${formData.name}` : t('editor.createCampaign')}
                        </h2>
                        {formData.alias && (
                            <span className="text-sm font-mono px-2 py-1 rounded-lg" style={{ color: 'var(--color-text-muted)', backgroundColor: 'var(--color-bg-hover)' }}>
                                /{formData.alias}
                            </span>
                        )}
                    </div>

                    <div className="flex items-center space-x-2">
                        <button
                            type="button"
                            onClick={() => handleSave(true)}
                            disabled={loading || saveSuccess}
                            className="btn btn-secondary"
                        >
                            {t('profile.saveAndClose')}
                        </button>

                        {/* Save button */}
                        <button
                            type="button"
                            onClick={() => handleSave(false)}
                            disabled={loading || saveSuccess}
                            className="btn btn-primary"
                            style={saveSuccess ? { backgroundColor: 'var(--color-success)' } : {}}
                        >
                            {saveSuccess ? <Check className="w-4 h-4" /> : <Settings className="w-4 h-4" />}
                            {saveSuccess ? t('editor.saved') : t('editor.save')}
                        </button>

                        {/* Copy URL */}
                        <button onClick={copyUrl} className="btn btn-ghost btn-icon" title={t('editor.copyUrl')}>
                            {copySuccess ? <Check className="w-5 h-5" /> : <Copy className="w-5 h-5" />}
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
                                    <button
                                        onClick={openTrafficSimulation}
                                        className="w-full text-left px-4 py-2 text-sm flex items-center gap-2"
                                        style={{ color: 'var(--color-text-primary)' }}
                                    >
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
                    <button onClick={requestClose} className="btn btn-secondary">
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
                                { key: 'tracking', label: t('editor.tracking', 'Tracking') },
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
                                                            <button
                                                                type="button"
                                                                className="btn btn-secondary btn-icon"
                                                                onClick={() => setShowGroupsModal(true)}
                                                                title={t('groupsModal.campaignGroups')}
                                                            >
                                                                <Plus className="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                    
                                                    <div className="pt-2 border-t" style={{ borderColor: 'var(--color-border)' }}>
                                                        <label className="form-label">{t('editor.rotationType')}</label>
                                                        <select
                                                            value={formData.rotation_type}
                                                            onChange={e => setFormData({ ...formData, rotation_type: e.target.value })}
                                                            className="form-select"
                                                        >
                                                            <option value="position">{t('rotationType.position')}</option>
                                                            <option value="weight">{t('rotationType.weight')}</option>
                                                        </select>
                                                    </div>

                                                    <div>
                                                        <label className="form-label">{t('editor.clickApiToken')}</label>
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="text"
                                                                value={formData.token || ''}
                                                                readOnly
                                                                className="form-input font-mono text-sm"
                                                                placeholder="3pTKR1fmNNHgp4X9"
                                                                title={t('editor.clickApiTokenHint')}
                                                            />
                                                            <button
                                                                type="button"
                                                                className="btn btn-secondary btn-icon"
                                                                onClick={copyToken}
                                                                disabled={!formData.token}
                                                                title={t('common.copy')}
                                                            >
                                                                {tokenCopySuccess ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="btn btn-secondary btn-icon"
                                                                onClick={regenerateToken}
                                                                disabled={tokenBusy || !activeCampaignId}
                                                                title={t('editor.regenerateToken') || t('common.refresh')}
                                                            >
                                                                <RefreshCw className={`w-4 h-4 ${tokenBusy ? 'animate-spin' : ''}`} />
                                                            </button>
                                                        </div>
                                                        <p className="text-xs mt-2" style={{ color: 'var(--color-text-muted)' }}>
                                                            {t('editor.clickApiTokenHint')}
                                                        </p>
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
                                                                onChange={e => handleSourceChange(e.target.value)}
                                                                className="form-select"
                                                            >
                                                                <option value="">{t('editor.organicTraffic')}</option>
                                                                {sources.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                                                            </select>
                                                            <button
                                                                type="button"
                                                                className="btn btn-secondary btn-icon"
                                                                onClick={() => setShowSourceEditor(true)}
                                                                title={t('sources.title')}
                                                            >
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
                                                        {copySuccess ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                                                    </button>
                                                </div>
                                            </div>

                                            {/* Bot Challenge Section */}
                                            <div style={{ marginTop: '24px', paddingTop: '24px', borderTop: '1px solid var(--color-border)' }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                                                    <Shield size={18} style={{ color: 'var(--color-primary)' }} />
                                                    <h3 style={{ fontSize: '15px', fontWeight: '600', color: 'var(--color-text-primary)', margin: 0 }}>
                                                        {t('challenge.sectionTitle')}
                                                    </h3>
                                                </div>
                                                <p style={{ fontSize: '13px', color: 'var(--color-text-muted)', marginBottom: '16px' }}>
                                                    {t('challenge.sectionDesc')}
                                                </p>

                                                {/* Email info banner */}
                                                {formData.source_id && (
                                                    <div style={{ background: 'var(--color-info-bg, #eff6ff)', border: '1px solid var(--color-info-border, #bfdbfe)', borderRadius: '8px', padding: '12px 14px', marginBottom: '16px', display: 'flex', gap: '10px' }}>
                                                        <span style={{ fontSize: '16px' }}>✉️</span>
                                                        <div>
                                                            <div style={{ fontSize: '13px', fontWeight: '600', color: 'var(--color-info-text, #1d4ed8)', marginBottom: '2px' }}>{t('challenge.infoEmailTitle')}</div>
                                                            <div style={{ fontSize: '12px', color: 'var(--color-info-text, #1d4ed8)' }}>{t('challenge.infoEmailDesc')}</div>
                                                        </div>
                                                    </div>
                                                )}

                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '16px' }}>
                                                    <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', marginBottom: '4px', display: 'block' }}>
                                                        {t('challenge.typeLabel')}
                                                    </label>
                                                    {[
                                                        { value: 'none', label: t('challenge.typeNone'), desc: t('challenge.typeNoneDesc') },
                                                        { value: 'turnstile', label: t('challenge.typeTurnstile'), desc: t('challenge.typeTurnstileDesc') },
                                                        { value: 'recaptcha_v2', label: t('challenge.typeV2'), desc: t('challenge.typeV2Desc') },
                                                        { value: 'recaptcha_v3', label: t('challenge.typeV3'), desc: t('challenge.typeV3Desc') },
                                                        { value: 'custom', label: t('challenge.typeCustom'), desc: t('challenge.typeCustomDesc') },
                                                    ].map(opt => (
                                                        <label key={opt.value} style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', padding: '10px 14px', borderRadius: '8px', border: `1px solid ${formData.challenge_type === opt.value ? 'var(--color-primary)' : 'var(--color-border)'}`, background: formData.challenge_type === opt.value ? 'var(--color-primary-alpha, rgba(79,70,229,0.06))' : 'var(--color-bg-card)', cursor: 'pointer' }}>
                                                            <input
                                                                type="radio"
                                                                name="challenge_type"
                                                                value={opt.value}
                                                                checked={formData.challenge_type === opt.value}
                                                                onChange={e => setFormData(prev => ({ ...prev, challenge_type: e.target.value }))}
                                                                style={{ marginTop: '4px', width: '16px', height: '16px', flexShrink: 0, cursor: 'pointer' }}
                                                            />
                                                            <div>
                                                                <div style={{ fontSize: '14px', fontWeight: '500', color: 'var(--color-text-primary)' }}>{opt.label}</div>
                                                                <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '2px' }}>{opt.desc}</div>
                                                            </div>
                                                        </label>
                                                    ))}
                                                </div>

                                                {/* Cloudflare Turnstile setup guide */}
                                                {formData.challenge_type === 'turnstile' && (
                                                    <div style={{ background: 'var(--color-warning-bg, #fffbeb)', border: '1px solid var(--color-warning-border, #fcd34d)', borderRadius: '10px', padding: '14px 16px', marginBottom: '4px' }}>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px' }}>
                                                            <span style={{ fontSize: '15px' }}>⚡</span>
                                                            <span style={{ fontSize: '13px', fontWeight: '700', color: 'var(--color-warning-text, #92400e)' }}>
                                                                {t('challenge.setupGuideTitle')}
                                                            </span>
                                                        </div>
                                                        <ol style={{ margin: 0, paddingLeft: '18px', display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep1')}{' '}
                                                                <a
                                                                    href="https://dash.cloudflare.com/?to=/:account/turnstile"
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    style={{ color: 'var(--color-primary)', fontWeight: '600', textDecoration: 'underline' }}
                                                                >
                                                                    Cloudflare Turnstile ↗
                                                                </a>
                                                            </li>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep3')}{' '}
                                                                <strong>{t('challenge.setupStep3Path')}</strong>
                                                            </li>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep4')}
                                                            </li>
                                                        </ol>
                                                    </div>
                                                )}

                                                {/* reCAPTCHA setup guide */}
                                                {(formData.challenge_type === 'recaptcha_v2' || formData.challenge_type === 'recaptcha_v3') && (
                                                    <div style={{ background: 'var(--color-warning-bg, #fffbeb)', border: '1px solid var(--color-warning-border, #fcd34d)', borderRadius: '10px', padding: '14px 16px', marginBottom: '4px' }}>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px' }}>
                                                            <span style={{ fontSize: '15px' }}>⚡</span>
                                                            <span style={{ fontSize: '13px', fontWeight: '700', color: 'var(--color-warning-text, #92400e)' }}>
                                                                {t('challenge.setupGuideTitle')}
                                                            </span>
                                                        </div>
                                                        <ol style={{ margin: 0, paddingLeft: '18px', display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep1')}{' '}
                                                                <a
                                                                    href="https://www.google.com/recaptcha/admin/create"
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    style={{ color: 'var(--color-primary)', fontWeight: '600', textDecoration: 'underline' }}
                                                                >
                                                                    Google reCAPTCHA Admin ↗
                                                                </a>
                                                            </li>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep2', {
                                                                    type: formData.challenge_type === 'recaptcha_v2' ? "v2 («I'm not a robot»)" : 'v3'
                                                                })}
                                                            </li>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep3')}{' '}
                                                                <strong>{t('challenge.setupStep3Path')}</strong>
                                                            </li>
                                                            <li style={{ fontSize: '12px', color: 'var(--color-warning-text, #92400e)', lineHeight: '1.5' }}>
                                                                {t('challenge.setupStep4')}
                                                            </li>
                                                        </ol>
                                                    </div>
                                                )}

                                                {formData.challenge_type === 'custom' && (
                                                    <div>
                                                        <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', marginBottom: '6px', display: 'block' }}>
                                                            {t('challenge.customCodeLabel')}
                                                        </label>
                                                        <textarea
                                                            value={formData.challenge_custom_code}
                                                            onChange={e => setFormData(prev => ({ ...prev, challenge_custom_code: e.target.value }))}
                                                            rows={8}
                                                            placeholder={t('challenge.customCodePlaceholder')}
                                                            style={{ width: '100%', padding: '12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '13px', fontFamily: 'monospace', resize: 'vertical' }}
                                                        />
                                                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                                            {t('challenge.customCodeHint')}
                                                        </p>
                                                    </div>
                                                )}
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
                                        <div className="space-y-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{t('editor.setupParams')}</p>
                                                    {activeSource && (
                                                        <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                                            {t('editor.trafficSource')}: <span style={{ color: 'var(--color-text-primary)', fontWeight: 600 }}>{activeSource.name}</span>
                                                        </p>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={addCustomParameter}
                                                    className="btn btn-secondary text-xs py-1 px-2.5 rounded-xl font-medium"
                                                >
                                                    <Plus className="w-3.5 h-3.5" />
                                                    {t('parameters.addParam', 'Add Parameter')}
                                                </button>
                                            </div>

                                            <div className="hidden sm:grid grid-cols-12 gap-2 px-2 text-[11px] font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-muted)' }}>
                                                <span className="col-span-3">{t('sourceEditor.alias')}</span>
                                                <span className="col-span-3">{t('sourceEditor.param')}</span>
                                                <span className="col-span-6">{t('sourceEditor.sourceMacro')}</span>
                                            </div>

                                            <div className="space-y-2 max-h-[420px] overflow-y-auto pr-1">
                                                {displayParameters.map(param => {
                                                    const hasMapping = Object.prototype.hasOwnProperty.call(formData.parameters || {}, param.key);
                                                    return (
                                                        <div key={param.key} className="grid grid-cols-1 sm:grid-cols-12 items-center gap-2 p-2 rounded-xl transition-colors" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                                            <span className="sm:col-span-3 text-xs font-medium truncate" style={{ color: 'var(--color-text-primary)' }} title={param.label}>
                                                                {param.label}
                                                            </span>
                                                            <code className="sm:col-span-3 text-xs px-2 py-1 rounded-lg border truncate" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }} title={param.key}>
                                                                {param.key}
                                                            </code>
                                                            <div className="sm:col-span-6 flex items-center gap-2 min-w-0">
                                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>=</span>
                                                                <input
                                                                    type="text"
                                                                    placeholder="{{macro}} or {keyword}"
                                                                    value={formData.parameters?.[param.key] ?? ''}
                                                                    onChange={event => setFormData(prev => ({
                                                                        ...prev,
                                                                        parameters: { ...(prev.parameters || {}), [param.key]: event.target.value }
                                                                    }))}
                                                                    className="form-input text-xs font-mono flex-1 min-w-0 py-1.5 rounded-xl"
                                                                />
                                                                {hasMapping && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => clearParameter(param.key)}
                                                                        className="btn btn-ghost btn-icon btn-sm flex-shrink-0"
                                                                        title={t('common.clear')}
                                                                        aria-label={`${t('common.clear')}: ${param.key}`}
                                                                    >
                                                                        <X className="w-3.5 h-3.5" />
                                                                    </button>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>

                                            <details
                                                className="pt-3"
                                                style={{ borderTop: '1px solid var(--color-border)' }}
                                            >
                                                <summary className="text-xs font-semibold cursor-pointer select-none" style={{ color: 'var(--color-text-secondary)' }}>
                                                    {t('parameters.additionalSubIds', 'Sub IDs (sub_id_1 .. sub_id_30)')}
                                                </summary>
                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-2 mt-3 max-h-60 overflow-y-auto pr-1">
                                                    {CAMPAIGN_SUB_ID_KEYS.map(subKey => (
                                                        <div key={subKey} className="flex items-center gap-2">
                                                            <code className="text-xs w-20 flex-shrink-0" style={{ color: 'var(--color-text-muted)' }}>{subKey}</code>
                                                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>=</span>
                                                            <input
                                                                type="text"
                                                                value={formData.parameters?.[subKey] ?? ''}
                                                                onChange={event => setFormData(prev => ({
                                                                    ...prev,
                                                                    parameters: { ...(prev.parameters || {}), [subKey]: event.target.value }
                                                                }))}
                                                                className="form-input text-xs font-mono py-1 rounded-lg flex-1 min-w-0"
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                            </details>
                                        </div>
                                    )}

                                    {/* Integrations Tab */}
                                    {activeTab === 'integrations' && (
                                        <div className="space-y-4">
                                            {/* Cost Sync: spend sources + match diagnostics for THIS campaign */}
                                            <div style={{
                                                border: '1px solid var(--color-border)',
                                                borderRadius: '16px',
                                                padding: '14px 16px',
                                                background: 'var(--color-bg-card)'
                                            }}>
                                                <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                                                    <div style={{ fontWeight: 600, fontSize: '14px', color: 'var(--color-text-primary)' }}>
                                                        {t('streamRefine.costSyncTitle', 'Cost Sync')}
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowAddCostConnModal(true)}
                                                            className="btn btn-secondary text-xs py-1 px-2.5 rounded-xl flex items-center gap-1.5"
                                                        >
                                                            <Plus className="w-3.5 h-3.5" />
                                                            {t('streamRefine.addCostConnection', '+ Add Cost Connection')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowCostModal(true)}
                                                            className="btn btn-secondary text-xs py-1 px-2.5 rounded-xl flex items-center gap-1.5"
                                                        >
                                                            <DollarSign className="w-3.5 h-3.5" />
                                                            {t('streamRefine.updateCostsManually', 'Update Costs Manually')}
                                                        </button>
                                                    </div>
                                                </div>

                                                {/* Match diagnostics: do the clicks carry the ad IDs? */}
                                                {costMatch && (
                                                    <div style={{
                                                        padding: '10px 12px',
                                                        borderRadius: '8px',
                                                        marginBottom: '12px',
                                                        fontSize: '13px',
                                                        border: '1px solid var(--color-border)',
                                                        background: (costMatch.clicks7d > 0 && (costMatch.with_ad_id > 0 || costMatch.with_adset_id > 0 || costMatch.with_campaign_id > 0))
                                                            ? 'color-mix(in srgb, var(--color-success) 12%, transparent)'
                                                            : 'var(--color-bg-soft)',
                                                        color: 'var(--color-text-primary)'
                                                    }}>
                                                        {costMatch.clicks7d === 0 && (
                                                            <span>{t('costSync.noClicks', 'No clicks in the last 7 days — nothing to attach spend to yet.')}</span>
                                                        )}
                                                        {costMatch.clicks7d > 0 && (costMatch.with_ad_id > 0 || costMatch.with_adset_id > 0 || costMatch.with_campaign_id > 0) ? (
                                                            <span>
                                                                {t('costSync.matchOk', 'Last 7 days')}: {costMatch.clicks7d} clicks · ad_id ×{costMatch.with_ad_id} · adset_id ×{costMatch.with_adset_id} · campaign_id ×{costMatch.with_campaign_id}
                                                            </span>
                                                        ) : costMatch.clicks7d > 0 ? (
                                                            <span>
                                                                {t('costSync.matchWarn', 'Clicks of the last 7 days carry no ad_id / adset_id / campaign_id — imported spend will not attach. Pick a traffic source (e.g. Facebook) in the campaign settings so the URL parameters reach the tracker.')}
                                                            </span>
                                                        ) : null}
                                                        {costMatch.source_name && (
                                                            <span style={{ color: 'var(--color-text-muted)' }}> · {t('costSync.source', 'Source')}: {costMatch.source_name}</span>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Pull connections (Facebook / Google Ads / TikTok) */}
                                                {costConns.length === 0 ? (
                                                    <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                        {t('costSync.noConnections', 'No cost connections yet — create one under Integrations (Facebook Costs / TikTok Ads) or Aggregators.')}
                                                    </p>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {costConns.map(conn => (
                                                            <div key={conn.id} className="flex items-center justify-between gap-2" style={{ fontSize: '13px' }}>
                                                                <div className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                                                    <span className="badge badge-secondary">{conn.engine}</span>
                                                                    <span>{conn.name}</span>
                                                                    {!Number.isInteger(Number(conn.is_active)) || Number(conn.is_active) === 0 ? (
                                                                        <span style={{ color: 'var(--color-text-muted)' }}>({t('costSync.paused', 'paused')})</span>
                                                                    ) : null}
                                                                    {conn.last_sync_at && (
                                                                        <span style={{ color: 'var(--color-text-muted)' }}>
                                                                            · {t('costSync.lastSync')}: {String(conn.last_sync_at).slice(0, 16).replace('T', ' ')}
                                                                            {conn.last_sync_status === 'error' ? ' ⚠' : ''}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-secondary"
                                                                    style={{ padding: '4px 10px', fontSize: '12px' }}
                                                                    disabled={syncingConnId === conn.id}
                                                                    onClick={() => syncCostConnection(conn.id)}
                                                                >
                                                                    {syncingConnId === conn.id ? t('common.saving') : t('costSync.syncNow', 'Sync now')}
                                                                </button>
                                                            </div>
                                                        ))}
                                                        {syncResult && (
                                                            <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{syncResult}</p>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Push endpoint (Dolphin / Fbtool) — ready for THIS campaign */}
                                                <div style={{ marginTop: '12px', paddingTop: '10px', borderTop: '1px solid var(--color-border)' }}>
                                                    <div style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-primary)', marginBottom: '4px' }}>
                                                        Dolphin / Fbtool — {t('costSync.pushHint', 'cost push URL for this campaign (API key: Users page, write permissions)')}
                                                    </div>
                                                    <code className="text-xs" style={{ color: 'var(--color-text-secondary)', wordBreak: 'break-all' }}>
                                                        POST {trackerUrl}/admin_api/v1/campaigns/{activeCampaignId}/update_costs
                                                    </code>
                                                </div>
                                            </div>

                                            {/* One-click attachment from the central Pixel Vault. The backend
                                                resolves the secret token and keeps the campaign snapshot synced. */}
                                            <div style={{
                                                border: '1px solid var(--color-border)',
                                                borderRadius: '16px',
                                                padding: '14px 16px',
                                                background: 'var(--color-bg-card)'
                                            }}>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 600, fontSize: '14px', color: 'var(--color-text-primary)', marginBottom: '10px' }}>
                                                    <Shield size={16} /> {t('pixelVault.attachPixel')}
                                                </div>
                                                <select
                                                    value={selectedPixelProfileId}
                                                    onChange={event => handleSelectPixelProfile(event.target.value)}
                                                    className="form-select"
                                                    disabled={!activeCampaignId || pixelProfileAttaching}
                                                >
                                                    <option value="">{t('pixelVault.noPixel')}</option>
                                                    {Object.entries(groupedPixelProfiles).map(([niche, profiles]) => (
                                                        <optgroup key={niche} label={`📁 ${t('pixelVault.niche')}: ${niche}`}>
                                                            {profiles.map(profile => {
                                                                const source = pixelPlatforms.find(platform => platform.id === profile.traffic_source)?.name || profile.traffic_source;
                                                                return (
                                                                    <option key={profile.id} value={profile.id} disabled={!profile.is_active}>
                                                                        {profile.name} ({profile.pixel_id}) · {source}{!profile.is_active ? ` — ${t('pixelVault.inactive')}` : ''}
                                                                    </option>
                                                                );
                                                            })}
                                                        </optgroup>
                                                    ))}
                                                </select>
                                                <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                                    {activeCampaignId ? t('pixelVault.pixelHint') : t('pixelVault.saveCampaignFirst')}
                                                </p>
                                                {pixelProfileMessage && (
                                                    <p style={{ fontSize: '11px', marginTop: '6px', color: pixelProfileMessage.type === 'success' ? 'var(--color-success, #10b981)' : 'var(--color-danger, #ef4444)' }}>
                                                        {pixelProfileMessage.text}
                                                    </p>
                                                )}
                                            </div>

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
                                                                {px.profile_name && (
                                                                    <span style={{ fontSize: '10px', color: 'var(--color-primary)', background: 'var(--color-primary-light)', padding: '2px 6px', borderRadius: '6px' }}>
                                                                        {t('pixelVault.vaultProfile')}: {px.profile_name}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                                                                <label style={{ display: 'flex', alignItems: 'center', gap: '6px', cursor: 'pointer', fontSize: '12px', color: 'var(--color-text-secondary)' }}>
                                                                    <input type="checkbox" checked={!!px.is_active} onChange={async (e) => {
                                                                        try {
                                                                            await cachedPost('save_campaign_pixel', { ...px, is_active: e.target.checked ? 1 : 0 });
                                                                            fetchPixels();
                                                                        } catch (err) { console.error(err); }
                                                                    }} />
                                                                    {t('pixels.active')}
                                                                </label>
                                                                <button onClick={() => openPixelForEdit(px)} className="action-btn" style={{ padding: '4px' }} title={t('common.edit')}>
                                                                    <Edit3 size={14} />
                                                                </button>
                                                                <button onClick={() => {
                                                                    if (confirm(t('pixels.confirmDelete'))) {
                                                                        cachedPost('delete_campaign_pixel', { id: px.id }).then(() => fetchPixels());
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
                                                            <PixelPicker
                                                                label={t('pixels.pixelId')}
                                                                value={pixelForm.pixel_id}
                                                                onPick={(patch) => setPixelForm(prev => ({ ...prev, ...patch }))}
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

                                                        {/* Conversions API — server-side delivery. Only meaningful for Meta,
                                                            and only once a Conversions API token is present. */}
                                                        {pixelForm.type === 'facebook' && (
                                                            <div style={{ borderTop: '1px dashed var(--color-border)', paddingTop: '12px' }}>
                                                                <div style={{ fontWeight: 600, fontSize: '13px', marginBottom: '4px' }}>{t('pixels.capiTitle')}</div>
                                                                <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginBottom: '10px' }}>{t('pixels.capiHint')}</p>

                                                                <label className="form-label">{t('pixels.mapping')}</label>
                                                                <div style={{ display: 'grid', gap: '6px', marginBottom: '10px' }}>
                                                                    {Object.keys(capiMeta.default_mapping || {}).map(status => (
                                                                        <div key={status} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', alignItems: 'center' }}>
                                                                            <span style={{ fontSize: '12px', color: 'var(--color-text-secondary)' }}>{t('conversions.' + status, status)}</span>
                                                                            <select
                                                                                className="form-input text-sm"
                                                                                value={pixelForm.mapping?.[status] ?? capiMeta.default_mapping[status] ?? ''}
                                                                                onChange={e => setPixelForm({ ...pixelForm, mapping: { ...pixelForm.mapping, [status]: e.target.value } })}
                                                                            >
                                                                                <option value="">{t('pixels.doNotSend')}</option>
                                                                                {(capiMeta.available_events || []).map(ev => <option key={ev} value={ev}>{ev}</option>)}
                                                                            </select>
                                                                        </div>
                                                                    ))}
                                                                </div>

                                                                <div>
                                                                    <label className="form-label">{t('pixels.eventSourceUrl', 'Event URL / Thank You Page URL')}</label>
                                                                    <input
                                                                        type="text"
                                                                        value={pixelForm.event_source_url || ''}
                                                                        onChange={e => setPixelForm({ ...pixelForm, event_source_url: e.target.value })}
                                                                        placeholder="https://example.com/thankyou.php"
                                                                        className="form-input font-mono text-sm"
                                                                    />
                                                                    <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                                                        {t('pixels.eventSourceUrlHint', 'Sent to Meta CAPI as event_source_url. Supports {campaign_url}, {landing_url} and {clickid}.')}
                                                                    </p>
                                                                </div>

                                                                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
                                                                    <div>
                                                                        <label className="form-label">{t('pixels.testEventCode')}</label>
                                                                        <input
                                                                            type="text"
                                                                            value={pixelForm.test_event_code}
                                                                            onChange={e => setPixelForm({ ...pixelForm, test_event_code: e.target.value })}
                                                                            placeholder="TEST12345"
                                                                            className="form-input font-mono text-sm"
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <ProxyInput
                                                                            label={t('pixels.proxy')}
                                                                            value={pixelForm.proxy_url}
                                                                            onChange={(val) => setPixelForm({ ...pixelForm, proxy_url: val })}
                                                                        />
                                                                    </div>
                                                                </div>

                                                                <div style={{ marginTop: '10px' }}>
                                                                    <button
                                                                        onClick={sendCapiTest}
                                                                        className="btn btn-secondary btn-sm"
                                                                        disabled={!pixelForm.pixel_id || !pixelForm.token || capiTesting}
                                                                    >
                                                                        <Play size={14} /> {capiTesting ? t('pixels.sending') : t('pixels.sendTestEvent')}
                                                                    </button>
                                                                    {capiTest && (
                                                                        <p style={{ fontSize: '11px', marginTop: '6px', color: capiTest.ok ? 'var(--color-success, #10b981)' : 'var(--color-danger, #ef4444)' }}>
                                                                            {capiTest.message}
                                                                            {capiTest.ok && !capiTest.usedRealClick ? ' ' + t('pixels.syntheticClick') : ''}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )}

                                                        <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                                                            <button onClick={() => { setEditingPixel(null); setCapiTest(null); }} className="btn btn-secondary btn-sm">
                                                                <X size={14} /> {t('common.cancel')}
                                                            </button>
                                                            <button
                                                                onClick={async () => {
                                                                    if (!pixelForm.pixel_id) return;
                                                                    try {
                                                                        await cachedPost('save_campaign_pixel', {
                                                                            campaign_id: activeCampaignId,
                                                                            ...pixelForm,
                                                                            mapping_json: JSON.stringify(pixelForm.mapping || {}),
                                                                        });
                                                                        setEditingPixel(null);
                                                                        setCapiTest(null);
                                                                        setPixelForm(emptyPixelForm);
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
                                                                setPixelForm({ ...emptyPixelForm, type: platform.id });
                                                                setCapiTest(null);
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

                                    {/* Tracking Tab — connection methods with the campaign baked in.
                                        Keitaro-style two columns: settings left, live-generated
                                        code + install instruction + widget preview right. */}
                                    {activeTab === 'tracking' && (
                                        <div className="space-y-4">
                                            {!(formData.token || '').trim() && (
                                                <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                    {t('tracking.saveFirst', 'Save the campaign first — the tracking code embeds the campaign token.')}
                                                </p>
                                            )}

                                            <div>
                                                <label className="form-label">{t('tracking.method', 'Connection method')}</label>
                                                <select
                                                    className="form-select"
                                                    value={trackingMethod}
                                                    onChange={e => {
                                                        setTrackingMethod(e.target.value);
                                                        setShowWidgetPreview(false);
                                                        setSnippetCopied(false);
                                                    }}
                                                >
                                                    <optgroup label={t('tracking.groupSite', 'Sites')}>
                                                        <option value="kclient_js">KClient JS</option>
                                                        <option value="kclient_php">KClient PHP</option>
                                                        <option value="tracking_script">{t('tracking.trackingScript', 'Tracking Script')}</option>
                                                    </optgroup>
                                                    <optgroup label={t('tracking.groupBanners', 'Banner blocks')}>
                                                        <option value="banner_script">{t('tracking.bannerScript', 'Banner block (script)')}</option>
                                                        <option value="banner_iframe">{t('tracking.bannerIframe', 'Banner block (iframe)')}</option>
                                                    </optgroup>
                                                    <optgroup label={t('tracking.groupAds', 'Ad networks')}>
                                                        <option value="campaign_url">Campaign URL</option>
                                                        <option value="link">{t('editor.intCode_link', 'Link')}</option>
                                                        <option value="iframe">Iframe</option>
                                                        <option value="script">Script</option>
                                                    </optgroup>
                                                    <optgroup label={t('tracking.groupMisc', 'Tools')}>
                                                        <option value="pixel">Tracking Pixel</option>
                                                        <option value="countdown">Countdown Timer</option>
                                                        <option value="back_button">Back Button Trap</option>
                                                        <option value="exit_intent">Exit Intent Popup</option>
                                                        <option value="wordpress">WordPress</option>
                                                    </optgroup>
                                                </select>
                                            </div>

                                            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
                                                {/* LEFT — per-method settings & options */}
                                                <div className="rounded-2xl p-4 space-y-3" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                                    <div style={{ fontWeight: 600, fontSize: '13px', color: 'var(--color-text-primary)', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
                                                        {t('tracking.settings', 'Settings & Options')}
                                                    </div>

                                                    {trackingMethod === 'kclient_js' && (
                                                        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                            <input
                                                                type="checkbox"
                                                                checked={trackOpts.base64}
                                                                onChange={e => setTrackOpts({ ...trackOpts, base64: e.target.checked })}
                                                            />
                                                            {t('tracking.base64', 'Base64 (hide from ad blockers)')}
                                                        </label>
                                                    )}

                                                    {trackingMethod === 'kclient_php' && (
                                                        <>
                                                            <div>
                                                                <label className="form-label">{t('tracking.phpMode', 'Execution mode')}</label>
                                                                <select
                                                                    className="form-select"
                                                                    value={trackOpts.phpMode}
                                                                    onChange={e => setTrackOpts({ ...trackOpts, phpMode: e.target.value })}
                                                                >
                                                                    <option value="redirect">{t('tracking.phpRedirect', 'Redirect to offer')}</option>
                                                                    <option value="show_html">{t('tracking.phpShowHtml', 'Show as HTML (content in page body)')}</option>
                                                                    <option value="get_link">{t('tracking.phpGetLink', 'Get offer link into a variable')}</option>
                                                                </select>
                                                            </div>
                                                            <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={trackOpts.sendParams}
                                                                    onChange={e => setTrackOpts({ ...trackOpts, sendParams: e.target.checked })}
                                                                />
                                                                {t('tracking.sendParams', 'Pass UTM / SubID parameters from the URL')}
                                                            </label>
                                                            <a
                                                                href={`${trackerUrl}/kclient.php?download=1`}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="btn btn-secondary"
                                                                style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
                                                            >
                                                                <FileText className="w-4 h-4" />
                                                                {t('tracking.downloadKclient', 'Download kclient.php')}
                                                            </a>
                                                        </>
                                                    )}

                                                    {(trackingMethod === 'banner_script' || trackingMethod === 'banner_iframe') && (
                                                        <div className="flex gap-2">
                                                            <div className="flex-1">
                                                                <label className="form-label">W</label>
                                                                <input type="number" className="form-input" value={trackOpts.width} onChange={e => setTrackOpts({ ...trackOpts, width: parseInt(e.target.value) || 300 })} />
                                                            </div>
                                                            <div className="flex-1">
                                                                <label className="form-label">H</label>
                                                                <input type="number" className="form-input" value={trackOpts.height} onChange={e => setTrackOpts({ ...trackOpts, height: parseInt(e.target.value) || 250 })} />
                                                            </div>
                                                        </div>
                                                    )}

                                                    {trackingMethod === 'countdown' && (
                                                        <>
                                                            <div className="grid grid-cols-2 gap-2">
                                                                <div>
                                                                    <label className="form-label">{t('tracking.hours', 'Duration, hours')}</label>
                                                                    <input type="number" min="0" className="form-input" value={trackOpts.hours} onChange={e => setTrackOpts({ ...trackOpts, hours: parseInt(e.target.value) || 0 })} />
                                                                </div>
                                                                <div>
                                                                    <label className="form-label">{t('tracking.minutes', 'Duration, minutes')}</label>
                                                                    <input type="number" min="0" className="form-input" value={trackOpts.minutes} onChange={e => setTrackOpts({ ...trackOpts, minutes: parseInt(e.target.value) || 0 })} />
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.headerText', 'Header text')}</label>
                                                                <input type="text" className="form-input" placeholder="OFFER EXPIRES IN" value={trackOpts.headerText} onChange={e => setTrackOpts({ ...trackOpts, headerText: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.ctaButtonText', 'Button text')}</label>
                                                                <input type="text" className="form-input" placeholder="GET SPECIAL OFFER" value={trackOpts.buttonText} onChange={e => setTrackOpts({ ...trackOpts, buttonText: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.offerUrl', 'Offer URL')}</label>
                                                                <input type="text" className="form-input" placeholder="https://your-offer.com" value={trackOpts.offerUrl} onChange={e => setTrackOpts({ ...trackOpts, offerUrl: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.theme', 'Style theme')}</label>
                                                                <select className="form-select" value={trackOpts.theme} onChange={e => setTrackOpts({ ...trackOpts, theme: e.target.value })}>
                                                                    <option value="purple">{t('tracking.themePurple', 'Purple / Indigo')}</option>
                                                                    <option value="emerald">{t('tracking.themeEmerald', 'Emerald Green')}</option>
                                                                    <option value="fire">{t('tracking.themeFire', 'Fire Red')}</option>
                                                                    <option value="dark">{t('tracking.themeDark', 'Dark Minimal')}</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.expireAction', 'On expire')}</label>
                                                                <select className="form-select" value={trackOpts.expireAction} onChange={e => setTrackOpts({ ...trackOpts, expireAction: e.target.value })}>
                                                                    <option value="expired">{t('tracking.expireShowBadge', 'Show "EXPIRED"')}</option>
                                                                    <option value="redirect">{t('tracking.expireRedirect', 'Redirect to fallback URL')}</option>
                                                                </select>
                                                            </div>
                                                            {trackOpts.expireAction === 'redirect' && (
                                                                <div>
                                                                    <label className="form-label">{t('tracking.expireUrl', 'Fallback URL (empty = offer URL)')}</label>
                                                                    <input type="text" className="form-input" placeholder="https://your-fallback.com" value={trackOpts.expireUrl} onChange={e => setTrackOpts({ ...trackOpts, expireUrl: e.target.value })} />
                                                                </div>
                                                            )}
                                                        </>
                                                    )}

                                                    {trackingMethod === 'back_button' && (
                                                        <>
                                                            <div>
                                                                <label className="form-label">{t('tracking.trapUrl', 'Trap Redirect URL')}</label>
                                                                <input type="text" className="form-input" placeholder="https://your-special-offer.com" value={trackOpts.trapUrl} onChange={e => setTrackOpts({ ...trackOpts, trapUrl: e.target.value })} />
                                                            </div>
                                                            <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={trackOpts.logClick}
                                                                    onChange={e => setTrackOpts({ ...trackOpts, logClick: e.target.checked })}
                                                                />
                                                                {t('tracking.logClick', 'Log click in tracker (sub1=back_button)')}
                                                            </label>
                                                            <div>
                                                                <label className="form-label">{t('tracking.activationDelay', 'Activation delay, seconds')}</label>
                                                                <input type="number" min="0" className="form-input" value={trackOpts.delay} onChange={e => setTrackOpts({ ...trackOpts, delay: parseInt(e.target.value) || 0 })} />
                                                            </div>
                                                        </>
                                                    )}

                                                    {trackingMethod === 'exit_intent' && (
                                                        <>
                                                            <div>
                                                                <label className="form-label">{t('tracking.heading', 'Heading')}</label>
                                                                <input type="text" className="form-input" placeholder="Wait! Special Offer!" value={trackOpts.heading} onChange={e => setTrackOpts({ ...trackOpts, heading: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.description', 'Description')}</label>
                                                                <input type="text" className="form-input" placeholder="Don't miss this exclusive deal just for you!" value={trackOpts.text} onChange={e => setTrackOpts({ ...trackOpts, text: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.popupButtonText', 'Button text')}</label>
                                                                <input type="text" className="form-input" placeholder="CLAIM 50% OFF" value={trackOpts.popupButtonText} onChange={e => setTrackOpts({ ...trackOpts, popupButtonText: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.buttonColor', 'Button color')}</label>
                                                                <select className="form-select" value={trackOpts.buttonColor} onChange={e => setTrackOpts({ ...trackOpts, buttonColor: e.target.value })}>
                                                                    {EXIT_BUTTON_COLORS.map(c => (
                                                                        <option key={c.value} value={c.value}>{t(c.labelKey)}</option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.offerUrl', 'Offer URL')}</label>
                                                                <input type="text" className="form-input" placeholder="https://your-offer.com" value={trackOpts.offerUrl} onChange={e => setTrackOpts({ ...trackOpts, offerUrl: e.target.value })} />
                                                            </div>
                                                            <div>
                                                                <label className="form-label">{t('tracking.showDelay', 'Show delay, seconds')}</label>
                                                                <input type="number" min="0" className="form-input" value={trackOpts.showDelay} onChange={e => setTrackOpts({ ...trackOpts, showDelay: parseInt(e.target.value) || 0 })} />
                                                            </div>
                                                            <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={trackOpts.closeOnBackdrop}
                                                                    onChange={e => setTrackOpts({ ...trackOpts, closeOnBackdrop: e.target.checked })}
                                                                />
                                                                {t('tracking.closeOnBackdrop', 'Close on backdrop click')}
                                                            </label>
                                                        </>
                                                    )}

                                                    {trackingMethod === 'pixel' && (
                                                        <>
                                                            <div>
                                                                <label className="form-label">{t('tracking.pixelType', 'Pixel type')}</label>
                                                                <select className="form-select" value={trackOpts.pixelType} onChange={e => setTrackOpts({ ...trackOpts, pixelType: e.target.value })}>
                                                                    <option value="click">{t('tracking.pixelClick', 'Click Tracking (email / banners)')}</option>
                                                                    <option value="conversion">{t('tracking.pixelConversion', 'Conversion (Thank You page)')}</option>
                                                                </select>
                                                            </div>
                                                            {trackOpts.pixelType === 'conversion' && (
                                                                <>
                                                                    <div>
                                                                        <label className="form-label">{t('tracking.convStatus', 'Conversion status')}</label>
                                                                        <select className="form-select" value={trackOpts.convStatus} onChange={e => setTrackOpts({ ...trackOpts, convStatus: e.target.value })}>
                                                                            {['lead', 'sale', 'deposit', 'registration'].map(st => (
                                                                                <option key={st} value={st}>{t('conversions.' + st, st)}</option>
                                                                            ))}
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label className="form-label">{t('tracking.payoutValue', 'Payout value (optional)')}</label>
                                                                        <input type="number" min="0" step="0.01" className="form-input" placeholder="10.00" value={trackOpts.payout} onChange={e => setTrackOpts({ ...trackOpts, payout: e.target.value })} />
                                                                    </div>
                                                                    <div>
                                                                        <label className="form-label">{t('tracking.subidParam', 'SubID parameter')}</label>
                                                                        <select className="form-select" value={trackOpts.subidParam} onChange={e => setTrackOpts({ ...trackOpts, subidParam: e.target.value })}>
                                                                            <option value="{subid}">{t('tracking.subidTemplate', '{subid} — templated by your email/CRM platform')}</option>
                                                                            <option value="{clickid}">{t('tracking.clickidTemplate', '{clickid} — templated click id')}</option>
                                                                        </select>
                                                                    </div>
                                                                </>
                                                            )}
                                                        </>
                                                    )}

                                                    {['campaign_url', 'link', 'iframe', 'script', 'tracking_script', 'wordpress'].includes(trackingMethod) && (
                                                        <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                            {t('tracking.noOptions', 'This method has no extra options — the code on the right is ready to use.')}
                                                        </p>
                                                    )}
                                                </div>

                                                {/* RIGHT — generated code, copy, preview, install instruction */}
                                                <div className="space-y-3">
                                                    <div style={{ position: 'relative' }}>
                                                        <button
                                                            type="button"
                                                            onClick={async () => {
                                                                await copyIntegrationSnippet(buildSnippet(trackingMethod, snippetCtx(), trackOpts));
                                                                setSnippetCopied(true);
                                                                setTimeout(() => setSnippetCopied(false), 2000);
                                                            }}
                                                            className={snippetCopied ? 'btn btn-primary btn-icon' : 'btn btn-secondary btn-icon'}
                                                            style={{ position: 'absolute', top: '8px', right: '8px', zIndex: 1, display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '6px 12px' }}
                                                            title={t('common.copy')}
                                                        >
                                                            {snippetCopied
                                                                ? <><Check className="w-4 h-4" /> {t('tracking.copied', 'Copied!')}</>
                                                                : <><Copy className="w-4 h-4" /> {t('tracking.copyCode', 'Copy code')}</>}
                                                        </button>
                                                        <div className="form-label" style={{ marginBottom: '6px' }}>{t('tracking.generatedCode', 'Generated integration code')}</div>
                                                        <pre
                                                            className="text-xs"
                                                            style={{
                                                                fontFamily: 'monospace',
                                                                color: 'var(--color-text-secondary)',
                                                                background: 'var(--color-bg-soft)',
                                                                border: '1px solid var(--color-border)',
                                                                borderRadius: '8px',
                                                                padding: '12px 140px 12px 12px',
                                                                margin: 0,
                                                                whiteSpace: 'pre-wrap',
                                                                wordBreak: 'break-all',
                                                                maxHeight: '420px',
                                                                overflowY: 'auto'
                                                            }}
                                                        >
                                                            {buildSnippet(trackingMethod, snippetCtx(), trackOpts)}
                                                        </pre>
                                                    </div>

                                                    {(trackingMethod === 'countdown' || trackingMethod === 'exit_intent') && (
                                                        <div className="space-y-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => setShowWidgetPreview(!showWidgetPreview)}
                                                                className="btn btn-secondary btn-icon"
                                                                style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '6px 12px' }}
                                                            >
                                                                <Eye className="w-4 h-4" />
                                                                {showWidgetPreview
                                                                    ? t('tracking.hidePreview', 'Hide preview')
                                                                    : t('tracking.previewWidget', 'Preview widget')}
                                                            </button>
                                                            {showWidgetPreview && (
                                                                <div className="rounded-2xl p-4" style={{ border: '1px dashed var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                                                    {trackingMethod === 'countdown' ? (
                                                                        <CountdownPreview
                                                                            hours={trackOpts.hours}
                                                                            minutes={trackOpts.minutes}
                                                                            headerText={trackOpts.headerText}
                                                                            buttonText={trackOpts.buttonText}
                                                                            theme={trackOpts.theme}
                                                                            expireAction={trackOpts.expireAction}
                                                                        />
                                                                    ) : (
                                                                        <ExitIntentPreview
                                                                            heading={trackOpts.heading}
                                                                            text={trackOpts.text}
                                                                            buttonText={trackOpts.popupButtonText}
                                                                            buttonColor={trackOpts.buttonColor}
                                                                        />
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}

                                                    <div className="rounded-2xl p-3 flex items-start gap-2" style={{ border: '1px solid var(--color-border)', backgroundColor: 'color-mix(in srgb, var(--color-primary) 6%, transparent)' }}>
                                                        <Info className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--color-primary)', marginTop: '1px' }} />
                                                        <div>
                                                            <div className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)', marginBottom: '2px' }}>
                                                                {t('tracking.howToInstall', 'How to install')}
                                                            </div>
                                                            <div className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                                {t(METHOD_INSTALL_HINTS[trackingMethod] || 'tracking.instWidgets')}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
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
                                    {formData.rotation_type === 'weight' && (
                                        <div className="flex items-center justify-between p-3 rounded-xl border text-xs" style={{
                                            backgroundColor: `color-mix(in srgb, ${totalStreamWeight === 100 ? 'var(--color-success)' : 'var(--color-warning)'} 8%, transparent)`,
                                            borderColor: totalStreamWeight === 100 ? 'var(--color-success)' : 'var(--color-warning)'
                                        }}>
                                            <div className="flex items-center gap-2">
                                                <span>{totalStreamWeight === 100 ? '✓' : '⚠️'}</span>
                                                <span className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                                    {t('editor.totalWeight', 'Total Stream Weight')}: {totalStreamWeight}%
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={handleEqualizeStreamWeights}
                                                className="btn btn-secondary btn-sm text-xs py-1 px-2.5 rounded-lg flex items-center gap-1 font-semibold"
                                            >
                                                <span>⚖</span>
                                                <span>{t('editor.equalizeSplit', 'Split Evenly')}</span>
                                            </button>
                                        </div>
                                    )}
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

                                                    {/* Weight + live share badge — the single weight control
                                                        for weighted rotation (the old duplicate inside stream
                                                        settings is gone). */}
                                                    {formData.rotation_type === 'weight' && stream.type === 'regular' && (
                                                        <div className="flex items-center gap-1.5 px-2 py-0.5 rounded-lg" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                                            <span className="text-[11px] font-semibold whitespace-nowrap" style={{ color: 'var(--color-text-muted)' }}>{t('editor.streamWeight')}:</span>
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                value={stream.weight ?? 100}
                                                                onChange={e => updateStream(idx, 'weight', Math.max(0, parseInt(e.target.value, 10) || 0))}
                                                                className="w-14 text-center text-xs font-bold py-0.5 px-1 rounded-md"
                                                                style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', color: 'var(--color-text-primary)' }}
                                                                title={t('editor.streamWeightHelp')}
                                                            />
                                                            <span
                                                                className="text-xs font-extrabold px-1.5 py-0.5 rounded-md whitespace-nowrap"
                                                                style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}
                                                                title={`${stream.weight ?? 100} / ${totalStreamWeight || 0}`}
                                                            >
                                                                {totalStreamWeight > 0 ? `${(((stream.weight ?? 100) / totalStreamWeight) * 100).toFixed(1)}%` : '—'}
                                                            </span>
                                                        </div>
                                                    )}
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
                                                    <label
                                                        className="flex items-center gap-2 text-sm cursor-pointer"
                                                        style={{ color: (stream.collect_clicks ?? 1) == 1 ? 'var(--color-text-primary)' : 'var(--color-text-muted)' }}
                                                        title={t('editor.collectClicksHint')}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={(stream.collect_clicks ?? 1) == 1}
                                                            onChange={e => updateStream(idx, 'collect_clicks', e.target.checked ? 1 : 0)}
                                                            className="rounded"
                                                        />
                                                        {t('editor.collectClicks')}
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
                                                {(stream.collect_clicks ?? 1) == 0 && (
                                                    <div className="rounded-2xl px-3 py-2 flex items-center gap-2" style={{
                                                        backgroundColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 10%, transparent)',
                                                        border: '1px solid var(--color-warning, #f59e0b)'
                                                    }}>
                                                        <span className="text-sm" style={{ color: 'var(--color-warning, #f59e0b)' }}>ℹ️</span>
                                                        <span className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                                            {t('editor.collectClicksDisabledNote')}
                                                        </span>
                                                    </div>
                                                )}
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
                                                        <option value="cloak">{t('cloaking.mode')}</option>
                                                    </select>
                                                </div>

                                                {stream.schema_type === 'action' && (() => {
                                                    // The router accepts "type" or "type:payload" — the
                                                    // payload carries the HTML/text, or the target campaign id.
                                                    const raw = String(stream.action_payload || '');
                                                    const sep = raw.indexOf(':');
                                                    const aType = sep === -1 ? raw : raw.slice(0, sep);
                                                    const aPayload = sep === -1 ? '' : raw.slice(sep + 1);
                                                    const setAction = (type, payload) => updateStream(idx, 'action_payload',
                                                        (type === 'to_campaign' || type === 'show_html' || type === 'show_text') && payload !== '' && payload != null
                                                            ? `${type}:${payload}`
                                                            : type);
                                                    return (
                                                        <div className="space-y-2" style={{ minWidth: '220px' }}>
                                                            <select
                                                                value={aType}
                                                                onChange={e => {
                                                                    const nextType = e.target.value;
                                                                    // Keep the payload only for types that use one.
                                                                    setAction(nextType, ['show_html', 'show_text', 'to_campaign'].includes(nextType) ? aPayload : '');
                                                                }}
                                                                className="form-select"
                                                                style={{ backgroundColor: 'var(--color-bg-soft)' }}
                                                            >
                                                                <option value="">{t('editor.selectAction')}</option>
                                                                <option value="do_nothing">{t('editor.doNothing')}</option>
                                                                <option value="not_found">{t('editor.show404')}</option>
                                                                <option value="show_text">{t('editor.showText', 'Show text / blank')}</option>
                                                                <option value="show_html">{t('editor.showHtml')}</option>
                                                                <option value="to_campaign">{t('editor.toCampaign', 'Send to campaign')}</option>
                                                            </select>

                                                            {(aType === 'show_html' || aType === 'show_text') && (
                                                                <textarea
                                                                    className="form-input text-xs"
                                                                    rows={aType === 'show_html' ? 3 : 1}
                                                                    value={aPayload}
                                                                    onChange={e => setAction(aType, e.target.value)}
                                                                    placeholder={aType === 'show_html' ? '<h1>...</h1>' : t('editor.textPayloadHint', 'текст; пусто = пустая белая страница')}
                                                                />
                                                            )}

                                                            {aType === 'to_campaign' && (
                                                                <select
                                                                    className="form-select"
                                                                    value={aPayload || ''}
                                                                    onChange={e => setAction('to_campaign', e.target.value)}
                                                                >
                                                                    <option value="">{t('editor.selectCampaign')}</option>
                                                                    {allCampaigns
                                                                        .filter(c => String(c.id) !== String(activeCampaignId || ''))
                                                                        .map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                                                </select>
                                                            )}
                                                        </div>
                                                    );
                                                })()}

                                                {stream.schema_type === 'landing_offer' && (
                                                    <div className="space-y-3 rounded-2xl p-3" style={{ border: '1px solid var(--color-border)', backgroundColor: 'rgba(59, 130, 246, 0.05)' }}>
                                                        <div>
                                                            <div className="flex justify-between items-center mb-2">
                                                                <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.landings')}</span>
                                                                <AddDropdownButton
                                                                    label={t('editor.addLandings')}
                                                                    createLabel={t('editor.createLandingDropdown')}
                                                                    onMain={() => openEntityPicker(idx, 'landings')}
                                                                    onCreate={() => setQuickCreate({ kind: 'landings', streamIdx: idx })}
                                                                />
                                                            </div>
                                                            {(stream.schema_custom?.landings || []).length === 0 ? (
                                                                <div className="text-xs py-3 px-4 rounded-xl border border-dashed text-center" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }}>
                                                                    {t('editor.noLandingsAdded')}
                                                                </div>
                                                            ) : (
                                                                <div className="space-y-1.5">
                                                                    {(stream.schema_custom?.landings || []).map((l, lIdx, list) => renderLandingRow(idx, l, lIdx, list))}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                            <div className="flex justify-between items-center mb-2">
                                                                <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.offers')}</span>
                                                                <AddDropdownButton
                                                                    label={t('editor.addOffers')}
                                                                    createLabel={t('editor.createOfferDropdown')}
                                                                    onMain={() => openEntityPicker(idx, 'offers')}
                                                                    onCreate={() => setQuickCreate({ kind: 'offers', streamIdx: idx })}
                                                                />
                                                            </div>
                                                            {(stream.schema_custom?.offers || []).length === 0 ? (
                                                                <div className="text-xs py-3 px-4 rounded-xl border border-dashed text-center" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }}>
                                                                    {t('editor.noOffersAdded')}
                                                                </div>
                                                            ) : (
                                                                <div className="space-y-1.5">
                                                                    {(stream.schema_custom?.offers || []).map((o, oIdx, list) => renderOfferRow(idx, o, oIdx, list))}
                                                                </div>
                                                            )}

                                                            <div className="mt-3 pt-3" style={{ borderTop: '1px dashed var(--color-border)' }}>
                                                                <div className="text-xs font-semibold mb-1" style={{ color: 'var(--color-text-primary)' }}>{t('editor.offerSelection')}</div>
                                                                <div className="flex gap-4">
                                                                    {['before', 'after'].map(mode => (
                                                                        <label key={mode} className="flex items-center gap-1 text-xs cursor-pointer" style={{ color: 'var(--color-text-secondary)' }}>
                                                                            <input
                                                                                type="radio"
                                                                                checked={(stream.offer_selection || 'before') === mode}
                                                                                onChange={() => updateStream(idx, 'offer_selection', mode)}
                                                                            />
                                                                            {mode === 'before' ? t('editor.offerSelectionBefore') : t('editor.offerSelectionAfter')}
                                                                        </label>
                                                                    ))}
                                                                </div>
                                                                <p className="mt-1" style={{ fontSize: '11.5px', color: 'var(--color-text-muted)', lineHeight: 1.5 }}>{t('editor.offerSelectionHint')}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                )}

                                                {stream.schema_type === 'cloak' && (() => {
                                                    const sc = stream.schema_custom || {};
                                                    const setCloakField = (field, value) => updateStream(idx, 'schema_custom', { ...sc, [field]: value });
                                                    const safeMode = sc.safe_mode || (sc.safe_landing_id ? 'landing' : sc.safe_html ? 'html' : 'url');
                                                    const setSafeMode = (mode) => updateStream(idx, 'schema_custom', { ...sc, safe_mode: mode });

                                                    return (
                                                        <div className="space-y-4 rounded-2xl p-4" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                                            <div className="text-xs" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                                                {t('cloaking.description')}
                                                            </div>

                                                            {/* Detection Layers Pills */}
                                                            <div>
                                                                <span className="text-xs font-semibold uppercase tracking-wider block mb-2" style={{ color: 'var(--color-text-muted)' }}>
                                                                    {t('streamRefine.detectionLayers', 'Bot Protection Layers')}
                                                                </span>
                                                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                                                    {[
                                                                        ['detect_datacenter', t('cloaking.datacenter', 'Datacenter / ASN')],
                                                                        ['detect_vpn', t('cloaking.vpnProxy', 'VPN / Proxy')],
                                                                        ['detect_bots', t('cloaking.bots', 'Known Bots / Crawlers')],
                                                                        ['detect_ua', t('cloaking.uaHeuristics', 'UA Heuristics')],
                                                                    ].map(([key, label]) => {
                                                                        const isChecked = sc[key] !== false;
                                                                        return (
                                                                            <label
                                                                                key={key}
                                                                                className="flex items-center gap-2 p-2 rounded-xl border cursor-pointer select-none transition-all text-xs font-medium"
                                                                                style={{
                                                                                    backgroundColor: isChecked ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                                    borderColor: isChecked ? 'var(--color-primary)' : 'var(--color-border)',
                                                                                    color: isChecked ? 'var(--color-primary)' : 'var(--color-text-primary)'
                                                                                }}
                                                                            >
                                                                                <input
                                                                                    type="checkbox"
                                                                                    checked={isChecked}
                                                                                    onChange={e => setCloakField(key, e.target.checked)}
                                                                                    className="w-3.5 h-3.5 rounded"
                                                                                    style={{ accentColor: 'var(--color-primary)' }}
                                                                                />
                                                                                <span>{label}</span>
                                                                            </label>
                                                                        );
                                                                    })}
                                                                </div>
                                                            </div>

                                                            {/* Sensitivity & JS Challenge row */}
                                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                                <div>
                                                                    <label className="text-xs font-semibold uppercase mb-1 block" style={{ color: 'var(--color-text-muted)' }}>
                                                                        {t('cloaking.sensitivity')}
                                                                    </label>
                                                                    <select
                                                                        value={sc.sensitivity || 'medium'}
                                                                        onChange={e => setCloakField('sensitivity', e.target.value)}
                                                                        className="form-select text-xs py-1.5 rounded-xl"
                                                                    >
                                                                        <option value="low">{t('cloaking.sensitivityLow', 'Low (Fewer false positives)')}</option>
                                                                        <option value="medium">{t('cloaking.sensitivityMedium', 'Medium (Recommended balance)')}</option>
                                                                        <option value="high">{t('cloaking.sensitivityHigh', 'High (Aggressive blocking)')}</option>
                                                                    </select>
                                                                </div>
                                                                <div>
                                                                    <label className="text-xs font-semibold uppercase mb-1 block" style={{ color: 'var(--color-text-muted)' }}>
                                                                        {t('cloaking.jsChallenge', 'Active Browser Verification')}
                                                                    </label>
                                                                    <label
                                                                        className="flex items-center gap-2 p-1.5 rounded-xl border cursor-pointer select-none text-xs"
                                                                        style={{
                                                                            backgroundColor: Boolean(sc.js_challenge) ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                            borderColor: Boolean(sc.js_challenge) ? 'var(--color-primary)' : 'var(--color-border)',
                                                                            color: 'var(--color-text-primary)'
                                                                        }}
                                                                    >
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={Boolean(sc.js_challenge)}
                                                                            onChange={e => setCloakField('js_challenge', e.target.checked)}
                                                                            className="w-3.5 h-3.5"
                                                                            style={{ accentColor: 'var(--color-primary)' }}
                                                                        />
                                                                        <span>{t('cloaking.jsChallenge', 'JS Fingerprint Challenge')}</span>
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            {/* Targeting Filters: hard routing rules — any miss goes to the Safe Page */}
                                                            <div className="space-y-3 pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                                <span className="text-xs font-semibold uppercase tracking-wider block" style={{ color: 'var(--color-text-muted)' }}>
                                                                    🎯 {t('cloaking.targetingTitle', 'Targeting Filters (Mismatch → Safe Page)')}
                                                                </span>

                                                                {/* Country (GEO) */}
                                                                <div>
                                                                    <div className="flex flex-wrap items-center justify-between gap-2 mb-1.5">
                                                                        <label className="text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                                                            🌍 {t('cloaking.geoFilter', 'Country Filter')}
                                                                        </label>
                                                                        <div className="flex items-center gap-3 text-xs">
                                                                            {[['allow', t('cloaking.allowIn', 'Allow (in)')], ['deny', t('cloaking.blockIn', 'Block (not in)')]].map(([mode, label]) => (
                                                                                <label key={mode} className="flex items-center gap-1 cursor-pointer">
                                                                                    <input
                                                                                        type="radio"
                                                                                        name={`cloak_geo_mode_${idx}`}
                                                                                        checked={(sc.geo_mode || 'allow') === mode}
                                                                                        onChange={() => setCloakField('geo_mode', mode)}
                                                                                        className="w-3 h-3"
                                                                                        style={{ accentColor: 'var(--color-primary)' }}
                                                                                    />
                                                                                    <span>{label}</span>
                                                                                </label>
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                    <GeoSelector
                                                                        value={sc.countries || ''}
                                                                        onChange={selected => setCloakField('countries', selected)}
                                                                        placeholder={t('cloaking.geoPlaceholder', 'Select target countries (e.g. US, DE, GB)...')}
                                                                    />
                                                                </div>

                                                                {/* Canonical device groups shared by routing and cloaking */}
                                                                <div>
                                                                    <div className="flex flex-wrap items-center justify-between gap-2 mb-1.5">
                                                                        <label className="text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                                                            📱 {t('cloaking.deviceFilter', 'Device Types')}
                                                                        </label>
                                                                        <div className="flex items-center gap-3 text-xs">
                                                                            {[['allow', t('cloaking.allowOnly', 'Allow only')], ['deny', t('cloaking.blockSelected', 'Block selected')]].map(([mode, label]) => (
                                                                                <label key={mode} className="flex items-center gap-1 cursor-pointer">
                                                                                    <input
                                                                                        type="radio"
                                                                                        name={`cloak_device_mode_${idx}`}
                                                                                        checked={(sc.device_mode || 'allow') === mode}
                                                                                        onChange={() => setCloakField('device_mode', mode)}
                                                                                        className="w-3 h-3"
                                                                                        style={{ accentColor: 'var(--color-primary)' }}
                                                                                    />
                                                                                    <span>{label}</span>
                                                                                </label>
                                                                            ))}
                                                                        </div>
                                                                    </div>
                                                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                                                        {[
                                                                            { id: 'mobile', label: t('streams.mobile', 'Mobile') },
                                                                            { id: 'tablet', label: t('streams.tablet', 'Tablet') },
                                                                            { id: 'desktop', label: t('streams.desktop', 'Desktop') }
                                                                        ].map(({ id: dev, label }) => {
                                                                            const currentDevs = typeof sc.devices === 'string' && sc.devices.trim() !== ''
                                                                                ? sc.devices.split(',').map(d => d.trim().toLowerCase()).filter(Boolean)
                                                                                : ['mobile', 'tablet', 'desktop'];
                                                                            const isSelected = currentDevs.includes(dev);
                                                                            return (
                                                                                <label
                                                                                    key={dev}
                                                                                    className="flex items-center gap-2 p-2 rounded-xl border cursor-pointer select-none transition-all text-xs font-medium"
                                                                                    style={{
                                                                                        backgroundColor: isSelected ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                                        borderColor: isSelected ? 'var(--color-primary)' : 'var(--color-border)',
                                                                                        color: isSelected ? 'var(--color-primary)' : 'var(--color-text-primary)'
                                                                                    }}
                                                                                >
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        checked={isSelected}
                                                                                        onChange={e => {
                                                                                            const next = e.target.checked
                                                                                                ? [...currentDevs, dev]
                                                                                                : currentDevs.filter(d => d !== dev);
                                                                                            setCloakField('devices', next.join(','));
                                                                                        }}
                                                                                        className="w-3.5 h-3.5 rounded"
                                                                                        style={{ accentColor: 'var(--color-primary)' }}
                                                                                    />
                                                                                    <span>{label}</span>
                                                                                </label>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>

                                                                {/* Bot ISP blocklist */}
                                                                <div className="space-y-1.5">
                                                                    <label
                                                                        className="flex items-center gap-2 p-2 rounded-xl border cursor-pointer select-none text-xs font-medium"
                                                                        style={{
                                                                            backgroundColor: sc.block_bot_isps !== false ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                            borderColor: sc.block_bot_isps !== false ? 'var(--color-primary)' : 'var(--color-border)',
                                                                            color: 'var(--color-text-primary)'
                                                                        }}
                                                                    >
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={sc.block_bot_isps !== false}
                                                                            onChange={e => setCloakField('block_bot_isps', e.target.checked)}
                                                                            className="w-3.5 h-3.5 rounded"
                                                                            style={{ accentColor: 'var(--color-primary)' }}
                                                                        />
                                                                        <span>🛡️ {t('cloaking.blockBotIsps', 'Block Bot & Datacenter ISPs (Facebook, Google, Amazon, Hetzner, etc.)')}</span>
                                                                    </label>
                                                                    {sc.block_bot_isps !== false && (
                                                                        <div>
                                                                            <textarea
                                                                                rows={2}
                                                                                className="form-input text-xs font-mono py-1.5 rounded-xl"
                                                                                placeholder={t('cloaking.botIspPlaceholder', 'Local override: facebook, hetzner, ... (leave empty for the global list)')}
                                                                                value={sc.custom_bot_isps || ''}
                                                                                onChange={e => setCloakField('custom_bot_isps', e.target.value)}
                                                                            />
                                                                            <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                                                                {t('cloaking.botIspHint', "Matched against the visitor's ISP and ASN. Leave empty to use the global list from Settings → Bots.")}
                                                                            </p>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            {/* Safe Page Section */}
                                                            <div className="p-3.5 rounded-xl space-y-3" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <span className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-primary)' }}>
                                                                        🛡️ {t('streamRefine.safePageTitle', 'Safe Page (For Reviewers & Bots)')}
                                                                    </span>
                                                                    <div className="flex rounded-lg overflow-hidden border" style={{ borderColor: 'var(--color-border)' }}>
                                                                        {[
                                                                            ['url', t('streamRefine.tabUrl', 'External URL')],
                                                                            ['landing', t('streamRefine.tabLanding', 'Tracker Landing')],
                                                                            ['html', t('streamRefine.tabHtml', 'Inline HTML')]
                                                                        ].map(([mode, label]) => (
                                                                            <button
                                                                                key={mode}
                                                                                type="button"
                                                                                onClick={() => setSafeMode(mode)}
                                                                                className="px-2.5 py-1 text-[11px] font-medium transition"
                                                                                style={{
                                                                                    backgroundColor: safeMode === mode ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                                                                    color: safeMode === mode ? '#ffffff' : 'var(--color-text-secondary)'
                                                                                }}
                                                                            >
                                                                                {label}
                                                                            </button>
                                                                        ))}
                                                                    </div>
                                                                </div>

                                                                {safeMode === 'url' && (
                                                                    <div>
                                                                        <input
                                                                            type="url"
                                                                            value={sc.safe_url || ''}
                                                                            onChange={e => setCloakField('safe_url', e.target.value)}
                                                                            className="form-input text-xs font-mono py-1.5 rounded-xl"
                                                                            placeholder="https://safe-white-page.com"
                                                                        />
                                                                    </div>
                                                                )}

                                                                {safeMode === 'landing' && (
                                                                    <div>
                                                                        <select
                                                                            value={sc.safe_landing_id || ''}
                                                                            onChange={e => setCloakField('safe_landing_id', e.target.value ? parseInt(e.target.value) : null)}
                                                                            className="form-select text-xs py-1.5 rounded-xl"
                                                                        >
                                                                            <option value="">{t('cloaking.safeLandingNone', 'Select a Safe Landing...')}</option>
                                                                            {allLandings.map(al => <option key={al.id} value={al.id}>{al.name}</option>)}
                                                                        </select>
                                                                    </div>
                                                                )}

                                                                {safeMode === 'html' && (
                                                                    <div>
                                                                        <textarea
                                                                            value={sc.safe_html || ''}
                                                                            onChange={e => setCloakField('safe_html', e.target.value)}
                                                                            className="form-input text-xs font-mono py-1.5 rounded-xl"
                                                                            rows={3}
                                                                            placeholder="<!DOCTYPE html><html><body><h1>Welcome</h1></body></html>"
                                                                        />
                                                                    </div>
                                                                )}

                                                                <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                                    <label className="flex items-start gap-2 cursor-pointer select-none">
                                                                        <input
                                                                            type="checkbox"
                                                                            checked={sc.dont_record_safe_clicks !== false}
                                                                            onChange={e => setCloakField('dont_record_safe_clicks', e.target.checked)}
                                                                            className="w-4 h-4 rounded mt-0.5"
                                                                            style={{ accentColor: 'var(--color-primary)' }}
                                                                        />
                                                                        <span>
                                                                            <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                                                                🚫 {t('cloaking.dontRecordSafeClicks', 'Do not record clicks for Safe Page')}
                                                                            </span>
                                                                            <span className="block text-[11px]" style={{ color: 'var(--color-text-muted)', lineHeight: 1.4 }}>
                                                                                {t('cloaking.dontRecordSafeClicksHint', 'Bots, crawlers, and reviewers routed to the Safe Page will not be saved in database logs or counted in campaign reports.')}
                                                                            </span>
                                                                        </span>
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            {/* Money Page Section */}
                                                            <div className="p-3.5 rounded-xl space-y-3" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                                                <span className="text-xs font-bold uppercase tracking-wider block" style={{ color: 'var(--color-text-primary)' }}>
                                                                    💰 {t('streamRefine.moneyPageTitle', 'Money Page (For Real Visitors)')}
                                                                </span>

                                                                {/* Money Landings */}
                                                                <div>
                                                                    <div className="flex justify-between items-center mb-1.5">
                                                                        <span className="text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>{t('editor.landings')}</span>
                                                                        <AddDropdownButton
                                                                            label={t('editor.addLandings')}
                                                                            createLabel={t('editor.createLandingDropdown')}
                                                                            onMain={() => openEntityPicker(idx, 'landings')}
                                                                            onCreate={() => setQuickCreate({ kind: 'landings', streamIdx: idx })}
                                                                        />
                                                                    </div>
                                                                    {(sc.landings || []).length === 0 ? (
                                                                        <div className="text-xs py-3 px-4 rounded-xl border border-dashed text-center" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }}>
                                                                            {t('editor.noLandingsAdded')}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="space-y-1.5">
                                                                            {(sc.landings || []).map((l, lIdx, list) => renderLandingRow(idx, l, lIdx, list))}
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                {/* Money Offers */}
                                                                <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                                                    <div className="flex justify-between items-center mb-1.5">
                                                                        <span className="text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>{t('editor.offers')}</span>
                                                                        <AddDropdownButton
                                                                            label={t('editor.addOffers')}
                                                                            createLabel={t('editor.createOfferDropdown')}
                                                                            onMain={() => openEntityPicker(idx, 'offers')}
                                                                            onCreate={() => setQuickCreate({ kind: 'offers', streamIdx: idx })}
                                                                        />
                                                                    </div>
                                                                    {(sc.offers || []).length === 0 ? (
                                                                        <div className="text-xs py-3 px-4 rounded-xl border border-dashed text-center" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }}>
                                                                            {t('editor.noOffersAdded')}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="space-y-1.5">
                                                                            {(sc.offers || []).map((o, oIdx, list) => renderOfferRow(idx, o, oIdx, list))}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    );
                                                })()}

                                                {stream.schema_type === 'redirect' && (() => {
                                                    const sc = stream.schema_custom || {};
                                                    const isDirectUrl = sc.redirect_mode === 'direct_url' || !!sc.direct_url;
                                                    const setRedirectMode = (mode) => {
                                                        updateStream(idx, 'schema_custom', { ...sc, redirect_mode: mode });
                                                    };
                                                    const setDirectUrl = (url) => {
                                                        updateStream(idx, 'schema_custom', { ...sc, redirect_mode: 'direct_url', direct_url: url });
                                                    };

                                                    return (
                                                        <div className="space-y-4 rounded-2xl p-4" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                                            {/* Segmented Destination Mode Selector: Tracker Offer vs Direct URL */}
                                                            <div>
                                                                <label className="text-xs font-semibold uppercase mb-1.5 block" style={{ color: 'var(--color-text-muted)' }}>
                                                                    {t('streamRefine.directMode', 'Destination Type')}
                                                                </label>
                                                                <div className="flex rounded-xl overflow-hidden max-w-sm" style={{ border: '1px solid var(--color-border)' }}>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => setRedirectMode('offers')}
                                                                        className="flex-1 px-3 py-1.5 text-xs font-medium transition"
                                                                        style={{
                                                                            backgroundColor: !isDirectUrl ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                            color: !isDirectUrl ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                                                            borderRight: '1px solid var(--color-border)'
                                                                        }}
                                                                    >
                                                                        {t('streamRefine.trackerOffer', 'Tracker Offer')}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => setRedirectMode('direct_url')}
                                                                        className="flex-1 px-3 py-1.5 text-xs font-medium transition"
                                                                        style={{
                                                                            backgroundColor: isDirectUrl ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                                                            color: isDirectUrl ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                                                                        }}
                                                                    >
                                                                        {t('streamRefine.directUrl', 'Direct URL')}
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            {isDirectUrl ? (
                                                                <div className="space-y-2">
                                                                    <label className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                                                        {t('streamRefine.directUrl', 'Direct Target URL')}
                                                                    </label>
                                                                    <input
                                                                        type="url"
                                                                        value={sc.direct_url || ''}
                                                                        onChange={e => setDirectUrl(e.target.value)}
                                                                        placeholder={t('streamRefine.directUrlPlaceholder', 'https://affiliate-offer.com/?subid={subid}&clickid={clickid}')}
                                                                        className="form-input text-xs font-mono py-2 rounded-xl"
                                                                    />
                                                                    <p className="text-xs" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                                                        {t('streamRefine.directUrlHelp')}
                                                                    </p>
                                                                    {/* Macro Helper Tags */}
                                                                    <div className="flex flex-wrap gap-1.5 pt-1">
                                                                        {['{subid}', '{clickid}', '{country}', '{ip}', '{sub_id_1}', '{sub_id_2}', '{sub_id_3}', '{cost}'].map(tag => (
                                                                            <button
                                                                                key={tag}
                                                                                type="button"
                                                                                onClick={() => setDirectUrl((sc.direct_url || '') + (sc.direct_url?.includes('?') ? '&' : '?') + `${tag.slice(1,-1)}=${tag}`)}
                                                                                className="text-[11px] px-2 py-0.5 rounded-lg border font-mono transition-colors hover:border-blue-400"
                                                                                style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}
                                                                            >
                                                                                + {tag}
                                                                            </button>
                                                                        ))}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <div className="space-y-3">
                                                                    <div className="flex justify-between items-center">
                                                                        <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('editor.offers')}</span>
                                                                        <AddDropdownButton
                                                                            label={t('editor.addOffers')}
                                                                            createLabel={t('editor.createOfferDropdown')}
                                                                            onMain={() => openEntityPicker(idx, 'offers')}
                                                                            onCreate={() => setQuickCreate({ kind: 'offers', streamIdx: idx })}
                                                                        />
                                                                    </div>

                                                                    {(() => {
                                                                        const offers = sc.offers || [];
                                                                        const totalWeight = offers.reduce((sum, o) => sum + (parseInt(o.weight) || 0), 0);
                                                                        const isOverWeight = totalWeight > 100 && offers.length > 1;

                                                                        return (
                                                                            <>
                                                                                {offers.length === 0 && (
                                                                                    <div className="text-xs text-center py-4 rounded-xl border border-dashed" style={{ color: 'var(--color-text-muted)', borderColor: 'var(--color-border)' }}>
                                                                                        {t('editor.addOffersHelp')}
                                                                                    </div>
                                                                                )}
                                                                                {isOverWeight && (
                                                                                    <div className="text-xs rounded-lg p-2" style={{ color: 'var(--color-warning)', backgroundColor: 'var(--color-warning-bg)', border: '1px solid var(--color-warning)' }}>
                                                                                        {t('editor.weightWarning')} {totalWeight}{t('editor.weightWarningEnd')}
                                                                                    </div>
                                                                                )}
                                                                                {offers.map((o, oIdx, list) => renderOfferRow(idx, o, oIdx, list))}
                                                                            </>
                                                                        );
                                                                    })()}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })()}

                                                {/* Filters */}
                                                {stream.type !== 'fallback' && (
                                                    <div>
                                                        <div className="flex justify-between items-center mb-2">
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-xs font-semibold uppercase" style={{ color: 'var(--color-text-muted)' }}>{t('editor.filters')}</span>
                                                                {/* AND / OR: one filter can't be combined with anything,
                                                                    so the switcher appears from the second filter on. */}
                                                                {stream.filters && stream.filters.length > 1 && (
                                                                    <div className="inline-flex rounded-lg p-0.5" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }} title={t('editor.filtersLogicHint')}>
                                                                        {['and', 'or'].map(mode => {
                                                                            const active = (stream.filters_logic || 'and') === mode;
                                                                            return (
                                                                                <button
                                                                                    key={mode}
                                                                                    type="button"
                                                                                    onClick={() => updateStream(idx, 'filters_logic', mode)}
                                                                                    className="px-2 py-0.5 text-[11px] font-bold rounded-md transition-colors"
                                                                                    style={{
                                                                                        backgroundColor: active ? 'var(--color-primary)' : 'transparent',
                                                                                        color: active ? 'var(--color-text-inverse)' : 'var(--color-text-secondary)'
                                                                                    }}
                                                                                >
                                                                                    {mode.toUpperCase()}
                                                                                </button>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                )}
                                                            </div>
                                                            <button onClick={() => openFilterModal(idx)} className="text-xs" style={{ color: 'var(--color-primary)' }}>{t('editor.addFilter')}</button>
                                                        </div>
                                                        {stream.filters && stream.filters.length > 0 ? (
                                                            <div className="space-y-1">
                                                                {stream.filters.map((f, fIdx) => (
                                                                    <div key={fIdx} className="flex rounded-lg text-sm overflow-hidden items-center" style={{ border: '1px solid var(--color-border)' }}>
                                                                        <div className="px-2 py-1 font-semibold" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-primary)' }}>{f.name}</div>
                                                                        <div className="px-2 py-1 font-bold" style={{ color: f.mode === 'include' ? 'var(--color-success)' : 'var(--color-danger)' }}>
                                                                            {f.mode === 'include' ? '✓' : '✗'}
                                                                        </div>
                                                                        <div
                                                                            className="flex-1 px-2 py-1 truncate cursor-pointer hover:underline"
                                                                            style={{ color: 'var(--color-text-secondary)' }}
                                                                            onClick={() => openFilterModal(idx, fIdx)}
                                                                            title={t('editor.editFilter')}
                                                                        >
                                                                            {Array.isArray(f.payload) ? f.payload.join(', ') : (f.payload || '')}
                                                                        </div>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => openFilterModal(idx, fIdx)}
                                                                            className="px-1.5 py-1 text-blue-500 hover:text-blue-700"
                                                                            title={t('editor.editFilter')}
                                                                        >
                                                                            <Edit3 className="w-3.5 h-3.5" />
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => removeFilter(idx, fIdx)}
                                                                            className="px-2 py-1"
                                                                            style={{ color: 'var(--color-danger)' }}
                                                                            title={t('common.delete')}
                                                                        >
                                                                            <X className="w-3.5 h-3.5" />
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
                    <div className="modal-content" style={{ maxWidth: '600px', minHeight: '480px', overflow: 'visible', display: 'flex', flexDirection: 'column' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">
                                {filterModal.filterIdx !== null ? t('editor.editFilter') : t('editor.addFilter')}
                            </h3>
                            <button onClick={() => setFilterModal({ open: false, streamIdx: null, filterIdx: null })} className="action-btn">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-4 flex-1">
                            <div>
                                <label className="form-label">{t('editor.filterType')}</label>
                                <select
                                    value={newFilter.name}
                                    onChange={e => setNewFilter({
                                        ...newFilter,
                                        name: e.target.value,
                                        payload: e.target.value === 'Bot' ? 'yes' : ''
                                    })}
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
                                ) : newFilter.name === 'Bot' ? (
                                    <select
                                        value="yes"
                                        onChange={() => setNewFilter({ ...newFilter, payload: 'yes' })}
                                        className="form-select"
                                    >
                                        <option value="yes">{t('filters.botYes')}</option>
                                    </select>
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
                            <button onClick={() => setFilterModal({ open: false, streamIdx: null, filterIdx: null })} className="btn btn-secondary">{t('common.cancel')}</button>
                            <button onClick={saveFilter} disabled={!newFilter.payload?.trim()} className="btn btn-primary">
                                {filterModal.filterIdx !== null ? t('common.save') : t('common.add')}
                            </button>
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

            {/* Add Cost Connection Modal */}
            {showAddCostConnModal && (
                <div className="modal-overlay">
                    <div className="modal-content max-w-md w-full rounded-2xl shadow-2xl p-6" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                        <div className="flex items-center justify-between pb-3 mb-4" style={{ borderBottom: '1px solid var(--color-border)' }}>
                            <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('streamRefine.addCostConnection', '+ Add Cost Connection')}
                            </h3>
                            <button onClick={() => setShowAddCostConnModal(false)} className="btn-icon">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleSaveCostConnection} className="space-y-4">
                            <div>
                                <label className="form-label text-xs">{t('editor.trafficSource', 'Platform / Engine')}</label>
                                <select
                                    value={costConnForm.engine}
                                    onChange={e => setCostConnForm({ ...costConnForm, engine: e.target.value })}
                                    className="form-select text-xs py-2 rounded-xl"
                                >
                                    <option value="facebook">Facebook Ads (Graph API / Marketing API)</option>
                                    <option value="google_ads">Google Ads</option>
                                    <option value="tiktok">TikTok Ads</option>
                                </select>
                            </div>

                            <div>
                                <label className="form-label text-xs">{t('editor.name', 'Connection Name')}</label>
                                <input
                                    type="text"
                                    required
                                    value={costConnForm.name}
                                    onChange={e => setCostConnForm({ ...costConnForm, name: e.target.value })}
                                    placeholder="e.g. FB Ad Account Main"
                                    className="form-input text-xs py-2 rounded-xl"
                                />
                            </div>

                            <div>
                                <label className="form-label text-xs">Ad Account ID</label>
                                <input
                                    type="text"
                                    required
                                    value={costConnForm.account_id}
                                    onChange={e => setCostConnForm({ ...costConnForm, account_id: e.target.value })}
                                    placeholder="act_1234567890"
                                    className="form-input text-xs font-mono py-2 rounded-xl"
                                />
                            </div>

                            <div>
                                <label className="form-label text-xs">Access Token</label>
                                <input
                                    type="password"
                                    required
                                    value={costConnForm.access_token}
                                    onChange={e => setCostConnForm({ ...costConnForm, access_token: e.target.value })}
                                    placeholder="EAA..."
                                    className="form-input text-xs font-mono py-2 rounded-xl"
                                />
                            </div>

                            <div>
                                <ProxyInput
                                    label={t('pixels.proxy')}
                                    value={costConnForm.proxy_url}
                                    onChange={(val) => setCostConnForm({ ...costConnForm, proxy_url: val })}
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <button
                                    type="button"
                                    onClick={() => setShowAddCostConnModal(false)}
                                    className="btn btn-ghost text-xs py-1.5 px-3 rounded-xl"
                                >
                                    {t('common.cancel')}
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingCostConn}
                                    className="btn btn-primary text-xs py-1.5 px-4 rounded-xl font-semibold"
                                >
                                    {savingCostConn ? t('common.saving') : t('common.save')}
                                </button>
                            </div>
                        </form>
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

            {showReports && activeCampaignId && (
                <CampaignReports
                    campaignId={activeCampaignId}
                    campaignName={formData.name}
                    onClose={() => setShowReports(false)}
                />
            )}

            {showConversionsLog && activeCampaignId && (
                <div className="modal-overlay" style={{ zIndex: 1100, top: '88px', height: 'calc(100vh - 88px)' }}>
                    <div className="modal-content" style={{ maxWidth: '1200px', maxHeight: '100%', overflow: 'auto' }}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="modal-title">{t('editor.conversionsLog')}</h3>
                            <button onClick={() => setShowConversionsLog(false)} className="btn btn-ghost btn-icon">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <ConversionsLog
                            campaignId={activeCampaignId}
                            onClose={() => setShowConversionsLog(false)}
                        />
                    </div>
                </div>
            )}

            {/* Traffic Simulation Modal */}
            {showTrafficSimModal && (
                <div className="modal-overlay" style={{ zIndex: 1100 }}>
                    <div className="modal-content" style={{ maxWidth: '600px' }}>
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="modal-title">{t('editor.trafficSimulation')}</h3>
                            <button onClick={() => setShowTrafficSimModal(false)} className="btn btn-ghost btn-icon">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-4">
                            <div>
                                <label className="form-label">{t('editor.ipAddress')}</label>
                                <input
                                    type="text"
                                    value={trafficSimForm.ip}
                                    onChange={(e) => setTrafficSimForm({ ...trafficSimForm, ip: e.target.value })}
                                    className="form-input"
                                    placeholder="127.0.0.1"
                                />
                            </div>
                            <div>
                                <label className="form-label">{t('editor.userAgent')}</label>
                                <input
                                    type="text"
                                    value={trafficSimForm.user_agent}
                                    onChange={(e) => setTrafficSimForm({ ...trafficSimForm, user_agent: e.target.value })}
                                    className="form-input"
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="form-label">{t('editor.trafficAsn')}</label>
                                    <input
                                        type="text"
                                        value={trafficSimForm.asn}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, asn: e.target.value })}
                                        className="form-input"
                                        placeholder="AS7922"
                                    />
                                </div>
                                <div>
                                    <label className="form-label">{t('editor.trafficIsp')}</label>
                                    <input
                                        type="text"
                                        value={trafficSimForm.isp}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, isp: e.target.value })}
                                        className="form-input"
                                        placeholder="Comcast Cable Communications"
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="form-label">{t('editor.country')}</label>
                                    <select
                                        value={trafficSimForm.country}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, country: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="US">US</option>
                                        <option value="RU">RU</option>
                                        <option value="DE">DE</option>
                                        <option value="GB">GB</option>
                                        <option value="FR">FR</option>
                                        <option value="CA">CA</option>
                                        <option value="AU">AU</option>
                                        <option value="BR">BR</option>
                                        <option value="IN">IN</option>
                                        <option value="CN">CN</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('editor.deviceType')}</label>
                                    <select
                                        value={trafficSimForm.device_type}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, device_type: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="desktop">{t('streams.desktop')}</option>
                                        <option value="mobile">{t('streams.mobile')}</option>
                                        <option value="tablet">{t('streams.tablet')}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('editor.language')}</label>
                                <select
                                    value={trafficSimForm.language}
                                    onChange={(e) => setTrafficSimForm({ ...trafficSimForm, language: e.target.value })}
                                    className="form-select"
                                >
                                    <option value="en">en</option>
                                    <option value="ru">ru</option>
                                    <option value="de">de</option>
                                    <option value="fr">fr</option>
                                    <option value="es">es</option>
                                    <option value="pt">pt</option>
                                    <option value="zh">zh</option>
                                </select>
                            </div>
                            <div className="flex flex-wrap gap-4">
                                <label className="form-checkbox-label">
                                    <input
                                        type="checkbox"
                                        checked={trafficSimForm.js_executed}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, js_executed: e.target.checked })}
                                    />
                                    {t('editor.jsExecuted')}
                                </label>
                                <label className="form-checkbox-label">
                                    <input
                                        type="checkbox"
                                        checked={trafficSimForm.webdriver}
                                        onChange={(e) => setTrafficSimForm({ ...trafficSimForm, webdriver: e.target.checked })}
                                    />
                                    {t('simulation.webdriverFlag')}
                                </label>
                            </div>
                        </div>

                        {trafficSimResult && (
                            <div className="mt-4 p-4 rounded" style={{
                                background: trafficSimResult.status === 'success' ? 'var(--color-success-bg)' : 'var(--color-danger-bg)',
                                color: trafficSimResult.status === 'success' ? 'var(--color-success)' : 'var(--color-danger)'
                            }}>
                                <div className="font-semibold mb-2">
                                    {trafficSimResult.status === 'success' ? `✓ ${t('common.success')}` : `✗ ${t('common.error')}`}
                                </div>
                                {trafficSimResult.message && <div>{trafficSimResult.message}</div>}
                                {trafficSimResult.trace && (
                                    <div className="mt-2 text-xs" style={{ whiteSpace: 'pre-wrap', opacity: 0.8 }}>
                                        {typeof trafficSimResult.trace === 'string'
                                            ? trafficSimResult.trace
                                            : JSON.stringify(trafficSimResult.trace, null, 2)}
                                    </div>
                                )}
                                {trafficSimResult.data && (
                                    <div className="mt-2 text-xs" style={{ whiteSpace: 'pre-wrap', opacity: 0.8 }}>
                                        {JSON.stringify(trafficSimResult.data, null, 2)}
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="modal-footer">
                            <button onClick={() => setShowTrafficSimModal(false)} className="btn btn-secondary">
                                {t('common.cancel')}
                            </button>
                            <button
                                onClick={runTrafficSimulation}
                                disabled={trafficSimLoading}
                                className="btn btn-primary"
                            >
                                {trafficSimLoading ? (
                                    <span className="flex items-center gap-2">
                                        <span className="animate-spin">⟳</span> {t('common.loading')}...
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-2">
                                        <Play size={16} /> {t('editor.runSimulation')}
                                    </span>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Landings render the one landing form there is. The stream used to
                carry its own copy of this markup, which is exactly why the two
                drifted: an archive picker in one, none in the other, and every
                fix written twice. */}
            {quickCreate && quickCreate.kind === 'landings' && (
                <LandingEditor
                    landingId={quickCreate.editingId || null}
                    onSaved={attachLandingToStream}
                    onClose={() => setQuickCreate(null)}
                />
            )}

            {/* Offers render the shared OfferEditor now. It used to be a
                stripped-down name+URL form; the split button's "Create Offer"
                and the row edit button both land here, and a newly created
                offer wires itself into the stream via onCreated. */}
            {quickCreate && quickCreate.kind === 'offers' && (
                <OfferEditor
                    offerId={quickCreate.editingId || null}
                    onCreated={attachOfferToStream}
                    onClose={() => setQuickCreate(null)}
                />
            )}

            {/* Keitaro-style entity picker opened by a stream's "Add ..." button */}
            {pickerState.open && (
                <EntitySelectorModal
                    type={pickerState.type}
                    items={pickerState.type === 'landings' ? allLandings : allOffers}
                    existingIds={(formData.streams[pickerState.streamIdx]?.schema_custom?.[pickerState.type] || [])
                        .map(x => parseInt(x.id, 10))
                        .filter(Boolean)}
                    onClose={() => setPickerState({ open: false, streamIdx: null, type: null })}
                    onAdd={(ids) => {
                        addEntitiesToStream(pickerState.streamIdx, pickerState.type, ids);
                        setPickerState({ open: false, streamIdx: null, type: null });
                    }}
                />
            )}

            {/* Groups quick-create from the "+" next to the group select */}
            {showGroupsModal && (
                <GroupsModal
                    type="campaign"
                    onClose={() => {
                        setShowGroupsModal(false);
                        invalidateCache('campaign_groups');
                    }}
                    onGroupCreated={(g) => {
                        if (!g || !g.id) return;
                        setGroups(prev => prev.some(x => x.id == g.id) ? prev : [...prev, g]);
                        setFormData(prev => ({ ...prev, group_id: String(g.id) }));
                    }}
                />
            )}

            {/* Traffic source quick-create from the "+" next to the source select */}
            {showSourceEditor && (
                <TrafficSourceEditor
                    onClose={() => setShowSourceEditor(false)}
                    onSave={handleSourceCreated}
                />
            )}
        </>
    );
};

export default CampaignEditor;
