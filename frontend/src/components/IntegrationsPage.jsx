import React, { useState, useEffect, useCallback, useRef } from 'react';
import axios from 'axios';
import { Terminal, Code, Image as ImageIcon, Copy, CheckCircle2, Server, Globe, Zap, Send, Eye, EyeOff, RefreshCw, Trash2, MessageCircle, Bell, BellOff, Clock, Users, Download, Settings, Plus, Edit2, Power, X, ArrowRight, Smartphone, Monitor, Timer, ArrowLeft, Palette, ExternalLink, Shield, DollarSign, Cloud, Database, Tag, Music2, Search } from 'lucide-react';
import InfoBanner from './InfoBanner';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard as copyUtil } from '../utils/clipboard';
import ProxyInput from './common/ProxyInput';
import PixelPicker from './common/PixelPicker';

const API_URL = '/api.php';

const IntegrationsPage = () => {
    const { t } = useLanguage();
    const translationRef = useRef(t);
    translationRef.current = t;
    const [activeTab, setActiveTab] = useState('kclient_php');
    const [copied, setCopied] = useState('');

    // Ads Manager browser-extension state. The key is dedicated, read-only,
    // and belongs to the currently signed-in user.
    const [extTemplate, setExtTemplate] = useState('cpa');
    const [extGuide, setExtGuide] = useState('adspower');
    const [extApiKey, setExtApiKey] = useState('');
    const [extKeyLoading, setExtKeyLoading] = useState(false);
    const [extKeyError, setExtKeyError] = useState('');

    // Telegram state
    const [tgToken, setTgToken] = useState('');
    const [tgShowToken, setTgShowToken] = useState(false);
    const [tgLoading, setTgLoading] = useState(false);
    const [tgSaving, setTgSaving] = useState(false);
    const [tgTesting, setTgTesting] = useState(false);
    const [tgSettings, setTgSettings] = useState(null);
    const [tgNotifyConversions, setTgNotifyConversions] = useState(true);
    const [tgDailyTime, setTgDailyTime] = useState('21:00');
    const [tgMessage, setTgMessage] = useState(null);

    // App Config state
    const [configs, setConfigs] = useState([]);
    const [configLoading, setConfigLoading] = useState(false);
    const [editingConfig, setEditingConfig] = useState(null);
    const [configForm, setConfigForm] = useState({ name: '', campaign_id: '', config_json: '', is_active: 1 });
    const [campaigns, setCampaigns] = useState([]);
    const [configMessage, setConfigMessage] = useState(null);

    // Facebook Costs state — ad-account connections that import spend.
    // These are aggregator_connections rows with engine = 'facebook'; the
    // Aggregators page manages the same records, this panel is the entry point a
    // Keitaro user actually looks for.
    const emptyFbForm = {
        name: '',
        sync_interval_hours: 2,
        is_active: 1,
        credentials: { token: '', ad_account_id: '', api_version: '', proxy_url: '' },
        field_mapping: { ad_id_param: '', adset_id_param: '', campaign_id_param: '' }
    };
    const [fbConnections, setFbConnections] = useState([]);
    const [fbLoading, setFbLoading] = useState(false);
    const [fbSearch, setFbSearch] = useState('');
    const [fbEditing, setFbEditing] = useState(null);
    const [fbForm, setFbForm] = useState(emptyFbForm);
    const [fbFields, setFbFields] = useState([]);
    const [fbTest, setFbTest] = useState(null);
    const [fbTesting, setFbTesting] = useState(false);
    const [fbBusyId, setFbBusyId] = useState(null);
    const [fbMessage, setFbMessage] = useState(null);
    const [fbShowAdvanced, setFbShowAdvanced] = useState(false);
    const [fbShowCustomApp, setFbShowCustomApp] = useState(false);
    const [fbShowTokenHowTo, setFbShowTokenHowTo] = useState(false);
    const [fbExtendingToken, setFbExtendingToken] = useState(false);
    const [fbDiscoveredAccounts, setFbDiscoveredAccounts] = useState([]);
    const [fbSelectedAccounts, setFbSelectedAccounts] = useState([]);
    const [fbOAuthFlowId, setFbOAuthFlowId] = useState('');
    const [fbOAuthLoading, setFbOAuthLoading] = useState(false);
    const [fbOAuthConnecting, setFbOAuthConnecting] = useState(false);
    // null = unknown (preflight unfinished or failed): the 1-Click button then
    // keeps its old behaviour instead of locking users out of the popup.
    const [fbOauthConfigured, setFbOauthConfigured] = useState(null);
    const fbOAuthPopupRef = useRef(null);
    const fbOAuthPollRef = useRef(null);

    // TikTok Costs state — engine 'tiktok' spend-sync connections. The 1-click
    // OAuth flow (discovery, pixel import) lives in this panel; before it sat in
    // a Pixel Vault modal.
    const emptyTtForm = {
        name: '',
        sync_interval_hours: 2,
        is_active: 1,
        credentials: { access_token: '', advertiser_id: '', app_id: '', app_secret: '', proxy_url: '' },
        field_mapping: { ad_id_param: '', adset_id_param: '', campaign_id_param: '' }
    };
    const [ttConnections, setTtConnections] = useState([]);
    const [ttLoading, setTtLoading] = useState(false);
    const [ttSearch, setTtSearch] = useState('');
    const [ttEditing, setTtEditing] = useState(null);
    const [ttForm, setTtForm] = useState(emptyTtForm);
    const [ttFields, setTtFields] = useState([]);
    const [ttTest, setTtTest] = useState(null);
    const [ttTesting, setTtTesting] = useState(false);
    const [ttBusyId, setTtBusyId] = useState(null);
    // TikTok 1-click OAuth — mirrors the Facebook flow. Discovery imports
    // pixels into the vault and creates one spend-sync connection per selected
    // ad account (engine 'tiktok', refreshed by cron).
    const [ttDiscoveredAccounts, setTtDiscoveredAccounts] = useState([]);
    const [ttDiscoveredPixels, setTtDiscoveredPixels] = useState([]);
    const [ttSelectedAccounts, setTtSelectedAccounts] = useState([]);
    const [ttOAuthFlowId, setTtOAuthFlowId] = useState('');
    const [ttOAuthLoading, setTtOAuthLoading] = useState(false);
    const [ttConnecting, setTtConnecting] = useState(false);
    const [ttImportPixels, setTtImportPixels] = useState(true);
    const [ttSyncInterval, setTtSyncInterval] = useState(2);
    const [ttMessage, setTtMessage] = useState(null);
    const ttPopupRef = useRef(null);
    const ttPollRef = useRef(null);

    // Google Ads Costs state — engine 'google_ads' connections: 1-Click Google
    // OAuth with MCC discovery plus the manual developer-app form.
    const emptyGaForm = {
        name: '',
        sync_interval_hours: 2,
        is_active: 1,
        credentials: { developer_token: '', client_id: '', client_secret: '', refresh_token: '', customer_id: '', login_customer_id: '', proxy_url: '' },
        field_mapping: { ad_id_param: '', adset_id_param: '', campaign_id_param: '' }
    };
    const [gaConnections, setGaConnections] = useState([]);
    const [gaLoading, setGaLoading] = useState(false);
    const [gaSearch, setGaSearch] = useState('');
    const [gaEditing, setGaEditing] = useState(null);
    const [gaForm, setGaForm] = useState(emptyGaForm);
    const [gaFields, setGaFields] = useState([]);
    const [gaTest, setGaTest] = useState(null);
    const [gaTesting, setGaTesting] = useState(false);
    const [gaBusyId, setGaBusyId] = useState(null);
    const [gaMessage, setGaMessage] = useState(null);
    // 1-click OAuth: managers = MCC directory for group headers, accounts =
    // flat list grouped by login_customer_id on render.
    const [gaDiscoveredManagers, setGaDiscoveredManagers] = useState([]);
    const [gaDiscoveredAccounts, setGaDiscoveredAccounts] = useState([]);
    const [gaSelectedAccounts, setGaSelectedAccounts] = useState([]);
    const [gaOAuthFlowId, setGaOAuthFlowId] = useState('');
    const [gaOAuthLoading, setGaOAuthLoading] = useState(false);
    const [gaConnecting, setGaConnecting] = useState(false);
    const [gaSyncInterval, setGaSyncInterval] = useState(2);
    const gaPopupRef = useRef(null);
    const gaPollRef = useRef(null);

    // Facebook Conversions state — campaign_pixels rows of type 'facebook' that
    // carry a Conversions API token.
    const emptyCapiForm = {
        campaign_id: '', pixel_profile_id: '', pixel_id: '', token: '', events: 'PageView,Lead',
        mapping: {}, test_event_code: '', proxy_url: '', is_active: 1, has_saved_token: false
    };
    const [capiPixels, setCapiPixels] = useState([]);
    const [capiLoading, setCapiLoading] = useState(false);
    const [capiSearch, setCapiSearch] = useState('');
    const [capiEditing, setCapiEditing] = useState(null);
    const [capiForm, setCapiForm] = useState(emptyCapiForm);
    const [capiMeta, setCapiMeta] = useState({ default_mapping: {}, available_events: [] });
    const [capiTest, setCapiTest] = useState(null);
    const [capiTesting, setCapiTesting] = useState(false);
    const [capiMessage, setCapiMessage] = useState(null);

    // Pixel Vault — reusable profiles are independent from campaigns. The API
    // deliberately returns only a token mask; an empty token while editing
    // means "keep the existing secret".
    const emptyPixelProfileForm = {
        traffic_source: 'facebook', niche: 'General', name: '', pixel_id: '', token: '',
        event_url: '', test_event_code: '', events: 'PageView,Lead,Purchase', is_active: 1
    };
    const [pixelProfiles, setPixelProfiles] = useState([]);
    const [pixelProfilesLoading, setPixelProfilesLoading] = useState(false);
    const [pixelProfileEditing, setPixelProfileEditing] = useState(null);
    const [pixelProfileForm, setPixelProfileForm] = useState(emptyPixelProfileForm);
    const [pixelProfileSearch, setPixelProfileSearch] = useState('');
    const [pixelProfileNiche, setPixelProfileNiche] = useState('');
    const [pixelProfileSource, setPixelProfileSource] = useState('all');
    const [pixelProfileMessage, setPixelProfileMessage] = useState(null);
    const [pixelProfileTesting, setPixelProfileTesting] = useState(null);

    // Cloudflare state — one account in settings, managed DNS for parked domains.
    const [cfForm, setCfForm] = useState({ api_token: '', proxied: true, ssl_mode: 'flexible', server_ip: '' });
    const [cfStatus, setCfStatus] = useState(null);
    const [cfBusy, setCfBusy] = useState(false);
    const [cfMessage, setCfMessage] = useState(null);

    // Namecheap state — one account in settings: zero-config DNS parking for
    // domains from the account + purchasing new domains right from the panel.
    const [ncForm, setNcForm] = useState({ username: '', api_key: '', sandbox: false, address_id: '', server_ip: '' });
    const [ncStatus, setNcStatus] = useState(null);
    const [ncBusy, setNcBusy] = useState(false);
    const [ncMessage, setNcMessage] = useState(null);
    const [ncAddresses, setNcAddresses] = useState([]);

    // reCAPTCHA state
    const [rcSaving, setRcSaving] = useState(false);
    const [rcMessage, setRcMessage] = useState(null);
    const [rcSettings, setRcSettings] = useState({
        recaptcha_v2_site_key: '',
        recaptcha_v2_secret_key: '',
        recaptcha_v3_site_key: '',
        recaptcha_v3_secret_key: '',
        recaptcha_v3_threshold: '0.5',
        turnstile_site_key: '',
        turnstile_secret_key: ''
    });

    const copyToClipboard = async (text, id) => {
        const ok = await copyUtil(text);
        if (ok) {
            setCopied(id);
            setTimeout(() => setCopied(''), 2000);
        }
    };

    const trackerUrl = window.location.origin;

    const loadExtensionCredentials = useCallback(async () => {
        setExtKeyLoading(true);
        setExtKeyError('');
        try {
            let res = await axios.get(`${API_URL}?action=extension_credentials`);
            if (res.data.status === 'success' && !res.data.data?.api_key) {
                res = await axios.post(`${API_URL}?action=extension_credentials`, {});
            }
            if (res.data.status === 'success' && res.data.data?.api_key) {
                setExtApiKey(res.data.data.api_key);
            } else {
                setExtKeyError(res.data.message || 'Unable to create a read API key.');
            }
        } catch (err) {
            setExtKeyError(err.response?.data?.message || err.message || 'Unable to create a read API key.');
        } finally {
            setExtKeyLoading(false);
        }
    }, []);

    const fetchTelegramSettings = useCallback(async () => {
        setTgLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=telegram_settings`);
            if (res.data.status === 'success') {
                setTgSettings(res.data.data);
                setTgNotifyConversions(res.data.data.notify_conversions);
                setTgDailyTime(res.data.data.daily_time || '21:00');
            }
        } catch (err) {
            console.error(err);
        } finally {
            setTgLoading(false);
        }
    }, []);

    const fetchRcSettings = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=settings`);
            if (res.data.status === 'success') {
                const s = res.data.data || {};
                setRcSettings({
                    recaptcha_v2_site_key: s.recaptcha_v2_site_key || '',
                    recaptcha_v2_secret_key: s.recaptcha_v2_secret_key || '',
                    recaptcha_v3_site_key: s.recaptcha_v3_site_key || '',
                    recaptcha_v3_secret_key: s.recaptcha_v3_secret_key || '',
                    recaptcha_v3_threshold: s.recaptcha_v3_threshold || '0.5',
                    turnstile_site_key: s.turnstile_site_key || '',
                    turnstile_secret_key: s.turnstile_secret_key || ''
                });
            }
        } catch (err) {
            console.error(err);
        }
    }, []);

    const saveRcSettings = async () => {
        setRcSaving(true);
        setRcMessage(null);
        try {
            await axios.post(`${API_URL}?action=save_settings`, rcSettings);
            setRcMessage({ type: 'success', text: t('recaptcha.saved') });
        } catch (err) {
            setRcMessage({ type: 'error', text: t('recaptcha.saveError') });
        } finally {
            setRcSaving(false);
            setTimeout(() => setRcMessage(null), 4000);
        }
    };

    useEffect(() => {
        if (activeTab === 'telegram') {
            fetchTelegramSettings();
        }
        if (activeTab === 'recaptcha') {
            fetchRcSettings();
        }
        if (activeTab === 'chrome_extension' && !extApiKey) {
            loadExtensionCredentials();
        }
    }, [activeTab, fetchTelegramSettings, fetchRcSettings, loadExtensionCredentials, extApiKey]);

    const handleTelegramConnect = async () => {
        if (!tgToken.trim()) return;
        setTgSaving(true);
        setTgMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=save_telegram_settings`, {
                token: tgToken,
                notify_conversions: tgNotifyConversions,
                daily_time: tgDailyTime
            });
            if (res.data.status === 'success') {
                setTgMessage({ type: 'success', text: t('telegram.connected') + (res.data.data?.bot_username ? ` @${res.data.data.bot_username}` : '') });
                setTgToken('');
                fetchTelegramSettings();
            } else {
                setTgMessage({ type: 'error', text: res.data.message });
            }
        } catch (err) {
            setTgMessage({ type: 'error', text: t('telegram.connectionError') });
        } finally {
            setTgSaving(false);
        }
    };

    const handleTelegramDisconnect = async () => {
        setTgSaving(true);
        try {
            await axios.post(`${API_URL}?action=save_telegram_settings`, { action: 'disconnect' });
            setTgSettings(null);
            setTgMessage({ type: 'success', text: t('telegram.disconnected') });
            fetchTelegramSettings();
        } catch (err) {
            setTgMessage({ type: 'error', text: t('common.error') });
        } finally {
            setTgSaving(false);
        }
    };

    const handleTelegramTest = async () => {
        setTgTesting(true);
        setTgMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=telegram_test`);
            setTgMessage({
                type: res.data.status === 'success' ? 'success' : 'error',
                text: res.data.message || (res.data.status === 'success' ? t('telegram.testSent') : t('telegram.testFailed'))
            });
        } catch (err) {
            setTgMessage({ type: 'error', text: t('telegram.testFailed') });
        } finally {
            setTgTesting(false);
        }
    };

    const handleSaveSettings = async () => {
        setTgSaving(true);
        try {
            await axios.post(`${API_URL}?action=save_telegram_settings`, {
                notify_conversions: tgNotifyConversions,
                daily_time: tgDailyTime
            });
            setTgMessage({ type: 'success', text: t('telegram.settingsSaved') });
        } catch (err) {
            setTgMessage({ type: 'error', text: t('common.error') });
        } finally {
            setTgSaving(false);
        }
    };

    // App Config functions
    const fetchConfigs = useCallback(async () => {
        setConfigLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=app_configs`);
            if (res.data.status === 'success') setConfigs(res.data.data || []);
        } catch (err) { console.error(err); }
        finally { setConfigLoading(false); }
    }, []);

    const fetchCampaigns = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=campaigns`);
            if (res.data.status === 'success') setCampaigns(res.data.data || []);
        } catch (err) { console.error(err); }
    }, []);

    useEffect(() => {
        if (activeTab === 'app_config') { fetchConfigs(); fetchCampaigns(); }
    }, [activeTab, fetchConfigs, fetchCampaigns]);

    const configTemplates = {
        default: JSON.stringify({ webview: { enabled: false, url: "" }, banner: { enabled: false, image_url: "", click_url: "" }, stub: { enabled: false, message: "Under maintenance" }, version: "1.0", force_update: false }, null, 2),
        webview: JSON.stringify({ webview: { enabled: true, url: "https://example.com/offer" }, stub: { enabled: false, message: "" }, version: "1.0", force_update: false }, null, 2),
        banner: JSON.stringify({ banner: { enabled: true, image_url: "https://example.com/banner.jpg", click_url: "https://example.com/offer" }, version: "1.0" }, null, 2),
        stub: JSON.stringify({ stub: { enabled: true, message: "App is temporarily unavailable" }, version: "1.0", force_update: true }, null, 2)
    };

    const handleSaveConfig = async () => {
        if (!configForm.name) return;
        try {
            JSON.parse(configForm.config_json);
        } catch (e) {
            setConfigMessage({ type: 'error', text: t('appConfig.invalidJson') });
            return;
        }
        try {
            const payload = { ...configForm };
            if (editingConfig && editingConfig !== 'new') payload.id = editingConfig;
            const res = await axios.post(`${API_URL}?action=save_app_config`, payload);
            if (res.data.status === 'success') {
                setConfigMessage({ type: 'success', text: t('appConfig.saved') });
                setEditingConfig(null);
                fetchConfigs();
            }
        } catch (err) { setConfigMessage({ type: 'error', text: err.message }); }
    };

    const handleDeleteConfig = async (id) => {
        if (!confirm(t('appConfig.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=delete_app_config`, { id });
        setConfigMessage({ type: 'success', text: t('appConfig.deleted') });
        fetchConfigs();
    };

    const renderAppConfigPanel = () => (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            {configLoading ? (
                <div className="flex justify-center py-10">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                </div>
            ) : (
                <div className="space-y-4" style={{ maxWidth: '700px' }}>
                    {configMessage && (
                        <div style={{
                            padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                            background: configMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                            color: configMessage.type === 'success' ? '#166534' : '#991b1b',
                            border: `1px solid ${configMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                        }}>{configMessage.text}</div>
                    )}

                    {editingConfig ? (
                        <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)' }}>
                            <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>{editingConfig === 'new' ? t('appConfig.create') : t('appConfig.edit')}</h4>
                            <div className="space-y-3">
                                <div>
                                    <label className="form-label">{t('appConfig.name')}</label>
                                    <input type="text" value={configForm.name} onChange={e => setConfigForm({ ...configForm, name: e.target.value })} className="form-input" placeholder="My App Config" />
                                </div>
                                <div>
                                    <label className="form-label">{t('appConfig.campaign')}</label>
                                    <select value={configForm.campaign_id} onChange={e => setConfigForm({ ...configForm, campaign_id: e.target.value })} className="form-select">
                                        <option value="">—</option>
                                        {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('appConfig.jsonEditor')}</label>
                                    <div style={{ display: 'flex', gap: '6px', marginBottom: '8px', flexWrap: 'wrap' }}>
                                        {Object.entries(configTemplates).map(([key, val]) => (
                                            <button key={key} onClick={() => setConfigForm({ ...configForm, config_json: val })} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', padding: '4px 10px' }}>
                                                {t(`appConfig.template${key.charAt(0).toUpperCase() + key.slice(1)}`)}
                                            </button>
                                        ))}
                                    </div>
                                    <textarea
                                        value={configForm.config_json}
                                        onChange={e => setConfigForm({ ...configForm, config_json: e.target.value })}
                                        style={{
                                            width: '100%', minHeight: '220px', fontFamily: "'JetBrains Mono', monospace",
                                            fontSize: '13px', background: '#1e1e2e', color: '#cdd6f4',
                                            border: '1px solid var(--color-border)', borderRadius: '12px', padding: '14px',
                                            resize: 'vertical', lineHeight: 1.5
                                        }}
                                    />
                                </div>
                                <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                                    <button onClick={() => setEditingConfig(null)} className="btn btn-secondary btn-sm"><X size={14} /> {t('common.cancel')}</button>
                                    <button onClick={handleSaveConfig} className="btn btn-primary btn-sm" disabled={!configForm.name}>{t('common.save')}</button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <button onClick={() => {
                            setConfigForm({ name: '', campaign_id: '', config_json: configTemplates.default, is_active: 1 });
                            setEditingConfig('new');
                        }} className="btn btn-primary">
                            <Plus size={16} /> {t('appConfig.create')}
                        </button>
                    )}

                    {/* Existing configs */}
                    {configs.map(cfg => (
                        <div key={cfg.id} style={{
                            border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                            background: cfg.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                            opacity: cfg.is_active ? 1 : 0.6
                        }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px' }}>
                                <div>
                                    <div style={{ fontWeight: 600, fontSize: '14px' }}>{cfg.name}</div>
                                    {cfg.campaign_name && <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>📍 {cfg.campaign_name}</div>}
                                </div>
                                <div style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
                                    <span style={{ fontSize: '11px', padding: '2px 8px', borderRadius: '6px', background: cfg.is_active ? '#dcfce7' : '#fee2e2', color: cfg.is_active ? '#166534' : '#991b1b' }}>
                                        {cfg.is_active ? t('appConfig.active') : t('appConfig.inactive')}
                                    </span>
                                </div>
                            </div>
                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', fontFamily: 'monospace', marginBottom: '10px', wordBreak: 'break-all' }}>
                                {trackerUrl}/api.php?action=app_config&key={cfg.config_key}
                            </div>
                            <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                <button onClick={() => copyToClipboard(`${trackerUrl}/api.php?action=app_config&key=${cfg.config_key}`, `url_${cfg.id}`)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    {copied === `url_${cfg.id}` ? <><CheckCircle2 size={12} style={{ color: 'var(--color-success)' }} /> {t('integrations.copied')}</> : <><Copy size={12} /> {t('appConfig.copyUrl')}</>}
                                </button>
                                <button onClick={() => {
                                    const blob = new Blob([cfg.config_json], { type: 'application/json' });
                                    const url = URL.createObjectURL(blob);
                                    const a = document.createElement('a');
                                    a.href = url; a.download = `${cfg.name.replace(/\s+/g, '_')}.json`;
                                    a.click(); URL.revokeObjectURL(url);
                                }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    <Download size={12} /> {t('appConfig.download')}
                                </button>
                                <button onClick={() => {
                                    setConfigForm({ name: cfg.name, campaign_id: cfg.campaign_id || '', config_json: cfg.config_json, is_active: cfg.is_active });
                                    setEditingConfig(cfg.id);
                                }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    <Edit2 size={12} /> {t('appConfig.edit')}
                                </button>
                                <button onClick={async () => {
                                    await axios.post(`${API_URL}?action=save_app_config`, { id: cfg.id, name: cfg.name, config_json: cfg.config_json, is_active: cfg.is_active ? 0 : 1 });
                                    fetchConfigs();
                                }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    <Power size={12} /> {cfg.is_active ? t('appConfig.inactive') : t('appConfig.active')}
                                </button>
                                <button onClick={() => handleDeleteConfig(cfg.id)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }}>
                                    <Trash2 size={12} />
                                </button>
                            </div>
                        </div>
                    ))}

                    {configs.length === 0 && !editingConfig && (
                        <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '30px 0' }}>
                            {t('appConfig.noConfigsDesc')}
                        </p>
                    )}
                </div>
            )}
        </div>
    );

    // ==================== Facebook Costs ====================

    const fetchFbConnections = useCallback(async () => {
        setFbLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connections`);
            if (res.data.status === 'success') {
                setFbConnections((res.data.data || []).filter(c => c.engine === 'facebook'));
            }
        } catch (err) { console.error(err); }
        finally { setFbLoading(false); }
    }, []);

    const fetchFbFields = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_engine_fields&engine=facebook`);
            if (res.data.status === 'success') setFbFields(res.data.data || []);
        } catch (err) { console.error(err); }
    }, []);

    const fetchFbOauthStatus = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=facebook_oauth_status`);
            if (res.data.status === 'success') setFbOauthConfigured(!!res.data.data?.configured);
        } catch (err) { console.error(err); }
    }, []);

    const fetchTtConnections = useCallback(async () => {
        setTtLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connections`);
            if (res.data.status === 'success') {
                setTtConnections((res.data.data || []).filter(c => c.engine === 'tiktok'));
            }
        } catch (err) { console.error(err); }
        finally { setTtLoading(false); }
    }, []);

    const fetchTtFields = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_engine_fields&engine=tiktok`);
            if (res.data.status === 'success') setTtFields(res.data.data || []);
        } catch (err) { console.error(err); }
    }, []);

    const fetchGaConnections = useCallback(async () => {
        setGaLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connections`);
            if (res.data.status === 'success') {
                setGaConnections((res.data.data || []).filter(c => c.engine === 'google_ads'));
            }
        } catch (err) { console.error(err); }
        finally { setGaLoading(false); }
    }, []);

    const fetchGaFields = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_engine_fields&engine=google_ads`);
            if (res.data.status === 'success') setGaFields(res.data.data || []);
        } catch (err) { console.error(err); }
    }, []);

    const fetchCapiPixels = useCallback(async () => {
        setCapiLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=facebook_capi_list`);
            if (res.data.status === 'success') setCapiPixels(res.data.data || []);
        } catch (err) { console.error(err); }
        finally { setCapiLoading(false); }
    }, []);

    const fetchCapiMeta = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}?action=facebook_capi_meta`);
            if (res.data.status === 'success') setCapiMeta(res.data.data);
        } catch (err) { console.error(err); }
    }, []);

    const fetchPixelProfiles = useCallback(async () => {
        setPixelProfilesLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=pixel_profiles_list`);
            if (res.data.status === 'success') setPixelProfiles(res.data.data || []);
        } catch (err) { console.error(err); }
        finally { setPixelProfilesLoading(false); }
    }, []);

    useEffect(() => {
        if (activeTab === 'facebook_costs') { fetchFbConnections(); fetchFbFields(); fetchFbOauthStatus(); }
        if (activeTab === 'tiktok_costs') { fetchTtConnections(); fetchTtFields(); }
        if (activeTab === 'google_ads_costs') { fetchGaConnections(); fetchGaFields(); }
        if (activeTab === 'facebook_conversions') { fetchCapiPixels(); fetchCapiMeta(); fetchCampaigns(); }
        if (activeTab === 'pixel_vault') fetchPixelProfiles();
        if (activeTab === 'cloudflare') {
            axios.get(`${API_URL}?action=cloudflare_status`)
                .then(res => {
                    if (res.data.status === 'success') {
                        setCfStatus(res.data.data);
                        setCfForm(f => ({ ...f, proxied: !!res.data.data.proxied, ssl_mode: res.data.data.ssl_mode || 'flexible', server_ip: res.data.data.server_ip || '' }));
                    }
                })
                .catch(() => {});
        }
        if (activeTab === 'namecheap') {
            const ncAddressRefresh = async () => {
                try {
                    const res = await axios.post(`${API_URL}?action=namecheap_addresses`, {});
                    if (res.data.status === 'success') setNcAddresses(res.data.data.addresses || []);
                } catch (err) { console.error(err); }
            };
            axios.get(`${API_URL}?action=namecheap_status`)
                .then(res => {
                    if (res.data.status === 'success') {
                        setNcStatus(res.data.data);
                        setNcForm(f => ({
                            ...f,
                            username: res.data.data.username || '',
                            sandbox: !!res.data.data.sandbox,
                            address_id: res.data.data.address_id || '',
                            server_ip: res.data.data.server_ip || ''
                        }));
                        if (res.data.data.connected) ncAddressRefresh();
                    }
                })
                .catch(() => {});
        }
    }, [activeTab, fetchFbConnections, fetchFbFields, fetchTtConnections, fetchTtFields, fetchGaConnections, fetchGaFields, fetchCapiPixels, fetchCapiMeta, fetchCampaigns, fetchPixelProfiles]);

    useEffect(() => {
        const handleFacebookOAuthMessage = (event) => {
            if (event.origin !== window.location.origin || event.data?.type !== 'orbitra.facebook_oauth') return;
            if (!fbOAuthPopupRef.current || event.source !== fbOAuthPopupRef.current) return;

            setFbOAuthLoading(false);
            if (fbOAuthPollRef.current) {
                window.clearInterval(fbOAuthPollRef.current);
                fbOAuthPollRef.current = null;
            }
            fbOAuthPopupRef.current = null;

            if (event.data.status !== 'success') {
                setFbDiscoveredAccounts([]);
                setFbSelectedAccounts([]);
                setFbOAuthFlowId('');
                setFbMessage({ type: 'error', text: event.data.message || translationRef.current('fbCosts.oauthFailed') });
                return;
            }

            const accounts = Array.isArray(event.data.accounts) ? event.data.accounts : [];
            setFbDiscoveredAccounts(accounts);
            setFbSelectedAccounts(accounts.map(account => account.id));
            setFbOAuthFlowId(event.data.flow_id || '');
            setFbMessage(accounts.length === 0
                ? { type: 'error', text: translationRef.current('fbCosts.noDiscoveredAccounts') }
                : null);
        };

        window.addEventListener('message', handleFacebookOAuthMessage);
        return () => {
            window.removeEventListener('message', handleFacebookOAuthMessage);
            if (fbOAuthPollRef.current) {
                window.clearInterval(fbOAuthPollRef.current);
                fbOAuthPollRef.current = null;
            }
            if (fbOAuthPopupRef.current && !fbOAuthPopupRef.current.closed) {
                fbOAuthPopupRef.current.close();
            }
            fbOAuthPopupRef.current = null;
        };
    }, []);

    // Same popup handshake for TikTok: the callback page posts the discovered
    // accounts and pixels back; tokens stay server-side behind the flow id.
    useEffect(() => {
        const handleTikTokOAuthMessage = (event) => {
            if (event.origin !== window.location.origin || event.data?.type !== 'orbitra.tiktok_oauth') return;
            if (!ttPopupRef.current || event.source !== ttPopupRef.current) return;

            setTtOAuthLoading(false);
            if (ttPollRef.current) {
                window.clearInterval(ttPollRef.current);
                ttPollRef.current = null;
            }
            ttPopupRef.current = null;

            if (event.data.status !== 'success') {
                setTtDiscoveredAccounts([]);
                setTtDiscoveredPixels([]);
                setTtSelectedAccounts([]);
                setTtOAuthFlowId('');
                setTtMessage({ type: 'error', text: event.data.message || translationRef.current('tiktokCosts.oauthFailed') });
                return;
            }

            const accounts = Array.isArray(event.data.accounts) ? event.data.accounts : [];
            const pixels = Array.isArray(event.data.pixels) ? event.data.pixels : [];
            setTtDiscoveredAccounts(accounts);
            setTtDiscoveredPixels(pixels);
            setTtSelectedAccounts(accounts.map(account => account.id));
            setTtOAuthFlowId(event.data.flow_id || '');
            setTtMessage(accounts.length === 0
                ? { type: 'error', text: translationRef.current('tiktokCosts.noDiscoveredAccounts') }
                : null);
        };

        window.addEventListener('message', handleTikTokOAuthMessage);
        return () => {
            window.removeEventListener('message', handleTikTokOAuthMessage);
            if (ttPollRef.current) {
                window.clearInterval(ttPollRef.current);
                ttPollRef.current = null;
            }
            if (ttPopupRef.current && !ttPopupRef.current.closed) {
                ttPopupRef.current.close();
            }
            ttPopupRef.current = null;
        };
    }, []);

    // Same popup handshake for Google Ads: the callback page posts the
    // discovered account tree (direct accounts + MCC sub-accounts); the refresh
    // token stays server-side behind the flow id.
    useEffect(() => {
        const handleGoogleAdsOAuthMessage = (event) => {
            if (event.origin !== window.location.origin || event.data?.type !== 'orbitra.google_ads_oauth') return;
            if (!gaPopupRef.current || event.source !== gaPopupRef.current) return;

            setGaOAuthLoading(false);
            if (gaPollRef.current) {
                window.clearInterval(gaPollRef.current);
                gaPollRef.current = null;
            }
            gaPopupRef.current = null;

            if (event.data.status !== 'success') {
                setGaDiscoveredManagers([]);
                setGaDiscoveredAccounts([]);
                setGaSelectedAccounts([]);
                setGaOAuthFlowId('');
                setGaMessage({ type: 'error', text: event.data.message || translationRef.current('googleAdsCosts.oauthFailed') });
                return;
            }

            const accounts = Array.isArray(event.data.accounts) ? event.data.accounts : [];
            const managers = Array.isArray(event.data.managers) ? event.data.managers : [];
            setGaDiscoveredManagers(managers);
            setGaDiscoveredAccounts(accounts);
            setGaSelectedAccounts(accounts.map(account => account.cid));
            setGaOAuthFlowId(event.data.flow_id || '');
            setGaMessage(accounts.length === 0
                ? { type: 'error', text: translationRef.current('googleAdsCosts.noDiscoveredAccounts') }
                : null);
        };

        window.addEventListener('message', handleGoogleAdsOAuthMessage);
        return () => {
            window.removeEventListener('message', handleGoogleAdsOAuthMessage);
            if (gaPollRef.current) {
                window.clearInterval(gaPollRef.current);
                gaPollRef.current = null;
            }
            if (gaPopupRef.current && !gaPopupRef.current.closed) {
                gaPopupRef.current.close();
            }
            gaPopupRef.current = null;
        };
    }, []);

    // The list endpoint strips credentials, so editing has to re-read the row.
    const loadFbConnection = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connection_detail&id=${id}`);
            if (res.data.status !== 'success' || !res.data.data) return null;
            const conn = res.data.data;
            return {
                name: conn.name || '',
                sync_interval_hours: conn.sync_interval_hours || 2,
                is_active: conn.is_active ? 1 : 0,
                credentials: { ...emptyFbForm.credentials, ...(conn.credentials || {}) },
                field_mapping: { ...emptyFbForm.field_mapping, ...(conn.field_mapping || {}) }
            };
        } catch (err) { console.error(err); return null; }
    };

    const saveFbConnection = async (form, id) => {
        const payload = {
            ...form,
            engine: 'facebook',
            auth_type: 'token',
            deal_type: 'cpa',
            click_id_param: 'sub_id'
        };
        if (id && id !== 'new') payload.id = id;
        const res = await axios.post(`${API_URL}?action=aggregator_connections`, payload);
        return res.data.status === 'success';
    };

    // TikTok / Google Ads Costs share the aggregator connection API with the
    // Facebook panel — only the engine name and the credential shape differ.
    const loadTtConnection = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connection_detail&id=${id}`);
            if (res.data.status !== 'success' || !res.data.data) return null;
            const conn = res.data.data;
            return {
                name: conn.name || '',
                sync_interval_hours: conn.sync_interval_hours || 2,
                is_active: conn.is_active ? 1 : 0,
                credentials: { ...emptyTtForm.credentials, ...(conn.credentials || {}) },
                field_mapping: { ...emptyTtForm.field_mapping, ...(conn.field_mapping || {}) }
            };
        } catch (err) { console.error(err); return null; }
    };

    const saveTtConnection = async (form, id) => {
        const payload = { ...form, engine: 'tiktok', auth_type: 'token', deal_type: 'cpa', click_id_param: 'sub_id' };
        if (id && id !== 'new') payload.id = id;
        const res = await axios.post(`${API_URL}?action=aggregator_connections`, payload);
        return res.data.status === 'success';
    };

    const loadGaConnection = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connection_detail&id=${id}`);
            if (res.data.status !== 'success' || !res.data.data) return null;
            const conn = res.data.data;
            return {
                name: conn.name || '',
                sync_interval_hours: conn.sync_interval_hours || 2,
                is_active: conn.is_active ? 1 : 0,
                credentials: { ...emptyGaForm.credentials, ...(conn.credentials || {}) },
                field_mapping: { ...emptyGaForm.field_mapping, ...(conn.field_mapping || {}) }
            };
        } catch (err) { console.error(err); return null; }
    };

    const saveGaConnection = async (form, id) => {
        const payload = { ...form, engine: 'google_ads', auth_type: 'token', deal_type: 'cpa', click_id_param: 'sub_id' };
        if (id && id !== 'new') payload.id = id;
        const res = await axios.post(`${API_URL}?action=aggregator_connections`, payload);
        return res.data.status === 'success';
    };

    const resetFacebookOAuth = () => {
        if (fbOAuthPollRef.current) {
            window.clearInterval(fbOAuthPollRef.current);
            fbOAuthPollRef.current = null;
        }
        if (fbOAuthPopupRef.current && !fbOAuthPopupRef.current.closed) {
            fbOAuthPopupRef.current.close();
        }
        fbOAuthPopupRef.current = null;
        setFbDiscoveredAccounts([]);
        setFbSelectedAccounts([]);
        setFbOAuthFlowId('');
        setFbOAuthLoading(false);
        setFbOAuthConnecting(false);
    };

    const handleStartFacebookOAuth = () => {
        setFbMessage(null);
        setFbDiscoveredAccounts([]);
        setFbSelectedAccounts([]);
        setFbOAuthFlowId('');

        if (fbOAuthPopupRef.current && !fbOAuthPopupRef.current.closed) {
            fbOAuthPopupRef.current.close();
        }
        if (fbOAuthPollRef.current) {
            window.clearInterval(fbOAuthPollRef.current);
        }

        const width = 640;
        const height = 720;
        const left = Math.max(0, window.screenX + Math.round((window.outerWidth - width) / 2));
        const top = Math.max(0, window.screenY + Math.round((window.outerHeight - height) / 2));
        const popup = window.open(
            `${API_URL}?action=facebook_oauth_start`,
            'orbitra-facebook-oauth',
            `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );
        if (!popup) {
            setFbOAuthLoading(false);
            setFbMessage({ type: 'error', text: t('fbCosts.popupBlocked') });
            return;
        }

        fbOAuthPopupRef.current = popup;
        setFbOAuthLoading(true);
        popup.focus();
        fbOAuthPollRef.current = window.setInterval(() => {
            if (popup.closed) {
                window.clearInterval(fbOAuthPollRef.current);
                fbOAuthPollRef.current = null;
                fbOAuthPopupRef.current = null;
                setFbOAuthLoading(false);
            }
        }, 500);
    };

    const toggleFacebookAccount = (accountId, checked) => {
        setFbSelectedAccounts(current => checked
            ? [...new Set([...current, accountId])]
            : current.filter(id => id !== accountId));
    };

    const resetTikTokOAuth = () => {
        if (ttPollRef.current) {
            window.clearInterval(ttPollRef.current);
            ttPollRef.current = null;
        }
        if (ttPopupRef.current && !ttPopupRef.current.closed) {
            ttPopupRef.current.close();
        }
        ttPopupRef.current = null;
        setTtDiscoveredAccounts([]);
        setTtDiscoveredPixels([]);
        setTtSelectedAccounts([]);
        setTtOAuthFlowId('');
        setTtOAuthLoading(false);
        setTtConnecting(false);
    };

    const handleStartTikTokOAuth = () => {
        setTtMessage(null);
        setTtDiscoveredAccounts([]);
        setTtDiscoveredPixels([]);
        setTtSelectedAccounts([]);
        setTtOAuthFlowId('');

        if (ttPopupRef.current && !ttPopupRef.current.closed) {
            ttPopupRef.current.close();
        }
        if (ttPollRef.current) {
            window.clearInterval(ttPollRef.current);
        }

        const width = 640;
        const height = 720;
        const left = Math.max(0, window.screenX + Math.round((window.outerWidth - width) / 2));
        const top = Math.max(0, window.screenY + Math.round((window.outerHeight - height) / 2));
        const popup = window.open(
            `${API_URL}?action=tiktok_oauth_start`,
            'orbitra-tiktok-oauth',
            `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );
        if (!popup) {
            setTtOAuthLoading(false);
            setTtMessage({ type: 'error', text: t('tiktokCosts.popupBlocked') });
            return;
        }

        ttPopupRef.current = popup;
        setTtOAuthLoading(true);
        popup.focus();
        ttPollRef.current = window.setInterval(() => {
            if (popup.closed) {
                window.clearInterval(ttPollRef.current);
                ttPollRef.current = null;
                ttPopupRef.current = null;
                setTtOAuthLoading(false);
            }
        }, 500);
    };

    const toggleTikTokAccount = (accountId, checked) => {
        setTtSelectedAccounts(current => checked
            ? [...new Set([...current, accountId])]
            : current.filter(id => id !== accountId));
    };

    const handleConnectTikTokAccounts = async () => {
        if (!ttOAuthFlowId || ttSelectedAccounts.length === 0) return;
        setTtConnecting(true);
        setTtMessage(null);
        try {
            const accounts = ttDiscoveredAccounts.filter(account => ttSelectedAccounts.includes(account.id));
            const res = await axios.post(`${API_URL}?action=tiktok_connect_accounts`, {
                flow_id: ttOAuthFlowId,
                accounts,
                sync_interval_hours: ttSyncInterval || 2,
                import_pixels: ttImportPixels
            });
            if (res.data.status !== 'success') {
                setTtMessage({ type: 'error', text: res.data.message || t('tiktokCosts.oauthFailed') });
                return;
            }

            const connected = res.data.data?.connected_count ?? accounts.length;
            const imported = res.data.data?.imported_pixels ?? 0;
            const summary = t('tiktokCosts.connectedAccounts')
                .replace('{n}', connected)
                .replace('{m}', imported);
            setTtMessage({ type: 'success', text: summary });
            resetTikTokOAuth();
            setTtEditing(null);
            // Imported pixels show up in the vault, not in this panel.
            fetchTtConnections();
            fetchPixelProfiles();
        } catch (err) {
            setTtMessage({ type: 'error', text: err.response?.data?.message || err.message || t('tiktokCosts.oauthFailed') });
        } finally {
            setTtConnecting(false);
        }
    };

    const handleConnectDiscoveredAccounts = async () => {
        if (!fbOAuthFlowId || fbSelectedAccounts.length === 0) return;
        setFbOAuthConnecting(true);
        setFbMessage(null);
        try {
            const accounts = fbDiscoveredAccounts.filter(account => fbSelectedAccounts.includes(account.id));
            const res = await axios.post(`${API_URL}?action=facebook_connect_accounts`, {
                flow_id: fbOAuthFlowId,
                accounts,
                sync_interval_hours: fbForm.sync_interval_hours || 2
            });
            if (res.data.status !== 'success') {
                setFbMessage({ type: 'error', text: res.data.message || t('fbCosts.oauthFailed') });
                return;
            }

            const connected = res.data.data?.connected_count ?? accounts.length;
            setFbMessage({ type: 'success', text: t('fbCosts.connectedAccounts').replace('{n}', connected) });
            setFbEditing(null);
            setFbTest(null);
            resetFacebookOAuth();
            fetchFbConnections();
        } catch (err) {
            setFbMessage({ type: 'error', text: err.response?.data?.message || err.message || t('fbCosts.oauthFailed') });
        } finally {
            setFbOAuthConnecting(false);
        }
    };

    const handleFbSave = async () => {
        if (!fbForm.name || !fbForm.credentials.ad_account_id || !fbForm.credentials.token) return;
        try {
            const ok = await saveFbConnection(fbForm, fbEditing);
            if (ok) {
                setFbMessage({ type: 'success', text: t('fbCosts.saved') });
                setFbEditing(null);
                setFbTest(null);
                resetFacebookOAuth();
                fetchFbConnections();
            }
        } catch (err) { setFbMessage({ type: 'error', text: err.message }); }
    };

    const handleFbTest = async () => {
        setFbTesting(true);
        setFbTest(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_test_connection`, {
                engine: 'facebook', credentials: fbForm.credentials
            });
            setFbTest(res.data.data || { success: false, message: 'No response' });
        } catch (err) { setFbTest({ success: false, message: err.message }); }
        finally { setFbTesting(false); }
    };

    // Swap a short-lived token (anti-detect browsers hand out 1-2 hour ones)
    // for a 60-day long-lived token. The app that issued the token must sign
    // the exchange, so the Custom Meta App fields win when filled.
    const handleExtendToken = async () => {
        const shortToken = (fbForm.credentials.token || '').trim();
        if (!shortToken || fbExtendingToken) return;
        setFbExtendingToken(true);
        try {
            const res = await axios.post(`${API_URL}?action=facebook_extend_token`, {
                short_token: shortToken,
                app_id: fbForm.credentials.app_id || '',
                app_secret: fbForm.credentials.app_secret || '',
                proxy_url: fbForm.credentials.proxy_url || ''
            });
            if (res.data.status === 'success' && res.data.data?.long_lived_token) {
                setFbForm(prev => ({ ...prev, credentials: { ...prev.credentials, token: res.data.data.long_lived_token } }));
                const days = Math.max(1, Math.round((res.data.data.expires_in || 5184000) / 86400));
                setFbMessage({ type: 'success', text: `${t('fbCosts.tokenExtended')} (~${days}d)` });
            } else {
                setFbMessage({ type: 'error', text: res.data.message || t('common.error') });
            }
        } catch (err) {
            setFbMessage({ type: 'error', text: err.response?.data?.message || err.message || t('common.error') });
        } finally {
            setFbExtendingToken(false);
        }
    };

    // "Обновить расходы" — the manual equivalent of one cron tick. The window
    // matches the scheduled sync so the button cannot produce a different answer.
    const handleFbSyncNow = async (conn) => {
        setFbBusyId(conn.id);
        setFbMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_sync`, {
                connection_id: conn.id,
                date_from: new Date(Date.now() - (conn.last_sync_at ? 5 : 30) * 86400000).toISOString().slice(0, 10),
                date_to: new Date().toISOString().slice(0, 10)
            });
            const d = res.data;
            if (d.status === 'success') {
                const matched = d.matched ?? d.data?.matched ?? 0;
                const fetched = d.fetched ?? d.data?.fetched ?? 0;
                setFbMessage({
                    type: matched === 0 && fetched > 0 ? 'error' : 'success',
                    text: matched === 0 && fetched > 0
                        ? t('fbCosts.syncedNoMatch').replace('{n}', fetched)
                        : t('fbCosts.syncedOk').replace('{n}', fetched).replace('{m}', matched)
                });
            } else {
                setFbMessage({ type: 'error', text: d.message || t('fbCosts.syncFailed') });
            }
            fetchFbConnections();
        } catch (err) { setFbMessage({ type: 'error', text: err.message }); }
        finally { setFbBusyId(null); }
    };

    const handleFbToggle = async (conn) => {
        // Re-read first: the save endpoint rewrites credentials from the payload,
        // so posting a partial row would wipe the token.
        const full = await loadFbConnection(conn.id);
        if (!full) return;
        await saveFbConnection({ ...full, is_active: conn.is_active ? 0 : 1 }, conn.id);
        fetchFbConnections();
    };

    const handleFbClone = async (conn) => {
        const full = await loadFbConnection(conn.id);
        if (!full) return;
        await saveFbConnection({ ...full, name: `${full.name} copy`, is_active: 0 }, 'new');
        setFbMessage({ type: 'success', text: t('fbCosts.cloned') });
        fetchFbConnections();
    };

    const handleFbDelete = async (conn) => {
        if (!confirm(t('fbCosts.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=aggregator_connections`, { action: 'delete', id: conn.id });
        setFbMessage({ type: 'success', text: t('fbCosts.deleted') });
        fetchFbConnections();
    };

    // ==================== TikTok Costs (rows) ====================

    const handleTtSave = async () => {
        if (!ttForm.name || !ttForm.credentials.access_token || !ttForm.credentials.advertiser_id) return;
        try {
            const ok = await saveTtConnection(ttForm, ttEditing);
            if (ok) {
                setTtMessage({ type: 'success', text: t('tiktokCosts.saved') });
                setTtEditing(null);
                setTtTest(null);
                resetTikTokOAuth();
                fetchTtConnections();
            }
        } catch (err) { setTtMessage({ type: 'error', text: err.message }); }
    };

    const handleTtTest = async () => {
        setTtTesting(true);
        setTtTest(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_test_connection`, {
                engine: 'tiktok', credentials: ttForm.credentials
            });
            setTtTest(res.data.data || { success: false, message: 'No response' });
        } catch (err) { setTtTest({ success: false, message: err.message }); }
        finally { setTtTesting(false); }
    };

    const handleTtSyncNow = async (conn) => {
        setTtBusyId(conn.id);
        setTtMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_sync`, {
                connection_id: conn.id,
                date_from: new Date(Date.now() - (conn.last_sync_at ? 5 : 30) * 86400000).toISOString().slice(0, 10),
                date_to: new Date().toISOString().slice(0, 10)
            });
            const d = res.data;
            if (d.status === 'success') {
                const matched = d.matched ?? d.data?.matched ?? 0;
                const fetched = d.fetched ?? d.data?.fetched ?? 0;
                setTtMessage({
                    type: matched === 0 && fetched > 0 ? 'error' : 'success',
                    text: matched === 0 && fetched > 0
                        ? t('tiktokCosts.syncedNoMatch').replace('{n}', fetched)
                        : t('tiktokCosts.syncedOk').replace('{n}', fetched).replace('{m}', matched)
                });
            } else {
                setTtMessage({ type: 'error', text: d.message || t('tiktokCosts.syncFailed') });
            }
            fetchTtConnections();
        } catch (err) { setTtMessage({ type: 'error', text: err.message }); }
        finally { setTtBusyId(null); }
    };

    const handleTtToggle = async (conn) => {
        // Re-read first: the save endpoint rewrites credentials from the payload,
        // so posting a partial row would wipe the token.
        const full = await loadTtConnection(conn.id);
        if (!full) return;
        await saveTtConnection({ ...full, is_active: conn.is_active ? 0 : 1 }, conn.id);
        fetchTtConnections();
    };

    const handleTtClone = async (conn) => {
        const full = await loadTtConnection(conn.id);
        if (!full) return;
        await saveTtConnection({ ...full, name: `${full.name} copy`, is_active: 0 }, 'new');
        setTtMessage({ type: 'success', text: t('tiktokCosts.cloned') });
        fetchTtConnections();
    };

    const handleTtDelete = async (conn) => {
        if (!confirm(t('tiktokCosts.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=aggregator_connections`, { action: 'delete', id: conn.id });
        setTtMessage({ type: 'success', text: t('tiktokCosts.deleted') });
        fetchTtConnections();
    };

    // ==================== Google Ads Costs ====================

    const resetGoogleAdsOAuth = () => {
        if (gaPollRef.current) {
            window.clearInterval(gaPollRef.current);
            gaPollRef.current = null;
        }
        if (gaPopupRef.current && !gaPopupRef.current.closed) {
            gaPopupRef.current.close();
        }
        gaPopupRef.current = null;
        setGaDiscoveredManagers([]);
        setGaDiscoveredAccounts([]);
        setGaSelectedAccounts([]);
        setGaOAuthFlowId('');
        setGaOAuthLoading(false);
        setGaConnecting(false);
    };

    const handleStartGaOAuth = () => {
        setGaMessage(null);
        setGaDiscoveredManagers([]);
        setGaDiscoveredAccounts([]);
        setGaSelectedAccounts([]);
        setGaOAuthFlowId('');

        if (gaPopupRef.current && !gaPopupRef.current.closed) {
            gaPopupRef.current.close();
        }
        if (gaPollRef.current) {
            window.clearInterval(gaPollRef.current);
        }

        const width = 640;
        const height = 720;
        const left = Math.max(0, window.screenX + Math.round((window.outerWidth - width) / 2));
        const top = Math.max(0, window.screenY + Math.round((window.outerHeight - height) / 2));
        const popup = window.open(
            `${API_URL}?action=google_ads_oauth_start`,
            'orbitra-google-ads-oauth',
            `popup=yes,width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );
        if (!popup) {
            setGaOAuthLoading(false);
            setGaMessage({ type: 'error', text: t('googleAdsCosts.popupBlocked') });
            return;
        }

        gaPopupRef.current = popup;
        setGaOAuthLoading(true);
        popup.focus();
        gaPollRef.current = window.setInterval(() => {
            if (popup.closed) {
                window.clearInterval(gaPollRef.current);
                gaPollRef.current = null;
                gaPopupRef.current = null;
                setGaOAuthLoading(false);
            }
        }, 500);
    };

    const toggleGaAccount = (cid, checked) => {
        setGaSelectedAccounts(current => checked
            ? [...new Set([...current, cid])]
            : current.filter(id => id !== cid));
    };

    const handleConnectGaAccounts = async () => {
        if (!gaOAuthFlowId || gaSelectedAccounts.length === 0) return;
        setGaConnecting(true);
        setGaMessage(null);
        try {
            const accounts = gaDiscoveredAccounts
                .filter(account => gaSelectedAccounts.includes(account.cid))
                .map(account => ({ id: account.cid }));
            const res = await axios.post(`${API_URL}?action=google_ads_connect_accounts`, {
                flow_id: gaOAuthFlowId,
                accounts,
                sync_interval_hours: gaSyncInterval || 2
            });
            if (res.data.status !== 'success') {
                setGaMessage({ type: 'error', text: res.data.message || t('googleAdsCosts.oauthFailed') });
                return;
            }

            const connected = res.data.data?.connected_count ?? accounts.length;
            setGaMessage({ type: 'success', text: t('googleAdsCosts.connectedAccounts').replace('{n}', connected) });
            resetGoogleAdsOAuth();
            setGaEditing(null);
            fetchGaConnections();
        } catch (err) {
            setGaMessage({ type: 'error', text: err.response?.data?.message || err.message || t('googleAdsCosts.oauthFailed') });
        } finally {
            setGaConnecting(false);
        }
    };

    const handleGaSave = async () => {
        if (!gaManualValid()) return;
        try {
            const ok = await saveGaConnection(gaForm, gaEditing);
            if (ok) {
                setGaMessage({ type: 'success', text: t('googleAdsCosts.saved') });
                setGaEditing(null);
                setGaTest(null);
                resetGoogleAdsOAuth();
                fetchGaConnections();
            }
        } catch (err) { setGaMessage({ type: 'error', text: err.message }); }
    };

    const gaManualValid = () =>
        !!(gaForm.name
            && gaForm.credentials.developer_token
            && gaForm.credentials.client_id
            && gaForm.credentials.client_secret
            && gaForm.credentials.refresh_token
            && gaForm.credentials.customer_id);

    const handleGaTest = async () => {
        setGaTesting(true);
        setGaTest(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_test_connection`, {
                engine: 'google_ads', credentials: gaForm.credentials
            });
            setGaTest(res.data.data || { success: false, message: 'No response' });
        } catch (err) { setGaTest({ success: false, message: err.message }); }
        finally { setGaTesting(false); }
    };

    const handleGaSyncNow = async (conn) => {
        setGaBusyId(conn.id);
        setGaMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_sync`, {
                connection_id: conn.id,
                date_from: new Date(Date.now() - (conn.last_sync_at ? 5 : 30) * 86400000).toISOString().slice(0, 10),
                date_to: new Date().toISOString().slice(0, 10)
            });
            const d = res.data;
            if (d.status === 'success') {
                const matched = d.matched ?? d.data?.matched ?? 0;
                const fetched = d.fetched ?? d.data?.fetched ?? 0;
                setGaMessage({
                    type: matched === 0 && fetched > 0 ? 'error' : 'success',
                    text: matched === 0 && fetched > 0
                        ? t('googleAdsCosts.syncedNoMatch').replace('{n}', fetched)
                        : t('googleAdsCosts.syncedOk').replace('{n}', fetched).replace('{m}', matched)
                });
            } else {
                setGaMessage({ type: 'error', text: d.message || t('googleAdsCosts.syncFailed') });
            }
            fetchGaConnections();
        } catch (err) { setGaMessage({ type: 'error', text: err.message }); }
        finally { setGaBusyId(null); }
    };

    const handleGaToggle = async (conn) => {
        // Re-read first: the save endpoint rewrites credentials from the payload,
        // so posting a partial row would wipe the refresh token.
        const full = await loadGaConnection(conn.id);
        if (!full) return;
        await saveGaConnection({ ...full, is_active: conn.is_active ? 0 : 1 }, conn.id);
        fetchGaConnections();
    };

    const handleGaClone = async (conn) => {
        const full = await loadGaConnection(conn.id);
        if (!full) return;
        await saveGaConnection({ ...full, name: `${full.name} copy`, is_active: 0 }, 'new');
        setGaMessage({ type: 'success', text: t('googleAdsCosts.cloned') });
        fetchGaConnections();
    };

    const handleGaDelete = async (conn) => {
        if (!confirm(t('googleAdsCosts.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=aggregator_connections`, { action: 'delete', id: conn.id });
        setGaMessage({ type: 'success', text: t('googleAdsCosts.deleted') });
        fetchGaConnections();
    };

    // Shared across the Facebook / TikTok / Google Ads cost panels — the row
    // shape (sync_interval_hours, last_sync_*) and the locale keys are the same.
    const costNextUpdate = (conn, prefix) => {
        if (!conn.is_active) return t(`${prefix}.paused`);
        if (!conn.last_sync_at) return t(`${prefix}.onNextCron`);
        const last = new Date(conn.last_sync_at.replace(' ', 'T') + 'Z').getTime();
        const next = last + (conn.sync_interval_hours || 2) * 3600000;
        const diff = next - Date.now();
        if (diff <= 0) return t(`${prefix}.due`);
        const mins = Math.round(diff / 60000);
        return mins >= 60 ? `${Math.floor(mins / 60)} h ${mins % 60} min` : `${mins} min`;
    };

    const costStatusBadge = (conn, prefix) => {
        if (!conn.is_active) return { label: t(`${prefix}.paused`), bg: '#e5e7eb', fg: '#374151' };
        if (conn.last_sync_status === 'error') return { label: t(`${prefix}.error`), bg: '#fee2e2', fg: '#991b1b' };
        if (!conn.last_sync_at) return { label: t(`${prefix}.neverSynced`), bg: '#fef3c7', fg: '#92400e' };
        return { label: t(`${prefix}.ok`), bg: '#dcfce7', fg: '#166534' };
    };

    const renderFacebookCostsPanel = () => {
        const visible = fbConnections.filter(c => !fbSearch || (c.name || '').toLowerCase().includes(fbSearch.toLowerCase()));
        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                {fbMessage && (
                    <div style={{
                        padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                        background: fbMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                        color: fbMessage.type === 'success' ? '#166534' : '#991b1b',
                        border: `1px solid ${fbMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                    }}>{fbMessage.text}</div>
                )}

                {fbEditing ? (
                    <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '760px' }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                            {fbEditing === 'new' ? t('fbCosts.addAccount') : t('fbCosts.editAccount')}
                        </h4>
                        <div className="space-y-3">
                            {fbEditing === 'new' && (
                                <>
                                    <div
                                        className="p-5 rounded-2xl border text-center flex flex-col items-center gap-3"
                                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                                    >
                                        <div className="w-12 h-12 rounded-2xl flex items-center justify-center font-bold text-2xl"
                                            style={{ backgroundColor: 'rgba(24, 119, 242, 0.15)', color: '#1877F2' }}>
                                            f
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold m-0" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('fbCosts.oneClickTitle')}
                                            </h4>
                                            <p className="text-xs m-0 mt-1 max-w-md" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('fbCosts.oneClickDesc')}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={handleStartFacebookOAuth}
                                            disabled={fbOAuthLoading || fbOAuthConnecting || fbOauthConfigured === false}
                                            className="btn py-2.5 px-6 rounded-xl font-bold flex items-center gap-2 transition-transform hover:scale-[1.02]"
                                            style={{ backgroundColor: '#1877F2', color: '#ffffff', boxShadow: '0 4px 14px rgba(24, 119, 242, 0.3)', opacity: (fbOAuthLoading || fbOauthConfigured === false) ? 0.75 : 1 }}
                                        >
                                            {fbOAuthLoading ? <RefreshCw size={16} className="animate-spin" /> : <span className="font-extrabold text-base">f</span>}
                                            <span>{fbOAuthLoading ? t('fbCosts.oauthConnecting') : t('fbCosts.loginWithFb')}</span>
                                        </button>
                                        {fbOauthConfigured === false && (
                                            <div className="w-full max-w-md text-left rounded-xl border p-3"
                                                style={{ background: 'var(--color-warning-bg)', borderColor: 'var(--color-warning)' }}>
                                                <div className="text-xs font-bold" style={{ color: 'var(--color-warning)' }}>
                                                    {t('fbCosts.oauthNotConfiguredTitle')}
                                                </div>
                                                <div className="text-[11px] leading-relaxed mt-1" style={{ color: 'var(--color-text-secondary)' }}>
                                                    {t('fbCosts.oauthNotConfiguredHint')}
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {fbDiscoveredAccounts.length > 0 && (
                                        <div className="p-4 rounded-2xl border space-y-3"
                                            style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-success)' }}>
                                            <div className="flex justify-between items-center gap-3">
                                                <span className="text-xs font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                                    {t('fbCosts.selectAccounts')}:
                                                </span>
                                                <button type="button" onClick={() => setFbSelectedAccounts(fbDiscoveredAccounts.map(account => account.id))}
                                                    className="text-xs hover:underline" style={{ color: 'var(--color-primary)' }}>
                                                    {t('common.selectAll')}
                                                </button>
                                            </div>

                                            <div className="space-y-2 max-h-48 overflow-y-auto">
                                                {fbDiscoveredAccounts.map(account => (
                                                    <label key={account.id}
                                                        className="flex items-center justify-between gap-3 p-2.5 rounded-xl border cursor-pointer hover:bg-black/5 dark:hover:bg-white/5"
                                                        style={{ borderColor: 'var(--color-border)' }}>
                                                        <div className="flex items-center gap-2.5 min-w-0">
                                                            <input
                                                                type="checkbox"
                                                                checked={fbSelectedAccounts.includes(account.id)}
                                                                onChange={event => toggleFacebookAccount(account.id, event.target.checked)}
                                                                className="rounded"
                                                            />
                                                            <div className="min-w-0">
                                                                <div className="text-xs font-semibold truncate" style={{ color: 'var(--color-text-primary)' }}>{account.name}</div>
                                                                <div className="text-[11px] font-mono" style={{ color: 'var(--color-text-muted)' }}>{account.id}</div>
                                                            </div>
                                                        </div>
                                                        {account.currency && (
                                                            <span className="text-xs font-semibold px-2 py-0.5 rounded shrink-0"
                                                                style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                                                                {account.currency}
                                                            </span>
                                                        )}
                                                    </label>
                                                ))}
                                            </div>

                                            <button type="button" onClick={handleConnectDiscoveredAccounts}
                                                disabled={fbOAuthConnecting || fbSelectedAccounts.length === 0}
                                                className="btn btn-primary w-full py-2 rounded-xl text-xs font-semibold">
                                                {fbOAuthConnecting ? t('fbCosts.connectingAccounts') : t('fbCosts.connectSelected')} ({fbSelectedAccounts.length})
                                            </button>
                                        </div>
                                    )}

                                    <div className="flex items-center gap-3 my-4">
                                        <div className="flex-1 border-t" style={{ borderColor: 'var(--color-border)' }}></div>
                                        <span className="text-[11px] uppercase font-bold" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('common.orManual')}
                                        </span>
                                        <div className="flex-1 border-t" style={{ borderColor: 'var(--color-border)' }}></div>
                                    </div>
                                </>
                            )}

                            <div>
                                <label className="form-label">{t('fbCosts.name')}</label>
                                <input type="text" className="form-input" value={fbForm.name}
                                    onChange={e => setFbForm({ ...fbForm, name: e.target.value })}
                                    placeholder="Main ad account" />
                            </div>

                            {fbFields.filter(f => !['app_id', 'app_secret'].includes(f.key)).map(field => (
                                <div key={field.key}>
                                    {field.key === 'proxy_url' ? (
                                        <ProxyInput
                                            label={field.label_key ? t(field.label_key, field.label) : field.label}
                                            value={fbForm.credentials.proxy_url || ''}
                                            onChange={(val) => setFbForm({ ...fbForm, credentials: { ...fbForm.credentials, proxy_url: val } })}
                                        />
                                    ) : field.key === 'token' ? (<>
                                        <label className="form-label">
                                            {field.label_key ? t(field.label_key, field.label) : field.label}{field.required && ' *'}
                                        </label>
                                        <div className="flex gap-2">
                                            <input className="form-input flex-1" type="password"
                                                value={fbForm.credentials.token || ''}
                                                placeholder={field.placeholder || ''}
                                                onChange={e => setFbForm({ ...fbForm, credentials: { ...fbForm.credentials, token: e.target.value } })} />
                                            <button type="button" onClick={handleExtendToken}
                                                disabled={fbExtendingToken || !(fbForm.credentials.token || '').trim()}
                                                className="btn btn-secondary text-xs whitespace-nowrap"
                                                title={t('fbCosts.extendTokenHint', 'Exchanges the token for a 60-day long-lived one via the Graph API')}>
                                                <Zap size={12} /> {fbExtendingToken ? '…' : t('fbCosts.extendToken')}
                                            </button>
                                        </div>
                                    </>) : (<>
                                        <label className="form-label">
                                            {field.label_key ? t(field.label_key, field.label) : field.label}{field.required && ' *'}
                                        </label>
                                        {field.type === 'select' ? (
                                            <select className="form-select" value={fbForm.credentials[field.key] || ''}
                                                onChange={e => setFbForm({ ...fbForm, credentials: { ...fbForm.credentials, [field.key]: e.target.value } })}>
                                                <option value="">{t('fbCosts.defaultVersion')}</option>
                                                {(field.options || []).map(o => <option key={o} value={o}>{o}</option>)}
                                            </select>
                                        ) : (
                                            <input className="form-input" type={field.type === 'password' ? 'password' : 'text'}
                                                value={fbForm.credentials[field.key] || ''}
                                                placeholder={field.placeholder || ''}
                                                onChange={e => setFbForm({ ...fbForm, credentials: { ...fbForm.credentials, [field.key]: e.target.value } })} />
                                        )}
                                    </>)}
                                </div>
                            ))}

                            {/* Keitaro-style flow: most users bring a ready
                                long-lived token — show where it comes from. */}
                            <div>
                                <button type="button" onClick={() => setFbShowTokenHowTo(!fbShowTokenHowTo)}
                                    className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    {fbShowTokenHowTo ? '−' : '+'} {t('fbCosts.tokenHowTo')}
                                </button>
                                {fbShowTokenHowTo && (
                                    <div style={{ border: '1px dashed var(--color-border)', borderRadius: '12px', padding: '14px', marginTop: '8px' }}>
                                        <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', margin: 0, whiteSpace: 'pre-line', lineHeight: 1.7 }}>
                                            {t('fbCosts.tokenHowToHint')}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Custom Meta App — most users never need these; the shared
                                instance app or a plain long-lived token is enough. */}
                            <div>
                                <button type="button" onClick={() => setFbShowCustomApp(!fbShowCustomApp)}
                                    className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                    {fbShowCustomApp ? '−' : '+'} {t('fbCosts.customApp')}
                                </button>
                                {fbShowCustomApp && (
                                    <div style={{ border: '1px dashed var(--color-border)', borderRadius: '12px', padding: '14px', marginTop: '8px' }}>
                                        <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginBottom: '10px' }}>{t('fbCosts.customAppHint')}</p>
                                        {fbFields.filter(f => ['app_id', 'app_secret'].includes(f.key)).map(field => (
                                            <div key={field.key} style={{ marginBottom: '8px' }}>
                                                <label className="form-label" style={{ fontSize: '12px' }}>
                                                    {field.label_key ? t(field.label_key, field.label) : field.label}
                                                </label>
                                                <input className="form-input" type={field.type === 'password' ? 'password' : 'text'}
                                                    value={fbForm.credentials[field.key] || ''}
                                                    onChange={e => setFbForm({ ...fbForm, credentials: { ...fbForm.credentials, [field.key]: e.target.value } })} />
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div>
                                <label className="form-label">{t('fbCosts.interval')}</label>
                                <select className="form-select" value={fbForm.sync_interval_hours}
                                    onChange={e => setFbForm({ ...fbForm, sync_interval_hours: parseInt(e.target.value, 10) })}>
                                    {[1, 2, 4, 6, 12, 24].map(h => <option key={h} value={h}>{h} h</option>)}
                                </select>
                            </div>

                            {/* Only relevant when macros arrive repacked by an app — hidden by
                                default so the common case stays a four-field form. */}
                            <button type="button" onClick={() => setFbShowAdvanced(!fbShowAdvanced)}
                                className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                {fbShowAdvanced ? '−' : '+'} {t('fbCosts.advanced')}
                            </button>
                            {fbShowAdvanced && (
                                <div style={{ border: '1px dashed var(--color-border)', borderRadius: '12px', padding: '14px' }}>
                                    <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginBottom: '10px' }}>{t('fbCosts.advancedHint')}</p>
                                    {['ad_id_param', 'adset_id_param', 'campaign_id_param'].map(key => (
                                        <div key={key} style={{ marginBottom: '8px' }}>
                                            <label className="form-label" style={{ fontSize: '12px' }}>{t(`fbCosts.${key}`)}</label>
                                            <input className="form-input" type="text" value={fbForm.field_mapping[key] || ''}
                                                placeholder={key === 'adset_id_param' ? 'sub_id_3' : ''}
                                                onChange={e => setFbForm({ ...fbForm, field_mapping: { ...fbForm.field_mapping, [key]: e.target.value } })} />
                                        </div>
                                    ))}
                                </div>
                            )}

                            {fbTest && (
                                <div style={{
                                    padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                                    background: fbTest.success ? '#dcfce7' : '#fee2e2',
                                    color: fbTest.success ? '#166534' : '#991b1b',
                                    border: `1px solid ${fbTest.success ? '#86efac' : '#fca5a5'}`
                                }}>{fbTest.message}</div>
                            )}

                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'space-between' }}>
                                <button onClick={handleFbTest} className="btn btn-secondary btn-sm"
                                    disabled={fbTesting || !fbForm.credentials.token || !fbForm.credentials.ad_account_id}>
                                    <Zap size={14} /> {fbTesting ? t('fbCosts.testing') : t('fbCosts.testConnection')}
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button onClick={() => { setFbEditing(null); setFbTest(null); resetFacebookOAuth(); }} className="btn btn-secondary btn-sm">
                                        <X size={14} /> {t('common.cancel')}
                                    </button>
                                    <button onClick={handleFbSave} className="btn btn-primary btn-sm"
                                        disabled={!fbForm.name || !fbForm.credentials.token || !fbForm.credentials.ad_account_id}>
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                        <button onClick={() => { setFbForm(emptyFbForm); setFbTest(null); resetFacebookOAuth(); setFbEditing('new'); }} className="btn btn-primary">
                            <Plus size={16} /> {t('fbCosts.addAccount')}
                        </button>
                        <input type="text" className="form-input" style={{ maxWidth: '240px' }}
                            placeholder={t('fbCosts.findAccount')} value={fbSearch}
                            onChange={e => setFbSearch(e.target.value)} />
                    </div>
                )}

                {fbLoading ? (
                    <div className="flex justify-center py-10">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                    </div>
                ) : (
                    <div className="space-y-3" style={{ marginTop: fbEditing ? '20px' : 0 }}>
                        {visible.map(conn => {
                            const badge = costStatusBadge(conn, 'fbCosts');
                            return (
                                <div key={conn.id} style={{
                                    border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                                    background: conn.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                    opacity: conn.is_active ? 1 : 0.7
                                }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px', gap: '12px', flexWrap: 'wrap' }}>
                                        <div>
                                            <div style={{ fontWeight: 600, fontSize: '14px' }}>{conn.name}</div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                {t('fbCosts.nextUpdate')}: {costNextUpdate(conn, 'fbCosts')}
                                                {conn.last_sync_at && <> · {t('fbCosts.lastSync')}: {conn.last_sync_at}</>}
                                            </div>
                                        </div>
                                        <span style={{ fontSize: '11px', padding: '2px 8px', borderRadius: '6px', background: badge.bg, color: badge.fg, whiteSpace: 'nowrap' }}>
                                            {badge.label}
                                        </span>
                                    </div>

                                    {conn.last_sync_status === 'error' && conn.last_sync_error && (
                                        <div style={{ fontSize: '12px', color: '#ef4444', marginBottom: '10px', wordBreak: 'break-word' }}>
                                            {conn.last_sync_error}
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                        <button onClick={() => handleFbSyncNow(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} disabled={fbBusyId === conn.id}>
                                            <RefreshCw size={12} className={fbBusyId === conn.id ? 'animate-spin' : ''} /> {t('fbCosts.syncNow')}
                                        </button>
                                        <button onClick={async () => {
                                            const full = await loadFbConnection(conn.id);
                                            if (full) {
                                                setFbForm(full);
                                                setFbTest(null);
                                                setFbEditing(conn.id);
                                                // A stored custom app should stay visible while editing.
                                                setFbShowCustomApp(!!(full.credentials?.app_id || full.credentials?.app_secret));
                                            }
                                        }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Edit2 size={12} /> {t('common.edit')}
                                        </button>
                                        <button onClick={() => handleFbToggle(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Power size={12} /> {conn.is_active ? t('fbCosts.pause') : t('fbCosts.resume')}
                                        </button>
                                        <button onClick={() => handleFbClone(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Copy size={12} /> {t('fbCosts.clone')}
                                        </button>
                                        <button onClick={() => handleFbDelete(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }}>
                                            <Trash2 size={12} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}

                        {visible.length === 0 && !fbEditing && (
                            <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '30px 0' }}>
                                {t('fbCosts.noAccounts')}
                            </p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // ==================== TikTok Costs ====================

    const renderTikTokCostsPanel = () => {
        const visible = ttConnections.filter(c => !ttSearch
            || (c.name || '').toLowerCase().includes(ttSearch.toLowerCase())
            || String(c.id).includes(ttSearch));
        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                {ttMessage && (
                    <div style={{
                        padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                        background: ttMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                        color: ttMessage.type === 'success' ? '#166534' : '#991b1b',
                        border: `1px solid ${ttMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                    }}>{ttMessage.text}</div>
                )}

                {ttEditing ? (
                    <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '760px' }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                            {ttEditing === 'new' ? t('tiktokCosts.addAccount') : t('tiktokCosts.editAccount')}
                        </h4>
                        <div className="space-y-3">
                            {ttEditing === 'new' && (
                                <>
                                    <div
                                        className="p-5 rounded-2xl border text-center flex flex-col items-center gap-3"
                                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                                    >
                                        <div className="w-12 h-12 rounded-2xl flex items-center justify-center"
                                            style={{ backgroundColor: 'rgba(254, 44, 85, 0.12)', color: '#FE2C55' }}>
                                            <Music2 size={22} />
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold m-0" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('tiktokCosts.oneClickTitle')}
                                            </h4>
                                            <p className="text-xs m-0 mt-1 max-w-md" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('tiktokCosts.oneClickDesc')}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={handleStartTikTokOAuth}
                                            disabled={ttOAuthLoading || ttConnecting}
                                            className="btn py-2.5 px-6 rounded-xl font-bold flex items-center gap-2 transition-transform hover:scale-[1.02]"
                                            style={{ backgroundColor: '#FE2C55', color: '#ffffff', boxShadow: '0 4px 14px rgba(254, 44, 85, 0.3)', opacity: ttOAuthLoading ? 0.75 : 1 }}
                                        >
                                            {ttOAuthLoading ? <RefreshCw size={16} className="animate-spin" /> : <Music2 size={16} />}
                                            <span>{ttOAuthLoading ? t('tiktokCosts.oauthConnecting') : t('tiktokCosts.loginWithTikTok')}</span>
                                        </button>
                                    </div>

                                    {ttDiscoveredAccounts.length > 0 && (
                                        <div className="p-4 rounded-2xl border space-y-3"
                                            style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-success)' }}>
                                            <div className="flex justify-between items-center gap-3">
                                                <span className="text-xs font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                                    {t('tiktokCosts.selectAccounts')}:
                                                </span>
                                                <button type="button" onClick={() => setTtSelectedAccounts(ttDiscoveredAccounts.map(account => account.id))}
                                                    className="text-xs hover:underline" style={{ color: 'var(--color-primary)' }}>
                                                    {t('common.selectAll')}
                                                </button>
                                            </div>

                                            <div className="space-y-2 max-h-48 overflow-y-auto">
                                                {ttDiscoveredAccounts.map(account => (
                                                    <label key={account.id}
                                                        className="flex items-center justify-between gap-3 p-2.5 rounded-xl border cursor-pointer hover:bg-black/5 dark:hover:bg-white/5"
                                                        style={{ borderColor: 'var(--color-border)' }}>
                                                        <div className="flex items-center gap-2.5 min-w-0">
                                                            <input
                                                                type="checkbox"
                                                                checked={ttSelectedAccounts.includes(account.id)}
                                                                onChange={event => toggleTikTokAccount(account.id, event.target.checked)}
                                                                className="rounded"
                                                            />
                                                            <div className="min-w-0">
                                                                <div className="text-xs font-semibold truncate" style={{ color: 'var(--color-text-primary)' }}>{account.name}</div>
                                                                <div className="text-[11px] font-mono" style={{ color: 'var(--color-text-muted)' }}>{account.id}</div>
                                                            </div>
                                                        </div>
                                                        {account.currency && (
                                                            <span className="text-xs font-semibold px-2 py-0.5 rounded shrink-0"
                                                                style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                                                                {account.currency}
                                                            </span>
                                                        )}
                                                    </label>
                                                ))}
                                            </div>

                                            {ttDiscoveredPixels.length > 0 && (
                                                <div className="p-3 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                                    <div className="text-xs font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                                        {t('tiktokCosts.discoveredPixels')} ({ttDiscoveredPixels.length}):
                                                    </div>
                                                    <div className="space-y-1 mb-2 max-h-28 overflow-y-auto">
                                                        {ttDiscoveredPixels.map(pixel => (
                                                            <div key={pixel.pixel_id} className="text-[11px] font-mono truncate" style={{ color: 'var(--color-text-muted)' }}>
                                                                {pixel.pixel_id} — {pixel.name} ({pixel.advertiser_name})
                                                            </div>
                                                        ))}
                                                    </div>
                                                    <label className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--color-text-secondary)' }}>
                                                        <input
                                                            type="checkbox"
                                                            checked={ttImportPixels}
                                                            onChange={event => setTtImportPixels(event.target.checked)}
                                                            className="rounded"
                                                        />
                                                        {t('tiktokCosts.importPixels')}
                                                    </label>
                                                </div>
                                            )}

                                            <div className="flex items-center gap-2">
                                                <span className="text-xs whitespace-nowrap" style={{ color: 'var(--color-text-secondary)' }}>
                                                    {t('tiktokCosts.syncEvery')}:
                                                </span>
                                                <select className="form-select text-xs" style={{ maxWidth: '120px' }}
                                                    value={ttSyncInterval} onChange={e => setTtSyncInterval(Number(e.target.value))}>
                                                    {[1, 2, 6, 12, 24].map(hours => (
                                                        <option key={hours} value={hours}>{hours}h</option>
                                                    ))}
                                                </select>
                                            </div>

                                            <button type="button" onClick={handleConnectTikTokAccounts}
                                                disabled={ttConnecting || ttSelectedAccounts.length === 0}
                                                className="btn btn-primary w-full py-2 rounded-xl text-xs font-semibold">
                                                {ttConnecting ? t('tiktokCosts.connecting') : t('tiktokCosts.connectSelected')} ({ttSelectedAccounts.length})
                                            </button>
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', margin: '4px 0' }}>
                                        <div style={{ flex: 1, height: '1px', background: 'var(--color-border)' }} />
                                        <span style={{ fontSize: '11px', color: 'var(--color-text-muted)', whiteSpace: 'nowrap' }}>{t('tiktokCosts.manualSection')}</span>
                                        <div style={{ flex: 1, height: '1px', background: 'var(--color-border)' }} />
                                    </div>
                                </>
                            )}

                            <div>
                                <label className="form-label">{t('tiktokCosts.name')} *</label>
                                <input className="form-input" type="text"
                                    value={ttForm.name}
                                    placeholder={t('tiktokCosts.namePlaceholder', 'Cabinet / account name')}
                                    onChange={e => setTtForm({ ...ttForm, name: e.target.value })} />
                            </div>

                            {ttFields.map(field => (
                                <div key={field.key}>
                                    {field.key === 'proxy_url' ? (
                                        <ProxyInput
                                            label={field.label_key ? t(field.label_key, field.label) : field.label}
                                            value={ttForm.credentials.proxy_url || ''}
                                            onChange={(val) => setTtForm({ ...ttForm, credentials: { ...ttForm.credentials, proxy_url: val } })}
                                        />
                                    ) : (
                                        <>
                                            <label className="form-label">
                                                {field.label_key ? t(field.label_key, field.label) : field.label}{field.required && ' *'}
                                            </label>
                                            <input className="form-input" type={field.type === 'password' ? 'password' : 'text'}
                                                value={ttForm.credentials[field.key] || ''}
                                                placeholder={field.placeholder || ''}
                                                onChange={e => setTtForm({ ...ttForm, credentials: { ...ttForm.credentials, [field.key]: e.target.value } })} />
                                        </>
                                    )}
                                </div>
                            ))}

                            <div>
                                <label className="form-label">{t('tiktokCosts.interval')}</label>
                                <select className="form-select" value={ttForm.sync_interval_hours}
                                    onChange={e => setTtForm({ ...ttForm, sync_interval_hours: parseInt(e.target.value, 10) })}>
                                    {[1, 2, 4, 6, 12, 24].map(h => <option key={h} value={h}>{h} h</option>)}
                                </select>
                            </div>

                            {ttTest && (
                                <div style={{
                                    padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                                    background: ttTest.success ? '#dcfce7' : '#fee2e2',
                                    color: ttTest.success ? '#166534' : '#991b1b',
                                    border: `1px solid ${ttTest.success ? '#86efac' : '#fca5a5'}`
                                }}>{ttTest.message}</div>
                            )}

                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'space-between' }}>
                                <button onClick={handleTtTest} className="btn btn-secondary btn-sm"
                                    disabled={ttTesting || !ttForm.credentials.access_token || !ttForm.credentials.advertiser_id}>
                                    <Zap size={14} /> {ttTesting ? t('tiktokCosts.testing') : t('tiktokCosts.testConnection')}
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button onClick={() => { setTtEditing(null); setTtTest(null); resetTikTokOAuth(); }} className="btn btn-secondary btn-sm">
                                        <X size={14} /> {t('common.cancel')}
                                    </button>
                                    <button onClick={handleTtSave} className="btn btn-primary btn-sm"
                                        disabled={!ttForm.name || !ttForm.credentials.access_token || !ttForm.credentials.advertiser_id}>
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                        <button onClick={() => {
                            setTtForm(emptyTtForm);
                            setTtTest(null);
                            resetTikTokOAuth();
                            setTtImportPixels(true);
                            setTtSyncInterval(2);
                            setTtMessage(null);
                            setTtEditing('new');
                        }} className="btn btn-primary">
                            <Plus size={16} /> {t('tiktokCosts.addAccount')}
                        </button>
                        <input type="text" className="form-input" style={{ maxWidth: '240px' }}
                            placeholder={t('tiktokCosts.findAccount')} value={ttSearch}
                            onChange={e => setTtSearch(e.target.value)} />
                    </div>
                )}

                {ttLoading ? (
                    <div className="flex justify-center py-10">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                    </div>
                ) : (
                    <div className="space-y-3" style={{ marginTop: ttEditing ? '20px' : 0 }}>
                        {visible.map(conn => {
                            const badge = costStatusBadge(conn, 'tiktokCosts');
                            return (
                                <div key={conn.id} style={{
                                    border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                                    background: conn.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                    opacity: conn.is_active ? 1 : 0.7
                                }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px', gap: '12px', flexWrap: 'wrap' }}>
                                        <div>
                                            <div style={{ fontWeight: 600, fontSize: '14px' }}>{conn.name}</div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                {t('tiktokCosts.nextUpdate')}: {costNextUpdate(conn, 'tiktokCosts')}
                                                {conn.last_sync_at && <> · {t('tiktokCosts.lastSync')}: {conn.last_sync_at}</>}
                                            </div>
                                        </div>
                                        <span style={{ fontSize: '11px', padding: '2px 8px', borderRadius: '6px', background: badge.bg, color: badge.fg, whiteSpace: 'nowrap' }}>
                                            {badge.label}
                                        </span>
                                    </div>

                                    {conn.last_sync_status === 'error' && conn.last_sync_error && (
                                        <div style={{ fontSize: '12px', color: '#ef4444', marginBottom: '10px', wordBreak: 'break-word' }}>
                                            {conn.last_sync_error}
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                        <button onClick={() => handleTtSyncNow(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} disabled={ttBusyId === conn.id}>
                                            <RefreshCw size={12} className={ttBusyId === conn.id ? 'animate-spin' : ''} /> {t('tiktokCosts.syncNow')}
                                        </button>
                                        <button onClick={async () => {
                                            const full = await loadTtConnection(conn.id);
                                            if (full) {
                                                setTtForm(full);
                                                setTtTest(null);
                                                setTtEditing(conn.id);
                                            }
                                        }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Edit2 size={12} /> {t('common.edit')}
                                        </button>
                                        <button onClick={() => handleTtToggle(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Power size={12} /> {conn.is_active ? t('tiktokCosts.pause') : t('tiktokCosts.resume')}
                                        </button>
                                        <button onClick={() => handleTtClone(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Copy size={12} /> {t('tiktokCosts.clone')}
                                        </button>
                                        <button onClick={() => handleTtDelete(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }}>
                                            <Trash2 size={12} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}

                        {visible.length === 0 && !ttEditing && (
                            <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '30px 0' }}>
                                {t('tiktokCosts.noAccounts')}
                            </p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // ==================== Google Ads Costs ====================

    const renderGoogleAdsCostsPanel = () => {
        const visible = gaConnections.filter(c => !gaSearch
            || (c.name || '').toLowerCase().includes(gaSearch.toLowerCase())
            || String(c.id).includes(gaSearch));
        const directAccounts = gaDiscoveredAccounts.filter(account => !account.login_customer_id);
        const mccGroups = gaDiscoveredManagers.map(manager => ({
            manager,
            accounts: gaDiscoveredAccounts.filter(account => account.login_customer_id === manager.cid),
        })).filter(group => group.accounts.length > 0);

        const renderGaAccountRow = (account) => (
            <label key={account.cid}
                className="flex items-center justify-between gap-3 p-2.5 rounded-xl border cursor-pointer hover:bg-black/5 dark:hover:bg-white/5"
                style={{ borderColor: 'var(--color-border)' }}>
                <div className="flex items-center gap-2.5 min-w-0">
                    <input
                        type="checkbox"
                        checked={gaSelectedAccounts.includes(account.cid)}
                        onChange={event => toggleGaAccount(account.cid, event.target.checked)}
                        className="rounded"
                    />
                    <div className="min-w-0">
                        <div className="text-xs font-semibold truncate" style={{ color: 'var(--color-text-primary)' }}>{account.name}</div>
                        <div className="text-[11px] font-mono" style={{ color: 'var(--color-text-muted)' }}>
                            {account.display_cid || account.cid}{account.manager ? ' · MCC' : ''}
                        </div>
                    </div>
                </div>
                {account.currency && (
                    <span className="text-xs font-semibold px-2 py-0.5 rounded shrink-0"
                        style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                        {account.currency}
                    </span>
                )}
            </label>
        );

        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                {gaMessage && (
                    <div style={{
                        padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                        background: gaMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                        color: gaMessage.type === 'success' ? '#166534' : '#991b1b',
                        border: `1px solid ${gaMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                    }}>{gaMessage.text}</div>
                )}

                {gaEditing ? (
                    <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '760px' }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                            {gaEditing === 'new' ? t('googleAdsCosts.addAccount') : t('googleAdsCosts.editAccount')}
                        </h4>
                        <div className="space-y-3">
                            {gaEditing === 'new' && (
                                <>
                                    <div
                                        className="p-5 rounded-2xl border text-center flex flex-col items-center gap-3"
                                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                                    >
                                        <div className="w-12 h-12 rounded-2xl flex items-center justify-center font-bold text-2xl"
                                            style={{ backgroundColor: 'rgba(66, 133, 244, 0.12)', color: '#4285F4' }}>
                                            G
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold m-0" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('googleAdsCosts.oneClickTitle')}
                                            </h4>
                                            <p className="text-xs m-0 mt-1 max-w-md" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('googleAdsCosts.oneClickDesc')}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={handleStartGaOAuth}
                                            disabled={gaOAuthLoading || gaConnecting}
                                            className="btn py-2.5 px-6 rounded-xl font-bold flex items-center gap-2 transition-transform hover:scale-[1.02]"
                                            style={{ backgroundColor: '#4285F4', color: '#ffffff', boxShadow: '0 4px 14px rgba(66, 133, 244, 0.3)', opacity: gaOAuthLoading ? 0.75 : 1 }}
                                        >
                                            {gaOAuthLoading ? <RefreshCw size={16} className="animate-spin" /> : <Search size={16} />}
                                            <span>{gaOAuthLoading ? t('googleAdsCosts.oauthConnecting') : t('googleAdsCosts.loginWithGoogle')}</span>
                                        </button>
                                    </div>

                                    {gaDiscoveredAccounts.length > 0 && (
                                        <div className="p-4 rounded-2xl border space-y-3"
                                            style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-success)' }}>
                                            <div className="flex justify-between items-center gap-3">
                                                <span className="text-xs font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                                    {t('googleAdsCosts.selectAccounts')}:
                                                </span>
                                                <button type="button" onClick={() => setGaSelectedAccounts(gaDiscoveredAccounts.map(account => account.cid))}
                                                    className="text-xs hover:underline" style={{ color: 'var(--color-primary)' }}>
                                                    {t('common.selectAll')}
                                                </button>
                                            </div>

                                            <div className="space-y-3 max-h-72 overflow-y-auto">
                                                {directAccounts.length > 0 && (
                                                    <div className="space-y-2">
                                                        <div className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--color-text-muted)' }}>
                                                            {t('googleAdsCosts.directAccounts')}
                                                        </div>
                                                        {directAccounts.map(renderGaAccountRow)}
                                                    </div>
                                                )}
                                                {mccGroups.map(group => (
                                                    <div key={group.manager.cid} className="space-y-2">
                                                        <div className="flex justify-between items-center gap-2">
                                                            <div className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--color-text-muted)' }}>
                                                                {t('googleAdsCosts.mccManager')}: {group.manager.name} <span className="font-mono normal-case">({group.manager.display_cid})</span>
                                                            </div>
                                                            <button type="button"
                                                                onClick={() => setGaSelectedAccounts(current => {
                                                                    const groupCids = group.accounts.map(account => account.cid);
                                                                    const allSelected = groupCids.every(cid => current.includes(cid));
                                                                    return allSelected
                                                                        ? current.filter(cid => !groupCids.includes(cid))
                                                                        : [...new Set([...current, ...groupCids])];
                                                                })}
                                                                className="text-[11px] hover:underline whitespace-nowrap" style={{ color: 'var(--color-primary)' }}>
                                                                {t('googleAdsCosts.selectAllInMcc')}
                                                            </button>
                                                        </div>
                                                        {group.accounts.map(renderGaAccountRow)}
                                                    </div>
                                                ))}
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <span className="text-xs whitespace-nowrap" style={{ color: 'var(--color-text-secondary)' }}>
                                                    {t('googleAdsCosts.syncEvery')}:
                                                </span>
                                                <select className="form-select text-xs" style={{ maxWidth: '120px' }}
                                                    value={gaSyncInterval} onChange={e => setGaSyncInterval(Number(e.target.value))}>
                                                    {[1, 2, 6, 12, 24].map(hours => (
                                                        <option key={hours} value={hours}>{hours}h</option>
                                                    ))}
                                                </select>
                                            </div>

                                            <button type="button" onClick={handleConnectGaAccounts}
                                                disabled={gaConnecting || gaSelectedAccounts.length === 0}
                                                className="btn btn-primary w-full py-2 rounded-xl text-xs font-semibold">
                                                {gaConnecting ? t('googleAdsCosts.connecting') : t('googleAdsCosts.connectSelected')} ({gaSelectedAccounts.length})
                                            </button>
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', margin: '4px 0' }}>
                                        <div style={{ flex: 1, height: '1px', background: 'var(--color-border)' }} />
                                        <span style={{ fontSize: '11px', color: 'var(--color-text-muted)', whiteSpace: 'nowrap' }}>{t('googleAdsCosts.manualSection')}</span>
                                        <div style={{ flex: 1, height: '1px', background: 'var(--color-border)' }} />
                                    </div>
                                </>
                            )}

                            <div>
                                <label className="form-label">{t('googleAdsCosts.name')} *</label>
                                <input className="form-input" type="text"
                                    value={gaForm.name}
                                    placeholder={t('googleAdsCosts.namePlaceholder', 'Account name')}
                                    onChange={e => setGaForm({ ...gaForm, name: e.target.value })} />
                            </div>

                            {gaFields.map(field => (
                                <div key={field.key}>
                                    {field.key === 'proxy_url' ? (
                                        <ProxyInput
                                            label={field.label_key ? t(field.label_key, field.label) : field.label}
                                            value={gaForm.credentials.proxy_url || ''}
                                            onChange={(val) => setGaForm({ ...gaForm, credentials: { ...gaForm.credentials, proxy_url: val } })}
                                        />
                                    ) : (
                                        <>
                                            <label className="form-label">
                                                {field.label_key ? t(field.label_key, field.label) : field.label}{field.required && ' *'}
                                            </label>
                                            <input className="form-input" type={field.type === 'password' ? 'password' : 'text'}
                                                value={gaForm.credentials[field.key] || ''}
                                                placeholder={field.placeholder || ''}
                                                onChange={e => setGaForm({ ...gaForm, credentials: { ...gaForm.credentials, [field.key]: e.target.value } })} />
                                        </>
                                    )}
                                </div>
                            ))}

                            <div>
                                <label className="form-label">{t('googleAdsCosts.interval')}</label>
                                <select className="form-select" value={gaForm.sync_interval_hours}
                                    onChange={e => setGaForm({ ...gaForm, sync_interval_hours: parseInt(e.target.value, 10) })}>
                                    {[1, 2, 4, 6, 12, 24].map(h => <option key={h} value={h}>{h} h</option>)}
                                </select>
                            </div>

                            {gaTest && (
                                <div style={{
                                    padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                                    background: gaTest.success ? '#dcfce7' : '#fee2e2',
                                    color: gaTest.success ? '#166534' : '#991b1b',
                                    border: `1px solid ${gaTest.success ? '#86efac' : '#fca5a5'}`
                                }}>{gaTest.message}</div>
                            )}

                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'space-between' }}>
                                <button onClick={handleGaTest} className="btn btn-secondary btn-sm"
                                    disabled={gaTesting || !gaManualValid()}>
                                    <Zap size={14} /> {gaTesting ? t('googleAdsCosts.testing') : t('googleAdsCosts.testConnection')}
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button onClick={() => { setGaEditing(null); setGaTest(null); resetGoogleAdsOAuth(); }} className="btn btn-secondary btn-sm">
                                        <X size={14} /> {t('common.cancel')}
                                    </button>
                                    <button onClick={handleGaSave} className="btn btn-primary btn-sm" disabled={!gaManualValid()}>
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                        <button onClick={() => {
                            setGaForm(emptyGaForm);
                            setGaTest(null);
                            resetGoogleAdsOAuth();
                            setGaSyncInterval(2);
                            setGaMessage(null);
                            setGaEditing('new');
                        }} className="btn btn-primary">
                            <Plus size={16} /> {t('googleAdsCosts.addAccount')}
                        </button>
                        <input type="text" className="form-input" style={{ maxWidth: '240px' }}
                            placeholder={t('googleAdsCosts.findAccount')} value={gaSearch}
                            onChange={e => setGaSearch(e.target.value)} />
                    </div>
                )}

                {gaLoading ? (
                    <div className="flex justify-center py-10">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                    </div>
                ) : (
                    <div className="space-y-3" style={{ marginTop: gaEditing ? '20px' : 0 }}>
                        {visible.map(conn => {
                            const badge = costStatusBadge(conn, 'googleAdsCosts');
                            return (
                                <div key={conn.id} style={{
                                    border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                                    background: conn.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                    opacity: conn.is_active ? 1 : 0.7
                                }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px', gap: '12px', flexWrap: 'wrap' }}>
                                        <div>
                                            <div style={{ fontWeight: 600, fontSize: '14px', display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                {conn.name}
                                                <span style={{
                                                    fontSize: '10px', padding: '1px 6px', borderRadius: '6px',
                                                    background: 'color-mix(in srgb, var(--color-primary) 12%, transparent)',
                                                    color: 'var(--color-primary)', whiteSpace: 'nowrap'
                                                }}>
                                                    {t('googleAdsCosts.mccSubAccount')}
                                                </span>
                                            </div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                {t('googleAdsCosts.nextUpdate')}: {costNextUpdate(conn, 'googleAdsCosts')}
                                                {conn.last_sync_at && <> · {t('googleAdsCosts.lastSync')}: {conn.last_sync_at}</>}
                                            </div>
                                        </div>
                                        <span style={{ fontSize: '11px', padding: '2px 8px', borderRadius: '6px', background: badge.bg, color: badge.fg, whiteSpace: 'nowrap' }}>
                                            {badge.label}
                                        </span>
                                    </div>

                                    {conn.last_sync_status === 'error' && conn.last_sync_error && (
                                        <div style={{ fontSize: '12px', color: '#ef4444', marginBottom: '10px', wordBreak: 'break-word' }}>
                                            {conn.last_sync_error}
                                        </div>
                                    )}

                                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                        <button onClick={() => handleGaSyncNow(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} disabled={gaBusyId === conn.id}>
                                            <RefreshCw size={12} className={gaBusyId === conn.id ? 'animate-spin' : ''} /> {t('googleAdsCosts.syncNow')}
                                        </button>
                                        <button onClick={async () => {
                                            const full = await loadGaConnection(conn.id);
                                            if (full) {
                                                setGaForm(full);
                                                setGaTest(null);
                                                setGaEditing(conn.id);
                                            }
                                        }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Edit2 size={12} /> {t('common.edit')}
                                        </button>
                                        <button onClick={() => handleGaToggle(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Power size={12} /> {conn.is_active ? t('googleAdsCosts.pause') : t('googleAdsCosts.resume')}
                                        </button>
                                        <button onClick={() => handleGaClone(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Copy size={12} /> {t('googleAdsCosts.clone')}
                                        </button>
                                        <button onClick={() => handleGaDelete(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }}>
                                            <Trash2 size={12} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}

                        {visible.length === 0 && !gaEditing && (
                            <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '30px 0' }}>
                                {t('googleAdsCosts.noAccounts')}
                            </p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // ==================== Pixel Vault ====================

    const pixelSourceOptions = [
        { value: 'facebook', label: 'Facebook', icon: '📘' },
        { value: 'tiktok', label: 'TikTok', icon: '🎵' },
        { value: 'google_ads', label: 'Google Ads', icon: '🔎' },
        { value: 'snapchat', label: 'Snapchat', icon: '👻' },
        { value: 'pinterest', label: 'Pinterest', icon: '📌' },
    ];
    const suggestedPixelNiches = ['Nutra', 'Crypto', 'E-Commerce', 'Gambling', 'Dating', 'Sweepstakes'];

    const pixelProfileToForm = (profile) => ({
        id: profile.id,
        traffic_source: profile.traffic_source || 'facebook',
        niche: profile.niche || 'General',
        name: profile.name || '',
        pixel_id: profile.pixel_id || '',
        // Secrets are not returned by the list endpoint. Blank preserves it.
        token: '',
        event_url: profile.event_url || '',
        test_event_code: profile.test_event_code || '',
        events: profile.events || 'PageView,Lead,Purchase',
        is_active: profile.is_active ? 1 : 0,
        has_token: !!profile.has_token,
    });

    const handlePixelProfileSave = async () => {
        if (!pixelProfileForm.name.trim() || !pixelProfileForm.pixel_id.trim()) return;
        if (pixelProfileEditing === 'new' && !pixelProfileForm.token.trim()) return;
        setPixelProfileMessage(null);
        try {
            const payload = { ...pixelProfileForm };
            delete payload.has_token;
            if (pixelProfileEditing && pixelProfileEditing !== 'new') payload.id = pixelProfileEditing;
            const res = await axios.post(`${API_URL}?action=save_pixel_profile`, payload);
            if (res.data.status === 'success') {
                setPixelProfileMessage({ type: 'success', text: t('pixelVault.saved') });
                setPixelProfileEditing(null);
                setPixelProfileForm(emptyPixelProfileForm);
                fetchPixelProfiles();
            } else {
                setPixelProfileMessage({ type: 'error', text: res.data.message || t('common.error') });
            }
        } catch (err) {
            setPixelProfileMessage({ type: 'error', text: err.response?.data?.message || err.message });
        }
    };

    const handlePixelProfileDelete = async (profile) => {
        if (!confirm(t('pixelVault.confirmDelete'))) return;
        try {
            const res = await axios.post(`${API_URL}?action=delete_pixel_profile`, { id: profile.id });
            if (res.data.status === 'success') {
                setPixelProfileMessage({ type: 'success', text: t('pixelVault.deleted') });
                fetchPixelProfiles();
            } else {
                setPixelProfileMessage({ type: 'error', text: res.data.message || t('common.error') });
            }
        } catch (err) {
            setPixelProfileMessage({ type: 'error', text: err.response?.data?.message || err.message });
        }
    };

    const handlePixelProfileDuplicate = async (profile) => {
        try {
            const res = await axios.post(`${API_URL}?action=save_pixel_profile`, { duplicate_from_id: profile.id });
            if (res.data.status === 'success') {
                setPixelProfileMessage({ type: 'success', text: t('pixelVault.duplicated') });
                fetchPixelProfiles();
            } else {
                setPixelProfileMessage({ type: 'error', text: res.data.message || t('common.error') });
            }
        } catch (err) {
            setPixelProfileMessage({ type: 'error', text: err.response?.data?.message || err.message });
        }
    };

    const handlePixelProfileTest = async (profile = null) => {
        const source = profile?.traffic_source || pixelProfileForm.traffic_source;
        if (!['facebook', 'tiktok'].includes(source)) {
            setPixelProfileMessage({ type: 'error', text: t('pixelVault.testUnsupported') });
            return;
        }
        const testingKey = profile?.id || 'form';
        setPixelProfileTesting(testingKey);
        setPixelProfileMessage(null);
        try {
            const payload = profile
                ? { id: profile.id }
                : {
                    id: pixelProfileEditing !== 'new' && !pixelProfileForm.token ? pixelProfileEditing : undefined,
                    traffic_source: pixelProfileForm.traffic_source,
                    pixel_id: pixelProfileForm.pixel_id,
                    token: pixelProfileForm.token,
                    event_url: pixelProfileForm.event_url,
                    test_event_code: pixelProfileForm.test_event_code,
                };
            const res = await axios.post(`${API_URL}?action=pixel_profile_test_event`, payload);
            setPixelProfileMessage({
                type: res.data.status === 'success' ? 'success' : 'error',
                text: res.data.status === 'success' ? t('pixelVault.testEventSent') : (res.data.message || t('common.error'))
            });
        } catch (err) {
            setPixelProfileMessage({ type: 'error', text: err.response?.data?.message || err.message });
        } finally {
            setPixelProfileTesting(null);
        }
    };

    const renderPixelVaultPanel = () => {
        const niches = [...new Set(pixelProfiles.map(profile => profile.niche || 'General'))]
            .sort((a, b) => a.localeCompare(b));
        const query = pixelProfileSearch.trim().toLowerCase();
        // Source tabs: "All" plus every source that actually has profiles.
        const sourceCounts = pixelProfiles.reduce((counts, profile) => {
            const source = profile.traffic_source || 'facebook';
            counts[source] = (counts[source] || 0) + 1;
            return counts;
        }, {});
        const visible = pixelProfiles.filter(profile => {
            if (pixelProfileSource !== 'all' && (profile.traffic_source || 'facebook') !== pixelProfileSource) return false;
            if (pixelProfileNiche && (profile.niche || 'General') !== pixelProfileNiche) return false;
            if (!query) return true;
            return [profile.name, profile.niche, profile.pixel_id, profile.traffic_source]
                .some(value => String(value || '').toLowerCase().includes(query));
        });
        const grouped = visible.reduce((groups, profile) => {
            const niche = profile.niche || 'General';
            if (!groups[niche]) groups[niche] = [];
            groups[niche].push(profile);
            return groups;
        }, {});

        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                {pixelProfileMessage && (
                    <div style={{
                        padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                        background: pixelProfileMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                        color: pixelProfileMessage.type === 'success' ? '#166534' : '#991b1b',
                        border: `1px solid ${pixelProfileMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                    }}>
                        {pixelProfileMessage.text}
                    </div>
                )}

                {pixelProfileEditing ? (
                    <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '820px', marginBottom: '20px' }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                            {pixelProfileEditing === 'new' ? t('pixelVault.addNewPixel') : t('pixelVault.editPixel')}
                        </h4>
                        <div className="space-y-3">
                            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '12px' }}>
                                <div>
                                    <label className="form-label">{t('pixelVault.trafficSource')} *</label>
                                    <select className="form-select" value={pixelProfileForm.traffic_source}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, traffic_source: e.target.value })}>
                                        {pixelSourceOptions.map(source => <option key={source.value} value={source.value}>{source.icon} {source.label}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('pixelVault.nicheTag')} *</label>
                                    <input className="form-input" list="pixel-vault-niches" value={pixelProfileForm.niche}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, niche: e.target.value })}
                                        placeholder="Nutra, Crypto…" />
                                    <datalist id="pixel-vault-niches">
                                        {[...new Set([...suggestedPixelNiches, ...niches])].map(niche => <option key={niche} value={niche} />)}
                                    </datalist>
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('pixelVault.pixelName')} *</label>
                                <input className="form-input" value={pixelProfileForm.name}
                                    onChange={e => setPixelProfileForm({ ...pixelProfileForm, name: e.target.value })}
                                    placeholder="[Nutra IN] WeightLoss Main CAPI" />
                            </div>
                            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '12px' }}>
                                <div>
                                    <label className="form-label">{t('pixelVault.pixelId')} *</label>
                                    <input className="form-input font-mono" value={pixelProfileForm.pixel_id}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, pixel_id: e.target.value })}
                                        placeholder="12020948572849" />
                                </div>
                                <div>
                                    <label className="form-label">{t('pixelVault.capiToken')} {pixelProfileEditing === 'new' ? '*' : ''}</label>
                                    <input type="password" className="form-input font-mono" value={pixelProfileForm.token}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, token: e.target.value })}
                                        placeholder={pixelProfileEditing === 'new' ? 'EAA…' : t('pixelVault.keepToken')} />
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('pixelVault.events')}</label>
                                <input className="form-input" value={pixelProfileForm.events}
                                    onChange={e => setPixelProfileForm({ ...pixelProfileForm, events: e.target.value })}
                                    placeholder="PageView,Lead,Purchase" />
                            </div>
                            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 1fr)', gap: '12px' }}>
                                <div>
                                    <label className="form-label">{t('pixelVault.eventUrl')}</label>
                                    <input className="form-input font-mono" value={pixelProfileForm.event_url}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, event_url: e.target.value })}
                                        placeholder="https://domain.com/thank-you" />
                                </div>
                                <div>
                                    <label className="form-label">{t('pixelVault.testEventCode')}</label>
                                    <input className="form-input font-mono" value={pixelProfileForm.test_event_code}
                                        onChange={e => setPixelProfileForm({ ...pixelProfileForm, test_event_code: e.target.value })}
                                        placeholder="TEST12345" />
                                </div>
                            </div>
                            <label style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px', color: 'var(--color-text-secondary)', cursor: 'pointer' }}>
                                <input type="checkbox" checked={!!pixelProfileForm.is_active}
                                    onChange={e => setPixelProfileForm({ ...pixelProfileForm, is_active: e.target.checked ? 1 : 0 })} />
                                {t('pixelVault.active')}
                            </label>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px', flexWrap: 'wrap' }}>
                                <button className="btn btn-secondary btn-sm" onClick={() => handlePixelProfileTest()}
                                    disabled={pixelProfileTesting === 'form' || !['facebook', 'tiktok'].includes(pixelProfileForm.traffic_source)
                                        || !pixelProfileForm.pixel_id || (!pixelProfileForm.token && pixelProfileEditing === 'new')}>
                                    <Zap size={14} /> {pixelProfileTesting === 'form' ? t('pixelVault.sending') : t('pixelVault.sendTestEvent')}
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button className="btn btn-secondary btn-sm" onClick={() => { setPixelProfileEditing(null); setPixelProfileMessage(null); }}>
                                        <X size={14} /> {t('common.cancel')}
                                    </button>
                                    <button className="btn btn-primary btn-sm" onClick={handlePixelProfileSave}
                                        disabled={!pixelProfileForm.name.trim() || !pixelProfileForm.pixel_id.trim()
                                            || (pixelProfileEditing === 'new' && !pixelProfileForm.token.trim())}>
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div style={{ border: '1px solid var(--color-border)', borderRadius: '14px', padding: '12px', marginBottom: '18px', background: 'var(--color-bg-soft)' }}>
                        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', marginBottom: pixelProfileNiche || query ? '10px' : 0 }}>
                            <button
                                className={`btn btn-sm ${pixelProfileSource === 'all' ? 'btn-primary' : 'btn-secondary'}`}
                                style={{ fontSize: '11px' }}
                                onClick={() => setPixelProfileSource('all')}
                            >
                                {t('pixelVault.allSources')} ({pixelProfiles.length})
                            </button>
                            {pixelSourceOptions.filter(source => sourceCounts[source.value]).map(source => (
                                <button
                                    key={source.value}
                                    className={`btn btn-sm ${pixelProfileSource === source.value ? 'btn-primary' : 'btn-secondary'}`}
                                    style={{ fontSize: '11px' }}
                                    onClick={() => setPixelProfileSource(pixelProfileSource === source.value ? 'all' : source.value)}
                                >
                                    {source.icon} {source.label} ({sourceCounts[source.value]})
                                </button>
                            ))}
                        </div>
                        <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                            <select className="form-select" style={{ maxWidth: '210px' }} value={pixelProfileNiche} onChange={e => setPixelProfileNiche(e.target.value)}>
                                <option value="">{t('pixelVault.allNiches')}</option>
                                {niches.map(niche => <option key={niche} value={niche}>{niche}</option>)}
                            </select>
                            <input className="form-input" style={{ maxWidth: '260px' }} value={pixelProfileSearch}
                                onChange={e => setPixelProfileSearch(e.target.value)} placeholder={t('pixelVault.search')} />
                            <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                {niches.slice(0, 5).map(niche => (
                                    <button key={niche} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}
                                        onClick={() => setPixelProfileNiche(pixelProfileNiche === niche ? '' : niche)}>
                                        <Tag size={11} /> {niche}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                )}

                {pixelProfilesLoading ? (
                    <div className="flex justify-center py-10"><div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }} /></div>
                ) : (
                    <div className="space-y-5">
                        {Object.entries(grouped).map(([niche, profiles]) => (
                            <section key={niche}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '7px', fontWeight: 600, fontSize: '13px', color: 'var(--color-text-secondary)', marginBottom: '8px' }}>
                                    <Tag size={14} /> {niche} <span style={{ color: 'var(--color-text-muted)', fontWeight: 400 }}>({profiles.length})</span>
                                </div>
                                <div className="space-y-2">
                                    {profiles.map(profile => {
                                        const source = pixelSourceOptions.find(item => item.value === profile.traffic_source) || { label: profile.traffic_source, icon: '📊' };
                                        return (
                                            <div key={profile.id} style={{
                                                border: '1px solid var(--color-border)', borderRadius: '14px', padding: '15px 16px',
                                                background: profile.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                                opacity: profile.is_active ? 1 : 0.68
                                            }}>
                                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px', flexWrap: 'wrap' }}>
                                                    <div style={{ minWidth: 0 }}>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                            <span style={{ width: 8, height: 8, borderRadius: 999, background: profile.is_active ? '#22c55e' : '#9ca3af' }} />
                                                            <strong style={{ fontSize: '14px', color: 'var(--color-text-primary)' }}>{profile.name}</strong>
                                                            <span style={{ fontSize: '11px', color: 'var(--color-text-muted)' }}>{source.icon} {source.label}</span>
                                                        </div>
                                                        <div style={{ marginTop: '6px', fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                            ID: <span style={{ fontFamily: 'monospace' }}>{profile.pixel_id}</span>
                                                            {' · '}{t('pixelVault.events')}: {profile.events || '—'}
                                                            {' · '}{t('pixelVault.token')}: <span style={{ fontFamily: 'monospace' }}>{profile.token_masked || '—'}</span>
                                                        </div>
                                                        {profile.event_url && <div style={{ marginTop: '3px', fontSize: '11px', color: 'var(--color-text-muted)', wordBreak: 'break-all' }}>{profile.event_url}</div>}
                                                    </div>
                                                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                                        {['facebook', 'tiktok'].includes(profile.traffic_source) && (
                                                            <button className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} onClick={() => handlePixelProfileTest(profile)} disabled={pixelProfileTesting === profile.id}>
                                                                <Zap size={12} /> {pixelProfileTesting === profile.id ? t('pixelVault.sending') : t('pixelVault.sendTestEvent')}
                                                            </button>
                                                        )}
                                                        <button className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} onClick={() => { setPixelProfileForm(pixelProfileToForm(profile)); setPixelProfileEditing(profile.id); setPixelProfileMessage(null); }}>
                                                            <Edit2 size={12} /> {t('common.edit')}
                                                        </button>
                                                        <button className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} onClick={() => handlePixelProfileDuplicate(profile)}>
                                                            <Copy size={12} /> {t('pixelVault.duplicate')}
                                                        </button>
                                                        <button className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }} onClick={() => handlePixelProfileDelete(profile)} title={t('common.delete')}>
                                                            <Trash2 size={12} />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        ))}
                        {visible.length === 0 && !pixelProfileEditing && (
                            <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '34px 0' }}>{t('pixelVault.noProfiles')}</p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // ==================== Facebook Conversions ====================

    const handleCapiSave = async () => {
        if (!capiForm.campaign_id || !capiForm.pixel_id) return;
        try {
            const payload = {
                ...capiForm,
                type: 'facebook',
                mapping_json: JSON.stringify(capiForm.mapping || {})
            };
            if (capiEditing && capiEditing !== 'new') payload.id = capiEditing;
            const res = await axios.post(`${API_URL}?action=save_campaign_pixel`, payload);
            if (res.data.status === 'success') {
                setCapiMessage({ type: 'success', text: t('fbConv.saved') });
                setCapiEditing(null);
                setCapiTest(null);
                fetchCapiPixels();
            } else {
                setCapiMessage({ type: 'error', text: res.data.message });
            }
        } catch (err) { setCapiMessage({ type: 'error', text: err.message }); }
    };

    const capiRowToForm = (px) => {
        let mapping = {};
        if (px.mapping_json) { try { mapping = JSON.parse(px.mapping_json) || {}; } catch { mapping = {}; } }
        return {
            campaign_id: px.campaign_id || '',
            pixel_profile_id: px.pixel_profile_id || '',
            pixel_id: px.pixel_id || '',
            token: '',
            events: px.events || 'PageView,Lead',
            mapping,
            test_event_code: px.test_event_code || '',
            proxy_url: px.proxy_url || '',
            is_active: px.is_active ? 1 : 0,
            has_saved_token: !!px.has_token
        };
    };

    const handleCapiToggle = async (px) => {
        await axios.post(`${API_URL}?action=save_campaign_pixel`, {
            ...capiRowToForm(px),
            id: px.id,
            type: 'facebook',
            mapping_json: px.mapping_json || '{}',
            is_active: px.is_active ? 0 : 1
        });
        fetchCapiPixels();
    };

    const handleCapiDelete = async (px) => {
        if (!confirm(t('fbConv.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=delete_campaign_pixel`, { id: px.id });
        setCapiMessage({ type: 'success', text: t('fbConv.deleted') });
        fetchCapiPixels();
    };

    const handleCapiTest = async () => {
        setCapiTesting(true);
        setCapiTest(null);
        try {
            const res = await axios.post(`${API_URL}?action=facebook_capi_test`, {
                id: capiEditing && capiEditing !== 'new' ? capiEditing : undefined,
                campaign_id: capiForm.campaign_id,
                pixel_profile_id: capiForm.pixel_profile_id || undefined,
                pixel_id: capiForm.pixel_id,
                token: capiForm.token,
                test_event_code: capiForm.test_event_code,
                proxy_url: capiForm.proxy_url,
                event_name: 'Lead'
            });
            setCapiTest({
                ok: res.data.status === 'success',
                message: res.data.message,
                usedRealClick: res.data.data?.used_real_click
            });
        } catch (err) { setCapiTest({ ok: false, message: err.message }); }
        finally { setCapiTesting(false); }
    };

    const renderFacebookConversionsPanel = () => {
        const visible = capiPixels.filter(p => !capiSearch ||
            (p.campaign_name || '').toLowerCase().includes(capiSearch.toLowerCase()) ||
            (p.pixel_id || '').includes(capiSearch));
        const editingPixel = capiPixels.find(pixel => String(pixel.id) === String(capiEditing));
        const savedTokenApplied = !!capiForm.pixel_profile_id && !!capiForm.has_saved_token;
        const hasCapiToken = !!capiForm.token || savedTokenApplied ||
            (capiEditing !== 'new' && !!editingPixel?.has_token);

        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                {capiMessage && (
                    <div style={{
                        padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                        background: capiMessage.type === 'success' ? '#dcfce7' : '#fee2e2',
                        color: capiMessage.type === 'success' ? '#166534' : '#991b1b',
                        border: `1px solid ${capiMessage.type === 'success' ? '#86efac' : '#fca5a5'}`
                    }}>{capiMessage.text}</div>
                )}

                {capiEditing ? (
                    <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '760px' }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                            {capiEditing === 'new' ? t('fbConv.addAccount') : t('fbConv.editAccount')}
                        </h4>
                        <div className="space-y-3">
                            <div>
                                <label className="form-label">{t('fbConv.campaign')} *</label>
                                <select className="form-select" value={capiForm.campaign_id}
                                    onChange={e => setCapiForm({ ...capiForm, campaign_id: e.target.value })}>
                                    <option value="">—</option>
                                    {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <PixelPicker
                                    label={`${t('fbConv.pixelId')} *`}
                                    value={capiForm.pixel_id}
                                    profileId={capiForm.pixel_profile_id}
                                    trafficSource="facebook"
                                    resolveServerSide
                                    onPick={(patch) => setCapiForm(prev => ({ ...prev, ...patch }))}
                                    onManage={() => setActiveTab('pixel_vault')}
                                />
                            </div>
                            <div>
                                <label className="form-label">{t('fbConv.token')}{savedTokenApplied ? ' (✓)' : ''}</label>
                                {capiForm.pixel_profile_id ? (
                                    <input type="password" className="form-input font-mono" readOnly
                                        value={savedTokenApplied ? '••••••••••••••••' : ''}
                                        placeholder="EAAxxxx..." />
                                ) : (
                                    <input type="password" className="form-input font-mono" value={capiForm.token}
                                        placeholder="EAAxxxx..."
                                        onChange={e => setCapiForm({ ...capiForm, token: e.target.value })} />
                                )}
                                <p style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                    {capiForm.pixel_profile_id ? t('fbConv.tokenAutoFilledHint') : t('fbConv.tokenHint')}
                                </p>
                            </div>

                            <div>
                                <label className="form-label">{t('fbConv.mapping')}</label>
                                <div style={{ display: 'grid', gap: '6px' }}>
                                    {Object.keys(capiMeta.default_mapping || {}).map(status => (
                                        <div key={status} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', alignItems: 'center' }}>
                                            <span style={{ fontSize: '12px', color: 'var(--color-text-secondary)' }}>{t('conversions.' + status, status)}</span>
                                            <select className="form-select"
                                                value={capiForm.mapping?.[status] ?? capiMeta.default_mapping[status] ?? ''}
                                                onChange={e => setCapiForm({ ...capiForm, mapping: { ...capiForm.mapping, [status]: e.target.value } })}>
                                                <option value="">{t('fbConv.doNotSend')}</option>
                                                {(capiMeta.available_events || []).map(ev => <option key={ev} value={ev}>{ev}</option>)}
                                            </select>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
                                <div>
                                    <label className="form-label">{t('fbConv.testEventCode')}</label>
                                    <input type="text" className="form-input font-mono" value={capiForm.test_event_code}
                                        placeholder="TEST12345"
                                        onChange={e => setCapiForm({ ...capiForm, test_event_code: e.target.value })} />
                                </div>
                                <div>
                                    <ProxyInput
                                        label={t('fbConv.proxy')}
                                        value={capiForm.proxy_url}
                                        onChange={(val) => setCapiForm({ ...capiForm, proxy_url: val })}
                                    />
                                </div>
                            </div>

                            {capiTest && (
                                <div style={{
                                    padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                                    background: capiTest.ok ? '#dcfce7' : '#fee2e2',
                                    color: capiTest.ok ? '#166534' : '#991b1b',
                                    border: `1px solid ${capiTest.ok ? '#86efac' : '#fca5a5'}`
                                }}>
                                    {capiTest.message}
                                    {capiTest.ok && !capiTest.usedRealClick ? ' ' + t('fbConv.syntheticClick') : ''}
                                </div>
                            )}

                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'space-between' }}>
                                <button onClick={handleCapiTest} className="btn btn-secondary btn-sm"
                                    disabled={capiTesting || !capiForm.pixel_id || !hasCapiToken}>
                                    <Zap size={14} /> {capiTesting ? t('fbConv.sending') : t('fbConv.sendTestEvent')}
                                </button>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button onClick={() => { setCapiEditing(null); setCapiTest(null); }} className="btn btn-secondary btn-sm">
                                        <X size={14} /> {t('common.cancel')}
                                    </button>
                                    <button onClick={handleCapiSave} className="btn btn-primary btn-sm"
                                        disabled={!capiForm.campaign_id || !capiForm.pixel_id}>
                                        {t('common.save')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                        <button onClick={() => { setCapiForm(emptyCapiForm); setCapiTest(null); setCapiEditing('new'); }} className="btn btn-primary">
                            <Plus size={16} /> {t('fbConv.addAccount')}
                        </button>
                        <input type="text" className="form-input" style={{ maxWidth: '240px' }}
                            placeholder={t('fbConv.findAccount')} value={capiSearch}
                            onChange={e => setCapiSearch(e.target.value)} />
                    </div>
                )}

                {capiLoading ? (
                    <div className="flex justify-center py-10">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                    </div>
                ) : (
                    <div className="space-y-3" style={{ marginTop: capiEditing ? '20px' : 0 }}>
                        {visible.map(px => {
                            // No token means the row is a browser pixel only — the panel says so
                            // rather than showing it as a working server-side integration.
                            const serverSide = !!px.has_token;
                            return (
                                <div key={px.id} style={{
                                    border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                                    background: px.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                    opacity: px.is_active ? 1 : 0.7
                                }}>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px', gap: '12px', flexWrap: 'wrap' }}>
                                        <div>
                                            <div style={{ fontWeight: 600, fontSize: '14px' }}>{px.campaign_name || `#${px.campaign_id}`}</div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', fontFamily: 'monospace' }}>
                                                Pixel {px.pixel_id}
                                            </div>
                                        </div>
                                        <span style={{
                                            fontSize: '11px', padding: '2px 8px', borderRadius: '6px', whiteSpace: 'nowrap',
                                            background: !px.is_active ? '#e5e7eb' : serverSide ? '#dcfce7' : '#fef3c7',
                                            color: !px.is_active ? '#374151' : serverSide ? '#166534' : '#92400e'
                                        }}>
                                            {!px.is_active ? t('fbConv.paused') : serverSide ? t('fbConv.serverSideOn') : t('fbConv.browserOnly')}
                                        </span>
                                    </div>

                                    <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '10px' }}>
                                        {t('fbConv.mapping')}: {px.mapping_summary || t('fbConv.defaultMapping')}
                                    </div>

                                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                        <button onClick={() => { setCapiForm(capiRowToForm(px)); setCapiTest(null); setCapiEditing(px.id); }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Edit2 size={12} /> {t('fbConv.editMapping')}
                                        </button>
                                        <button onClick={() => handleCapiToggle(px)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Power size={12} /> {px.is_active ? t('fbConv.pause') : t('fbConv.resume')}
                                        </button>
                                        <button onClick={() => { setCapiForm({ ...capiRowToForm(px), campaign_id: '' }); setCapiTest(null); setCapiEditing('new'); }} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                            <Copy size={12} /> {t('fbConv.clone')}
                                        </button>
                                        <button onClick={() => handleCapiDelete(px)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }}>
                                            <Trash2 size={12} />
                                        </button>
                                    </div>
                                </div>
                            );
                        })}

                        {visible.length === 0 && !capiEditing && (
                            <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px', padding: '30px 0' }}>
                                {t('fbConv.noAccounts')}
                            </p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    const scripts = {
        kclient_php: {
            title: 'KClient PHP',
            icon: <Terminal className="w-5 h-5" />,
            description: t('integrations.kclientPhpDesc'),
            code: `<?php\n// ${t('integrations.codeToInsert')}\n// kclient.php: ${trackerUrl}/kclient.php?download=1\nrequire_once dirname(__FILE__) . '/kclient.php';\n$client = new KClickClient('${trackerUrl}', 'CAMPAIGN_TOKEN');\n$client->sendAllParams();\n$client->execute(); \n?>`
        },
        kclient_js: {
            title: 'KClient JS',
            icon: <Globe className="w-5 h-5" />,
            description: t('integrations.kclientJsDesc'),
            code: `<script type="application/javascript">\n  var orbitra_db_url = '${trackerUrl}';\n  var orbitra_campaign_token = 'CAMPAIGN_TOKEN';\n</script>\n<script src="${trackerUrl}/kclient.js"></script>\n<noscript><img src="${trackerUrl}/pixel.gif?token=CAMPAIGN_TOKEN" alt="" /></noscript>`
        },
        js_banner: {
            title: t('integrations.jsBannerTitle'),
            icon: <Code className="w-5 h-5" />,
            description: t('integrations.jsBannerDesc'),
            code: `<div id="orbitra-banner" style="width:300px;height:250px;overflow:hidden"></div>\n<script>\n  var orbitra_db_url = '${trackerUrl}';\n  var orbitra_campaign_token = 'CAMPAIGN_TOKEN';\n</script>\n<script src="${trackerUrl}/banner.js" async></script>`
        },
        tracking_pixel: {
            title: 'Tracking Pixel',
            icon: <ImageIcon className="w-5 h-5" />,
            description: t('integrations.pixelDesc'),
            code: `<!-- Tracking impressions -->\n<img src="${trackerUrl}/pixel.gif?campaign_id=YOUR_ID" width="1" height="1" border="0" alt="" />\n\n<!-- Tracking conversions (place on Thank You page) -->\n<img src="${trackerUrl}/pixel.gif?action=conversion&subid={subid}&status=lead" width="1" height="1" border="0" alt="" />`
        },
        tiktok_pixel: {
            title: t('integrations.tiktokPixelTitle', 'TikTok Pixel'),
            icon: <Music2 className="w-5 h-5" />,
            description: t('integrations.tiktokPixelDesc', 'Browser TikTok Pixel on local landings. The pixel id arrives in the {pixel} campaign parameter from the TikTok source template and is stored in a cookie for the thank-you page.'),
            code: `<!-- 1. Landing <head>: store the pixel id from the campaign parameter.\n        The TikTok source template fills {pixel} = __PIXEL__; replace "pixel"\n        if your campaign passes it under another name. -->\n<script>\nvar date = new Date();\ndate.setTime(date.getTime() + (5 * 24 * 60 * 60 * 1000));\nif (!'{pixel}'.match('{')) {\n  document.cookie = "pixel={pixel}; " + "expires=" + date.toUTCString() + "";\n}\n</script>\n\n<!-- 2. Where events fire (landing or thank-you page): read the cookie\n        and boot the pixel with it. -->\n<script>\nvar matches = document.cookie.match(new RegExp("(?:^|; )" + 'pixel' + "=([^;]*)"));\nvar pixel = matches ? decodeURIComponent(matches[1]) : undefined;\n</script>\n\n<!-- TikTok Pixel Code Start -->\n<script>\n    !function (w, d, t) {\n      w.TiktokAnalyticsObject = t;\n      var ttq = w[t] = w[t] = [];\n      ttq.methods = ["page", "track", "identify", "instances", "debug", "on", "off", "once", "ready", "alias", "group", "enableCookie", "disableCookie"],\n        ttq.setAndDefer = function (t, e) {\n          t[e] = function () {\n            t.push([e].concat(Array.prototype.slice.call(arguments, 0)))\n          }\n        };\n      for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);\n      ttq.instance = function (t) {\n        for (var e = ttq._i[t] || [], n = 0; n < ttq.methods.length; n++) ttq.setAndDefer(e, ttq.methods[n]);\n        return e\n      }, ttq.load = function (e, n) {\n        var i = "https://analytics.tiktok.com/i18n/pixel/events.js";\n        ttq._i = ttq._i || {}, ttq._i[e] = [], ttq._i[e]._u = i, ttq._t = ttq._t || {}, ttq._t[e] = +new Date, ttq._o = ttq._o || {}, ttq._o[e] = n || {};\n        var o = document.createElement("script");\n        o.type = "text/javascript", o.async = !0, o.src = i + "?sdkid=" + e + "&lib=" + t;\n        var a = document.getElementsByTagName("script")[0];\n        a.parentNode.insertBefore(o, a)\n      };\n      ttq.load(pixel);\n      ttq.page();\n    }(window, document, 'ttq');\n</script>\n<!-- TikTok Pixel Code End -->\n\n<!-- No access to the landing code (offer redirect)? Use the server-side\n        TikTok Conversions API instead: Pixel Vault -> Connect TikTok. -->`
        },
        facebook_costs: {
            title: t('fbCosts.title'),
            icon: <DollarSign className="w-5 h-5" />,
            description: t('fbCosts.description'),
            isFacebookCosts: true
        },
        tiktok_costs: {
            title: t('tiktokCosts.title', 'TikTok Costs'),
            icon: <Music2 className="w-5 h-5" />,
            description: t('tiktokCosts.description'),
            isTikTokCosts: true
        },
        google_ads_costs: {
            title: t('googleAdsCosts.title', 'Google Ads Costs'),
            icon: <Search className="w-5 h-5" />,
            description: t('googleAdsCosts.description'),
            isGoogleAdsCosts: true
        },
        facebook_conversions: {
            title: t('fbConv.title'),
            icon: <ArrowRight className="w-5 h-5" />,
            description: t('fbConv.description'),
            isFacebookConversions: true
        },
        pixel_vault: {
            title: t('pixelVault.pixelVaultTitle'),
            icon: <Database className="w-5 h-5" />,
            description: t('pixelVault.description'),
            isPixelVault: true
        },
        chrome_extension: {
            title: t('integrations.chromeExtension', 'Chrome & Antidetect Extension'),
            icon: <Monitor className="w-5 h-5" />,
            description: t('integrations.chromeExtDesc', 'Overlay real-time tracker analytics directly inside Facebook Ads Manager.'),
            isChromeExtension: true
        },
        dolphin_fbtool: {
            title: t('extCosts.title', 'Dolphin / Fbtool — Keitaro API'),
            icon: <DollarSign className="w-5 h-5" />,
            description: t('extCosts.description', 'Приём расходов из Dolphin и Fbtool.pro через Keitaro-совместимый Admin API endpoint'),
            code: `# ${t('extCosts.step1', '1. Создайте API-ключ с правами write: Пользователи → ваш профиль → сгенерировать ключ (permissions: write)')}\n\n# ${t('extCosts.step2', '2. Скопируйте в Dolphin (Настройки → Экспорт расходов → Keitaro) или Fbtool (Расходы → Keitaro):')}\n#    Tracker URL:  ${trackerUrl}\n#    Admin API key: <ваш ключ>\n\n# ${t('extCosts.step3', '3. Endpoint, который вызывают сервисы (совместим с Keitaro Admin API v1):')}\n# POST ${trackerUrl}/admin_api/v1/campaigns/CAMPAIGN_ID/update_costs\n# Authorization: Bearer <API_KEY>\n{\n  "start_date": "2026-08-15",\n  "end_date": "2026-08-15",\n  "cost": 12.34,\n  "currency": "USD",\n  "timezone": "Europe/Berlin",\n  "filters": { "sub_id_4": "120212558973560058" }\n}\n\n# ${t('extCosts.hint', 'Фильтры матчатся по параметрам клика: sub_id_4 = ad_id, sub_id_3 = adset_id (дефолты шаблона Facebook), либо любое имя параметра напрямую (ad_id, adset_id, campaign_id...). Расход делится поровну между совпавшими кликами за период; повторная отправка перезаписывает, а не суммирует.')}`
        },
        cloudflare: {
            title: t('cloudflare.title', 'Cloudflare'),
            icon: <Cloud className="w-5 h-5" />,
            description: t('cloudflare.description', 'Управление DNS доменов трекера через Cloudflare API: A-записи прописываются сами, SSL — краем CF'),
            isCloudflare: true
        },
        namecheap: {
            title: t('namecheap.title', 'Namecheap'),
            icon: <Globe className="w-5 h-5" />,
            description: t('namecheap.description', 'Автопарковка DNS доменов из аккаунта Namecheap, покупка новых доменов и выпуск SSL — прямо из трекера'),
            isNamecheap: true
        },
        recaptcha: {
            title: t('recaptcha.tabTitle'),
            icon: <Shield className="w-5 h-5" />,
            description: t('recaptcha.tabDesc'),
            isRecaptcha: true
        },
        telegram: {
            title: 'Telegram Bot',
            icon: <Send className="w-5 h-5" />,
            description: t('telegram.description'),
            isTelegram: true
        },
        app_config: {
            title: t('appConfig.title'),
            icon: <Settings className="w-5 h-5" />,
            description: t('appConfig.subtitle'),
            isAppConfig: true
        },
        wordpress: {
            title: t('wordpress.title'),
            icon: <Globe className="w-5 h-5" />,
            description: t('wordpress.description'),
            code: `<?php\n// ${t('wordpress.instruction')}\nfunction ltt_check_remote_config() {\n    $config_url = '${trackerUrl}/api.php?action=app_config&key=CONFIG_KEY';\n    $response = wp_remote_get($config_url, ['timeout' => 5]);\n    \n    if (is_wp_error($response)) return null;\n    \n    $body = wp_remote_retrieve_body($response);\n    return json_decode($body, true);\n}\n\n// Example: Conditionally show content based on remote config\nfunction ltt_conditional_content($atts, $content = null) {\n    $config = ltt_check_remote_config();\n    if (!$config || !isset($config['stub'])) return $content;\n    \n    if ($config['stub']['enabled']) {\n        return '<div class="ltt-stub">' . esc_html($config['stub']['message']) . '</div>';\n    }\n    return $content;\n}\nadd_shortcode('ltt_content', 'ltt_conditional_content');\n\n// Example: WebView redirect\nfunction ltt_webview_redirect() {\n    $config = ltt_check_remote_config();\n    if ($config && isset($config['webview']) && $config['webview']['enabled']) {\n        $url = esc_url($config['webview']['url']);\n        echo '<script>if(/Android|iPhone/i.test(navigator.userAgent)){window.location="' . $url . '"}</script>';\n    }\n}\nadd_action('wp_head', 'ltt_webview_redirect');\n?>`
        },
        static_site: {
            title: t('staticSite.title'),
            icon: <Code className="w-5 h-5" />,
            description: t('staticSite.description'),
            code: `<script>\n// ${t('staticSite.instruction')}\n(async function() {\n    const CONFIG_URL = '${trackerUrl}/api.php?action=app_config&key=CONFIG_KEY';\n    try {\n        const res = await fetch(CONFIG_URL);\n        const config = await res.json();\n        \n        // WebView: redirect mobile users\n        if (config.webview?.enabled && /Android|iPhone/i.test(navigator.userAgent)) {\n            window.location.href = config.webview.url;\n            return;\n        }\n        \n        // Banner: show/hide banner element\n        if (config.banner?.enabled) {\n            const banner = document.getElementById('ltt-banner');\n            if (banner) {\n                banner.style.display = 'block';\n                banner.innerHTML = '<a href="' + config.banner.click_url + '">' +\n                    '<img src="' + config.banner.image_url + '" alt="banner" />' +\n                '</a>';\n            }\n        }\n        \n        // Stub: show maintenance page\n        if (config.stub?.enabled) {\n            document.body.innerHTML = '<div style="display:flex;align-items:center;' +\n                'justify-content:center;min-height:100vh;font-family:sans-serif;' +\n                'background:#f5f5f5"><h1>' + config.stub.message + '</h1></div>';\n        }\n    } catch (e) { console.error('Config error:', e); }\n})();\n</script>`
        },
        geo_redirect: {
            title: t('integrations.geoRedirectTitle'),
            icon: <Globe className="w-5 h-5" />,
            description: t('integrations.geoRedirectDesc'),
            code: `<script>\n// Geo Redirect Script - redirect users based on country\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = 'YOUR_CAMPAIGN_ID';\n    \n    // Mapping: country code -> offer URL\n    var geoOffers = {\n        'RU': 'https://offer1.com',\n        'DE': 'https://offer2.com',\n        'ES': 'https://offer3.com',\n        'US': 'https://offer4.com',\n        'DEFAULT': 'https://default-offer.com'\n    };\n    \n    fetch(trackerUrl + '/api.php?action=detect_geo')\n        .then(r => r.json())\n        .then(data => {\n            var country = data.country || 'DEFAULT';\n            var url = geoOffers[country] || geoOffers['DEFAULT'];\n            \n            // Track click and redirect\n            var clickUrl = trackerUrl + '/click.php?campaign_id=' + campaignId + \n                '&sub1=' + country + '&redirect=0';\n            \n            fetch(clickUrl).finally(() => {\n                window.location.href = url;\n            });\n        })\n        .catch(() => {\n            window.location.href = geoOffers['DEFAULT'];\n        });\n})();\n</script>`
        },
        device_redirect: {
            title: t('integrations.deviceRedirectTitle'),
            icon: <Smartphone className="w-5 h-5" />,
            description: t('integrations.deviceRedirectDesc'),
            code: `<script>\n// Device Redirect Script - redirect based on device type\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = 'YOUR_CAMPAIGN_ID';\n    \n    var mobileUrl = 'https://mobile-offer.com';\n    var desktopUrl = 'https://desktop-offer.com';\n    var tabletUrl = 'https://tablet-offer.com';\n    \n    function getDeviceType() {\n        var ua = navigator.userAgent;\n        if (/tablet|ipad/i.test(ua)) return 'tablet';\n        if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';\n        return 'desktop';\n    }\n    \n    var device = getDeviceType();\n    var targetUrl = device === 'mobile' ? mobileUrl : \n                    device === 'tablet' ? tabletUrl : desktopUrl;\n    \n    // Track click and redirect\n    var clickUrl = trackerUrl + '/click.php?campaign_id=' + campaignId + \n        '&sub1=' + device + '&redirect=0';\n    \n    fetch(clickUrl).finally(() => {\n        window.location.href = targetUrl;\n    });\n})();\n</script>`
        },
        countdown_timer: {
            title: t('integrations.countdownTitle'),
            icon: <Timer className="w-5 h-5" />,
            description: t('integrations.countdownDesc'),
            code: `<div id="ltt-countdown" style="font-family:sans-serif;text-align:center;padding:20px;\n    background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border-radius:12px;max-width:400px;\n    margin:0 auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">\n    <div style="font-size:14px;text-transform:uppercase;letter-spacing:2px;margin-bottom:10px;">\n        OFFER EXPIRES IN\n    </div>\n    <div id="ltt-timer" style="font-size:48px;font-weight:bold;">\n        <span id="ltt-hours">00</span>:<span id="ltt-minutes">00</span>:<span id="ltt-seconds">00</span>\n    </div>\n    <a id="ltt-cta" href="#" style="display:inline-block;margin-top:20px;padding:14px 40px;\n        background:#22c55e;color:white;text-decoration:none;border-radius:8px;font-weight:600;\n        font-size:16px;transition:transform 0.2s;">\n        GET OFFER NOW\n    </a>\n</div>\n\n<script>\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = 'YOUR_CAMPAIGN_ID';\n    var redirectUrl = 'https://your-offer.com';\n    var hoursFromNow = 2; // Countdown duration\n    \n    var endTime = new Date().getTime() + (hoursFromNow * 60 * 60 * 1000);\n    \n    document.getElementById('ltt-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(redirectUrl);\n    \n    function updateTimer() {\n        var now = new Date().getTime();\n        var distance = endTime - now;\n        \n        if (distance < 0) {\n            document.getElementById('ltt-countdown').innerHTML = '<h2>OFFER EXPIRED</h2>';\n            return;\n        }\n        \n        var hours = Math.floor(distance / (1000 * 60 * 60));\n        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));\n        var seconds = Math.floor((distance % (1000 * 60)) / 1000);\n        \n        document.getElementById('ltt-hours').textContent = String(hours).padStart(2, '0');\n        document.getElementById('ltt-minutes').textContent = String(minutes).padStart(2, '0');\n        document.getElementById('ltt-seconds').textContent = String(seconds).padStart(2, '0');\n    }\n    \n    updateTimer();\n    setInterval(updateTimer, 1000);\n})();\n</script>`
        },
        back_button_trap: {
            title: t('integrations.backButtonTitle'),
            icon: <ArrowLeft className="w-5 h-5" />,
            description: t('integrations.backButtonDesc'),
            code: `<script>\n// Back Button Trap - intercept browser back button\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = 'YOUR_CAMPAIGN_ID';\n    var trapUrl = 'https://your-special-offer.com';\n    \n    // Push a state to intercept back button\n    history.pushState({ trap: true }, '', location.href);\n    \n    window.addEventListener('popstate', function(e) {\n        if (e.state && e.state.trap) {\n            // Track the back button click\n            var clickUrl = trackerUrl + '/click.php?campaign_id=' + campaignId + \n                '&sub1=back_button&redirect=0';\n            \n            fetch(clickUrl).finally(() => {\n                // Redirect to special offer\n                window.location.href = trapUrl;\n            });\n        }\n    });\n})();\n</script>`
        },
        exit_popup: {
            title: t('integrations.exitPopupTitle'),
            icon: <ExternalLink className="w-5 h-5" />,
            description: t('integrations.exitPopupDesc'),
            code: `<style>\n.ltt-exit-popup { display:none; position:fixed; top:0; left:0; width:100%; height:100%;\n    background:rgba(0,0,0,0.7); z-index:99999; justify-content:center; align-items:center; }\n.ltt-exit-popup.show { display:flex; }\n.ltt-exit-content { background:white; padding:40px; border-radius:16px; max-width:500px;\n    text-align:center; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.3); }\n.ltt-exit-close { position:absolute; top:15px; right:20px; font-size:24px; cursor:pointer;\n    color:#999; border:none; background:none; }\n.ltt-exit-close:hover { color:#333; }\n.ltt-exit-btn { display:inline-block; margin-top:20px; padding:16px 40px; background:#22c55e;\n    color:white; text-decoration:none; border-radius:8px; font-weight:600; font-size:18px; }\n</style>\n\n<div id="ltt-exit-popup" class="ltt-exit-popup">\n    <div class="ltt-exit-content">\n        <button class="ltt-exit-close" onclick="document.getElementById('ltt-exit-popup').classList.remove('show')">&times;</button>\n        <h2 style="margin:0 0 15px;font-size:28px;">Wait! Special Offer!</h2>\n        <p style="font-size:16px;color:#666;margin-bottom:10px;">Don't miss this exclusive deal just for you!</p>\n        <a id="ltt-exit-cta" href="#" class="ltt-exit-btn">CLAIM OFFER</a>\n    </div>\n</div>\n\n<script>\n(function() {\n    var trackerUrl = '${trackerUrl}';\n    var campaignId = 'YOUR_CAMPAIGN_ID';\n    var offerUrl = 'https://your-offer.com';\n    var shown = false;\n    \n    document.getElementById('ltt-exit-cta').href = trackerUrl + '/click.php?campaign_id=' + campaignId + '&url=' + encodeURIComponent(offerUrl);\n    \n    document.addEventListener('mouseout', function(e) {\n        if (shown) return;\n        if (e.clientY < 10 && e.relatedTarget === null) {\n            shown = true;\n            document.getElementById('ltt-exit-popup').classList.add('show');\n            \n            // Track exit intent\n            fetch(trackerUrl + '/click.php?campaign_id=' + campaignId + '&sub1=exit_popup&redirect=0');\n        }\n    });\n})();\n</script>`
        },
        wordpress_plugin: {
            title: t('integrations.wpPluginTitle'),
            icon: <Download className="w-5 h-5" />,
            description: t('integrations.wpPluginDesc'),
            isWpPlugin: true
        }
    };

    const activeObj = scripts[activeTab];

    const renderChromeExtensionPanel = () => {
        const guideTabs = [
            { id: 'adspower', label: 'AdsPower', text: t('integrations.adspowerGuide') },
            { id: 'dolphin', label: 'Dolphin{anty}', text: t('integrations.dolphinGuide') },
            { id: 'chrome', label: 'Google Chrome', text: t('integrations.chromeGuide') },
        ];
        const preview = extTemplate === 'cod'
            ? [
                ['CPL', '$8.33'], ['CPS', '$15.00'], ['SPENT', '$150.00'],
                ['REV (CONF)', '$280.00'], ['ROI (CONF)', '+86.7%'], ['PROFIT', '+$130.00'],
            ]
            : [
                ['CPA', '$12.50'], ['SPENT', '$150.00'], ['REV', '$320.00'],
                ['ROI', '+113.3%'], ['PROFIT', '+$170.00'],
            ];

        return (
            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                <div style={{ maxWidth: '920px' }} className="space-y-6">
                    <div style={{
                        padding: '26px',
                        borderRadius: '18px',
                        background: 'linear-gradient(135deg, #21143d 0%, #4c1d95 52%, #1d4ed8 100%)',
                        color: '#fff',
                        boxShadow: '0 18px 45px rgba(76, 29, 149, .22)'
                    }}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '20px', flexWrap: 'wrap' }}>
                            <div style={{ maxWidth: '650px' }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '10px' }}>
                                    <div style={{ width: '42px', height: '42px', borderRadius: '12px', background: 'rgba(255,255,255,.15)', display: 'grid', placeItems: 'center' }}>
                                        <Monitor size={23} />
                                    </div>
                                    <h3 style={{ fontSize: '21px', fontWeight: 700, margin: 0 }}>
                                        Orbitra {t('integrations.chromeExtension')} (Ads Manager Overlay)
                                    </h3>
                                </div>
                                <p style={{ margin: 0, color: '#ede9fe', fontSize: '14px', lineHeight: 1.6 }}>
                                    {t('integrations.chromeExtDesc')}
                                </p>
                            </div>
                            <a
                                href="/data/orbitra-extension.zip"
                                download="orbitra-extension.zip"
                                className="btn"
                                style={{ background: '#fff', color: '#4c1d95', borderColor: '#fff', fontWeight: 700, flexShrink: 0 }}
                            >
                                <Download size={18} />
                                {t('integrations.downloadExtension')}
                            </a>
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', marginTop: '20px' }}>
                            {['AdsPower', 'Dolphin{anty}', 'Octo Browser', 'Google Chrome', 'Multilogin'].map(browser => (
                                <span key={browser} style={{ padding: '5px 10px', borderRadius: '999px', background: 'rgba(255,255,255,.12)', border: '1px solid rgba(255,255,255,.2)', fontSize: '12px', fontWeight: 600 }}>
                                    {browser}
                                </span>
                            ))}
                        </div>
                    </div>

                    <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', gap: '12px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                            <div>
                                <h4 style={{ margin: 0, color: 'var(--color-text-primary)', fontSize: '15px', fontWeight: 650 }}>
                                    {t('integrations.connectionCredentials', 'Connection credentials')}
                                </h4>
                                <p style={{ margin: '4px 0 0', color: 'var(--color-text-muted)', fontSize: '12px' }}>
                                    {t('integrations.readKeyOnly', 'A dedicated read-only key is generated for this extension.')}
                                </p>
                            </div>
                            <span style={{ padding: '4px 9px', borderRadius: '999px', background: 'rgba(34,197,94,.12)', color: '#16a34a', fontSize: '11px', fontWeight: 700 }}>
                                READ ONLY
                            </span>
                        </div>

                        <div style={{ display: 'grid', gap: '12px' }}>
                            {[
                                { label: t('integrations.trackerApiUrl', 'Tracker API URL'), value: `${trackerUrl}/api.php`, copyId: 'extension_url' },
                                { label: t('integrations.apiKey', 'API Key'), value: extKeyLoading ? t('common.loading', 'Loading…') : extApiKey, copyId: 'extension_key', secret: true },
                            ].map(field => (
                                <div key={field.copyId} style={{ padding: '12px 14px', background: 'var(--color-bg-card)', border: '1px solid var(--color-border)', borderRadius: '11px' }}>
                                    <div style={{ fontSize: '11px', color: 'var(--color-text-muted)', marginBottom: '6px', textTransform: 'uppercase', letterSpacing: '.05em' }}>
                                        {field.label}
                                    </div>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                        <code style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: field.secret ? 'var(--color-text-primary)' : 'var(--color-primary)', fontSize: '12px' }}>
                                            {field.value || '—'}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() => field.value && copyToClipboard(field.value, field.copyId)}
                                            disabled={!field.value || extKeyLoading}
                                            className="btn btn-secondary btn-sm"
                                        >
                                            {copied === field.copyId ? <CheckCircle2 size={14} style={{ color: 'var(--color-success, #16a34a)' }} /> : <Copy size={14} />}
                                            <span style={copied === field.copyId ? { color: 'var(--color-success, #16a34a)' } : undefined}>
                                                {copied === field.copyId ? t('integrations.copied') : t('integrations.copyCode', 'Copy')}
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                        {extKeyError && (
                            <div style={{ marginTop: '12px', padding: '10px 12px', borderRadius: '9px', background: 'var(--color-danger-bg, #fee2e2)', color: '#b91c1c', fontSize: '12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px' }}>
                                <span>{extKeyError}</span>
                                <button type="button" className="btn btn-secondary btn-sm" onClick={loadExtensionCredentials}>
                                    <RefreshCw size={13} /> {t('common.retry', 'Retry')}
                                </button>
                            </div>
                        )}
                    </div>

                    <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                        <h4 style={{ margin: '0 0 14px', color: 'var(--color-text-primary)', fontSize: '15px', fontWeight: 650 }}>
                            {t('integrations.templatePreview', 'Metric template & live preview')}
                        </h4>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(270px, 1fr))', gap: '10px' }}>
                            {[
                                { id: 'cpa', text: t('integrations.extTemplate1') },
                                { id: 'cod', text: t('integrations.extTemplate2') },
                            ].map(template => (
                                <button
                                    key={template.id}
                                    type="button"
                                    onClick={() => setExtTemplate(template.id)}
                                    style={{
                                        padding: '13px 14px', textAlign: 'left', borderRadius: '11px', cursor: 'pointer',
                                        border: `1px solid ${extTemplate === template.id ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                        background: extTemplate === template.id ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                        color: 'var(--color-text-primary)', fontSize: '12px', lineHeight: 1.45,
                                        boxShadow: extTemplate === template.id ? '0 0 0 2px rgba(124,58,237,.10)' : 'none'
                                    }}
                                >
                                    <span style={{ display: 'inline-grid', placeItems: 'center', width: '16px', height: '16px', marginRight: '8px', borderRadius: '50%', border: '1px solid var(--color-primary)', color: 'var(--color-primary)', fontSize: '10px' }}>
                                        {extTemplate === template.id ? '●' : ''}
                                    </span>
                                    {template.text}
                                </button>
                            ))}
                        </div>

                        <div style={{ marginTop: '16px', padding: '14px', borderRadius: '12px', background: '#f8fafc', border: '1px solid #dbe3ee' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '9px', color: '#1e293b', fontSize: '12px', fontWeight: 650 }}>
                                <span style={{ width: '14px', height: '14px', borderRadius: '3px', border: '1px solid #94a3b8', background: '#fff' }} />
                                Summer Scale Campaign <span style={{ color: '#94a3b8', fontWeight: 500 }}>1203849102948</span>
                            </div>
                            <div style={{ display: 'inline-flex', flexWrap: 'wrap', alignItems: 'center', gap: '5px', padding: '6px 8px', marginLeft: '24px', borderRadius: '8px', background: 'linear-gradient(135deg,#151322,#211a38)', border: '1px solid rgba(139,92,246,.45)', boxShadow: '0 4px 14px rgba(15,23,42,.18)' }}>
                                <span style={{ color: '#c4b5fd', fontSize: '10px', fontWeight: 700 }}>Orbitra</span>
                                {preview.map(([label, value], index) => (
                                    <React.Fragment key={label}>
                                        {index > 0 && <span style={{ color: '#64748b', fontSize: '10px' }}>|</span>}
                                        <span style={{ color: '#e2e8f0', fontSize: '10px', fontWeight: 600, whiteSpace: 'nowrap' }}>
                                            {label}: <strong style={{ color: label.includes('ROI') || label === 'PROFIT' ? '#4ade80' : '#fff' }}>{value}</strong>
                                        </span>
                                    </React.Fragment>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div style={{ padding: '20px', borderRadius: '16px', background: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px', flexWrap: 'wrap', marginBottom: '14px' }}>
                            <h4 style={{ margin: 0, color: 'var(--color-text-primary)', fontSize: '15px', fontWeight: 650 }}>
                                {t('integrations.antidetectInstallGuide')}
                            </h4>
                            <a href="/data/orbitra-extension.zip" download="orbitra-extension.zip" className="btn btn-primary btn-sm">
                                <Download size={15} /> {t('integrations.downloadExtension')}
                            </a>
                        </div>
                        <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', marginBottom: '14px' }}>
                            {guideTabs.map(guide => (
                                <button
                                    key={guide.id}
                                    type="button"
                                    onClick={() => setExtGuide(guide.id)}
                                    className="btn btn-sm"
                                    style={{
                                        background: extGuide === guide.id ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                        color: extGuide === guide.id ? '#fff' : 'var(--color-text-secondary)',
                                        border: `1px solid ${extGuide === guide.id ? 'var(--color-primary)' : 'var(--color-border)'}`
                                    }}
                                >
                                    {guide.label}
                                </button>
                            ))}
                        </div>
                        <div style={{ display: 'flex', gap: '12px', padding: '14px', borderRadius: '11px', background: 'var(--color-bg-card)', border: '1px solid var(--color-border)', color: 'var(--color-text-secondary)', fontSize: '13px', lineHeight: 1.6 }}>
                            <span style={{ display: 'grid', placeItems: 'center', flexShrink: 0, width: '24px', height: '24px', borderRadius: '50%', background: 'var(--color-primary-light)', color: 'var(--color-primary)', fontWeight: 700 }}>1</span>
                            <span>{guideTabs.find(guide => guide.id === extGuide)?.text}</span>
                        </div>
                        <p style={{ margin: '12px 0 0', color: 'var(--color-text-muted)', fontSize: '12px', lineHeight: 1.5 }}>
                            {t('integrations.extensionPopupHint', 'After installation, open the extension popup and paste the Tracker API URL and read API key shown above.')}
                        </p>
                    </div>
                </div>
            </div>
        );
    };

    // WordPress Plugin Generator
    const generateWpPlugin = () => {
        const pluginPhp = `<?php
/**
 * Plugin Name: Orbitra Tracker Integration
 * Plugin URI: ${trackerUrl}
 * Description: Integrate Orbitra Tracker - geo redirects, tracking pixels, banners, buttons, countdowns.
 * Version: 1.1.0
 * Author: Orbitra
 * Text Domain: orbitra-tracker
 */

if (!defined('ABSPATH')) exit;

define('ORBITRA_TRACKER_URL', '${trackerUrl}');
define('ORBITRA_TRACKER_VER', '1.1.0');

class Orbitra_Tracker {
    
    private static \\$instance = null;
    private \\$tracker_url;
    
    public static function get_instance() {
        if (null === self::\\$instance) {
            self::\\$instance = new self();
        }
        return self::\\$instance;
    }
    
    private function __construct() {
        \\$this->tracker_url = ORBITRA_TRACKER_URL;
        add_action('plugins_loaded', array(\\$this, 'init'));
    }
    
    public function init() {
        load_plugin_textdomain('orbitra-tracker', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        add_shortcode('orbitra_link', array(\\$this, 'shortcode_link'));
        add_shortcode('orbitra_banner', array(\\$this, 'shortcode_banner'));
        add_shortcode('orbitra_button', array(\\$this, 'shortcode_button'));
        add_shortcode('orbitra_if_geo', array(\\$this, 'shortcode_if_geo'));
        add_shortcode('orbitra_countdown', array(\\$this, 'shortcode_countdown'));
        
        add_action('admin_menu', array(\\$this, 'admin_menu'));
        add_action('admin_init', array(\\$this, 'admin_init'));
        add_action('wp_footer', array(\\$this, 'tracking_pixel'));
        add_action('wp_enqueue_scripts', array(\\$this, 'enqueue_assets'));
        
        add_action('wp_ajax_orbitra_detect_geo', array(\\$this, 'ajax_detect_geo'));
        add_action('wp_ajax_nopriv_orbitra_detect_geo', array(\\$this, 'ajax_detect_geo'));
        add_action('wp_ajax_orbitra_test_connection', array(\\$this, 'ajax_test_connection'));
    }
    
    public function enqueue_assets() {
        wp_register_style('orbitra-tracker', false);
        wp_enqueue_style('orbitra-tracker');
        wp_add_inline_style('orbitra-tracker', '
            .orbitra-btn{display:inline-block;padding:14px 32px;font-weight:600;text-decoration:none;text-align:center;cursor:pointer;transition:all .2s ease;box-shadow:0 2px 8px rgba(0,0,0,.15)}
            .orbitra-btn:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.2);filter:brightness(1.1)}
            .orbitra-btn-sm{padding:10px 20px;font-size:14px}
            .orbitra-btn-lg{padding:18px 44px;font-size:18px}
        ');
    }
    
    // [orbitra_link campaign_id="1" text="Click Here" text_uk="Натисни" text_ru="Жми" geo_redirect="RU:https://offer1.com,DE:https://offer2.com"]
    public function shortcode_link(\\$atts, \\$content = null) {
        \\$raw_atts = (array) \\$atts;
        \\$atts = shortcode_atts(array(
            'campaign_id' => '',
            'url' => '',
            'text' => \\$content ?: 'Click Here',
            'class' => 'orbitra-link',
            'target' => '_blank',
            'geo_redirect' => '',
        ), \\$atts);
        
        if (empty(\\$atts['campaign_id'])) return '';
        
        // Multilingual text — supports any text_{lang} attribute
        \\$text = \\$this->get_localized_text(\\$raw_atts, 'text', \\$atts['text']);
        
        \\$click_url = \\$this->tracker_url . '/click.php?campaign_id=' . \\$atts['campaign_id'];
        if (!empty(\\$atts['url'])) \\$click_url .= '&url=' . urlencode(\\$atts['url']);
        
        \\$geo_script = '';
        if (!empty(\\$atts['geo_redirect'])) {
            \\$geo_map = array();
            foreach (explode(',', \\$atts['geo_redirect']) as \\$item) {
                \\$parts = explode(':', \\$item, 2);
                if (count(\\$parts) === 2) \\$geo_map[trim(\\$parts[0])] = trim(\\$parts[1]);
            }
            \\$id = 'orbitra-link-' . uniqid();
            \\$geo_script = '<script>
(function(){
    var map = ' . json_encode(\\$geo_map) . ';
    fetch("' . \\$this->tracker_url . '/api.php?action=detect_geo")
        .then(r=>r.json())
        .then(d=>{
            var url = map[d.country] || map["DEFAULT"];
            if(url) document.getElementById("' . \\$id . '").href = url;
        });
})();
</script>';
            return sprintf('<a href="#" id="%s" class="%s" target="%s" rel="nofollow noopener">%s</a>%s',
                \\$id, esc_attr(\\$atts['class']), esc_attr(\\$atts['target']), esc_html(\\$text), \\$geo_script);
        }
        
        return sprintf('<a href="%s" class="%s" target="%s" rel="nofollow noopener">%s</a>',
            esc_url(\\$click_url), esc_attr(\\$atts['class']), esc_attr(\\$atts['target']), esc_html(\\$text));
    }
    
    // [orbitra_banner campaign_id="1" image="https://..." width="300" height="250"]
    public function shortcode_banner(\\$atts) {
        \\$atts = shortcode_atts(array(
            'campaign_id' => '',
            'image' => '',
            'alt' => 'Banner',
            'width' => '300',
            'height' => '250',
        ), \\$atts);
        
        if (empty(\\$atts['campaign_id']) || empty(\\$atts['image'])) return '';
        
        \\$click_url = \\$this->tracker_url . '/click.php?campaign_id=' . \\$atts['campaign_id'];
        
        return sprintf('<a href="%s" target="_blank" rel="nofollow noopener"><img src="%s" alt="%s" width="%s" height="%s" loading="lazy" style="border:none;max-width:100%%;height:auto;" /></a>',
            esc_url(\\$click_url), esc_url(\\$atts['image']), esc_attr(\\$atts['alt']), 
            esc_attr(\\$atts['width']), esc_attr(\\$atts['height']));
    }
    
    // [orbitra_button campaign_id="1" text="PLAY NOW" text_uk="Грати" text_ru="Играть" text_de="Spielen" bg="#22c55e" color="#fff" radius="8" size="md"]
    public function shortcode_button(\\$atts) {
        \\$raw_atts = (array) \\$atts;
        \\$atts = shortcode_atts(array(
            'campaign_id' => '',
            'url' => '',
            'text' => 'PLAY NOW',
            'bg' => '#22c55e',
            'color' => '#ffffff',
            'radius' => '8',
            'size' => 'md',
            'target' => '_blank',
            'full_width' => 'false',
            'icon' => '',
        ), \\$atts);
        
        if (empty(\\$atts['campaign_id']) && empty(\\$atts['url'])) return '';
        
        // Multilingual text — supports any text_{lang} (uk, ru, de, fr, es...)
        \\$text = \\$this->get_localized_text(\\$raw_atts, 'text', \\$atts['text']);
        
        \\$click_url = !empty(\\$atts['campaign_id'])
            ? \\$this->tracker_url . '/click.php?campaign_id=' . \\$atts['campaign_id'] . (!empty(\\$atts['url']) ? '&url=' . urlencode(\\$atts['url']) : '')
            : \\$atts['url'];
        
        \\$size_class = 'orbitra-btn';
        if (\\$atts['size'] === 'sm') \\$size_class .= ' orbitra-btn-sm';
        elseif (\\$atts['size'] === 'lg') \\$size_class .= ' orbitra-btn-lg';
        
        \\$style = sprintf('background:%s;color:%s;border-radius:%spx;%s',
            esc_attr(\\$atts['bg']),
            esc_attr(\\$atts['color']),
            intval(\\$atts['radius']),
            \\$atts['full_width'] === 'true' ? 'display:block;width:100%;' : ''
        );
        
        \\$icon_html = '';
        if (!empty(\\$atts['icon'])) {
            \\$icon_html = '<span style="margin-right:6px;">' . esc_html(\\$atts['icon']) . '</span>';
        }
        
        return sprintf('<a href="%s" class="%s" target="%s" rel="nofollow noopener" style="%s">%s%s</a>',
            esc_url(\\$click_url), esc_attr(\\$size_class), esc_attr(\\$atts['target']), \\$style, \\$icon_html, esc_html(\\$text));
    }
    
    // [orbitra_if_geo countries="RU,DE,ES"]Content here[/orbitra_if_geo]
    public function shortcode_if_geo(\\$atts, \\$content = null) {
        \\$atts = shortcode_atts(array('countries' => '', 'exclude' => ''), \\$atts);
        if (empty(\\$atts['countries']) && empty(\\$atts['exclude'])) return \\$content;
        
        \\$geo = \\$this->get_visitor_geo();
        
        if (!empty(\\$atts['exclude'])) {
            \\$excluded = array_map('trim', explode(',', strtoupper(\\$atts['exclude'])));
            if (in_array(\\$geo, \\$excluded)) return '';
        }
        if (!empty(\\$atts['countries'])) {
            \\$allowed = array_map('trim', explode(',', strtoupper(\\$atts['countries'])));
            if (!in_array(\\$geo, \\$allowed)) return '';
        }
        
        return do_shortcode(\\$content);
    }
    
    // [orbitra_countdown campaign_id="1" hours="2" redirect="https://offer.com" text="OFFER EXPIRES IN" text_ru="ПРЕДЛОЖЕНИЕ ИСТЕКАЕТ" button="GET OFFER NOW" button_uk="Забрати" button_ru="Забрать"]
    public function shortcode_countdown(\\$atts) {
        \\$raw_atts = (array) \\$atts;
        \\$atts = shortcode_atts(array(
            'campaign_id' => '',
            'hours' => '2',
            'redirect' => '',
            'text' => 'OFFER EXPIRES IN',
            'button' => 'GET OFFER NOW',
            'bg' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            'button_bg' => '#22c55e',
        ), \\$atts);
        
        \\$timer_text = \\$this->get_localized_text(\\$raw_atts, 'text', \\$atts['text']);
        \\$button_text = \\$this->get_localized_text(\\$raw_atts, 'button', \\$atts['button']);
        
        \\$id = 'orbitra-timer-' . uniqid();
        \\$click_url = !empty(\\$atts['campaign_id']) 
            ? \\$this->tracker_url . '/click.php?campaign_id=' . \\$atts['campaign_id'] . '&url=' . urlencode(\\$atts['redirect'])
            : \\$atts['redirect'];
        
        \\$hours_int = intval(\\$atts['hours']);
        
        return '<div id="' . \\$id . '" style="font-family:sans-serif;text-align:center;padding:24px;background:' . esc_attr(\\$atts['bg']) . ';color:white;border-radius:16px;max-width:420px;margin:0 auto;">
    <div style="font-size:13px;text-transform:uppercase;letter-spacing:2px;margin-bottom:12px;opacity:0.9;">' . esc_html(\\$timer_text) . '</div>
    <div style="font-size:48px;font-weight:700;font-variant-numeric:tabular-nums;"><span class="h">00</span>:<span class="m">00</span>:<span class="s">00</span></div>
    <a href="' . esc_url(\\$click_url) . '" target="_blank" rel="nofollow noopener" class="orbitra-btn" style="margin-top:20px;background:' . esc_attr(\\$atts['button_bg']) . ';border-radius:10px;">' . esc_html(\\$button_text) . '</a>
</div>
<script>(function(){var end=new Date().getTime()+(' . \\$hours_int . '*3600*1000);var el=document.getElementById("' . \\$id . '");function u(){var d=end-new Date().getTime();if(d<0){el.querySelector("div:nth-child(2)").innerHTML="EXPIRED";return}el.querySelector(".h").textContent=String(Math.floor(d/3600000)).padStart(2,"0");el.querySelector(".m").textContent=String(Math.floor((d%3600000)/60000)).padStart(2,"0");el.querySelector(".s").textContent=String(Math.floor((d%60000)/1000)).padStart(2,"0")}u();setInterval(u,1000)})();</script>';
    }
    
    public function tracking_pixel() {
        \\$campaign_id = get_option('orbitra_tracking_campaign', '');
        if (empty(\\$campaign_id)) return;
        echo '<img src="' . esc_url(\\$this->tracker_url . '/click.php?campaign_id=' . \\$campaign_id . '&redirect=0') . '" width="1" height="1" style="display:none;" alt="" />';
    }
    
    /**
     * Get visitor geo using client IP -> Orbitra API.
     * Uses WordPress Transients for caching (no session_start needed).
     */
    private function get_visitor_geo() {
        \\$ip = \\$this->get_client_ip();
        \\$cache_key = 'orbitra_geo_' . md5(\\$ip);
        
        \\$cached = get_transient(\\$cache_key);
        if (\\$cached !== false) return \\$cached;
        
        \\$response = wp_remote_get(\\$this->tracker_url . '/api.php?action=detect_geo&ip=' . urlencode(\\$ip), array('timeout' => 3));
        if (!is_wp_error(\\$response)) {
            \\$data = json_decode(wp_remote_retrieve_body(\\$response), true);
            if (isset(\\$data['country'])) {
                set_transient(\\$cache_key, \\$data['country'], 3600); // cache for 1 hour
                return \\$data['country'];
            }
        }
        return 'UNKNOWN';
    }
    
    private function get_client_ip() {
        \\$keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        foreach (\\$keys as \\$key) {
            if (!empty(\\$_SERVER[\\$key])) {
                \\$ip = trim(explode(',', \\$_SERVER[\\$key])[0]);
                if (filter_var(\\$ip, FILTER_VALIDATE_IP)) return \\$ip;
            }
        }
        return \\$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Dynamic multilingual text.
     * Scans shortcode attributes for {prefix}_{lang} pattern.
     * Supports ANY WordPress locale: text_uk, text_ru, text_de, text_fr, text_es, etc.
     * Falls back to default text if no matching locale found.
     *
     * Example: [orbitra_button text="PLAY NOW" text_uk="Грати" text_ru="Играть" text_de="Spielen"]
     */
    private function get_localized_text(\\$raw_atts, \\$prefix, \\$default) {
        \\$locale = strtolower(substr(get_locale(), 0, 2));
        
        // Try exact locale match: text_uk, text_ru, text_de, etc.
        \\$key = \\$prefix . '_' . \\$locale;
        if (!empty(\\$raw_atts[\\$key])) return \\$raw_atts[\\$key];
        
        // Try full locale (e.g. text_pt_br for pt_BR)
        \\$full_locale = strtolower(str_replace('-', '_', get_locale()));
        \\$key_full = \\$prefix . '_' . \\$full_locale;
        if (!empty(\\$raw_atts[\\$key_full])) return \\$raw_atts[\\$key_full];
        
        return \\$default;
    }
    
    public function ajax_detect_geo() {
        wp_send_json_success(array('country' => \\$this->get_visitor_geo()));
    }
    
    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
        \\$response = wp_remote_get(\\$this->tracker_url . '/api.php?action=detect_geo', array('timeout' => 5));
        if (is_wp_error(\\$response)) {
            wp_send_json_error(\\$response->get_error_message());
        }
        \\$code = wp_remote_retrieve_response_code(\\$response);
        \\$body = json_decode(wp_remote_retrieve_body(\\$response), true);
        wp_send_json_success(array('status_code' => \\$code, 'response' => \\$body));
    }
    
    public function admin_menu() {
        add_options_page('Orbitra Tracker', 'Orbitra Tracker', 'manage_options', 'orbitra-tracker', array(\\$this, 'admin_page'));
    }
    
    public function admin_init() {
        register_setting('orbitra_tracker', 'orbitra_tracking_campaign');
        register_setting('orbitra_tracker', 'orbitra_default_campaign');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:8px;">
                <span style="background:linear-gradient(135deg,#667eea,#764ba2);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-weight:700;">Orbitra</span> Tracker
            </h1>
            
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0;max-width:700px;">
                <h3 style="margin-top:0;">Connection</h3>
                <p>Tracker URL: <code><?php echo esc_html(\\$this->tracker_url); ?></code></p>
                <button type="button" class="button button-secondary" id="orbitra-test-btn" onclick="
                    var btn=this;btn.disabled=true;btn.textContent='Testing...';
                    fetch(ajaxurl+'?action=orbitra_test_connection').then(r=>r.json()).then(d=>{
                        btn.disabled=false;btn.textContent='Test Connection';
                        var el=document.getElementById('orbitra-test-result');
                        if(d.success){el.innerHTML='<span style=\\'color:#16a34a\\'>✅ Connected (Status: '+d.data.status_code+')</span>';}
                        else{el.innerHTML='<span style=\\'color:#dc2626\\'>❌ '+d.data+'</span>';}
                    }).catch(e=>{btn.disabled=false;btn.textContent='Test Connection';});
                ">Test Connection</button>
                <span id="orbitra-test-result" style="margin-left:12px;"></span>
            </div>
            
            <form method="post" action="options.php" style="max-width:700px;">
                <?php settings_fields('orbitra_tracker'); ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-bottom:20px;">
                    <h3 style="margin-top:0;">Tracking Pixel</h3>
                    <table class="form-table">
                        <tr>
                            <th>Campaign ID</th>
                            <td>
                                <input type="number" name="orbitra_tracking_campaign" value="<?php echo esc_attr(get_option('orbitra_tracking_campaign', '')); ?>" class="regular-text" placeholder="Leave empty to disable">
                                <p class="description">Automatic tracking pixel on all pages of this site.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </div>
            </form>
            
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;max-width:700px;">
                <h3 style="margin-top:0;">Shortcodes Reference</h3>
                <table class="widefat striped" style="max-width:100%;">
                    <thead><tr><th>Shortcode</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><code>[orbitra_link campaign_id="1" text="Click"]</code></td>
                            <td>Trackable link. Supports <code>geo_redirect="RU:url,DE:url"</code>, <code>text_ru=""</code>, <code>text_en=""</code></td>
                        </tr>
                        <tr>
                            <td><code>[orbitra_button campaign_id="1" text="Get Offer"]</code></td>
                            <td>Styled CTA button. Options: <code>bg="#22c55e"</code>, <code>color="#fff"</code>, <code>radius="8"</code>, <code>size="sm|md|lg"</code>, <code>full_width="true"</code>, <code>icon="🔥"</code>, <code>text_ru=""</code>, <code>text_en=""</code></td>
                        </tr>
                        <tr>
                            <td><code>[orbitra_banner campaign_id="1" image="url"]</code></td>
                            <td>Trackable image banner. Options: <code>width</code>, <code>height</code>, <code>alt</code></td>
                        </tr>
                        <tr>
                            <td><code>[orbitra_if_geo countries="RU,DE"]...[/orbitra_if_geo]</code></td>
                            <td>Show content only for specific countries. Also supports <code>exclude="US,GB"</code></td>
                        </tr>
                        <tr>
                            <td><code>[orbitra_countdown campaign_id="1" hours="2"]</code></td>
                            <td>Countdown timer with CTA. Options: <code>text</code>, <code>button</code>, <code>redirect</code>, <code>bg</code>, <code>button_bg</code>, multilingual <code>text_ru</code>, <code>button_ru</code></td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top:12px;color:#666;">
                    <strong>Multilingual:</strong> All text shortcodes support <code>text_ru="..."</code> and <code>text_en="..."</code> attributes. 
                    The plugin auto-selects the text based on the WordPress site language.
                </p>
            </div>
        </div>
        <?php
    }
}

Orbitra_Tracker::get_instance();
`;

        const readmeTxt = `=== Orbitra Tracker Integration ===
Contributors: orbitra
Tags: tracker, affiliate, geo, redirect, banner, button
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.1.0
License: GPLv2 or later

Integrate Orbitra Tracker with WordPress - geo redirects, tracking pixels, banners, buttons, countdowns.

== Description ==

This plugin integrates your WordPress site with Orbitra Tracker installed at: ${trackerUrl}

Features:
* Geo-based redirects - show different offers per country
* Tracking pixels - automatic tracking on all pages
* Banner shortcodes - add responsive trackable banners
* CTA Buttons - styled buttons with multilingual support
* Countdown timers - create urgency with countdowns
* Conditional content - show/hide content by country
* Multilingual - auto text selection based on site language

== Shortcodes ==

= orbitra_link =
Trackable link with optional geo-redirect.
[orbitra_link campaign_id="1" text="Click" text_ru="Нажми" geo_redirect="RU:https://...,DE:https://..."]

= orbitra_button =
Styled CTA button with design options.
[orbitra_button campaign_id="1" text="Get Offer" text_ru="Получить" bg="#22c55e" size="lg" icon="🔥"]

= orbitra_banner =
Responsive trackable banner image.
[orbitra_banner campaign_id="1" image="https://..." width="728" height="90"]

= orbitra_if_geo =
Show content only for specific countries.
[orbitra_if_geo countries="RU,DE"]Content here[/orbitra_if_geo]

= orbitra_countdown =
Countdown timer with CTA button.
[orbitra_countdown campaign_id="1" hours="2" redirect="https://..." button_ru="Забрать"]

== Installation ==

1. Upload plugin folder to /wp-content/plugins/
2. Activate the plugin
3. Go to Settings > Orbitra Tracker
4. Test connection and configure tracking
`;

        const uninstallPhp = `<?php
/**
 * Orbitra Tracker - Uninstall
 * Cleans up plugin data on uninstall.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('orbitra_tracking_campaign');
delete_option('orbitra_default_campaign');

// Clean up geo transients
global \$wpdb;
\$wpdb->query("DELETE FROM " . \$wpdb->prefix . "options WHERE option_name LIKE '_transient_orbitra_geo_%' OR option_name LIKE '_transient_timeout_orbitra_geo_%'");
`;

        // Create ZIP file
        const JSZip = window.JSZip;
        if (JSZip) {
            const zip = new JSZip();
            const folder = zip.folder('orbitra-tracker');
            folder.file('orbitra-tracker.php', pluginPhp);
            folder.file('readme.txt', readmeTxt);
            folder.file('uninstall.php', uninstallPhp);
            zip.generateAsync({ type: 'blob' }).then(content => {
                const url = URL.createObjectURL(content);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'orbitra-tracker.zip';
                a.click();
                URL.revokeObjectURL(url);
            });
        } else {
            // Fallback: download PHP file directly
            const blob = new Blob([pluginPhp], { type: 'text/php' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'orbitra-tracker.php';
            a.click();
            URL.revokeObjectURL(url);
        }
    };

    const renderWpPluginPanel = () => (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            <div style={{ maxWidth: '700px' }} className="space-y-6">
                {/* Download Button */}
                <div style={{
                    padding: '24px',
                    borderRadius: '16px',
                    background: 'linear-gradient(135deg, #0073aa 0%, #005177 100%)',
                    color: 'white',
                    textAlign: 'center'
                }}>
                    <div style={{ fontSize: '48px', marginBottom: '16px' }}>📦</div>
                    <h3 style={{ fontSize: '20px', fontWeight: 600, marginBottom: '8px' }}>
                        {t('wpPlugin.downloadTitle')}
                    </h3>
                    <p style={{ fontSize: '14px', opacity: 0.9, marginBottom: '20px' }}>
                        {t('wpPlugin.downloadDesc')}
                    </p>
                    <button onClick={generateWpPlugin} className="btn btn-primary" style={{ background: '#22c55e', borderColor: '#22c55e' }}>
                        <Download size={18} />
                        <span>{t('wpPlugin.downloadBtn')}</span>
                    </button>
                </div>

                {/* Tracker URL */}
                <div style={{
                    padding: '16px',
                    borderRadius: '12px',
                    background: 'var(--color-bg-soft)',
                    border: '1px solid var(--color-border)'
                }}>
                    <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '4px' }}>
                        {t('wpPlugin.trackerUrl')}
                    </div>
                    <code style={{ fontSize: '14px', color: 'var(--color-primary)' }}>{trackerUrl}</code>
                </div>

                {/* Features */}
                <div style={{
                    padding: '20px',
                    borderRadius: '16px',
                    background: 'var(--color-bg-soft)',
                    border: '1px solid var(--color-border)'
                }}>
                    <h4 style={{ fontWeight: 600, marginBottom: '16px', color: 'var(--color-text-primary)' }}>
                        {t('wpPlugin.featuresTitle')}
                    </h4>
                    <div className="space-y-3">
                        {[
                            { icon: '🌍', title: t('wpPlugin.feature1Title'), desc: t('wpPlugin.feature1Desc') },
                            { icon: '📊', title: t('wpPlugin.feature2Title'), desc: t('wpPlugin.feature2Desc') },
                            { icon: '🖼️', title: t('wpPlugin.feature3Title'), desc: t('wpPlugin.feature3Desc') },
                            { icon: '⏱️', title: t('wpPlugin.feature4Title'), desc: t('wpPlugin.feature4Desc') },
                            { icon: '🎯', title: t('wpPlugin.feature5Title'), desc: t('wpPlugin.feature5Desc') },
                        ].map((f, i) => (
                            <div key={i} style={{ display: 'flex', gap: '12px', alignItems: 'flex-start' }}>
                                <span style={{ fontSize: '20px' }}>{f.icon}</span>
                                <div>
                                    <div style={{ fontWeight: 500, fontSize: '14px', color: 'var(--color-text-primary)' }}>{f.title}</div>
                                    <div style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>{f.desc}</div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Shortcodes */}
                <div style={{
                    padding: '20px',
                    borderRadius: '16px',
                    background: 'var(--color-bg-soft)',
                    border: '1px solid var(--color-border)'
                }}>
                    <h4 style={{ fontWeight: 600, marginBottom: '16px', color: 'var(--color-text-primary)' }}>
                        {t('wpPlugin.shortcodesTitle')}
                    </h4>
                    <div className="space-y-3" style={{ fontSize: '13px' }}>
                        {[
                            { code: '[orbitra_link campaign_id="1" text="Click" text_uk="Тисни" text_ru="Жми" geo_redirect="RU:https://..."]', desc: t('wpPlugin.shortcodeLink') },
                            { code: '[orbitra_button campaign_id="1" text_uk="Грати" text_ru="Играть" text_de="Spielen" bg="#22c55e" size="lg"]', desc: t('wpPlugin.shortcodeButton') || 'Styled CTA button with design and multilingual support' },
                            { code: '[orbitra_banner campaign_id="1" image="https://..."]', desc: t('wpPlugin.shortcodeBanner') },
                            { code: '[orbitra_if_geo countries="RU,DE"]...[/orbitra_if_geo]', desc: t('wpPlugin.shortcodeGeo') },
                            { code: '[orbitra_countdown campaign_id="1" hours="2" button_uk="Забрати" button_ru="Забрать"]', desc: t('wpPlugin.shortcodeCountdown') },
                        ].map((s, i) => (
                            <div key={i} style={{ padding: '12px', background: 'var(--color-bg-card)', borderRadius: '8px', border: '1px solid var(--color-border)' }}>
                                <code style={{ color: 'var(--color-primary)', display: 'block', marginBottom: '4px', wordBreak: 'break-all' }}>{s.code}</code>
                                <span style={{ color: 'var(--color-text-muted)' }}>{s.desc}</span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Installation */}
                <div style={{
                    padding: '20px',
                    borderRadius: '16px',
                    background: 'var(--color-bg-soft)',
                    border: '1px solid var(--color-border)'
                }}>
                    <h4 style={{ fontWeight: 600, marginBottom: '16px', color: 'var(--color-text-primary)' }}>
                        {t('wpPlugin.installTitle')}
                    </h4>
                    <ol style={{ fontSize: '14px', lineHeight: 2, marginLeft: '20px', color: 'var(--color-text-secondary)' }}>
                        <li>{t('wpPlugin.install1')}</li>
                        <li>{t('wpPlugin.install2')}</li>
                        <li>{t('wpPlugin.install3')}</li>
                        <li>{t('wpPlugin.install4')}</li>
                    </ol>
                </div>
            </div>
        </div>
    );

    const renderTelegramPanel = () => (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            {tgLoading ? (
                <div className="flex justify-center py-10">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                </div>
            ) : (
                <div style={{ maxWidth: '600px' }} className="space-y-6">
                    {/* Status Message */}
                    {tgMessage && (
                        <div style={{
                            padding: '12px 16px',
                            borderRadius: '12px',
                            background: tgMessage.type === 'success' ? 'var(--color-success-bg, #dcfce7)' : 'var(--color-danger-bg, #fee2e2)',
                            color: tgMessage.type === 'success' ? '#166534' : '#991b1b',
                            border: `1px solid ${tgMessage.type === 'success' ? '#86efac' : '#fca5a5'} `,
                            fontSize: '14px'
                        }}>
                            {tgMessage.text}
                        </div>
                    )}

                    {/* Connection Status */}
                    {tgSettings?.token_set ? (
                        <div style={{
                            padding: '20px',
                            borderRadius: '16px',
                            background: 'var(--color-bg-soft)',
                            border: '1px solid var(--color-border)'
                        }}>
                            <div className="flex items-center justify-between mb-4">
                                <div className="flex items-center gap-3">
                                    <div style={{
                                        width: '10px', height: '10px', borderRadius: '50%',
                                        background: tgSettings.webhook_set ? '#22c55e' : '#ef4444',
                                        boxShadow: tgSettings.webhook_set ? '0 0 8px #22c55e80' : '0 0 8px #ef444480'
                                    }} />
                                    <span style={{ fontWeight: 600, color: 'var(--color-text-primary)' }}>
                                        {tgSettings.webhook_set ? t('telegram.statusConnected') : t('telegram.statusError')}
                                    </span>
                                </div>
                                <button onClick={handleTelegramDisconnect} disabled={tgSaving} className="btn btn-secondary btn-sm" style={{ color: '#ef4444' }}>
                                    <Trash2 size={14} />
                                    <span>{t('telegram.disconnect')}</span>
                                </button>
                            </div>
                            <div style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>
                                Token: <code style={{ color: 'var(--color-primary)' }}>{tgSettings.masked_token}</code>
                            </div>

                            {/* Connected Chats */}
                            {tgSettings.chats?.length > 0 && (
                                <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: '1px solid var(--color-border)' }}>
                                    <div className="flex items-center gap-2 mb-3">
                                        <Users size={16} style={{ color: 'var(--color-text-muted)' }} />
                                        <span style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>
                                            {t('telegram.connectedChats')} ({tgSettings.chats.length})
                                        </span>
                                    </div>
                                    <div className="space-y-2">
                                        {tgSettings.chats.map(chat => (
                                            <div key={chat.chat_id} className="flex items-center justify-between" style={{
                                                padding: '8px 12px', borderRadius: '10px',
                                                background: 'var(--color-bg-card)', border: '1px solid var(--color-border)'
                                            }}>
                                                <div className="flex items-center gap-2">
                                                    <MessageCircle size={14} style={{ color: '#0088cc' }} />
                                                    <span style={{ fontSize: '13px', color: 'var(--color-text-primary)' }}>
                                                        {chat.first_name} {chat.username ? `(@${chat.username})` : ''}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {chat.notify_conversions == 1 && <Bell size={12} style={{ color: '#22c55e' }} />}
                                                    <span style={{ fontSize: '11px', color: 'var(--color-text-muted)' }}>{chat.language?.toUpperCase()}</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Test & Actions */}
                            <div style={{ marginTop: '16px', paddingTop: '16px', borderTop: '1px solid var(--color-border)' }} className="flex gap-2">
                                <button onClick={handleTelegramTest} disabled={tgTesting} className="btn btn-primary btn-sm">
                                    {tgTesting ? <RefreshCw size={14} className="animate-spin" /> : <Send size={14} />}
                                    <span>{t('telegram.sendTest')}</span>
                                </button>
                                <button onClick={fetchTelegramSettings} className="btn btn-secondary btn-sm">
                                    <RefreshCw size={14} />
                                    <span>{t('telegram.refresh')}</span>
                                </button>
                            </div>
                        </div>
                    ) : (
                        /* Token Input */
                        <div style={{
                            padding: '20px',
                            borderRadius: '16px',
                            background: 'var(--color-bg-soft)',
                            border: '1px solid var(--color-border)'
                        }}>
                            <h4 style={{ fontWeight: 600, marginBottom: '12px', color: 'var(--color-text-primary)' }}>
                                {t('telegram.connectBot')}
                            </h4>
                            <p style={{ fontSize: '13px', color: 'var(--color-text-secondary)', marginBottom: '16px', lineHeight: 1.6 }}>
                                {t('telegram.connectInstructions')}
                            </p>
                            <div className="flex gap-2">
                                <div style={{ position: 'relative', flex: 1 }}>
                                    <input
                                        type={tgShowToken ? 'text' : 'password'}
                                        value={tgToken}
                                        onChange={e => setTgToken(e.target.value)}
                                        placeholder={t('telegram.tokenPlaceholder')}
                                        className="form-input"
                                        style={{ paddingRight: '40px', fontFamily: 'monospace', fontSize: '13px' }}
                                    />
                                    <button
                                        onClick={() => setTgShowToken(!tgShowToken)}
                                        style={{
                                            position: 'absolute', right: '8px', top: '50%', transform: 'translateY(-50%)',
                                            background: 'none', border: 'none', cursor: 'pointer',
                                            color: 'var(--color-text-muted)', padding: '4px'
                                        }}
                                    >
                                        {tgShowToken ? <EyeOff size={16} /> : <Eye size={16} />}
                                    </button>
                                </div>
                                <button onClick={handleTelegramConnect} disabled={tgSaving || !tgToken.trim()} className="btn btn-primary">
                                    {tgSaving ? <RefreshCw size={16} className="animate-spin" /> : <Send size={16} />}
                                    <span>{t('telegram.connect')}</span>
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Notification Settings */}
                    {tgSettings?.token_set && (
                        <div style={{
                            padding: '20px',
                            borderRadius: '16px',
                            background: 'var(--color-bg-soft)',
                            border: '1px solid var(--color-border)'
                        }}>
                            <h4 style={{ fontWeight: 600, marginBottom: '16px', color: 'var(--color-text-primary)' }}>
                                {t('telegram.notificationSettings')}
                            </h4>
                            <div className="space-y-4">
                                <label className="flex items-center justify-between cursor-pointer">
                                    <div className="flex items-center gap-3">
                                        {tgNotifyConversions ? <Bell size={18} style={{ color: '#22c55e' }} /> : <BellOff size={18} style={{ color: 'var(--color-text-muted)' }} />}
                                        <div>
                                            <div style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('telegram.notifyConversions')}</div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>{t('telegram.notifyConversionsDesc')}</div>
                                        </div>
                                    </div>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked={tgNotifyConversions} onChange={e => setTgNotifyConversions(e.target.checked)} className="sr-only peer" />
                                        <div className="w-11 h-6 bg-[var(--color-border)] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500" />
                                    </label>
                                </label>

                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <Clock size={18} style={{ color: 'var(--color-primary)' }} />
                                        <div>
                                            <div style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>{t('telegram.dailySummary')}</div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>{t('telegram.dailySummaryDesc')}</div>
                                        </div>
                                    </div>
                                    <input
                                        type="time"
                                        value={tgDailyTime}
                                        onChange={e => setTgDailyTime(e.target.value)}
                                        className="form-input"
                                        style={{ width: '110px', textAlign: 'center' }}
                                    />
                                </div>

                                <button onClick={handleSaveSettings} disabled={tgSaving} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                                    {t('common.save')}
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Commands Reference */}
                    <div style={{
                        padding: '20px',
                        borderRadius: '16px',
                        background: 'var(--color-bg-soft)',
                        border: '1px solid var(--color-border)'
                    }}>
                        <h4 style={{ fontWeight: 600, marginBottom: '12px', color: 'var(--color-text-primary)' }}>
                            {t('telegram.commandsTitle')}
                        </h4>
                        <div style={{ fontSize: '13px', lineHeight: 2 }}>
                            {[
                                ['/start', t('telegram.cmdStart')],
                                ['/stats', t('telegram.cmdStats')],
                                ['/stats 7d', t('telegram.cmdStats7d')],
                                ['/campaigns', t('telegram.cmdCampaigns')],
                                ['/campaign ID', t('telegram.cmdCampaignId')],
                                ['/top', t('telegram.cmdTop')],
                                ['/conversions', t('telegram.cmdConversions')],
                                ['/notify on|off', t('telegram.cmdNotify')],
                                ['/daily on|off', t('telegram.cmdDaily')],
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
            )}
        </div>
    );

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_integrations" title={t('help.integrationsBannerTitle')}>
                <p>{t('help.integrationsBanner')}</p>
            </InfoBanner>
            {/* Info Card */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <Zap size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('integrations.howItWorks')}</h3>
                    </div>
                </div>

                <div style={{ marginTop: '20px' }}>
                    <p style={{ color: 'var(--color-text-secondary)', lineHeight: 1.6, marginBottom: '16px' }}>
                        {t('integrations.introText')}
                    </p>

                    <div style={{
                        background: 'var(--color-primary-light)',
                        borderLeft: `4px solid var(--color - primary)`,
                        padding: '16px',
                        borderRadius: '0 12px 12px 0'
                    }}>
                        <h4 style={{ fontWeight: 500, marginBottom: '8px', color: 'var(--color-text-primary)' }}>
                            {t('integrations.usageExamples')}
                        </h4>
                        <ul style={{ listStyle: 'disc', marginLeft: '20px', fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                            <li style={{ marginBottom: '4px' }}>{t('integrations.usageList1')}</li>
                            <li style={{ marginBottom: '4px' }}>{t('integrations.usageList2')}</li>
                            <li>{t('integrations.usageList3')}</li>
                        </ul>
                    </div>

                    <p style={{ color: 'var(--color-text-secondary)', lineHeight: 1.6, marginTop: '16px', fontSize: '14px' }}>
                        {t('integrations.introFooter')}
                        {' '}<strong>KClient PHP</strong> {t('integrations.kclientPhpIntro')}
                        <strong> KClient JS</strong> {t('integrations.kclientJsIntro')}
                        {' '}<strong>{t('integrations.jsBannerTitle')}</strong> {t('integrations.jsBannerIntro')}
                    </p>
                </div>
            </div>

            {/* Main Content */}
            <div className="page-card" style={{ padding: 0 }}>
                <div style={{ display: 'flex', flexDirection: 'row' }}>
                    {/* Sidebar */}
                    <div style={{
                        width: '240px',
                        flexShrink: 0,
                        borderRight: '1px solid var(--color-border)',
                        background: 'var(--color-bg-soft)',
                        borderRadius: '24px 0 0 24px'
                    }}>
                        <nav style={{ padding: '8px', overflowY: 'auto' }}>
                            {(() => {
                                // The page grew one flat list at a time; grouping it the
                                // way Keitaro does (ad networks / sites / tools) makes the
                                // entry a user looks for findable again. Anything not
                                // listed falls into a trailing group so a new entry can
                                // never silently disappear from the menu.
                                const groups = [
                                    { label: t('integrations.groupAds', 'Ad networks'), ids: ['pixel_vault', 'facebook_costs', 'tiktok_costs', 'google_ads_costs', 'facebook_conversions', 'dolphin_fbtool', 'chrome_extension'] },
                                    { label: t('integrations.groupDomains', 'Domains & SSL'), ids: ['cloudflare', 'namecheap'] },
                                    { label: t('integrations.groupSites', 'Sites & landings'), ids: ['kclient_php', 'kclient_js', 'tracking_pixel', 'tiktok_pixel', 'js_banner', 'wordpress', 'wordpress_plugin', 'static_site', 'geo_redirect', 'device_redirect'] },
                                    { label: t('integrations.groupTools', 'Tools'), ids: ['countdown_timer', 'back_button_trap', 'exit_popup', 'app_config', 'recaptcha', 'telegram'] },
                                ];
                                const grouped = new Set(groups.flatMap(g => g.ids));
                                const rest = Object.keys(scripts).filter(id => !grouped.has(id));
                                if (rest.length) {
                                    groups.push({ label: t('integrations.groupOther', 'Other'), ids: rest });
                                }

                                const renderBtn = (id) => {
                                    const script = scripts[id];
                                    if (!script) return null;
                                    return (
                                        <button
                                            key={id}
                                            onClick={() => setActiveTab(id)}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'space-between',
                                                width: '100%',
                                                padding: '10px 16px',
                                                textAlign: 'left',
                                                border: 'none',
                                                background: activeTab === id ? 'var(--color-bg-card)' : 'transparent',
                                                color: activeTab === id ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                                fontWeight: activeTab === id ? 500 : 400,
                                                borderRadius: '12px',
                                                cursor: 'pointer',
                                                marginBottom: '2px',
                                                transition: 'all 0.2s ease',
                                                boxShadow: activeTab === id ? 'var(--shadow-soft)' : 'none'
                                            }}
                                        >
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                                <span style={{ color: activeTab === id ? 'var(--color-primary)' : 'var(--color-text-muted)' }}>
                                                    {script.icon}
                                                </span>
                                                <span style={{ fontSize: '13.5px' }}>{script.title}</span>
                                            </div>
                                            {id === 'telegram' && tgSettings?.token_set && (
                                                <div style={{
                                                    width: '8px', height: '8px', borderRadius: '50%',
                                                    background: tgSettings.webhook_set ? '#22c55e' : '#ef4444'
                                                }} />
                                            )}
                                        </button>
                                    );
                                };

                                return groups.map((group, gi) => (
                                    <div key={group.label} style={gi > 0 ? { marginTop: '10px' } : undefined}>
                                        <div style={{
                                            fontSize: '11px',
                                            fontWeight: 600,
                                            textTransform: 'uppercase',
                                            letterSpacing: '0.08em',
                                            color: 'var(--color-text-muted)',
                                            padding: '6px 16px 4px'
                                        }}>
                                            {group.label}
                                        </div>
                                        {group.ids.map(renderBtn)}
                                    </div>
                                ));
                            })()}
                        </nav>
                    </div>

                    {/* Content Area */}
                    <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
                        {/* Header */}
                        <div style={{
                            padding: '20px 24px',
                            borderBottom: '1px solid var(--color-border)',
                            background: 'var(--color-bg-soft)',
                            display: 'flex',
                            justifyContent: 'space-between',
                            gap: '16px',
                            alignItems: 'center',
                            flexWrap: 'wrap'
                        }}>
                            <div>
                                <h3 style={{
                                    fontSize: '16px',
                                    fontWeight: 600,
                                    color: 'var(--color-text-primary)',
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '10px',
                                    margin: 0
                                }}>
                                    <span style={{ color: 'var(--color-primary)' }}>{activeObj.icon}</span>
                                    {activeObj.title}
                                </h3>
                                <p style={{
                                    fontSize: '14px',
                                    color: 'var(--color-text-secondary)',
                                    lineHeight: 1.5,
                                    margin: '8px 0 0 0'
                                }}>
                                    {activeObj.description}
                                </p>
                            </div>
                            {activeObj.isPixelVault && !pixelProfileEditing && (
                                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                    <button
                                        className="btn btn-primary"
                                        onClick={() => {
                                            setPixelProfileForm(emptyPixelProfileForm);
                                            setPixelProfileEditing('new');
                                            setPixelProfileMessage(null);
                                        }}
                                    >
                                        <Plus size={16} /> {t('pixelVault.addNewPixel')}
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* Content */}
                        {activeObj.isCloudflare ? (
                            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                                <div style={{ maxWidth: '620px', display: 'flex', flexDirection: 'column', gap: '20px' }}>
                                    <div style={{ background: 'var(--color-bg-card)', borderRadius: '12px', padding: '24px', border: '1px solid var(--color-border)' }}>
                                        <p className="text-xs" style={{ color: 'var(--color-text-secondary)', marginBottom: '16px', lineHeight: 1.6 }}>
                                            {t('cloudflare.howTo', 'Создайте токен: Cloudflare → My Profile → API Tokens → Create Token → шаблон «Edit zone DNS» → Permissions: Zone·DNS·Edit + Zone·Zone·Edit → Zone Resources: All zones. Домены, чья зона есть в аккаунте, при парковке получают A-запись автоматически; при включённом прокси SSL выдаётся краем Cloudflare мгновенно.')}
                                        </p>

                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    API Token {cfStatus?.connected ? '✓' : ''}
                                                </label>
                                                <input
                                                    type="password"
                                                    className="form-input"
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                    value={cfForm.api_token}
                                                    onChange={e => setCfForm({ ...cfForm, api_token: e.target.value })}
                                                    placeholder={cfStatus?.connected ? t('cloudflare.tokenSaved', 'токен сохранён — введите новый, чтобы заменить') : ''}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('cloudflare.serverIp', 'IP сервера (A-запись)')}
                                                </label>
                                                <input
                                                    type="text"
                                                    className="form-input"
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                    value={cfForm.server_ip}
                                                    onChange={e => setCfForm({ ...cfForm, server_ip: e.target.value })}
                                                    placeholder={cfStatus?.server_ip || 'auto'}
                                                />
                                            </div>
                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                                                <div>
                                                    <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                        {t('cloudflare.sslMode', 'SSL режим зоны')}
                                                    </label>
                                                    <select
                                                        className="form-select"
                                                        style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px' }}
                                                        value={cfForm.ssl_mode}
                                                        onChange={e => setCfForm({ ...cfForm, ssl_mode: e.target.value })}
                                                    >
                                                        <option value="flexible">Flexible — {t('cloudflare.sslFlexible', 'SSL сразу, сервер по HTTP')}</option>
                                                        <option value="full">Full — {t('cloudflare.sslFull', 'нужен сертификат на сервере')}</option>
                                                        <option value="strict">Strict</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                        Cloudflare Proxy
                                                    </label>
                                                    <label className="flex items-center gap-2" style={{ padding: '10px 0', color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                                        <input type="checkbox" checked={cfForm.proxied} onChange={e => setCfForm({ ...cfForm, proxied: e.target.checked })} />
                                                        {t('cloudflare.proxied', 'оранжевое облако (SSL от CF)')}
                                                    </label>
                                                </div>
                                            </div>

                                            <div className="flex gap-2" style={{ marginTop: '4px' }}>
                                                <button
                                                    className="btn btn-primary"
                                                    disabled={cfBusy}
                                                    onClick={async () => {
                                                        setCfBusy(true); setCfMessage(null);
                                                        try {
                                                            const res = await axios.post(`${API_URL}?action=cloudflare_save`, cfForm);
                                                            if (res.data.status === 'success') {
                                                                setCfMessage('✓ ' + t('cloudflare.saved', 'Сохранено'));
                                                                const st = await axios.get(`${API_URL}?action=cloudflare_status`);
                                                                if (st.data.status === 'success') setCfStatus(st.data.data);
                                                                setCfForm(f => ({ ...f, api_token: '' }));
                                                            } else {
                                                                setCfMessage('⚠ ' + (res.data.message || t('common.error')) + (res.data.detail?.error ? `: ${res.data.detail.error}` : ''));
                                                            }
                                                        } catch (err) { setCfMessage('⚠ ' + t('common.networkError')); }
                                                        finally { setCfBusy(false); }
                                                    }}
                                                >
                                                    {cfBusy ? t('common.saving') : t('common.save')}
                                                </button>
                                                {cfStatus?.connected && (
                                                    <>
                                                        <button
                                                            className="btn btn-secondary"
                                                            disabled={cfBusy}
                                                            onClick={async () => {
                                                                setCfBusy(true); setCfMessage(null);
                                                                try {
                                                                    const res = await axios.post(`${API_URL}?action=cloudflare_test`, {});
                                                                    setCfMessage(res.data.status === 'success'
                                                                        ? `✓ ${t('cloudflare.zonesAvailable', 'зон в аккаунте')}: ${res.data.data.zones}`
                                                                        : '⚠ ' + (res.data.message || t('common.error')));
                                                                } catch (err) { setCfMessage('⚠ ' + t('common.networkError')); }
                                                                finally { setCfBusy(false); }
                                                            }}
                                                        >
                                                            {t('cloudflare.test', 'Проверить')}
                                                        </button>
                                                        <button
                                                            className="btn btn-secondary"
                                                            disabled={cfBusy}
                                                            onClick={async () => {
                                                                if (!window.confirm(t('cloudflare.syncAllConfirm', 'Переписать A-записи всех доменов, чьи зоны есть в Cloudflare, на текущий IP сервера?'))) return;
                                                                setCfBusy(true); setCfMessage(null);
                                                                try {
                                                                    const res = await axios.post(`${API_URL}?action=cloudflare_sync_all`, {});
                                                                    if (res.data.status === 'success') {
                                                                        const d = res.data.data;
                                                                        setCfMessage(`✓ ${t('cloudflare.syncedCount', 'перепарковано')}: ${d.synced.length}` + (d.failed.length ? ` · ⚠ ${d.failed.length}: ${d.failed.slice(0, 3).join('; ')}` : ''));
                                                                        const st = await axios.get(`${API_URL}?action=cloudflare_status`);
                                                                        if (st.data.status === 'success') setCfStatus(st.data.data);
                                                                    } else {
                                                                        setCfMessage('⚠ ' + (res.data.message || t('common.error')));
                                                                    }
                                                                } catch (err) { setCfMessage('⚠ ' + t('common.networkError')); }
                                                                finally { setCfBusy(false); }
                                                            }}
                                                        >
                                                            <RefreshCw className="w-4 h-4" />
                                                            {t('cloudflare.syncAll', 'Перепарковать все домены')}
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                            {cfMessage && <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{cfMessage}</p>}
                                        </div>
                                    </div>

                                    {cfStatus?.connected && (
                                        <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('cloudflare.managedDomains', 'Доменов под управлением Cloudflare')}: {cfStatus.managed_domains}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ) : activeObj.isNamecheap ? (
                            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                                <div style={{ maxWidth: '620px', display: 'flex', flexDirection: 'column', gap: '20px' }}>
                                    <div style={{ background: 'var(--color-bg-card)', borderRadius: '12px', padding: '24px', border: '1px solid var(--color-border)' }}>
                                        <p className="text-xs" style={{ color: 'var(--color-text-secondary)', marginBottom: '16px', lineHeight: 1.6 }}>
                                            {t('namecheap.howTo', 'Включите API-доступ: Namecheap → Profile → Tools → Business & Dev Tools → Namecheap API Access → Manage → Toggle API Access. Исходящий IP сервера добавьте в Whitelisted IPs. Домены, зарегистрированные в аккаунте, при парковке получают A-запись автоматически; SSL Let\'s Encrypt выпускается сразу после того, как DNS обновится.')}
                                        </p>

                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('namecheap.username', 'Username')} {ncStatus?.connected ? '✓' : ''}
                                                </label>
                                                <input
                                                    type="text"
                                                    className="form-input"
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px' }}
                                                    value={ncForm.username}
                                                    onChange={e => setNcForm({ ...ncForm, username: e.target.value })}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    API Key
                                                </label>
                                                <input
                                                    type="password"
                                                    className="form-input"
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                    value={ncForm.api_key}
                                                    onChange={e => setNcForm({ ...ncForm, api_key: e.target.value })}
                                                    placeholder={ncStatus?.connected ? t('namecheap.keySaved', 'ключ сохранён — введите новый, чтобы заменить') : ''}
                                                />
                                            </div>

                                            {/* Whitelisted IP: Namecheap rejects calls from unlisted IPs, and the
                                                real outgoing address usually differs from what the admin expects. */}
                                            <div style={{ padding: '12px', borderRadius: '8px', border: '1px dashed var(--color-border)', background: 'var(--color-bg-soft)' }}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <div>
                                                        <div style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)' }}>
                                                            {t('namecheap.whitelistIp', 'IP сервера для Whitelisted IPs')}
                                                        </div>
                                                        <div className="font-mono" style={{ fontSize: '15px', color: 'var(--color-text-primary)' }}>
                                                            {ncStatus?.detected_ip || ncStatus?.server_ip || '—'}
                                                        </div>
                                                    </div>
                                                    <button
                                                        className="btn btn-secondary btn-icon"
                                                        onClick={() => copyToClipboard(ncStatus?.detected_ip || ncStatus?.server_ip || '', 'nc-ip')}
                                                        title={t('common.copy')}
                                                    >
                                                        {copied === 'nc-ip' ? <CheckCircle2 className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                                                    </button>
                                                </div>
                                                <p className="text-xs" style={{ margin: '8px 0 0', color: 'var(--color-text-muted)' }}>
                                                    {t('namecheap.whitelistHint', 'Добавьте этот IP в Namecheap: Profile → Tools → Business & Dev Tools → Namecheap API Access → Manage → Whitelisted IPs. Если показан неверный адрес — нажмите «Проверить»: Namecheap сам назовёт IP, с которого пришли запросы.')}
                                                </p>
                                            </div>

                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('namecheap.addressBook', 'Профиль контакта (Address Book) для новых доменов')}
                                                </label>
                                                <div className="flex gap-2">
                                                    <select
                                                        className="form-select"
                                                        style={{ flex: 1, padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px' }}
                                                        value={ncForm.address_id}
                                                        onChange={e => setNcForm({ ...ncForm, address_id: e.target.value })}
                                                    >
                                                        <option value="">{t('namecheap.addressPlaceholder', '— не выбран —')}</option>
                                                        {ncAddresses.map(a => (
                                                            <option key={a.id} value={a.id}>{a.name}{a.is_default ? ' ✓' : ''}</option>
                                                        ))}
                                                    </select>
                                                    <button
                                                        className="btn btn-secondary btn-icon"
                                                        disabled={ncBusy}
                                                        onClick={async () => {
                                                            setNcBusy(true);
                                                            try {
                                                                const res = await axios.post(`${API_URL}?action=namecheap_addresses`, {});
                                                                if (res.data.status === 'success') {
                                                                    setNcAddresses(res.data.data.addresses || []);
                                                                    setNcMessage('✓ ' + t('namecheap.addressesLoaded', 'профили загружены'));
                                                                } else setNcMessage('⚠ ' + (res.data.message || t('common.error')));
                                                            } catch (err) { setNcMessage('⚠ ' + t('common.networkError')); }
                                                            finally { setNcBusy(false); }
                                                        }}
                                                        title={t('namecheap.addressRefresh', 'Обновить список из Address Book')}
                                                    >
                                                        <RefreshCw className="w-4 h-4" />
                                                    </button>
                                                </div>
                                                {!ncAddresses.length && (
                                                    <p className="text-xs" style={{ margin: '6px 0 0', color: 'var(--color-text-muted)' }}>
                                                        {t('namecheap.addressHint', 'Профили подтягиваются из Namecheap Address Book — они нужны для регистрации новых доменов.')}
                                                    </p>
                                                )}
                                            </div>

                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('namecheap.environment', 'Окружение')}
                                                </label>
                                                <div className="flex gap-4">
                                                    <label className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                                        <input type="radio" name="nc-env" checked={!ncForm.sandbox} onChange={() => setNcForm({ ...ncForm, sandbox: false })} />
                                                        {t('namecheap.production', 'Production')}
                                                    </label>
                                                    <label className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                                        <input type="radio" name="nc-env" checked={ncForm.sandbox} onChange={() => setNcForm({ ...ncForm, sandbox: true })} />
                                                        {t('namecheap.sandbox', 'Sandbox')}
                                                    </label>
                                                </div>
                                            </div>

                                            <div className="flex gap-2" style={{ marginTop: '4px' }}>
                                                <button
                                                    className="btn btn-primary"
                                                    disabled={ncBusy}
                                                    onClick={async () => {
                                                        setNcBusy(true); setNcMessage(null);
                                                        try {
                                                            const res = await axios.post(`${API_URL}?action=namecheap_save`, ncForm);
                                                            if (res.data.status === 'success') {
                                                                setNcMessage('✓ ' + t('namecheap.saved', 'Сохранено'));
                                                                const st = await axios.get(`${API_URL}?action=namecheap_status`);
                                                                if (st.data.status === 'success') setNcStatus(st.data.data);
                                                                setNcForm(f => ({ ...f, api_key: '' }));
                                                            } else if (res.data.message === 'namecheap_connection_failed') {
                                                                setNcMessage('⚠ ' + t('namecheap.errConnection', 'Namecheap отклонил подключение') + (res.data.detail?.error ? `: ${res.data.detail.error}` : ''));
                                                                if (res.data.detail?.ip) {
                                                                    setNcStatus(s => s ? { ...s, detected_ip: res.data.detail.ip } : s);
                                                                }
                                                            } else {
                                                                setNcMessage('⚠ ' + (res.data.message || t('common.error')));
                                                            }
                                                        } catch (err) { setNcMessage('⚠ ' + t('common.networkError')); }
                                                        finally { setNcBusy(false); }
                                                    }}
                                                >
                                                    {ncBusy ? t('common.saving') : t('common.save')}
                                                </button>
                                                {ncStatus?.connected && (
                                                    <button
                                                        className="btn btn-secondary"
                                                        disabled={ncBusy}
                                                        onClick={async () => {
                                                            setNcBusy(true); setNcMessage(null);
                                                            try {
                                                                const res = await axios.post(`${API_URL}?action=namecheap_test`, {});
                                                                if (res.data.status === 'success') {
                                                                    setNcMessage('✓ ' + t('namecheap.connected', 'Подключено') + (res.data.data?.balance ? ` · ${t('namecheap.balance', 'баланс')}: ${res.data.data.balance}` : ''));
                                                                } else {
                                                                    const st = await axios.get(`${API_URL}?action=namecheap_status`);
                                                                    if (st.data.status === 'success') setNcStatus(st.data.data);
                                                                    setNcMessage('⚠ ' + (res.data.message || t('common.error')));
                                                                }
                                                            } catch (err) { setNcMessage('⚠ ' + t('common.networkError')); }
                                                            finally { setNcBusy(false); }
                                                        }}
                                                    >
                                                        <Zap className="w-4 h-4" />
                                                        {t('namecheap.test', 'Проверить')}
                                                    </button>
                                                )}
                                            </div>
                                            {ncMessage && <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{ncMessage}</p>}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : activeObj.isRecaptcha ? (
                            <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
                                <div style={{ maxWidth: '600px', display: 'flex', flexDirection: 'column', gap: '24px' }}>
                                    {/* v2 section */}
                                    <div style={{ background: 'var(--color-bg-card)', borderRadius: '12px', padding: '24px', border: '1px solid var(--color-border)' }}>
                                        <div style={{ marginBottom: '16px' }}>
                                            <h3 style={{ fontSize: '16px', fontWeight: '600', color: 'var(--color-text-primary)', marginBottom: '4px' }}>
                                                {t('recaptcha.v2Title')}
                                            </h3>
                                            <p style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>{t('recaptcha.v2Desc')}</p>
                                        </div>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.siteKey')}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={rcSettings.recaptcha_v2_site_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, recaptcha_v2_site_key: e.target.value }))}
                                                    placeholder={t('recaptcha.siteKeyPlaceholder')}
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.secretKey')}
                                                </label>
                                                <input
                                                    type="password"
                                                    value={rcSettings.recaptcha_v2_secret_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, recaptcha_v2_secret_key: e.target.value }))}
                                                    placeholder={t('recaptcha.secretKeyPlaceholder')}
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {/* v3 section */}
                                    <div style={{ background: 'var(--color-bg-card)', borderRadius: '12px', padding: '24px', border: '1px solid var(--color-border)' }}>
                                        <div style={{ marginBottom: '16px' }}>
                                            <h3 style={{ fontSize: '16px', fontWeight: '600', color: 'var(--color-text-primary)', marginBottom: '4px' }}>
                                                {t('recaptcha.v3Title')}
                                            </h3>
                                            <p style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>{t('recaptcha.v3Desc')}</p>
                                        </div>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.siteKey')}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={rcSettings.recaptcha_v3_site_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, recaptcha_v3_site_key: e.target.value }))}
                                                    placeholder={t('recaptcha.siteKeyPlaceholder')}
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.secretKey')}
                                                </label>
                                                <input
                                                    type="password"
                                                    value={rcSettings.recaptcha_v3_secret_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, recaptcha_v3_secret_key: e.target.value }))}
                                                    placeholder={t('recaptcha.secretKeyPlaceholder')}
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.v3Threshold')}
                                                </label>
                                                <input
                                                    type="number"
                                                    min="0" max="1" step="0.1"
                                                    value={rcSettings.recaptcha_v3_threshold}
                                                    onChange={e => setRcSettings(s => ({ ...s, recaptcha_v3_threshold: e.target.value }))}
                                                    style={{ width: '160px', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px' }}
                                                />
                                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>{t('recaptcha.v3ThresholdDesc')}</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Cloudflare Turnstile section */}
                                    <div style={{ background: 'var(--color-bg-card)', borderRadius: '12px', padding: '24px', border: '1px solid var(--color-border)' }}>
                                        <div style={{ marginBottom: '16px' }}>
                                            <h3 style={{ fontSize: '16px', fontWeight: '600', color: 'var(--color-text-primary)', marginBottom: '4px' }}>
                                                {t('recaptcha.turnstileTitle')}
                                            </h3>
                                            <p style={{ fontSize: '13px', color: 'var(--color-text-muted)' }}>{t('recaptcha.turnstileDesc')}</p>
                                        </div>
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.siteKey')}
                                                </label>
                                                <input
                                                    type="text"
                                                    value={rcSettings.turnstile_site_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, turnstile_site_key: e.target.value }))}
                                                    placeholder="0x4AAAAAAA..."
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                            <div>
                                                <label style={{ fontSize: '13px', fontWeight: '500', color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                                                    {t('recaptcha.secretKey')}
                                                </label>
                                                <input
                                                    type="password"
                                                    value={rcSettings.turnstile_secret_key}
                                                    onChange={e => setRcSettings(s => ({ ...s, turnstile_secret_key: e.target.value }))}
                                                    placeholder="0x4AAAAAAA..."
                                                    style={{ width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid var(--color-border)', background: 'var(--color-bg-input)', color: 'var(--color-text-primary)', fontSize: '14px', fontFamily: 'monospace' }}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {/* Admin console link + save */}
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
                                        <button
                                            onClick={saveRcSettings}
                                            disabled={rcSaving}
                                            style={{ padding: '10px 24px', background: 'var(--color-primary)', color: '#fff', border: 'none', borderRadius: '8px', fontWeight: '600', fontSize: '14px', cursor: 'pointer' }}
                                        >
                                            {rcSaving ? t('common.saving') : t('common.save')}
                                        </button>
                                        <a
                                            href="https://www.google.com/recaptcha/admin"
                                            target="_blank"
                                            rel="noreferrer"
                                            style={{ fontSize: '13px', color: 'var(--color-primary)', textDecoration: 'none', display: 'flex', alignItems: 'center', gap: '4px' }}
                                        >
                                            {t('recaptcha.adminConsoleLink')} ↗
                                        </a>
                                    </div>
                                    {rcMessage && (
                                        <div style={{ padding: '12px 16px', borderRadius: '8px', fontSize: '14px',
                                            background: rcMessage.type === 'success' ? 'var(--color-success-bg, #dcfce7)' : 'var(--color-error-bg, #fee2e2)',
                                            color: rcMessage.type === 'success' ? 'var(--color-success, #16a34a)' : 'var(--color-error, #dc2626)' }}
                                        >
                                            {rcMessage.text}
                                        </div>
                                    )}
                                </div>
                            </div>
                        ) : activeObj.isTelegram ? renderTelegramPanel() : activeObj.isAppConfig ? renderAppConfigPanel() : activeObj.isPixelVault ? renderPixelVaultPanel() : activeObj.isFacebookCosts ? renderFacebookCostsPanel() : activeObj.isTikTokCosts ? renderTikTokCostsPanel() : activeObj.isGoogleAdsCosts ? renderGoogleAdsCostsPanel() : activeObj.isFacebookConversions ? renderFacebookConversionsPanel() : activeObj.isChromeExtension ? renderChromeExtensionPanel() : activeObj.isWpPlugin ? renderWpPluginPanel() : (
                            <div style={{ padding: '20px 24px', flex: 1, display: 'flex', flexDirection: 'column' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                                    <span style={{ fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>
                                        {t('integrations.codeToInsert')}
                                    </span>
                                    <button
                                        onClick={() => copyToClipboard(activeObj.code, activeTab)}
                                        className="btn btn-secondary btn-sm"
                                    >
                                        {copied === activeTab ? (
                                            <>
                                                <CheckCircle2 size={16} style={{ color: 'var(--color-success)' }} />
                                                <span style={{ color: 'var(--color-success)' }}>{t('integrations.copied')}</span>
                                            </>
                                        ) : (
                                            <>
                                                <Copy size={16} />
                                                <span>{t('integrations.copyCode')}</span>
                                            </>
                                        )}
                                    </button>
                                </div>

                                {/* Code Editor */}
                                <div style={{
                                    flex: 1,
                                    minHeight: '280px',
                                    background: '#1e1e2e',
                                    borderRadius: '16px',
                                    overflow: 'hidden',
                                    boxShadow: 'inset 0 2px 8px rgba(0,0,0,0.3)'
                                }}>
                                    {/* Editor Header */}
                                    <div style={{
                                        height: '36px',
                                        background: '#2d2d3f',
                                        display: 'flex',
                                        alignItems: 'center',
                                        padding: '0 16px'
                                    }}>
                                        <div style={{ width: '12px', height: '12px', borderRadius: '50%', background: '#ff5f57', marginRight: '8px' }} />
                                        <div style={{ width: '12px', height: '12px', borderRadius: '50%', background: '#febc2e', marginRight: '8px' }} />
                                        <div style={{ width: '12px', height: '12px', borderRadius: '50%', background: '#28c840' }} />
                                    </div>

                                    {/* Code Content */}
                                    <pre style={{
                                        padding: '16px 20px',
                                        margin: 0,
                                        overflow: 'auto',
                                        height: 'calc(100% - 36px)',
                                        fontSize: '13px',
                                        fontFamily: "'JetBrains Mono', 'Fira Code', monospace",
                                        color: '#cdd6f4',
                                        lineHeight: 1.6
                                    }}>
                                        <code>{activeObj.code}</code>
                                    </pre>
                                </div>

                                {/* Warning for KClient PHP */}
                                {activeTab === 'kclient_php' && (
                                    <div style={{
                                        marginTop: '16px',
                                        padding: '12px 16px',
                                        background: 'var(--color-warning-bg)',
                                        border: '1px solid var(--color-warning)',
                                        borderRadius: '12px',
                                        display: 'flex',
                                        alignItems: 'flex-start',
                                        gap: '10px'
                                    }}>
                                        <Server size={18} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: '2px' }} />
                                        <div style={{ fontSize: '14px', color: '#92400e' }}>
                                            <strong>{t('integrations.important')}</strong> {t('integrations.phpWarning')}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default IntegrationsPage;
