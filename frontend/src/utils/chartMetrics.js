// Dashboard chart metrics — one place that knows, for every metric card,
// how its series is drawn: which scale it belongs to, its default line style,
// how its value is formatted and which theme colour it starts from.
//
// Why families: a chart that mixes clicks (thousands), conversions (units),
// money and percentages cannot put them on one honest axis. Each family gets
// its own auto-scaled axis instead, so a curve's height always means "this
// metric's real value" — never "percent of some other point".
//
//   traffic  left axis, real counts          clicks, uniques, offer clicks, bots
//   money    right axis, account currency    cost, revenue, profit…
//   events   own axis (left when no traffic) conversions, leads, sales — bars
//   roi      own hidden axis                 ROI family — dashed
//   rate     one hidden axis per metric      CR, approve %, … — dashed
//   unit     one hidden axis per metric      CPA, CPC, EPC… — dotted

export const CHART_STYLES = ['solid', 'dashed', 'dotted', 'dashdot', 'bars'];

const FAMILY_DEFAULT_STYLE = {
    traffic: 'solid',
    money: 'solid',
    events: 'bars',
    roi: 'dashed',
    rate: 'dashed',
    unit: 'dotted',
};

// `shade` nudges a metric away from a same-family sibling that starts from the
// same theme variable (Sales vs Conversions are both "success" green), so the
// default look stays inside the theme and still tells them apart.
export const CHART_METRIC_META = {
    clicks: { family: 'traffic', colorVar: '--color-primary', fallback: '#2563EB', labelKey: 'metrics.clicks', labelFallback: 'Clicks' },
    unique_clicks: { family: 'traffic', colorVar: '--color-accent-turquoise', fallback: '#0EA5E9', labelKey: 'metrics.uniqueClicks', labelFallback: 'Unique' },
    real_offer_clicks: { family: 'traffic', colorVar: '--color-accent-purple', fallback: '#8B5CF6', labelKey: 'metrics.offerClicks', labelFallback: 'Offer clicks' },
    bots: { family: 'traffic', colorVar: '--color-danger', fallback: '#EF4444', shade: 'dark', labelKey: 'metrics.bots', labelFallback: 'Bots' },

    conversions: { family: 'events', colorVar: '--color-success', fallback: '#10B981', labelKey: 'metrics.conversions', labelFallback: 'Conversions' },
    leads: { family: 'events', colorVar: '--color-accent-turquoise', fallback: '#0EA5E9', labelKey: 'metrics.leads', labelFallback: 'Leads' },
    sales: { family: 'events', colorVar: '--color-success', fallback: '#10B981', shade: 'dark', labelKey: 'metrics.sales', labelFallback: 'Sales' },

    cost: { family: 'money', colorVar: '--color-danger', fallback: '#EF4444', labelKey: 'metrics.cost', labelFallback: 'Cost' },
    revenue: { family: 'money', colorVar: '--color-warning', fallback: '#F59E0B', labelKey: 'metrics.revenue', labelFallback: 'Revenue' },
    profit: { family: 'money', colorVar: '--color-info', fallback: '#3B82F6', labelKey: 'metrics.profit', labelFallback: 'Profit' },
    revenue_confirmed: { family: 'money', colorVar: '--color-success', fallback: '#10B981', labelKey: 'metrics.revenueConfirmed', labelFallback: 'Confirmed Revenue' },
    profit_confirmed: { family: 'money', colorVar: '--color-success', fallback: '#10B981', shade: 'light', labelKey: 'metrics.profitConfirmed', labelFallback: 'Confirmed Profit' },
    real_revenue: { family: 'money', colorVar: '--color-real-rev', fallback: '#4338CA', labelKey: 'metrics.realRevenue', labelFallback: 'Real Rev' },

    roi: { family: 'roi', colorVar: '--color-accent-purple', fallback: '#8B5CF6', labelKey: 'metrics.roi', labelFallback: 'ROI' },
    roi_confirmed: { family: 'roi', colorVar: '--color-accent-purple', fallback: '#8B5CF6', shade: 'light', labelKey: 'metrics.roiConfirmed', labelFallback: 'Confirmed ROI' },
    real_roi: { family: 'roi', colorVar: '--color-real-roi', fallback: '#6366F1', labelKey: 'metrics.realRoi', labelFallback: 'Real ROI' },

    cpa: { family: 'unit', colorVar: '--color-warning', fallback: '#F59E0B', labelKey: 'metrics.cpa', labelFallback: 'CPA' },
    cpc: { family: 'unit', colorVar: '--color-info', fallback: '#3B82F6', labelKey: 'metrics.cpc', labelFallback: 'CPC' },
    cpv: { family: 'unit', colorVar: '--color-accent-turquoise', fallback: '#0EA5E9', labelKey: 'metrics.cpv', labelFallback: 'CPV' },
    cpl: { family: 'unit', colorVar: '--color-warning', fallback: '#F59E0B', shade: 'dark', labelKey: 'metrics.cpl', labelFallback: 'CPL' },
    cps: { family: 'unit', colorVar: '--color-primary', fallback: '#2563EB', labelKey: 'metrics.cps', labelFallback: 'CPS' },
    epc: { family: 'unit', colorVar: '--color-success', fallback: '#10B981', labelKey: 'metrics.epc', labelFallback: 'EPC' },
    epv: { family: 'unit', colorVar: '--color-success', fallback: '#10B981', shade: 'light', labelKey: 'metrics.epv', labelFallback: 'EPV' },

    cr: { family: 'rate', colorVar: '--color-ctr', fallback: '#EC4899', labelKey: 'metrics.cr', labelFallback: 'CR %' },
    approve_rate: { family: 'rate', colorVar: '--color-success', fallback: '#10B981', labelKey: 'metrics.approveRate', labelFallback: 'Approve %' },
    lp_ctr: { family: 'rate', colorVar: '--color-accent-purple', fallback: '#8B5CF6', shade: 'dark', labelKey: 'metrics.lpCtr', labelFallback: 'LP CTR %' },
    bot_rate: { family: 'rate', colorVar: '--color-danger', fallback: '#EF4444', labelKey: 'metrics.botRate', labelFallback: 'Bot %' },
    ctr: { family: 'rate', colorVar: '--color-ctr', fallback: '#EC4899', shade: 'light', labelKey: 'metrics.ctr', labelFallback: 'CTR' },
};

