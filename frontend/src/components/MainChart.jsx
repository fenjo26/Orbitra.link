import React, { Component, useEffect, useState } from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    LineController,
    BarController,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Chart } from 'react-chartjs-2';
import { AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import {
    CHART_METRIC_META,
    clearMetricColorCache,
    isChartMetric,
    metricAxisId,
    metricColorRgb,
    metricFamily,
    metricStyle,
    metricValueKind,
    rgbToCss,
} from '../utils/chartMetrics';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    LineController,
    BarController,
    Title,
    Tooltip,
    Legend,
    Filler
);

class ChartErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error, errorInfo) {
        console.error("Chart Error:", error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="flex flex-col items-center justify-center h-full text-red-500">
                    <AlertCircle size={32} className="mb-2" />
                    <p className="font-semibold">{this.props.t?.('mainChart.chartError') || 'Chart error'}</p>
                    <p className="text-sm text-gray-500 mt-1">{this.props.t?.('mainChart.invalidData') || 'Invalid chart data'}</p>
                </div>
            );
        }
        return this.props.children;
    }
}

// UI language → a locale Intl formats dates with.
const INTL_LOCALES = { en: 'en-GB', ru: 'ru', uk: 'uk', es: 'es', de: 'de', fr: 'fr', zh: 'zh-CN' };

const LINE_DASH = {
    solid: [],
    dashed: [8, 5],
    dotted: [2, 4],
    dashdot: [10, 4, 2, 4],
};

// Axis labels in the user's own language, built from the machine-readable
// period keys the API sends ('Y-m-d H:00:00' | 'Y-m-d' | 'Y-m-01'). Falls back
// to the API's own labels for a response without them.
const buildAxisLabels = (chartData, language) => {
    const periods = Array.isArray(chartData?.periods) ? chartData.periods : null;
    const fallback = Array.isArray(chartData?.labels) ? chartData.labels : [];
    if (!periods || periods.length !== fallback.length) return fallback;

    const granularity = chartData.granularity;
    if (granularity === 'hour') {
        return periods.map((p, i) => (typeof p === 'string' && p.length >= 16 ? p.slice(11, 16) : fallback[i]));
    }

    const locale = INTL_LOCALES[language] || 'en-GB';
    let format;
    if (granularity === 'month') format = { month: 'short' };
    // A week or two reads best by weekday; longer ranges by day and month.
    else if (periods.length <= 14) format = { weekday: 'short', day: 'numeric' };
    else format = { day: 'numeric', month: 'short' };

    let formatter;
    try {
        formatter = new Intl.DateTimeFormat(locale, { ...format, timeZone: 'UTC' });
    } catch {
        return fallback;
    }
    return periods.map((p, i) => {
        const m = typeof p === 'string' ? p.match(/^(\d{4})-(\d{2})-(\d{2})/) : null;
        if (!m) return fallback[i];
        return formatter.format(new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))));
    });
};

