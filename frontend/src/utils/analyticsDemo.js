// Made-up data for the Analytics demo mode ("Show me on an example").
//
// Deterministic on purpose: the tour text describes what the numbers show
// ("clicks grow while CR falls", "M1 already loses a third"), so the numbers
// must tell that story every time. The derived metrics are computed with the
// same formulas as api.php (action=trends / action=cohort), so the example
// teaches how the real page counts — including that the Total of unique
// clicks is smaller than the sum of the rows.

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const ym = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
const round2 = (n) => Math.round(n * 100) / 100;

// Same rules as $trendCr / $trendCtr / $trendRealRoi in api.php.
const cr = (conv, clicks) => (clicks > 0 ? round2((conv / clicks) * 100) : 0);
const ctr = (lp, pre) => (pre > 0 ? round2((lp / pre) * 100) : null);
const realRoi = (rp, cost) => (cost > 0 ? round2((rp / cost) * 100) : (rp > 0 ? 100 : 0));

const WIGGLE = [1, 1.06, 0.97, 1.04, 0.99, 1.08, 1.02, 0.95, 1.05, 1.01, 0.98, 1.03];
// Hourly traffic shape: quiet night, evening peak.
const HOUR_SHAPE = [0.35, 0.25, 0.2, 0.18, 0.2, 0.3, 0.5, 0.75, 0.9, 1, 1.05, 1.1,
    1.1, 1.05, 1.05, 1.1, 1.2, 1.35, 1.5, 1.6, 1.55, 1.3, 0.95, 0.6];

const buckets = (groupBy) => {
    const now = new Date();
    if (groupBy === 'hour') {
        const day = ymd(now);
        return Array.from({ length: 24 }, (_, h) => ({ key: `${day} ${pad(h)}:00:00`, base: 45 * HOUR_SHAPE[h] }));
    }
    if (groupBy === 'day_of_week') {
        // 0 = Sunday, like strftime('%w') on the server.
        return Array.from({ length: 7 }, (_, d) => ({ key: String(d), base: 900 }));
    }
    if (groupBy === 'month') {
        return Array.from({ length: 6 }, (_, i) => {
            const d = new Date(now.getFullYear(), now.getMonth() - 5 + i, 1);
            return { key: ym(d), base: 26000 };
        });
    }
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6 + i);
        return { key: ymd(d), base: 900 };
    });
};

export const buildTrendsDemo = (groupBy) => {
    const list = buckets(groupBy);
    const last = Math.max(1, list.length - 1);
    // For the hourly view the story runs along the day, so CR follows the
    // bucket index too; for the others it runs across the period.
    const table = list.map((b, i) => {
        const p = i / last;
        const clicks = Math.round(b.base * (1 + 0.8 * p) * WIGGLE[i % WIGGLE.length]);
        const conversions = Math.round(clicks * (3.2 - 1.8 * p) / 100);
        const revenue = conversions * 25;
        const realRevenue = round2(revenue * 0.88);
        const cost = round2(clicks * 0.42);
        const prelander = Math.round(clicks * 0.85);
        const lpClicks = Math.round(prelander * (0.44 - 0.18 * p));
        return {
            period: b.key,
            period_key: b.key,
            clicks,
            unique_clicks: Math.round(clicks * 0.82),
            conversions,
            revenue: round2(revenue),
            real_revenue: realRevenue,
            cost,
            profit: round2(revenue - cost),
            real_profit: round2(realRevenue - cost),
            ctr: ctr(lpClicks, prelander),
            cr: cr(conversions, clicks),
            real_roi: realRoi(realRevenue - cost, cost),
            _pre: prelander,
            _lp: lpClicks,
        };
    });
    const sum = (k) => table.reduce((acc, r) => acc + r[k], 0);
    const clicks = sum('clicks');
    const conversions = sum('conversions');
    const revenue = sum('revenue');
    const realRevenue = sum('real_revenue');
    const cost = sum('cost');
    const totals = {
        clicks,
        // Returning visitors: across the whole range there are fewer unique
        // people than the per-period uniques add up to.
        unique_clicks: Math.round(sum('unique_clicks') * 0.71),
        conversions,
        revenue: round2(revenue),
        real_revenue: round2(realRevenue),
        cost: round2(cost),
        profit: round2(revenue - cost),
        real_profit: round2(realRevenue - cost),
        ctr: ctr(sum('_lp'), sum('_pre')),
        cr: cr(conversions, clicks),
        real_roi: realRoi(realRevenue - cost, cost),
    };
    // Drop the helper fields the server does not send.
    for (const r of table) { delete r._pre; delete r._lp; }
    return { table, totals };
};

// Four launch cohorts; the oldest has lived four periods (M0..M3), the
// newest only one. Each period keeps less than the one before — the
// "campaigns burn out" curve the cohort tour points at.
export const buildCohortDemo = (granularity) => {
    const now = new Date();
    const labels = Array.from({ length: 4 }, (_, i) => {
        if (granularity === 'quarter') {
            const q0 = now.getFullYear() * 4 + Math.floor(now.getMonth() / 3) - 3 + i;
            return `${Math.floor(q0 / 4)}-Q${(q0 % 4) + 1}`;
        }
        return ym(new Date(now.getFullYear(), now.getMonth() - 3 + i, 1));
    });
    const launched = [6, 8, 5, 7];
    const decay = [1, 0.62, 0.38, 0.21];
    const activeShare = [1, 0.75, 0.5, 0.34];
    const crByAge = [2.6, 2.3, 2.1, 1.9];
    const scale = granularity === 'quarter' ? 3 : 1;
    const rows = [];
    labels.forEach((label, c) => {
        const periods = labels.length - c;
        for (let p = 0; p < periods; p++) {
            const clicks = Math.round(launched[c] * 3000 * scale * decay[p] * WIGGLE[(c * 3 + p) % WIGGLE.length]);
            const conversions = Math.round(clicks * crByAge[p] / 100);
            const revenue = conversions * 30;
            const realRevenue = round2(revenue * 0.9);
            const cost = round2(clicks * 0.55);
            const profit = round2(revenue - cost);
            rows.push({
                cohort_label: label,
                period_index: p,
                clicks,
                unique_clicks: Math.round(clicks * 0.8),
                conversions,
                revenue: round2(revenue),
                real_revenue: realRevenue,
                cost,
                profit,
                cr: cr(conversions, clicks),
                roi: cost > 0 ? round2((profit / cost) * 100) : 0,
                real_roi: realRoi(realRevenue - cost, cost),
                campaigns_active: Math.max(1, Math.round(launched[c] * activeShare[p])),
            });
        }
    });
    return {
        rows,
        launched: Object.fromEntries(labels.map((l, i) => [l, launched[i]])),
        max_period: labels.length - 1,
    };
};
