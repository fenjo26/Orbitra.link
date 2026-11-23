// Report metrics list.
// `label` — full description (columns modal, header tooltips);
// `shortLabel` — compact table-header abbreviation (nowrap <th>).
export const ALL_REPORT_METRICS = [
    // Margin, not "Profitability": this is profit / revenue, where ROI is
    // profit / cost. Two genuinely different questions, and both earn a
    // column — the old label read like a synonym for ROI. The id stays
    // `profitability`, so stored column sets and widths survive the rename.
    { id: 'profitability', label: 'Margin', shortLabel: 'Margin', hintKey: 'marginHint', hint: 'Profit ÷ revenue × 100%. Differs from ROI, which is profit ÷ cost' },
    { id: 'clicks', label: 'Clicks', shortLabel: 'Clicks', hintKey: 'clicksHint', hint: 'Every visit to the campaign link, as in Keitaro — whatever stream served it. Transitions to the offer are counted separately (Offer clicks, LP clicks)' },
    { id: 'unique_clicks', label: 'Unique clicks (campaign)', shortLabel: 'uClicks', hintKey: 'uniqueClicksHint', hint: 'Visitors counted once per campaign by IP (or IP + user agent) within the campaign uniqueness window' },
    { id: 'conversions', label: 'Conversions', shortLabel: 'Conv', hintKey: 'conversionsHint', hint: 'Every conversion that came back by postback, any status (lead, sale, rejected, trash)' },
    { id: 'roi_confirmed', label: 'ROI (confirmed)', shortLabel: 'ROI (conf)' },
    { id: 'deposits', label: 'Deposits', shortLabel: 'Deposits' },
    { id: 'revenue_deposit', label: 'Revenue (deposit)', shortLabel: 'Rev (dep)' },
    { id: 'revenue_registration', label: 'Revenue (registration)', shortLabel: 'Rev (reg)' },
    { id: 'revenue', label: 'Revenue', shortLabel: 'Revenue' },
    { id: 'profit', label: 'Profit/Loss (all)', shortLabel: 'Profit' },
    { id: 'revenue_hold', label: 'Revenue (hold)', shortLabel: 'Rev (hold)' },
    { id: 'revenue_confirmed', label: 'Revenue (confirmed)', shortLabel: 'Rev (conf)' },
    { id: 'revenue_rejected', label: 'Revenue (rejected)', shortLabel: 'Rev (rej)' },
    { id: 'revenue_trash', label: 'Revenue (trash)', shortLabel: 'Rev (trash)' },
    { id: 'cost', label: 'Cost', shortLabel: 'Cost' },
    { id: 'cabinet_cost', label: 'Spend (cabinet)', shortLabel: 'Cabinet' },
    { id: 'fee', label: 'Agency fee', shortLabel: 'Fee' },
    { id: 'impressions', label: 'Impressions', shortLabel: 'Impr' },
    { id: 'platform_clicks', label: 'Clicks (platform)', shortLabel: 'Clicks (plat)' },
    { id: 'cpm', label: 'CPM (platform)', shortLabel: 'CPM' },
    { id: 'platform_ctr', label: 'CTR (platform)', shortLabel: 'CTR (plat)' },
    { id: 'click_loss', label: 'Click loss', shortLabel: 'Loss' },
    { id: 'visitors', label: 'Visitors', shortLabel: 'Visitors', hintKey: 'visitorsHint', hint: 'All hits on the campaign URL — the same number as Clicks; the CPV/EPV denominator' },
    { id: 'unique_clicks_stream', label: 'Unique clicks (flow)', shortLabel: 'uClicks (flow)', hintKey: 'uniqueClicksStreamHint', hint: 'Visitors counted once per stream: a visitor who hits two streams counts in each' },
    { id: 'unique_clicks_global', label: 'Unique clicks (global)', shortLabel: 'uClicks (glob)', hintKey: 'uniqueClicksGlobalHint', hint: 'Visitors counted once across all campaigns of the tracker' },
    { id: 'uc_rate', label: 'Unique clicks % (campaign)', shortLabel: 'uClicks %' },
    { id: 'uc_rate_stream', label: 'Unique clicks % (flow)', shortLabel: 'uClicks % (fl)' },
    { id: 'uc_rate_global', label: 'Unique clicks % (global)', shortLabel: 'uClicks % (gl)' },
    { id: 'bots', label: 'Bots', shortLabel: 'Bots', hintKey: 'botsHint', hint: 'Hits whose user agent looks like a crawler or a tool (the same signatures the cloak uses). They are counted in Visitors too' },
    { id: 'possible_bots', label: 'Possible bots', shortLabel: 'Poss. bots', hintKey: 'possibleBotsHint', hint: 'Visits where the landing timer ran but not one mouse move or touch arrived — a behavioural hint to look closer, not a verdict. NULL (timer never ran) never counts' },
    { id: 'bot_rate', label: 'Bot %', shortLabel: 'Bot %', hintKey: 'botRateHint', hint: 'Bots ÷ Visitors × 100%' },
    { id: 'proxies', label: 'Proxies', shortLabel: 'Proxies', hintKey: 'proxiesHint', hint: 'Hits from proxy / VPN / Tor IPs. Needs the IP2Proxy database (Settings → Geo databases)' },
    { id: 'empty_referrers', label: 'Empty referrers', shortLabel: 'Empty ref', hintKey: 'emptyReferrersHint', hint: 'Hits with no referrer — typed-in, in-app browsers, or a source that strips it' },
    { id: 'leads', label: 'Leads', shortLabel: 'Leads' },
    { id: 'sales', label: 'Sales', shortLabel: 'Sales' },
    { id: 'rejected', label: 'Rejected', shortLabel: 'Rejected' },
    { id: 'trash', label: 'Trash', shortLabel: 'Trash' },
    { id: 'approve_rate', label: 'Approve %', shortLabel: 'Approve %' },
    { id: 'profit_confirmed', label: 'Profit/Loss (confirmed)', shortLabel: 'P/L (conf)' },
    { id: 'cr', label: 'CR — Conversion rate', shortLabel: 'CR', hintKey: 'crHint', hint: 'Conversions ÷ Clicks × 100%' },
    { id: 'cr_sales', label: 'CR (sales) — Conversion rate', shortLabel: 'CR (sales)' },
    { id: 'cr_deposits', label: 'CR (deposits) — Conversion rate', shortLabel: 'CR (deps)' },
    { id: 'cr_holds', label: 'CR (hold) — Conversion rate', shortLabel: 'CR (hold)' },
    { id: 'cr_registrations', label: 'CR (registrations) — Conversion rate', shortLabel: 'CR (regs)' },
    { id: 'roi', label: 'ROI (all) — Return on investment', shortLabel: 'ROI' },
    { id: 'epc', label: 'EPC (all) — Earnings per LP click', shortLabel: 'EPC', hintKey: 'epcHint', hint: 'Revenue / LP Clicks (Lander CTA) — equals EPV on direct offers' },
    { id: 'epv', label: 'EPV — Earnings per visit', shortLabel: 'EPV', hintKey: 'epvHint', hint: 'Revenue / all visits — universal for Direct and Lander flows' },
    { id: 'uepc', label: 'uEPC (all) — Earnings per unique click', shortLabel: 'uEPC' },
    { id: 'epc_hold', label: 'EPC (hold) — Earnings per click', shortLabel: 'EPC (hold)' },
    { id: 'uepc_hold', label: 'uEPC (hold) — Earnings per unique click', shortLabel: 'uEPC (hold)' },
    { id: 'epc_registration', label: 'EPC (registration) — Earnings per click', shortLabel: 'EPC (reg)' },
    { id: 'uepc_registration', label: 'uEPC (registration) — Earnings per unique click', shortLabel: 'uEPC (reg)' },
    { id: 'epc_confirmed', label: 'EPC (confirmed) — Earnings per click', shortLabel: 'EPC (conf)' },
    { id: 'uepc_confirmed', label: 'uEPC (confirmed) — Earnings per unique click', shortLabel: 'uEPC (conf)' },
    { id: 'cps', label: 'CPS — Cost per sale', shortLabel: 'CPS' },
    { id: 'cpl', label: 'CPL — Cost per lead', shortLabel: 'CPL' },
    { id: 'cpr', label: 'CPR — Cost per registration', shortLabel: 'CPR' },
    { id: 'cpd', label: 'CPD — Cost per deposit', shortLabel: 'CPD' },
    { id: 'cpa', label: 'CPA — Cost per conversion', shortLabel: 'CPA' },
    { id: 'cpc', label: 'CPC — Cost per LP click', shortLabel: 'CPC', hintKey: 'cpcHint', hint: 'Cost / LP Clicks (Lander CTA) — equals CPV on direct offers' },
    { id: 'ucpc', label: 'uCPC — Cost per unique click', shortLabel: 'uCPC' },
    { id: 'cpv', label: 'CPV — Cost per visit', shortLabel: 'CPV', hintKey: 'cpvHint', hint: 'Cost / all visits — universal for Direct and Lander flows' },
    { id: 'ecpc', label: 'eCPC — Cost per 1000 clicks', shortLabel: 'eCPC' },
    { id: 'ecpm_all', label: 'eCPM (all) — Profit per 1k clicks', shortLabel: 'eCPM' },
    { id: 'ecpm_confirmed', label: 'eCPM (confirmed) — Profit per 1k clicks', shortLabel: 'eCPM (conf)' },
    { id: 'earnings_per_conv', label: 'EC (all) — Earnings per conversion', shortLabel: 'EC' },
    { id: 'ec_confirmed', label: 'EC (confirmed) — Earning per conversion', shortLabel: 'EC (conf)' },
    { id: 'registrations', label: 'Registrations', shortLabel: 'Regs' },
    { id: 'ucr', label: 'uCR — Unique clicks to registrations', shortLabel: 'uCR' },
    { id: 'time_since_lp_click', label: 'Time since LP click', shortLabel: 'LP time' },
    { id: 'time_on_lp', label: 'Time on LP', shortLabel: 'LP time (all)', hintKey: 'timeOnLpHint', hint: 'Average time spent on the landing by EVERY visitor, the ones who never clicked the offer included — measured by the landing timer, not by the offer transition' },
    { id: 'lp_bounce_rate', label: 'LP bounce rate', shortLabel: 'LP bounce', hintKey: 'lpBounceRateHint', hint: 'Share of measured visits that lasted under 5 seconds. Denominator is measured visits, not clicks — a page that never ran the timer reports nothing' },
    { id: 'lp_scroll_depth', label: 'LP scroll depth', shortLabel: 'LP scroll', hintKey: 'lpScrollDepthHint', hint: 'Average deepest scroll reached on the landing, 0-100%' },
    { id: 'lp_measured', label: 'LP measured visits', shortLabel: 'LP measured', hintKey: 'lpMeasuredHint', hint: 'Visits that actually reported a time — the sample behind Time on LP, bounce rate and scroll depth' },
    { id: 'lp_views', label: 'LP views / visits', shortLabel: 'LP Views', hintKey: 'lpViewsHint', hint: 'Total landing-page impressions' },
    { id: 'lp_clicks', label: 'LP clicks', shortLabel: 'LP Clicks', hintKey: 'lpClicksHint', hint: 'Total clicks on landing-page CTA buttons' },
    { id: 'real_lp_clicks', label: 'Real LP clicks', shortLabel: 'Real LP', hintKey: 'realLpClicksHint', hint: 'Landing views where the visitor actually left through the offer link (offer transition recorded) — not the pre-bound stream' },
    { id: 'real_offer_clicks', label: 'Real offer clicks', shortLabel: 'Real Offer', hintKey: 'realOfferClicksHint', hint: 'Clicks that really reached the offer: direct clicks plus completed landing transitions' },
    { id: 'lp_ctr', label: 'LP CTR — LP Click-Through Rate', shortLabel: 'LP CTR', hintKey: 'lpCtrHint', hint: '(LP Clicks / LP Views) × 100% — dash on direct offers (no CTA)' },
    { id: 'real_lp_ctr', label: 'Real LP CTR', shortLabel: 'Real LP CTR', hintKey: 'realLpCtrHint', hint: '(Real LP clicks / LP Views) × 100% — the honest CTA conversion of the landing' },
    { id: 'pwa_intents', label: 'PWA install intents', shortLabel: 'PWA Intent', hintKey: 'pwaIntentsHint', hint: 'Taps on the PWA landing Install button (before the browser prompt)' },
    { id: 'pwa_installs', label: 'PWA installs', shortLabel: 'PWA Installs', hintKey: 'pwaInstallsHint', hint: 'Install events recorded for the PWA landing, bots included' },
    { id: 'real_pwa_installs', label: 'Real PWA installs', shortLabel: 'Real PWA Inst', hintKey: 'realPwaInstallsHint', hint: 'PWA installs from non-bot clicks — appinstalled (Android) or first standalone open (iOS)' },
    { id: 'pwa_opens', label: 'PWA opens', shortLabel: 'PWA Opens', hintKey: 'pwaOpensHint', hint: 'Reopens of the installed PWA from the home screen (throttled, one per 10 min per click)' },
    { id: 'pwa_install_rate', label: 'PWA install rate', shortLabel: 'PWA CR', hintKey: 'pwaInstallRateHint', hint: '(Real PWA installs / clicks) × 100% — on a PWA landing row this IS view→install; dash when zero installs' },
    { id: 'push_subscribed', label: 'Push subscribers', shortLabel: 'Push subs', hintKey: 'pushSubscribedHint', hint: 'Clicks whose visitor accepted notifications and stored a subscription in the push base' },
    { id: 'popup_shown', label: 'Popup shown', shortLabel: 'Pop shown', hintKey: 'popupShownHint', hint: 'Visitors the conversion popup actually rendered for (post delay/activity gates, bots excluded)' },
    { id: 'popup_ctr', label: 'Popup CTR', shortLabel: 'Pop CTR', hintKey: 'popupCtrHint', hint: '(Popup clicks / Popup shown) × 100% — dash when no popup was shown' },
    { id: 'popup_clicked', label: 'Popup clicks', shortLabel: 'Pop clicks', hintKey: 'popupClickedHint', hint: 'Visitors who pressed the popup offer button (the click continues through the tracker like any CTA)' },
    { id: 'popup_closed', label: 'Popup closed', shortLabel: 'Pop closed', hintKey: 'popupClosedHint', hint: 'Visitors who dismissed the popup without clicking' },
    { id: 'popup_conversions', label: 'Popup conversions', shortLabel: 'Pop conv', hintKey: 'popupConversionsHint', hint: 'Conversions on clicks that came through the popup button — the number that decides whether the plugin pays for itself' },
    { id: 'pwa_screen_views', label: 'PWA screen views', shortLabel: 'PWA Screens', hintKey: 'pwaScreenViewsHint', hint: 'Total PWA flow-screen views (the configurable funnel): every time a visitor was shown a funnel screen — one click can contribute several' },
    { id: 'cr_regs_to_deps', label: 'CR (regs to deps)', shortLabel: 'CR (r→d)' },
];

