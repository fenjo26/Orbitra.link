import React, { Component } from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import { AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
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

    static getDerivedStateFromError(error) {
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
                    <p className="font-semibold">{t('mainChart.chartError')}</p>
                    <p className="text-sm text-gray-500 mt-1">{t('mainChart.invalidData')}</p>
                </div>
            );
        }
        return this.props.children;
    }
}

const MainChart = ({ chartData, activeMetrics = [] }) => {
    const { t } = useLanguage();
    // Basic validation to prevent immediate crashes
    const isValidData = chartData && Array.isArray(chartData.labels) && chartData.labels.length > 0;

    const transformToPercentage = (dataArray) => {
        if (!dataArray || dataArray.length === 0) return [];
        const baseValue = dataArray.find(val => val > 0);
        if (!baseValue) return dataArray; // all zeros
        return dataArray.map(val => {
            if (val === 0) return 0;
            return Math.round((val / baseValue) * 100);
        });
    };

    // Resolve CSS variables dynamically for Canvas 2D context
    const getCssVar = (name, fallback) => {
        if (typeof window !== 'undefined') {
            const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            if (val) return val;
        }
        return fallback;
    };

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
                    boxWidth: 8,
                    boxHeight: 8,
                    padding: 20,
                    color: getCssVar('--color-text-secondary', '#6B7280')
                }
            },
            tooltip: {
                backgroundColor: getCssVar('--color-bg-card', 'rgba(15, 23, 42, 0.9)'),
                titleColor: getCssVar('--color-text-primary', '#F3F4F6'),
                bodyColor: getCssVar('--color-text-primary', '#F3F4F6'),
                borderColor: getCssVar('--color-border', '#1E293B'),
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
                displayColors: true,
                callbacks: {
                    label: function (context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += context.parsed.y + '%';
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                beginAtZero: true,
                grid: {
                    color: getCssVar('--color-border', '#1E293B'),
                },
                ticks: {
                    precision: 0,
                    color: getCssVar('--color-text-muted', '#6B7280'),
                },
                border: { dash: [4, 4], color: getCssVar('--color-border', '#1E293B') },
                title: { display: true, text: t('dashboard.scale'), color: getCssVar('--color-text-muted', '#6B7280'), font: { size: 10, family: 'Plus Jakarta Sans' }, padding: { bottom: 10 } }
            },
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    color: getCssVar('--color-text-muted', '#6B7280'),
                }
            }
        },
    };

    const chartColors = {
        'clicks': getCssVar('--color-primary', '#2563EB'),
        'unique_clicks': getCssVar('--color-accent-turquoise', '#0EA5E9'),
        'conversions': getCssVar('--color-success', '#10B981'),
        'cost': getCssVar('--color-danger', '#EF4444'),
        'revenue': getCssVar('--color-warning', '#F59E0B'),
        'profit': getCssVar('--color-info', '#3B82F6'),
        'roi': getCssVar('--color-accent-purple', '#8B5CF6'),
        'real_revenue': getCssVar('--color-real-rev', '#4338CA'),
        'real_roi': getCssVar('--color-real-roi', '#6366F1'),
        'ctr': getCssVar('--color-ctr', '#EC4899'),
    };

    const defaultDatasets = isValidData && chartData.datasets ? chartData.datasets : [];

    // Filter by active metrics and apply the same percentage transformation and colors
    const activeDatasets = defaultDatasets
        .filter(ds => activeMetrics.includes(ds.label))
        .map(ds => {
            const color = chartColors[ds.label] || '#9ca3af';

            // Map the label for legend display
            let translatedLabel = ds.label;
            if (ds.label === 'unique_clicks') translatedLabel = t('metrics.uniqueClicks');
            else if (ds.label === 'clicks') translatedLabel = t('metrics.clicks');
            else if (ds.label === 'conversions') translatedLabel = t('metrics.conversions');
            else if (ds.label === 'cost') translatedLabel = t('metrics.cost');
            else if (ds.label === 'revenue') translatedLabel = t('metrics.revenue');
            else if (ds.label === 'profit') translatedLabel = t('metrics.profit');
            else if (ds.label === 'roi') translatedLabel = t('metrics.roi');
            else if (ds.label === 'real_revenue') translatedLabel = t('metrics.realRevenue') || 'Real Rev';
            else if (ds.label === 'real_roi') translatedLabel = t('metrics.realRoi') || 'Real ROI';
            else if (ds.label === 'ctr') translatedLabel = t('metrics.ctr') || 'CTR';

            return {
                label: translatedLabel,
                originalLabel: ds.label, // keep for reference
                data: transformToPercentage(ds.data),
                borderColor: color,
                backgroundColor: (context) => {
                    const ctx = context.chart.ctx;
                    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
                    // Add safe hex to rgba conversions dynamically
                    const hexToRgba = (hex, alpha) => {
                        let r = 0, g = 0, b = 0;
                        if (hex.startsWith('#')) {
                            if (hex.length === 4) {
                                r = parseInt(hex[1] + hex[1], 16);
                                g = parseInt(hex[2] + hex[2], 16);
                                b = parseInt(hex[3] + hex[3], 16);
                            } else if (hex.length === 7) {
                                r = parseInt(hex.slice(1, 3), 16);
                                g = parseInt(hex.slice(3, 5), 16);
                                b = parseInt(hex.slice(5, 7), 16);
                            }
                        } else if (hex.startsWith('rgb')) {
                            return hex.replace('rgb', 'rgba').replace(')', `, ${alpha})`);
                        }
                        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                    };

                    gradient.addColorStop(0, hexToRgba(color, 0.15)); // 15% opacity fading down to avoid muddy overlaps
                    gradient.addColorStop(1, hexToRgba(color, 0)); // 0% opacity
                    return gradient;
                },
                fill: true,
                yAxisID: 'y',
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 0,
                pointHoverRadius: 6,
            };
        });

    const data = {
        labels: isValidData ? chartData.labels : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: activeDatasets.length > 0 ? activeDatasets : [{
            label: t('dashboard.noData'),
            data: [0, 0, 0, 0, 0, 0, 0],
            borderColor: '#e5e7eb',
            backgroundColor: '#e5e7eb',
        }],
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
                ) : (
                    <ChartErrorBoundary>
                        <Line options={options} data={data} />
                    </ChartErrorBoundary>
                )}
            </div>
        </div>
    );
};

export default MainChart;