const MainChart = ({ chartData, activeMetrics = [], currency = 'USD', chartPrefs = null }) => {
    const { t, language } = useLanguage();
    // Theme switches repaint CSS variables; drop the resolved colours and redraw.
    const [, setThemeTick] = useState(0);
    useEffect(() => {
        const onThemeChange = () => {
            clearMetricColorCache();
            setThemeTick(n => n + 1);
        };
        window.addEventListener('themeChanged', onThemeChange);
        window.addEventListener('storage', onThemeChange);
        return () => {
            window.removeEventListener('themeChanged', onThemeChange);
            window.removeEventListener('storage', onThemeChange);
        };
    }, []);

    // Basic validation to prevent immediate crashes
    const isValidData = chartData && Array.isArray(chartData.labels) && chartData.labels.length > 0;
    const currencyCode = (currency || 'USD').toUpperCase();

    // Resolve CSS variables dynamically for Canvas 2D context
    const getCssVar = (name, fallback) => {
        if (typeof window !== 'undefined') {
            const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            if (val) return val;
        }
        return fallback;
    };

    const formatNumber = (v, maxFractionDigits = 2) => {
        const num = Number(v);
        if (!Number.isFinite(num)) return String(v);
        try {
            return new Intl.NumberFormat(undefined, { maximumFractionDigits: maxFractionDigits }).format(num);
        } catch {
            return String(num);
        }
    };

    // 1 250 000 → "1.3M": the left axis must stay readable at any volume.
    const formatCompact = (v) => {
        const num = Number(v);
        if (!Number.isFinite(num)) return String(v);
        if (Math.abs(num) < 10000) return formatNumber(num, 2);
        try {
            return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(num);
        } catch {
            return formatNumber(num, 0);
        }
    };

    const formatMoney = (v, maxFractionDigits = 2) => {
        const num = Number(v);
        if (!Number.isFinite(num)) return String(v);
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currencyCode,
                maximumFractionDigits: maxFractionDigits
            }).format(num);
        } catch {
            // Fallback: if Intl rejects currency code.
            return `${formatNumber(num, maxFractionDigits)} ${currencyCode}`;
        }
    };

    const formatValue = (metricId, value) => {
        const kind = metricValueKind(metricId);
        // Money below 0.01 (crypto payouts) needs 4 digits or a real value
        // would read as 0.00 in the tooltip.
        if (kind === 'money') return formatMoney(value, Math.abs(Number(value)) < 0.01 ? 4 : 2);
        // CPC/EPC live in fractions of a cent — two digits would round them to 0.
        if (kind === 'unit') return formatMoney(value, Math.abs(Number(value)) < 1 ? 4 : 2);
        if (kind === 'percent') return `${formatNumber(value, 2)}%`;
        return formatNumber(value, 0);
    };

    const rawDatasets = isValidData && Array.isArray(chartData.datasets) ? chartData.datasets : [];
    const activeRaw = rawDatasets.filter(ds => isChartMetric(ds.label) && activeMetrics.includes(ds.label));

    // Which axes are in play, and which of them carry numbers the user reads.
    const axisIds = [...new Set(activeRaw.map(ds => metricAxisId(ds.label)))];
    const hasTraffic = axisIds.includes('yTraffic');
    const hasEvents = axisIds.includes('yEvents');
    const hasMoney = axisIds.includes('yMoney');
    // Left = real counts. Traffic when there is any; otherwise the events
    // (conversions/leads/sales) take the visible axis instead of floating.
    const leftAxisId = hasTraffic ? 'yTraffic' : (hasEvents ? 'yEvents' : null);
    const rightAxisId = hasMoney ? 'yMoney' : null;
    const visibleAxisIds = new Set([leftAxisId, rightAxisId].filter(Boolean));
    // Series scaled on an axis without numbers — named under the chart so
    // nobody has to guess which curves the visible scales do not describe.
    const ownScaleLabels = activeRaw
        .filter(ds => !visibleAxisIds.has(metricAxisId(ds.label)))
        .map(ds => t(CHART_METRIC_META[ds.label].labelKey, CHART_METRIC_META[ds.label].labelFallback));

    const mutedColor = getCssVar('--color-text-muted', '#6B7280');
    const borderColor = getCssVar('--color-border', '#1E293B');
    const axisFont = { size: 10 };

    const moneyMax = activeRaw
        .filter(ds => metricAxisId(ds.label) === 'yMoney')
        .reduce((acc, ds) => Math.max(acc, ...(ds.data || []).map(v => Math.abs(Number(v) || 0))), 0);
    // Tick spacing is ~max/5, so the display digits must cover one more
    // magnitude than the max itself: sub-0.01 money (crypto-denominated
    // payouts) would otherwise round distinct ticks into a wall of 0.000.
    const moneyTickDigits = moneyMax <= 0 ? 0
        : (moneyMax < 0.01 ? 4 : (moneyMax < 0.1 ? 3 : (moneyMax < 10 ? 2 : (moneyMax < 100 ? 1 : 0))));

    const hasBars = activeRaw.some(ds => metricStyle(ds.label, chartPrefs) === 'bars');
    const scales = {
        x: {
            // Bars need half a slot of room at both ends, or the first and
            // the last one are cut in half by the frame.
            offset: hasBars,
            grid: { display: false },
            ticks: { color: mutedColor, maxRotation: 0, autoSkipPadding: 12 },
        },
    };

    // Profit can go below zero, which pushes the money axis' zero up from the
    // floor while the count axis still starts at the floor — two different
    // "zero" lines on one chart. When that happens both visible axes are
    // given the same share of room below zero, so 0 is one line again.
    const axisExtent = (axisId) => {
        let min = 0;
        let max = 0;
        activeRaw.forEach((ds) => {
            if (metricAxisId(ds.label) !== axisId) return;
            (ds.data || []).forEach((v) => {
                const num = Number(v);
                if (v === null || v === undefined || !Number.isFinite(num)) return;
                if (num < min) min = num;
                if (num > max) max = num;
            });
        });
        return { min, max };
    };
    const alignedBounds = {};
    if (leftAxisId && rightAxisId) {
        const extents = { [leftAxisId]: axisExtent(leftAxisId), [rightAxisId]: axisExtent(rightAxisId) };
        const belowShare = Math.max(...Object.values(extents).map(({ min, max }) => (
            max - min > 0 ? -min / (max - min) : 0
        )));
        if (belowShare > 0 && belowShare < 0.9) {
            Object.entries(extents).forEach(([axisId, { min, max }]) => {
                // Headroom above the peak, then the matching room below zero.
                const top = max > 0 ? max * 1.08 : (-min * (1 - belowShare)) / belowShare;
                alignedBounds[axisId] = { min: -(belowShare * top) / (1 - belowShare), max: top };
            });
            // The series on their own scale stand on the same zero line (bars
            // would otherwise hang below it) — unless they go negative
            // themselves, like ROI, and need their own room.
            axisIds.forEach((axisId) => {
                if (alignedBounds[axisId]) return;
                const { min, max } = axisExtent(axisId);
                if (min < 0) return;
                const top = max > 0 ? max * 1.08 : 1;
                alignedBounds[axisId] = { min: -(belowShare * top) / (1 - belowShare), max: top };
            });
        }
    }
    axisIds.forEach((axisId) => {
        const isLeft = axisId === leftAxisId;
        const isRight = axisId === rightAxisId;
        const isCount = axisId === 'yTraffic' || axisId === 'yEvents';
        scales[axisId] = {
            type: 'linear',
            display: isLeft || isRight,
            position: isRight ? 'right' : 'left',
            beginAtZero: true,
            ...(alignedBounds[axisId]
                ? { min: alignedBounds[axisId].min, max: alignedBounds[axisId].max }
                // Headroom so a peak never touches the frame.
                : { grace: '8%' }),
            grid: {
                // One grid only — the left axis when there is one.
                drawOnChartArea: isLeft || (isRight && !leftAxisId),
                color: borderColor,
            },
            ticks: {
                color: mutedColor,
                ...(isCount ? { precision: 0 } : {}),
                // Zero money today still spans 0..1, and its fractional ticks
                // render as "1 1 1 0 0 0" once rounded to the axis' 0 decimals
                // — integer ticks leave a clean 0 and 1.
                ...(isRight && moneyMax <= 0 ? { precision: 0 } : {}),
                // Aligned bounds are not round numbers — keep them off the axis.
                includeBounds: !alignedBounds[axisId],
                callback: (value) => {
                    // A count cannot be negative; the room below zero only
                    // mirrors the money axis.
                    if (isCount && value < 0) return '';
                    return isRight ? formatNumber(value, moneyTickDigits) : formatCompact(value);
                },
            },
            border: { dash: [4, 4], color: borderColor },
            title: {
                display: isLeft || isRight,
                text: isRight ? currencyCode : t('dashboard.axisCount', 'Count'),
                color: mutedColor,
                font: axisFont,
                padding: { bottom: 10 },
            },
        };
    });

    // "+12%" against the previous bucket — only where a percentage of growth
    // means something (counts and money, never a rate of a rate).
    const growthSuffix = (metricId, data, index) => {
        const kind = metricValueKind(metricId);
        if (kind !== 'count' && kind !== 'money') return '';
        const current = data?.[index];
        const previous = index > 0 ? data?.[index - 1] : null;
        if (current === null || current === undefined || previous === null || previous === undefined) return '';
        const prev = Number(previous);
        const cur = Number(current);
        if (!Number.isFinite(prev) || !Number.isFinite(cur) || prev <= 0) return '';
        const pct = ((cur - prev) / prev) * 100;
        if (!Number.isFinite(pct)) return '';
        const rounded = Math.abs(pct) >= 10 ? Math.round(pct) : Math.round(pct * 10) / 10;
        if (rounded === 0) return ' (0%)';
        return ` (${rounded > 0 ? '+' : '−'}${formatNumber(Math.abs(rounded), 1)}%)`;
    };

    const granularity = chartData?.granularity;
    const growthHint = granularity === 'hour'
        ? t('dashboard.chartChangeVsPrevHour', 'In brackets: change vs the previous hour')
        : granularity === 'month'
            ? t('dashboard.chartChangeVsPrevMonth', 'In brackets: change vs the previous month')
            : t('dashboard.chartChangeVsPrevDay', 'In brackets: change vs the previous day');

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    pointStyleWidth: 26,
                    boxHeight: 8,
                    padding: 20,
                    color: getCssVar('--color-text-secondary', '#6B7280'),
                    // The legend shows each series the way it is drawn — a
                    // solid/dashed/dotted stroke or a bar — so a series can be
                    // told apart without relying on its colour.
                    generateLabels: (chart) => ChartJS.defaults.plugins.legend.labels.generateLabels(chart).map((item) => {
                        const ds = chart.data.datasets[item.datasetIndex] || {};
                        const isBar = ds.type === 'bar';
                        return {
                            ...item,
                            pointStyle: isBar ? 'rect' : 'line',
                            lineDash: isBar ? [] : (ds.borderDash || []),
                            lineWidth: isBar ? 1 : 2.5,
                            strokeStyle: ds.borderColor,
                            fillStyle: isBar ? ds.backgroundColor : ds.borderColor,
                        };
                    }),
                }
            },
            tooltip: {
                backgroundColor: getCssVar('--color-bg-card', 'rgba(15, 23, 42, 0.9)'),
                titleColor: getCssVar('--color-text-primary', '#F3F4F6'),
                bodyColor: getCssVar('--color-text-primary', '#F3F4F6'),
                footerColor: mutedColor,
                footerFont: { size: 10, weight: 'normal' },
                borderColor: borderColor,
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
                displayColors: true,
                // A bucket without a value (the future, or an undefined ratio)
                // has nothing to say.
                filter: (item) => item.raw !== null && item.raw !== undefined,
                callbacks: {
                    label: (context) => {
                        const metricId = context.dataset.metricKey;
                        const value = context.raw;
                        const name = context.dataset.label ? `${context.dataset.label}: ` : '';
                        return `${name}${formatValue(metricId, value)}${growthSuffix(metricId, context.dataset.data, context.dataIndex)}`;
                    },
                    footer: (items) => (
                        items.some(item => growthSuffix(item.dataset.metricKey, item.dataset.data, item.dataIndex) !== '')
                            ? growthHint
                            : ''
                    ),
                }
            }
        },
        scales,
    };

    const datasets = activeRaw.map((ds) => {
        const metricId = ds.label;
        const meta = CHART_METRIC_META[metricId];
        const rgb = metricColorRgb(metricId, chartPrefs);
        const color = rgbToCss(rgb);
        const style = metricStyle(metricId, chartPrefs);
        const family = metricFamily(metricId);
        const data = (ds.data || []).map(v => (v === null || v === undefined ? null : Number(v)));
        const common = {
            label: t(meta.labelKey, meta.labelFallback),
            metricKey: metricId,
            data,
            yAxisID: metricAxisId(metricId),
            borderColor: color,
        };

        if (style === 'bars') {
            return {
                ...common,
                type: 'bar',
                backgroundColor: rgbToCss(rgb, 0.55),
                borderWidth: 1,
                borderRadius: 3,
                maxBarThickness: 18,
                categoryPercentage: 0.7,
                barPercentage: 0.8,
                // Bars sit behind the lines.
                order: 10,
            };
        }

        // Only the solid series on a readable axis get the soft area fill;
        // filling dashed "own scale" lines would suggest a volume they are not.
        const filled = style === 'solid' && (family === 'traffic' || family === 'money');
        return {
            ...common,
            type: 'line',
            borderDash: LINE_DASH[style] || [],
            borderCapStyle: style === 'dotted' ? 'round' : 'butt',
            backgroundColor: filled
                ? (context) => {
                    const { chart } = context;
                    const area = chart.chartArea;
                    if (!area) return rgbToCss(rgb, 0.1);
                    const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                    gradient.addColorStop(0, rgbToCss(rgb, 0.15)); // fades down to avoid muddy overlaps
                    gradient.addColorStop(1, rgbToCss(rgb, 0));
                    return gradient;
                }
                : rgbToCss(rgb, 0),
            fill: filled,
            // Monotone: the curve never overshoots between two points, so it
            // cannot dip below zero or invent a peak that is not in the data.
            cubicInterpolationMode: 'monotone',
            tension: 0,
            // A ratio can be undefined in the middle of the range (ROI without
            // spend); bridge those, but the future stays empty.
            spanGaps: family !== 'traffic' && family !== 'money' && family !== 'events',
            borderWidth: 2.5,
            // A lone point between two gaps would otherwise be invisible.
            pointRadius: (context) => {
                const values = context.dataset.data || [];
                const i = context.dataIndex;
                if (values[i] === null || values[i] === undefined) return 0;
                const prevMissing = i === 0 || values[i - 1] === null || values[i - 1] === undefined;
                const nextMissing = i === values.length - 1 || values[i + 1] === null || values[i + 1] === undefined;
                return prevMissing && nextMissing ? 3 : 0;
            },
            pointBackgroundColor: color,
            pointHoverRadius: 5,
            order: 1,
        };
    });

    const data = {
        labels: isValidData ? buildAxisLabels(chartData, language) : [],
        datasets,
    };

    return (
        <div className="card shadow-sm p-5 mb-6" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            <div className="flex justify-between items-center mb-4">
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{t('dashboard.trafficDynamics')}</h2>
            </div>
            <div className="w-full h-[300px]">
                {!isValidData ? (
                    <div className="flex flex-col items-center justify-center h-full text-gray-400">
                        <AlertCircle size={32} className="mb-2" />
                        <p>{t('dashboard.noStatsData')}</p>
                    </div>
                ) : datasets.length === 0 ? (
                    <div
                        className="flex flex-col items-center justify-center h-full text-sm text-center px-6"
                        style={{ color: 'var(--color-text-muted)' }}
                        data-testid="chart-empty-hint"
                    >
                        <p>{t('dashboard.chartPickMetric', 'Click a metric card above to draw it on the chart')}</p>
                    </div>
                ) : (
                    <ChartErrorBoundary t={t}>
                        <Chart type="line" options={options} data={data} />
                    </ChartErrorBoundary>
                )}
            </div>
            {isValidData && ownScaleLabels.length > 0 && (
                <p className="text-[11px] mt-3 leading-snug" style={{ color: 'var(--color-text-muted)' }} data-testid="chart-scale-hint">
                    {t('dashboard.chartOwnScale', 'Drawn in their own scale so they stay visible (hover the chart for exact values): {list}')
                        .replace('{list}', ownScaleLabels.join(', '))}
                </p>
            )}
        </div>
    );
};

export default MainChart;