export const CHART_METRIC_IDS = Object.keys(CHART_METRIC_META);

export const isChartMetric = (id) => Object.prototype.hasOwnProperty.call(CHART_METRIC_META, id);

export const metricFamily = (id) => CHART_METRIC_META[id]?.family || 'traffic';

// Axis a series is scaled on. Families whose members share a unit AND a
// magnitude share an axis (their relative height then means something);
// rates and unit prices get one axis each — CR 2 % next to Approve 60 %, or
// CPC $0.02 next to CPA $20, would flatten the smaller one to the floor.
export const metricAxisId = (id) => {
    const family = metricFamily(id);
    if (family === 'traffic') return 'yTraffic';
    if (family === 'money') return 'yMoney';
    if (family === 'events') return 'yEvents';
    if (family === 'roi') return 'yRoi';
    return `y_${id}`;
};

// How a value is written in the tooltip.
export const metricValueKind = (id) => {
    const family = metricFamily(id);
    if (family === 'money') return 'money';
    if (family === 'unit') return 'unit';
    if (family === 'roi' || family === 'rate') return 'percent';
    return 'count';
};

// Okabe–Ito palette: eight colours that stay distinguishable under the common
// forms of colour blindness. Black is swapped for mid-grey so it survives the
// dark themes. Paired with a line style per metric, every (colour, style)
// combination below is unique — nothing relies on hue alone.
const CVD = {
    blue: '#0072b2',
    sky: '#56b4e9',
    green: '#009e73',
    yellow: '#f0e442',
    orange: '#e69f00',
    vermillion: '#d55e00',
    purple: '#cc79a7',
    grey: '#999999',
};

export const COLOR_BLIND_PRESET = {
    colors: {
        clicks: CVD.blue, unique_clicks: CVD.sky, real_offer_clicks: CVD.purple, bots: CVD.grey,
        conversions: CVD.green, leads: CVD.sky, sales: CVD.orange,
        cost: CVD.vermillion, revenue: CVD.orange, profit: CVD.green, real_revenue: CVD.yellow,
        revenue_confirmed: CVD.orange, profit_confirmed: CVD.green,
        roi: CVD.purple, roi_confirmed: CVD.purple, real_roi: CVD.yellow,
        cpa: CVD.vermillion, cpc: CVD.blue, cpv: CVD.sky, cpl: CVD.orange, cps: CVD.grey, epc: CVD.green, epv: CVD.yellow,
        cr: CVD.blue, approve_rate: CVD.sky, lp_ctr: CVD.vermillion, bot_rate: CVD.grey, ctr: CVD.blue,
    },
    styles: {
        clicks: 'solid', unique_clicks: 'solid', real_offer_clicks: 'solid', bots: 'solid',
        conversions: 'bars', leads: 'bars', sales: 'bars',
        cost: 'solid', revenue: 'solid', profit: 'solid', real_revenue: 'solid',
        revenue_confirmed: 'dashed', profit_confirmed: 'dashed',
        roi: 'dashed', roi_confirmed: 'dotted', real_roi: 'dashed',
        cpa: 'dotted', cpc: 'dotted', cpv: 'dotted', cpl: 'dotted', cps: 'dotted', epc: 'dotted', epv: 'dotted',
        cr: 'dashed', approve_rate: 'dashed', lp_ctr: 'dashed', bot_rate: 'dashed', ctr: 'dashdot',
    },
};

