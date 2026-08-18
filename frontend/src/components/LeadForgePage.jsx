import React, { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';
import {
    Zap, Upload, FileArchive, CheckCircle2, AlertCircle, AlertTriangle, Download,
    Layers, Globe, RefreshCw, Terminal, Sliders, ArrowRight, X, ScanSearch, Rocket,
    Wifi, ShieldCheck, Scissors, Repeat, Tag, Link2, Plus, Check, PackageX
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { invalidateCache } from '../utils/apiCache';

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

// Must stay in sync with core/LeadForge.php::geoMasks() — the backend owns
// the actual phone masks; this list only feeds the GEO picker (regions, flags,
// labels). A GEO missing from the backend map falls back to a generic mask.
const GEO_REGIONS = [
    { id: 'europe', labelKey: 'leadforge.geoEurope', fallback: 'Europe' },
    { id: 'americas', labelKey: 'leadforge.geoAmericas', fallback: 'Americas' },
    { id: 'asia', labelKey: 'leadforge.geoAsia', fallback: 'Asia' },
    { id: 'mena', labelKey: 'leadforge.geoMena', fallback: 'MENA & Africa' },
];

const GEO_PRESETS = [
    // Europe
    { code: 'IT', region: 'europe', name: 'Italy (+39)', flag: '🇮🇹' },
    { code: 'ES', region: 'europe', name: 'Spain (+34)', flag: '🇪🇸' },
    { code: 'DE', region: 'europe', name: 'Germany (+49)', flag: '🇩🇪' },
    { code: 'FR', region: 'europe', name: 'France (+33)', flag: '🇫🇷' },
    { code: 'PL', region: 'europe', name: 'Poland (+48)', flag: '🇵🇱' },
    { code: 'RO', region: 'europe', name: 'Romania (+40)', flag: '🇷🇴' },
    { code: 'GR', region: 'europe', name: 'Greece (+30)', flag: '🇬🇷' },
    { code: 'GB', region: 'europe', name: 'United Kingdom (+44)', flag: '🇬🇧' },
    { code: 'PT', region: 'europe', name: 'Portugal (+351)', flag: '🇵🇹' },
    { code: 'NL', region: 'europe', name: 'Netherlands (+31)', flag: '🇳🇱' },
    { code: 'BE', region: 'europe', name: 'Belgium (+32)', flag: '🇧🇪' },
    { code: 'AT', region: 'europe', name: 'Austria (+43)', flag: '🇦🇹' },
    { code: 'CH', region: 'europe', name: 'Switzerland (+41)', flag: '🇨🇭' },
    { code: 'CZ', region: 'europe', name: 'Czechia (+420)', flag: '🇨🇿' },
    { code: 'SK', region: 'europe', name: 'Slovakia (+421)', flag: '🇸🇰' },
    { code: 'HU', region: 'europe', name: 'Hungary (+36)', flag: '🇭🇺' },
    { code: 'BG', region: 'europe', name: 'Bulgaria (+359)', flag: '🇧🇬' },
    { code: 'RS', region: 'europe', name: 'Serbia (+381)', flag: '🇷🇸' },
    { code: 'HR', region: 'europe', name: 'Croatia (+385)', flag: '🇭🇷' },
    { code: 'SI', region: 'europe', name: 'Slovenia (+386)', flag: '🇸🇮' },
    { code: 'LT', region: 'europe', name: 'Lithuania (+370)', flag: '🇱🇹' },
    { code: 'LV', region: 'europe', name: 'Latvia (+371)', flag: '🇱🇻' },
    { code: 'EE', region: 'europe', name: 'Estonia (+372)', flag: '🇪🇪' },
    { code: 'DK', region: 'europe', name: 'Denmark (+45)', flag: '🇩🇰' },
    { code: 'SE', region: 'europe', name: 'Sweden (+46)', flag: '🇸🇪' },
    { code: 'NO', region: 'europe', name: 'Norway (+47)', flag: '🇳🇴' },
    { code: 'FI', region: 'europe', name: 'Finland (+358)', flag: '🇫🇮' },
    { code: 'IE', region: 'europe', name: 'Ireland (+353)', flag: '🇮🇪' },
    { code: 'CY', region: 'europe', name: 'Cyprus (+357)', flag: '🇨🇾' },
    { code: 'MD', region: 'europe', name: 'Moldova (+373)', flag: '🇲🇩' },
    { code: 'BY', region: 'europe', name: 'Belarus (+375)', flag: '🇧🇾' },
    { code: 'TR', region: 'europe', name: 'Türkiye (+90)', flag: '🇹🇷' },
    { code: 'UA', region: 'europe', name: 'Ukraine (+380)', flag: '🇺🇦' },
    { code: 'RU', region: 'europe', name: 'Russia (+7)', flag: '🇷🇺' },
    { code: 'AL', region: 'europe', name: 'Albania (+355)', flag: '🇦🇱' },
    { code: 'BA', region: 'europe', name: 'Bosnia and Herzegovina (+387)', flag: '🇧🇦' },
    { code: 'IS', region: 'europe', name: 'Iceland (+354)', flag: '🇮🇸' },
    { code: 'LU', region: 'europe', name: 'Luxembourg (+352)', flag: '🇱🇺' },
    { code: 'MT', region: 'europe', name: 'Malta (+356)', flag: '🇲🇹' },
    { code: 'ME', region: 'europe', name: 'Montenegro (+382)', flag: '🇲🇪' },
    { code: 'MK', region: 'europe', name: 'North Macedonia (+389)', flag: '🇲🇰' },
    // Americas
    { code: 'US', region: 'americas', name: 'United States (+1)', flag: '🇺🇸' },
    { code: 'MX', region: 'americas', name: 'Mexico (+52)', flag: '🇲🇽' },
    { code: 'CO', region: 'americas', name: 'Colombia (+57)', flag: '🇨🇴' },
    { code: 'BR', region: 'americas', name: 'Brazil (+55)', flag: '🇧🇷' },
    { code: 'AR', region: 'americas', name: 'Argentina (+54)', flag: '🇦🇷' },
    { code: 'CL', region: 'americas', name: 'Chile (+56)', flag: '🇨🇱' },
    { code: 'PE', region: 'americas', name: 'Peru (+51)', flag: '🇵🇪' },
    { code: 'EC', region: 'americas', name: 'Ecuador (+593)', flag: '🇪🇨' },
    { code: 'VE', region: 'americas', name: 'Venezuela (+58)', flag: '🇻🇪' },
    { code: 'UY', region: 'americas', name: 'Uruguay (+598)', flag: '🇺🇾' },
    { code: 'PY', region: 'americas', name: 'Paraguay (+595)', flag: '🇵🇾' },
    { code: 'BO', region: 'americas', name: 'Bolivia (+591)', flag: '🇧🇴' },
    { code: 'CR', region: 'americas', name: 'Costa Rica (+506)', flag: '🇨🇷' },
    { code: 'PA', region: 'americas', name: 'Panama (+507)', flag: '🇵🇦' },
    { code: 'GT', region: 'americas', name: 'Guatemala (+502)', flag: '🇬🇹' },
    { code: 'DO', region: 'americas', name: 'Dominican Rep. (+1)', flag: '🇩🇴' },
    { code: 'SV', region: 'americas', name: 'El Salvador (+503)', flag: '🇸🇻' },
    { code: 'HN', region: 'americas', name: 'Honduras (+504)', flag: '🇭🇳' },
    { code: 'NI', region: 'americas', name: 'Nicaragua (+505)', flag: '🇳🇮' },
    { code: 'CA', region: 'americas', name: 'Canada (+1)', flag: '🇨🇦' },
    { code: 'BZ', region: 'americas', name: 'Belize (+501)', flag: '🇧🇿' },
    { code: 'CU', region: 'americas', name: 'Cuba (+53)', flag: '🇨🇺' },
    // Asia & Oceania
    { code: 'KZ', region: 'asia', name: 'Kazakhstan (+7)', flag: '🇰🇿' },
    { code: 'UZ', region: 'asia', name: 'Uzbekistan (+998)', flag: '🇺🇿' },
    { code: 'ID', region: 'asia', name: 'Indonesia (+62)', flag: '🇮🇩' },
    { code: 'TH', region: 'asia', name: 'Thailand (+66)', flag: '🇹🇭' },
    { code: 'VN', region: 'asia', name: 'Vietnam (+84)', flag: '🇻🇳' },
    { code: 'MY', region: 'asia', name: 'Malaysia (+60)', flag: '🇲🇾' },
    { code: 'PH', region: 'asia', name: 'Philippines (+63)', flag: '🇵🇭' },
    { code: 'IN', region: 'asia', name: 'India (+91)', flag: '🇮🇳' },
    { code: 'KH', region: 'asia', name: 'Cambodia (+855)', flag: '🇰🇭' },
    { code: 'JP', region: 'asia', name: 'Japan (+81)', flag: '🇯🇵' },
    { code: 'KR', region: 'asia', name: 'South Korea (+82)', flag: '🇰🇷' },
    { code: 'CN', region: 'asia', name: 'China (+86)', flag: '🇨🇳' },
    { code: 'PK', region: 'asia', name: 'Pakistan (+92)', flag: '🇵🇰' },
    { code: 'BD', region: 'asia', name: 'Bangladesh (+880)', flag: '🇧🇩' },
    { code: 'AU', region: 'asia', name: 'Australia (+61)', flag: '🇦🇺' },
    { code: 'NZ', region: 'asia', name: 'New Zealand (+64)', flag: '🇳🇿' },
    { code: 'SG', region: 'asia', name: 'Singapore (+65)', flag: '🇸🇬' },
    { code: 'HK', region: 'asia', name: 'Hong Kong (+852)', flag: '🇭🇰' },
    { code: 'TW', region: 'asia', name: 'Taiwan (+886)', flag: '🇹🇼' },
    { code: 'FJ', region: 'asia', name: 'Fiji (+679)', flag: '🇫🇯' },
    { code: 'LA', region: 'asia', name: 'Laos (+856)', flag: '🇱🇦' },
    { code: 'MO', region: 'asia', name: 'Macau (+853)', flag: '🇲🇴' },
    { code: 'MN', region: 'asia', name: 'Mongolia (+976)', flag: '🇲🇳' },
    { code: 'MM', region: 'asia', name: 'Myanmar (+95)', flag: '🇲🇲' },
    { code: 'NP', region: 'asia', name: 'Nepal (+977)', flag: '🇳🇵' },
    { code: 'PG', region: 'asia', name: 'Papua New Guinea (+675)', flag: '🇵🇬' },
    { code: 'WS', region: 'asia', name: 'Samoa (+685)', flag: '🇼🇸' },
    { code: 'SB', region: 'asia', name: 'Solomon Islands (+677)', flag: '🇸🇧' },
    { code: 'LK', region: 'asia', name: 'Sri Lanka (+94)', flag: '🇱🇰' },
    { code: 'TO', region: 'asia', name: 'Tonga (+676)', flag: '🇹🇴' },
    { code: 'VU', region: 'asia', name: 'Vanuatu (+678)', flag: '🇻🇺' },
    // MENA & Africa
    { code: 'MA', region: 'mena', name: 'Morocco (+212)', flag: '🇲🇦' },
    { code: 'DZ', region: 'mena', name: 'Algeria (+213)', flag: '🇩🇿' },
    { code: 'TN', region: 'mena', name: 'Tunisia (+216)', flag: '🇹🇳' },
    { code: 'EG', region: 'mena', name: 'Egypt (+20)', flag: '🇪🇬' },
    { code: 'ZA', region: 'mena', name: 'South Africa (+27)', flag: '🇿🇦' },
    { code: 'NG', region: 'mena', name: 'Nigeria (+234)', flag: '🇳🇬' },
    { code: 'KE', region: 'mena', name: 'Kenya (+254)', flag: '🇰🇪' },
    { code: 'GH', region: 'mena', name: 'Ghana (+233)', flag: '🇬🇭' },
    { code: 'SN', region: 'mena', name: 'Senegal (+221)', flag: '🇸🇳' },
    { code: 'CI', region: 'mena', name: 'Ivory Coast (+225)', flag: '🇨🇮' },
    { code: 'SA', region: 'mena', name: 'Saudi Arabia (+966)', flag: '🇸🇦' },
    { code: 'AE', region: 'mena', name: 'UAE (+971)', flag: '🇦🇪' },
    { code: 'IL', region: 'mena', name: 'Israel (+972)', flag: '🇮🇱' },
    { code: 'IQ', region: 'mena', name: 'Iraq (+964)', flag: '🇮🇶' },
    { code: 'JO', region: 'mena', name: 'Jordan (+962)', flag: '🇯🇴' },
    { code: 'KW', region: 'mena', name: 'Kuwait (+965)', flag: '🇰🇼' },
    { code: 'LB', region: 'mena', name: 'Lebanon (+961)', flag: '🇱🇧' },
    { code: 'LY', region: 'mena', name: 'Libya (+218)', flag: '🇱🇾' },
    { code: 'OM', region: 'mena', name: 'Oman (+968)', flag: '🇴🇲' },
    { code: 'PS', region: 'mena', name: 'Palestine (+970)', flag: '🇵🇸' },
    { code: 'QA', region: 'mena', name: 'Qatar (+974)', flag: '🇶🇦' },
    { code: 'AO', region: 'mena', name: 'Angola (+244)', flag: '🇦🇴' },
    { code: 'BH', region: 'mena', name: 'Bahrain (+973)', flag: '🇧🇭' },
    { code: 'BJ', region: 'mena', name: 'Benin (+229)', flag: '🇧🇯' },
    { code: 'BW', region: 'mena', name: 'Botswana (+267)', flag: '🇧🇼' },
    { code: 'CM', region: 'mena', name: 'Cameroon (+237)', flag: '🇨🇲' },
    { code: 'CG', region: 'mena', name: 'Congo (+242)', flag: '🇨🇬' },
    { code: 'CD', region: 'mena', name: 'Congo (DRC) (+243)', flag: '🇨🇩' },
    { code: 'SZ', region: 'mena', name: 'Eswatini (+268)', flag: '🇸🇿' },
    { code: 'ET', region: 'mena', name: 'Ethiopia (+251)', flag: '🇪🇹' },
    { code: 'GA', region: 'mena', name: 'Gabon (+241)', flag: '🇬🇦' },
    { code: 'GM', region: 'mena', name: 'Gambia (+220)', flag: '🇬🇲' },
    { code: 'GE', region: 'mena', name: 'Georgia (+995)', flag: '🇬🇪' },
    { code: 'GN', region: 'mena', name: 'Guinea (+224)', flag: '🇬🇳' },
    { code: 'LS', region: 'mena', name: 'Lesotho (+266)', flag: '🇱🇸' },
    { code: 'LR', region: 'mena', name: 'Liberia (+231)', flag: '🇱🇷' },
    { code: 'MG', region: 'mena', name: 'Madagascar (+261)', flag: '🇲🇬' },
    { code: 'MW', region: 'mena', name: 'Malawi (+265)', flag: '🇲🇼' },
    { code: 'ML', region: 'mena', name: 'Mali (+223)', flag: '🇲🇱' },
    { code: 'MR', region: 'mena', name: 'Mauritania (+222)', flag: '🇲🇷' },
    { code: 'MU', region: 'mena', name: 'Mauritius (+230)', flag: '🇲🇺' },
    { code: 'MZ', region: 'mena', name: 'Mozambique (+258)', flag: '🇲🇿' },
    { code: 'NA', region: 'mena', name: 'Namibia (+264)', flag: '🇳🇦' },
    { code: 'NE', region: 'mena', name: 'Niger (+227)', flag: '🇳🇪' },
    { code: 'RW', region: 'mena', name: 'Rwanda (+250)', flag: '🇷🇼' },
    { code: 'SC', region: 'mena', name: 'Seychelles (+248)', flag: '🇸🇨' },
    { code: 'SL', region: 'mena', name: 'Sierra Leone (+232)', flag: '🇸🇱' },
    { code: 'SO', region: 'mena', name: 'Somalia (+252)', flag: '🇸🇴' },
    { code: 'SS', region: 'mena', name: 'South Sudan (+211)', flag: '🇸🇸' },
    { code: 'SD', region: 'mena', name: 'Sudan (+249)', flag: '🇸🇩' },
    { code: 'TZ', region: 'mena', name: 'Tanzania (+255)', flag: '🇹🇿' },
    { code: 'TG', region: 'mena', name: 'Togo (+228)', flag: '🇹🇬' },
    { code: 'UG', region: 'mena', name: 'Uganda (+256)', flag: '🇺🇬' },
    { code: 'YE', region: 'mena', name: 'Yemen (+967)', flag: '🇾🇪' },
    { code: 'ZM', region: 'mena', name: 'Zambia (+260)', flag: '🇿🇲' },
    { code: 'ZW', region: 'mena', name: 'Zimbabwe (+263)', flag: '🇿🇼' },
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
    // Payout is opt-in: by default nothing is hardcoded into the landing and
    // the real revenue comes from the network's S2S postback (postback.php
    // upserts payout on the existing conversion).
    const [useCustomPayout, setUseCustomPayout] = useState(false);
    const [payout, setPayout] = useState('');
    const [selectedGroupId, setSelectedGroupId] = useState('');
    const [selectedOfferGroupId, setSelectedOfferGroupId] = useState('');
    const [offerGroups, setOfferGroups] = useState([]);

    // Where the built bundle lands in the tracker: 'none' | 'lander' | 'offer' | 'both'
    const [destType, setDestType] = useState('lander');

    // Inline "+ New group" creation
    const [showNewGroup, setShowNewGroup] = useState(false);
    const [newGroupName, setNewGroupName] = useState('');
    const [creatingGroup, setCreatingGroup] = useState(false);

    // Toggles (per ТЗ: CRM Sync + Auto QA drive the build)
    const [crmEnabled, setCrmEnabled] = useState(true);
    const [autoQa, setAutoQa] = useState(true);

    // PHP landings gate: order.php/thank_you.php builds fail hard while the
    // tracker setting is off. null = preflight not answered yet — no banner.
    const [phpLandingsEnabled, setPhpLandingsEnabled] = useState(null);
    const [phpEnabling, setPhpEnabling] = useState(false);

    const [options, setOptions] = useState({
        injectOfferMacro: true,
        injectJsAdapter: true,
        addPhoneMask: true,
        generateThankYou: true,
        generateOrderPhp: true
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
        axios.get(`${API_URL}?action=offer_groups`)
            .then(res => {
                if (res.data?.status === 'success') {
                    setOfferGroups(res.data.data || []);
                }
            })
            .catch(() => {});
        axios.get(`${API_URL}?action=global_settings`)
            .then(res => {
                if (res.data?.status === 'success') {
                    // Missing row = the on-by-default backend semantics (v1.0.4).
                    setPhpLandingsEnabled(String(res.data.data?.allow_php_landings ?? '1') === '1');
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

    // One-click enable from the warning banner. The POST is admin-only on the
    // server and silently skips for other roles, so the banner state comes from
    // a re-read, not from the 200.
    const handleQuickEnablePhp = async () => {
        setPhpEnabling(true);
        try {
            const res = await axios.post(`${API_URL}?action=global_settings`, { settings: { allow_php_landings: '1' } });
            if (res.data?.status !== 'success') {
                addLog(`❌ ${res.data?.message || 'Failed to save settings'}`, 'error');
                return;
            }
            const check = await axios.get(`${API_URL}?action=global_settings`);
            const nowEnabled = String(check.data?.data?.allow_php_landings) === '1';
            setPhpLandingsEnabled(nowEnabled);
            addLog(nowEnabled
                ? t('leadforge.phpEnabledLog', 'PHP landings enabled — rebuild the bundles')
                : t('leadforge.phpEnableDenied', 'Could not enable PHP landings: an admin session is required (Settings → General).'),
                nowEnabled ? 'success' : 'error');
        } catch (err) {
            addLog(`❌ ${err.response?.data?.message || err.message}`, 'error');
        } finally {
            setPhpEnabling(false);
        }
    };

    // ---- Tracker destination & groups -------------------------------------
    const isOfferDest = destType === 'offer';
    const destGroups = isOfferDest ? offerGroups : landingGroups;
    const destGroupId = isOfferDest ? selectedOfferGroupId : selectedGroupId;
    const setDestGroupId = isOfferDest ? setSelectedOfferGroupId : setSelectedGroupId;

    const handleCreateGroup = async () => {
        const name = newGroupName.trim();
        if (!name || creatingGroup) return;
        setCreatingGroup(true);
        const action = isOfferDest ? 'offer_groups' : 'landing_groups';
        const applyFound = (g) => {
            setDestGroupId(String(g.id));
            setNewGroupName('');
            setShowNewGroup(false);
        };
        try {
            const res = await axios.post(`${API_URL}?action=${action}`, { name });
            if (res.data?.status === 'success') {
                const created = { id: Number(res.data.data.id), name };
                const merge = (list) => [...list.filter(g => g.id !== created.id), created]
                    .sort((a, b) => a.name.localeCompare(b.name));
                if (isOfferDest) setOfferGroups(merge); else setLandingGroups(merge);
                applyFound(created);
                addLog(t('leadforge.logGroupCreated', `📁 ${isOfferDest ? 'Offer' : 'Landing'} group "${name}" created`, { name }), 'success');
            } else {
                // Most likely a duplicate — re-fetch and select the existing one.
                try {
                    const list = await axios.get(`${API_URL}?action=${action}`);
                    const found = (list.data?.data || []).find(g => g.name === name);
                    if (found) {
                        if (isOfferDest) setOfferGroups(list.data.data || []); else setLandingGroups(list.data.data || []);
                        applyFound(found);
                        addLog(t('leadforge.logGroupExists', `📁 Group "${name}" already exists — selected it`, { name }), 'step');
                    } else {
                        addLog(`⚠️ ${res.data?.message || 'Group create failed'}`, 'error');
                    }
                } catch {
                    addLog(`⚠️ ${res.data?.message || 'Group create failed'}`, 'error');
                }
            }
        } catch (err) {
            addLog(`⚠️ ${err.response?.data?.message || err.message}`, 'error');
        } finally {
            setCreatingGroup(false);
        }
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
        fd.append('payout', useCustomPayout && payout !== '' ? String(parseFloat(payout) || 0) : '0');
        fd.append('currency', currency);
        if (destType === 'offer') {
            if (selectedOfferGroupId) fd.append('group_id', selectedOfferGroupId);
        } else if (destType !== 'none') {
            if (selectedGroupId) fd.append('group_id', selectedGroupId);
        }
        if (destType !== 'none') fd.append('target_type', destType);
        fd.append('crm_enabled', (!isRaw && crmEnabled) ? '1' : '0');
        fd.append('auto_qa', (!isRaw && autoQa) ? '1' : '0');
        fd.append('inject_offer_macro', options.injectOfferMacro ? '1' : '0');
        fd.append('inject_js_adapter', options.injectJsAdapter ? '1' : '0');
        fd.append('add_phone_mask', options.addPhoneMask ? '1' : '0');
        fd.append('generate_thank_you', (!isRaw && options.generateThankYou) ? '1' : '0');
        fd.append('generate_order_php', (!isRaw && options.generateOrderPhp) ? '1' : '0');
        // Legacy pair, kept in sync with destType for older API consumers.
        fd.append('auto_save_tracker', destType === 'lander' || destType === 'both' ? '1' : '0');
        fd.append('auto_create_offer', destType === 'both' ? '1' : '0');

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
                    const errorText = r.message === 'php_landings_disabled'
                        ? t('leadforge.phpDisabledHint', 'PHP landings are disabled — enable "Allow PHP landings" (banner above or Settings → General) and rebuild.')
                        : (r.message || 'Build failed');
                    addLog(`❌ ${names[r.token] || `Bundle ${r.token?.slice(0, 8)}…`}: ${errorText}`, 'error');
                }
                setBundles(prev => prev.map(b => (b.token === r.token
                    ? { ...b, status: r.ok ? 'built' : 'error', result: r.result, qa: r.qa, error: r.ok ? null : r.message }
                    : b)));
            });
            addLog(t('leadforge.logBuildDone', '🎉 Build pass finished. Landings are in the library, ready for campaigns.'), 'success');
            // The build may have created landings/offers; the campaign editor
            // caches those dropdown lists for 5 minutes — drop them so its
            // picker doesn't serve a list from before the build.
            invalidateCache('all_offers');
            invalidateCache('landings_simple');
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
        <div className="space-y-6 w-full pb-12">
            {/* PHP landings gate — order.php/thank_you.php cannot build without it */}
            {phpLandingsEnabled === false && (
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-4 rounded-2xl border"
                    style={{ background: 'var(--color-warning-bg)', borderColor: 'var(--color-warning)' }}>
                    <div className="flex items-start gap-3">
                        <AlertTriangle size={20} className="shrink-0 mt-0.5" style={{ color: 'var(--color-warning)' }} />
                        <div>
                            <div className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('leadforge.phpBannerTitle', 'PHP landings are disabled in tracker settings')}
                            </div>
                            <p className="text-xs mt-1 m-0 leading-relaxed" style={{ color: 'var(--color-text-secondary)' }}>
                                {t('leadforge.phpBannerHint', 'Compiling order.php for CPA networks (Dr.Cash, Leadbit…) requires PHP landings — every bundle build fails with php_landings_disabled while this is off.')}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={handleQuickEnablePhp}
                        disabled={phpEnabling}
                        className="btn-primary !py-2.5 !px-4 text-xs flex items-center gap-2 cursor-pointer shrink-0 disabled:opacity-50"
                    >
                        {phpEnabling ? <RefreshCw size={14} className="animate-spin" /> : <Zap size={14} />}
                        <span>{t('leadforge.phpEnableButton', 'Enable in 1 click')}</span>
                    </button>
                </div>
            )}

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
                                                    {b.result.landing_id ? (
                                                        <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 flex items-center gap-1">
                                                            <CheckCircle2 size={12} /> #{b.result.landing_id} · /lander/{b.result.slug}/
                                                        </span>
                                                    ) : b.result.offer_id && (
                                                        <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 flex items-center gap-1">
                                                            <Tag size={12} /> #{b.result.offer_id} · {t('leadforge.localOfferBadge', 'Local offer')}
                                                        </span>
                                                    )}
                                                    {b.result.landing_id && b.result.offer_id && (
                                                        <span className="px-2.5 py-1 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 flex items-center gap-1 border border-emerald-200 dark:border-emerald-900">
                                                            <Link2 size={12} /> {t('leadforge.linkedOfferBadge', 'Offer')} #{b.result.offer_id}
                                                        </span>
                                                    )}
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
                                            {GEO_REGIONS.map(region => (
                                                <optgroup key={region.id} label={t(region.labelKey, region.fallback)}>
                                                    {GEO_PRESETS.filter(geo => geo.region === region.id).map(geo => (
                                                        <option key={geo.code} value={geo.code}>{geo.flag} {geo.name}</option>
                                                    ))}
                                                </optgroup>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                                {t('leadforge.payout', 'Payout')}
                                            </label>
                                            <label className="flex items-center gap-1.5 text-xs cursor-pointer select-none" style={{ color: 'var(--color-text-secondary)' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={useCustomPayout}
                                                    onChange={(e) => setUseCustomPayout(e.target.checked)}
                                                    className="rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                                />
                                                {t('leadforge.overridePayout', 'Fixed payout')}
                                            </label>
                                        </div>
                                        <div className="flex gap-2">
                                            <input
                                                type="number"
                                                step="0.1"
                                                min="0"
                                                disabled={!useCustomPayout}
                                                value={useCustomPayout ? payout : ''}
                                                placeholder={useCustomPayout ? '0.00' : t('leadforge.autoFromPostback', 'Auto (S2S postback)')}
                                                onChange={(e) => setPayout(e.target.value)}
                                                className="w-full px-3 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] disabled:opacity-60 disabled:cursor-not-allowed"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            />
                                            <input
                                                type="text"
                                                disabled={!useCustomPayout}
                                                value={currency}
                                                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                                                className="w-16 px-2 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-bold text-center focus:outline-none disabled:opacity-60 disabled:cursor-not-allowed"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}

                        {/* Tracker destination: lander / direct local offer / both */}
                        <div className="space-y-3 pt-3 border-t border-[var(--color-border)]">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-[var(--color-text-muted)]">
                                {t('leadforge.destTitle', 'Tracker destination')}
                            </h4>
                            <div className="grid grid-cols-2 gap-2">
                                {[
                                    { id: 'none', icon: PackageX, label: t('leadforge.destNone', 'Not saved'), sub: t('leadforge.destNoneSub', 'ZIP only') },
                                    { id: 'lander', icon: Globe, label: t('leadforge.destLander', 'Lander'), sub: t('leadforge.destLanderSub', 'Landings') },
                                    { id: 'offer', icon: Tag, label: t('leadforge.destOffer', 'Local offer'), sub: t('leadforge.destOfferSub', 'Offers') },
                                    { id: 'both', icon: Link2, label: t('leadforge.destBoth', 'Lander + Offer'), sub: t('leadforge.destBothSub', 'Linked pair') },
                                ].map(d => {
                                    const Icon = d.icon;
                                    const active = destType === d.id;
                                    return (
                                        <button
                                            key={d.id}
                                            type="button"
                                            onClick={() => setDestType(d.id)}
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
                                            <div className="text-xs font-bold leading-none">{d.label}</div>
                                            <div className={`text-[10px] mt-0.5 leading-none ${active ? 'opacity-75' : 'text-[var(--color-text-muted)]'}`}>{d.sub}</div>
                                        </button>
                                    );
                                })}
                            </div>

                            {destType !== 'none' && (
                                <div className="space-y-2">
                                    <label className="block text-xs font-semibold" style={{ color: 'var(--color-text-secondary)' }}>
                                        {isOfferDest
                                            ? t('leadforge.offerGroup', 'Offer Group')
                                            : t('leadforge.landingGroup', 'Landing Group')}
                                    </label>
                                    {showNewGroup ? (
                                        <div className="flex gap-2">
                                            <input
                                                autoFocus
                                                type="text"
                                                value={newGroupName}
                                                disabled={creatingGroup}
                                                onChange={(e) => setNewGroupName(e.target.value)}
                                                onKeyDown={(e) => { if (e.key === 'Enter') handleCreateGroup(); if (e.key === 'Escape') setShowNewGroup(false); }}
                                                placeholder={t('leadforge.newGroupName', 'New group name…')}
                                                className="flex-1 px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            />
                                            <button
                                                type="button"
                                                onClick={handleCreateGroup}
                                                disabled={creatingGroup || !newGroupName.trim()}
                                                className="px-3 py-2 rounded-xl text-sm font-bold border border-transparent flex items-center gap-1.5 disabled:opacity-50"
                                                style={{ background: 'var(--color-primary)', color: 'var(--color-text-inverse, white)' }}
                                            >
                                                <Check size={14} /> {t('leadforge.createGroup', 'Create')}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => { setShowNewGroup(false); setNewGroupName(''); }}
                                                className="px-2.5 py-2 rounded-xl border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-hover)]"
                                                title="Cancel"
                                            >
                                                <X size={14} />
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="flex gap-2">
                                            <select
                                                value={destGroupId}
                                                onChange={(e) => setDestGroupId(e.target.value)}
                                                className="flex-1 px-3.5 py-2.5 rounded-xl border bg-[var(--color-bg-main)] text-sm font-medium focus:outline-none"
                                                style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                            >
                                                <option value="">{t('leadforge.noGroup', 'No Group')}</option>
                                                {destGroups.map(g => (
                                                    <option key={g.id} value={g.id}>{g.name}</option>
                                                ))}
                                            </select>
                                            <button
                                                type="button"
                                                onClick={() => setShowNewGroup(true)}
                                                className="px-3 py-2.5 rounded-xl border border-[var(--color-border)] text-xs font-bold hover:bg-[var(--color-bg-hover)] flex items-center gap-1"
                                                style={{ color: 'var(--color-text-primary)' }}
                                            >
                                                <Plus size={14} /> {t('leadforge.newGroup', 'New group')}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

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
