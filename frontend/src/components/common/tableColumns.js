// Shared column geometry for the three tracker tables (Campaigns, Offers,
// Landings) and anything else that renders `.tracker-table`.
//
// Widths and alignment used to be declared three times, once per page, so the
// same metric was 120px on one tab and something else on another, and a column
// added to one table quietly inherited a width nobody had chosen for it. One
// definition here, imported by all three.
//
// This file is deliberately dependency-free: it knows column IDs and the shape
// of their content, nothing about React or about where the metric list lives.

/**
 * Fixed (identity) columns. These are the same everywhere they appear, so a
 * campaign's Status column and an offer's Status column are the same width.
 */
export const FIXED_COL_WIDTHS = {
    check: 40,
    id: 70,
    state: 90,
    name: 300,
    group_name: 140,
    actions: 150,
    // Offers
    affiliate_network_name: 150,
    geo: 80,
    payout: 110,
    redirect_type: 110,
    // Landings
    url: 300,
    type: 110,
    last_event: 150,
    offer_name: 180,
};

export const DEFAULT_FIXED_COL_WIDTH = 120;

/**
 * How wide a metric's *values* want to be, by the shape of what they print.
 * A percentage never needs the room a currency figure does, and giving all of
 * them one number is what made the table feel loose.
 */
const VALUE_WIDTH = {
    percent: 78,
    currency: 96,
    count: 84,
    text: 120,
};

const PERCENT_RE = /(^cr$|^cr_|^roi$|^roi_|_rate$|^ucr$|_ctr$|^profitability$|^lp_scroll_depth$|%)/;
const CURRENCY_RE = /(revenue|profit|^cost$|^payout|^epc|^uepc|^epv$|^cp[acdlrsv]$|^ucpc$|^ecpc$|^ecpm|^earnings_per_conv$|^ec_confirmed$)/;
const TEXT_RE = /(^time_since_lp_click$|^time_on_lp$)/;

/**
 * Hand-tuned exceptions. Anything not listed is classified below - add an entry
 * here only when the classifier gets a specific column visibly wrong.
 */
export const METRIC_COL_WIDTHS = {};

export const metricKind = (id) => {
    if (TEXT_RE.test(id)) return 'text';
    if (PERCENT_RE.test(id)) return 'percent';
    if (CURRENCY_RE.test(id)) return 'currency';
    return 'count';
};

// The header must fit too, because `.tracker-table th` clips rather than
// ellipses and a label cut off mid-word reads as a typo, not as truncation.
//
// Measured, not estimated: a per-character average is wrong by 15% either way
// between "Conv" and "Profitability", which is the difference between a column
// that fits and one that prints "Clic...". The canvas is created once and asks
// for the same 10px semibold face the header renders in (SortableTh's
// `text-[10px] font-semibold`), taking the family from the live document so it
// tracks the theme rather than hard-coding a stack that drifts.
//
// The chrome allowance is what sits beside the text inside the cell: 2x10px of
// padding, a 12px sort icon, a 12px drag grip and the two 4px flex gaps.
const HEADER_CHROME_PX = 52;
const FALLBACK_CHAR_PX = 6.6;
// `.tracker-table th` sets letter-spacing: 0.04em, which canvas measureText()
// does not apply - it cost "Profitability" two pixels and clipped it.
const LETTER_SPACING_PX = 0.4;

let headerCtx;

const measureHeaderLabel = (label) => {
    const text = String(label || '');
    try {
        if (headerCtx === undefined) {
            const family = getComputedStyle(document.body).fontFamily
                || 'system-ui, -apple-system, sans-serif';
            headerCtx = document.createElement('canvas').getContext('2d');
            headerCtx.font = `600 10px ${family}`;
        }
        if (headerCtx) return Math.ceil(headerCtx.measureText(text).width + text.length * LETTER_SPACING_PX);
    } catch {
        // No DOM (tests, SSR) or a canvas the browser refuses: fall through.
        headerCtx = null;
    }
    return Math.ceil(text.length * FALLBACK_CHAR_PX);
};

/**
 * Width for one metric column: wide enough for its values, and never narrower
 * than its own heading.
 *
 * @param {string} id     metric id
 * @param {string} label  the short label actually rendered in the header
 */
export const metricColWidth = (id, label = '') => {
    const explicit = METRIC_COL_WIDTHS[id];
    if (explicit) return explicit;
    const byValue = VALUE_WIDTH[metricKind(id)];
    const byLabel = measureHeaderLabel(label) + HEADER_CHROME_PX;
    return Math.max(byValue, byLabel);
};

/**
 * Width for any column, fixed or metric.
 *
 * A fixed column's number is a minimum, not a ceiling: the same heading is
 * "Actions" in English and "Aktionen" in German, and a width tuned against the
 * English build clips the moment the panel is opened in another language.
 * The checkbox anchor is exempt - it has no label and a hard 40px contract with
 * `.tracker-table th.col-check` in index.css.
 */
export const colWidth = (id, label = '') => {
    if (id === 'check' || id === 'checkbox') return FIXED_COL_WIDTHS.check;
    if (id in FIXED_COL_WIDTHS) {
        return Math.max(FIXED_COL_WIDTHS[id], measureHeaderLabel(label) + HEADER_CHROME_PX);
    }
    return metricColWidth(id, label);
};

// --- alignment -----------------------------------------------------------
//
// Names, groups and action rows read from the left. Short tokens (ID, Status,
// GEO, Type) centre, because a two-character value pinned to one edge of its
// column reads as a rendering fault. Everything numeric goes right, so digits
// line up by place value down the column - the whole point of tabular figures.
//
// Metric HEADERS align left rather than following their values: a short label
// over a wide right-aligned column drifts away from the table's reading edge,
// and the sort arrow travels with it.

const LEFT_COLS = new Set([
    'name', 'group_name', 'actions', 'affiliate_network_name', 'url', 'offer_name',
    'last_event',
]);

const CENTER_COLS = new Set([
    // 'checkbox' is Landings' legacy spelling; kept so a stale stored order or
    // an un-migrated caller still aligns instead of falling through to right.
    'check', 'checkbox', 'id', 'state', 'geo', 'redirect_type', 'type',
]);

/** 'left' | 'center' | 'right' */
export const colAlign = (id) => {
    if (LEFT_COLS.has(id)) return 'left';
    if (CENTER_COLS.has(id)) return 'center';
    return 'right';
};

/** The class the cell carries; the rules live in index.css beside .tracker-table. */
export const colAlignClass = (id) => `align-${colAlign(id)}`;