// `prelander_clicks` was previously exposed as the LP-click column even
// though the API uses it as the legacy alias for LP views. Migrate saved
// browser templates/selections to the unambiguous canonical field.
export const normalizeReportMetricIds = (ids) => {
    if (!Array.isArray(ids)) return [];
    return [...new Set(ids.map(id => id === 'prelander_clicks' ? 'lp_clicks' : id))];
};

export const PRESETS = {
    best: ['profitability', 'visitors', 'clicks', 'unique_clicks', 'conversions', 'roi_confirmed', 'cost', 'revenue', 'profit', 'cr', 'epc', 'cpc', 'cpa'],
    finance: ['cost', 'revenue', 'revenue_confirmed', 'revenue_hold', 'revenue_rejected', 'profit', 'roi', 'profit_confirmed', 'roi_confirmed', 'cpa', 'epc'],
    cod: ['clicks', 'unique_clicks', 'leads', 'sales', 'approve_rate', 'rejected', 'trash', 'cost', 'cpl', 'cps', 'cpa', 'revenue_confirmed', 'profit_confirmed', 'roi_confirmed'],
    lander_to_offer: ['clicks', 'unique_clicks', 'lp_views', 'lp_clicks', 'real_lp_clicks', 'real_lp_ctr', 'time_since_lp_click', 'time_on_lp', 'lp_bounce_rate', 'lp_scroll_depth', 'pwa_intents', 'real_pwa_installs', 'pwa_opens', 'pwa_install_rate', 'conversions', 'cr', 'cpv', 'cpc', 'epv', 'epc', 'cpa', 'cost', 'revenue', 'profit', 'roi'],
    traffic: ['clicks', 'unique_clicks', 'visitors', 'unique_clicks_stream', 'unique_clicks_global', 'uc_rate', 'bots', 'possible_bots', 'bot_rate', 'proxies', 'empty_referrers', 'conversions', 'cr'],
    all: ALL_REPORT_METRICS.map(m => m.id),
};