// Quick-pick swatches in the colour popover: the colour-blind-safe eight plus
// a few saturated extras people reach for.
export const COLOR_SWATCHES = [
    CVD.blue, CVD.sky, CVD.green, CVD.yellow, CVD.orange, CVD.vermillion, CVD.purple, CVD.grey,
    '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6',
];

export const EMPTY_CHART_PREFS = { colors: {}, styles: {} };

const HEX = /^#[0-9a-f]{6}$/i;

// Same rules as the backend (orbitraDashboardChartPrefsSanitize): anything
// that is not a known metric with a #rrggbb colour / known style is dropped.
export const sanitizeChartPrefs = (input) => {
    const out = { colors: {}, styles: {} };
    if (!input || typeof input !== 'object') return out;
    Object.entries(input.colors || {}).forEach(([id, color]) => {
        if (isChartMetric(id) && typeof color === 'string' && HEX.test(color)) out.colors[id] = color.toLowerCase();
    });
    Object.entries(input.styles || {}).forEach(([id, style]) => {
        if (isChartMetric(id) && CHART_STYLES.includes(style)) out.styles[id] = style;
    });
    return out;
};

export const metricStyle = (id, prefs) =>
    prefs?.styles?.[id] || FAMILY_DEFAULT_STYLE[metricFamily(id)] || 'solid';

export const metricDefaultStyle = (id) => FAMILY_DEFAULT_STYLE[metricFamily(id)] || 'solid';

const SHADE_MIX = { dark: 'black 30%', light: 'white 35%' };

// A CSS colour for the metric — usable in inline styles (card glow, swatches).
// The user's own pick wins; otherwise the theme variable, shaded if needed.
export const metricColorCss = (id, prefs) => {
    const picked = prefs?.colors?.[id];
    if (picked) return picked;
    const meta = CHART_METRIC_META[id];
    if (!meta) return '#9ca3af';
    const base = `var(${meta.colorVar}, ${meta.fallback})`;
    return meta.shade ? `color-mix(in srgb, ${base}, ${SHADE_MIX[meta.shade]})` : base;
};

// --- resolving a CSS colour to plain rgb for the canvas -------------------
// Canvas gradients need concrete channel values, and theme variables can hold
// anything CSS accepts. A hidden probe element lets the browser do the
// resolving (variables, color-mix, named colours) once per colour and theme.
let probeEl = null;
const resolvedCache = new Map();

export const clearMetricColorCache = () => resolvedCache.clear();

const parseRgb = (value) => {
    if (!value) return null;
    let m = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);
    if (m) return [Number(m[1]), Number(m[2]), Number(m[3])];
    // color-mix() computes to "color(srgb r g b)" with 0..1 channels.
    m = value.match(/^color\(\s*srgb\s+([\d.e-]+)\s+([\d.e-]+)\s+([\d.e-]+)/i);
    if (m) return [Number(m[1]) * 255, Number(m[2]) * 255, Number(m[3]) * 255];
    m = value.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
    if (m) return [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)];
    return null;
};

const clampChannel = (n) => Math.max(0, Math.min(255, Math.round(n)));

// → [r, g, b] (0..255). Falls back to the metric's built-in hex.
export const resolveCssColor = (cssColor, fallbackHex = '#9ca3af') => {
    if (resolvedCache.has(cssColor)) return resolvedCache.get(cssColor);
    let rgb = parseRgb(cssColor);
    if (!rgb && typeof document !== 'undefined' && document.body) {
        try {
            if (!probeEl) {
                probeEl = document.createElement('span');
                probeEl.style.display = 'none';
                probeEl.setAttribute('aria-hidden', 'true');
                document.body.appendChild(probeEl);
            }
            probeEl.style.color = '';
            probeEl.style.color = cssColor;
            rgb = probeEl.style.color ? parseRgb(getComputedStyle(probeEl).color) : null;
        } catch {
            rgb = null;
        }
    }
    if (!rgb) rgb = parseRgb(fallbackHex) || [156, 163, 175];
    rgb = rgb.map(clampChannel);
    resolvedCache.set(cssColor, rgb);
    return rgb;
};

// The metric's colour as [r, g, b] for canvas drawing.
export const metricColorRgb = (id, prefs) => {
    const meta = CHART_METRIC_META[id];
    return resolveCssColor(metricColorCss(id, prefs), meta?.fallback || '#9ca3af');
};

export const rgbToCss = ([r, g, b], alpha = 1) =>
    (alpha >= 1 ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${alpha})`);

export const rgbToHex = ([r, g, b]) =>
    `#${[r, g, b].map(n => clampChannel(n).toString(16).padStart(2, '0')).join('')}`;