export const DEFAULT_METRIC_ORDER = ALL_REPORT_METRICS.map(m => m.id);
export const getReportMetricTooltip = (metric, t) => {
    if (!metric) return '';
    return metric.hintKey ? t(`metrics.${metric.hintKey}`, metric.hint || metric.label) : metric.label;
};
// Single source of truth for report dimensions: the picker grid, the label
// map and the i18n map are all derived from this list. To expose a new
// dimension: add it here, add its SQL expression to $allowed_dimensions in
// api.php (campaign_report), and its label to the "dimensions" section of
// all 7 locale files — nothing else. Order below is the picker order.
// isp/asn are real clicks columns; param_* names resolve through the generic
// parameters_json handler in the same api.php block (no backend entry needed).
export const REPORT_DIMENSIONS = [
    // Geography, devices and network
    { id: 'country', label: 'Country', i18n: 'country' },
    { id: 'city', label: 'City', i18n: 'city' },
    { id: 'region', label: 'Region', i18n: 'region' },
    { id: 'device_type', label: 'Device Type', i18n: 'deviceType' },
    { id: 'os', label: 'Operating System', i18n: 'os' },
    { id: 'browser', label: 'Browser', i18n: 'browser' },
    { id: 'language', label: 'Language', i18n: 'language' },
    { id: 'isp', label: 'ISP', i18n: 'isp' },
    { id: 'asn', label: 'ASN', i18n: 'asn' },
    { id: 'carrier', label: 'Mobile operator', i18n: 'carrier' },
    { id: 'connection_type', label: 'Connection type', i18n: 'connectionType' },

    // Time
    { id: 'day', label: 'Date (Day)', i18n: 'day' },
    { id: 'hour', label: 'Hour', i18n: 'hour' },
    { id: 'lp_time', label: 'Time to offer (bucket)', i18n: 'lpTime' },
    { id: 'lp_dwell', label: 'Time on LP (bucket)', i18n: 'lpDwell' },

    // PWA funnel screens (per-screen stats, renderer v16)
    { id: 'pwa_entry_screen', label: 'PWA entry screen', i18n: 'pwaEntryScreen' },
    { id: 'pwa_last_screen', label: 'PWA last screen', i18n: 'pwaLastScreen' },

    // Conversion popups (Settings → Plugins): which design×text variant was
    // rendered — group by it to see what actually converts.
    { id: 'popup_variant', label: 'Popup variant', i18n: 'popupVariant' },

    // Tracker entities
    { id: 'campaign_id', label: 'Campaign', i18n: 'campaign' },
    { id: 'source_id', label: 'Traffic Source', i18n: 'source' },
    { id: 'stream_id', label: 'Stream', i18n: 'stream' },
    { id: 'landing_id', label: 'Landing Page', i18n: 'landing' },
    { id: 'offer_id', label: 'Offer', i18n: 'offer' },

    // Ad parameters
    { id: 'ad_campaign_id', label: 'Ad Campaign ID', i18n: 'adCampaignId' },
    { id: 'adset_id', label: 'AdSet ID', i18n: 'adsetId' },
    { id: 'ad_id', label: 'Ad ID', i18n: 'adId' },
    { id: 'keyword', label: 'Keyword', i18n: 'keyword' },
    { id: 'creative_id', label: 'Creative ID', i18n: 'creativeId' },
    { id: 'external_id', label: 'External ID', i18n: 'externalId' },

    // Human-readable names and labels from parameters_json
    { id: 'param_campaign_name', label: 'Campaign Name', i18n: 'campaignName' },
    { id: 'param_adset_name', label: 'AdSet Name', i18n: 'adsetName' },
    { id: 'param_ad_name', label: 'Ad Name', i18n: 'adName' },
    { id: 'param_utm_placement', label: 'UTM Placement', i18n: 'utmPlacement' },
    { id: 'param_source', label: 'Source', i18n: 'paramSource' },

    // SubIDs
    { id: 'sub_id_1', label: 'Sub ID 1', i18n: 'subId1' },
    { id: 'sub_id_2', label: 'Sub ID 2', i18n: 'subId2' },
    { id: 'sub_id_3', label: 'Sub ID 3', i18n: 'subId3' },
    { id: 'sub_id_4', label: 'Sub ID 4', i18n: 'subId4' },
    { id: 'sub_id_5', label: 'Sub ID 5', i18n: 'subId5' }
];

export const REPORT_DIMENSION_LABELS = Object.fromEntries(REPORT_DIMENSIONS.map(d => [d.id, d.label]));
const DIMENSION_I18N_KEYS = Object.fromEntries(REPORT_DIMENSIONS.map(d => [d.id, d.i18n]));

export const getDimensionLabel = (dim, t) => {
    if (!dim) return '';
    const i18nKey = DIMENSION_I18N_KEYS[dim];
    if (t && i18nKey) {
        const fullKey = `dimensions.${i18nKey}`;
        const translated = t(fullKey);
        if (translated && translated !== fullKey) {
            return translated;
        }
    }
    return REPORT_DIMENSION_LABELS[dim] || dim;
};

// --- User-saved column templates (localStorage) ---
const TEMPLATES_KEY = 'orbitra_column_templates';
const DEFAULT_TEMPLATE_KEY = 'orbitra_default_template_id';
const DEFAULT_GROUP_KEY = 'orbitra_default_group_id';
export const GROUP_TEMPLATES_KEY = 'orbitra_report_group_templates';
export const LAST_GROUP_BY_KEY = 'orbitra_report_group_by';

// A flat, single-dimension report — the multi-level drill-down is opt-in via
// the layer builder. Shared with CampaignReports so "restore to default" and
// the initial load agree on the same shape.
export const DEFAULT_REPORT_LAYERS = ['country'];

export const arraysEqual = (a, b) => Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.every((v, i) => v === b[i]);

export const loadColumnTemplates = () => {
    try {
        const parsed = JSON.parse(localStorage.getItem(TEMPLATES_KEY) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed
            .filter(t => t && typeof t.id === 'string' && typeof t.name === 'string' && Array.isArray(t.columns))
            .map(t => ({ ...t, columns: normalizeReportMetricIds(t.columns) }));
    } catch {
        // Corrupt storage entry — fall back to no saved templates
        return [];
    }
};

export const persistColumnTemplates = (next) => {
    try {
        localStorage.setItem(TEMPLATES_KEY, JSON.stringify(next));
    } catch {
        // Storage full/unavailable — templates just won't survive the session
    }
};

export const loadDefaultTemplateId = () => {
    try {
        return localStorage.getItem(DEFAULT_TEMPLATE_KEY);
    } catch {
        return null;
    }
};

export const persistDefaultTemplateId = (id) => {
    try {
        if (id) localStorage.setItem(DEFAULT_TEMPLATE_KEY, id);
        else localStorage.removeItem(DEFAULT_TEMPLATE_KEY);
    } catch {
        // Storage full/unavailable — default choice won't survive the session
    }
};

export const loadDefaultGroupId = () => {
    try {
        return localStorage.getItem(DEFAULT_GROUP_KEY);
    } catch {
        return null;
    }
};

export const persistDefaultGroupId = (id) => {
    try {
        if (id) localStorage.setItem(DEFAULT_GROUP_KEY, id);
        else localStorage.removeItem(DEFAULT_GROUP_KEY);
    } catch {
        // Storage full/unavailable — default choice won't survive the session
    }
};

// Columns of the user's default template (system preset id or custom template
// id), or null when no default is set. Pages use this as the initial shape
// when no per-page column selection has been saved yet.
export const getDefaultTemplateColumns = () => {
    const id = loadDefaultTemplateId();
    if (!id) return null;
    if (Array.isArray(PRESETS[id])) return normalizeReportMetricIds(PRESETS[id]);
    const tpl = loadColumnTemplates().find(t => t.id === id);
    return tpl ? normalizeReportMetricIds(tpl.columns) : null;
};

export const loadGroupTemplates = () => {
    try {
        const parsed = JSON.parse(localStorage.getItem(GROUP_TEMPLATES_KEY) || '[]');
        if (!Array.isArray(parsed)) return [];
        return parsed.filter(tpl => tpl && typeof tpl.id === 'string' && typeof tpl.name === 'string' && Array.isArray(tpl.layers));
    } catch {
        // Corrupt storage entry — fall back to no saved grouping templates
        return [];
    }
};

// A grouping is only usable if every dimension still exists. URL params
// (`param_*`) are user-defined and always valid; the rest must be known
// dimensions. An unusable grouping falls through to the next fallback rather
// than producing an empty report.
const isUsableGrouping = (dims) =>
    Array.isArray(dims) && dims.length > 0
    && dims.every(dim => typeof dim === 'string' && (REPORT_DIMENSION_LABELS[dim] || dim.startsWith('param_')));

// Layers of the user's starred grouping (system preset id or saved template
// id), or null when nothing is starred / the starred entry no longer exists.
export const getDefaultGroupLayers = (layerPresets = []) => {
    const id = loadDefaultGroupId();
    if (!id) return null;
    const preset = layerPresets.find(p => p && p.id === id);
    const candidate = preset ? preset.layers : loadGroupTemplates().find(tpl => tpl.id === id)?.layers;
    return isUsableGrouping(candidate) ? [...candidate] : null;
};

// The grouping the user last applied, or null when nothing was stored / the
// stored dimensions no longer exist.
export const getLastAppliedGroupBy = () => {
    try {
        const raw = localStorage.getItem(LAST_GROUP_BY_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return isUsableGrouping(parsed) ? [...parsed] : null;
    } catch {
        return null;
    }
};

export const persistLastAppliedGroupBy = (dims) => {
    try {
        localStorage.setItem(LAST_GROUP_BY_KEY, JSON.stringify(dims));
    } catch {
        // Storage full/unavailable — the grouping just won't survive a reopen
    }
};

export const clearLastAppliedGroupBy = () => {
    try {
        localStorage.removeItem(LAST_GROUP_BY_KEY);
    } catch {
        // Nothing to do — the stale value is harmless, it validates on read
    }
};

// Grouping to show when the report opens: the starred one, else the last
// applied one, else the built-in default.
export const resolveInitialGroupLayers = (layerPresets = []) =>
    getDefaultGroupLayers(layerPresets)
    || getLastAppliedGroupBy()
    || [...DEFAULT_REPORT_LAYERS];
