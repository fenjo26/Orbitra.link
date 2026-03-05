import React, { useState, useEffect } from 'react';
import { Calendar, Filter, Download, BarChart3, TrendingUp, Clock, PieChart } from 'lucide-react';
import InfoBanner from './InfoBanner';
import { Line } from 'react-chartjs-2';
import {
    Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement,
    Title, Tooltip, Legend, Filler
} from 'chart.js';
import { useLanguage } from '../contexts/LanguageContext';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

const API_URL = '/api.php';

const TrendsPage = () => {
    const { t } = useLanguage();
    const [groupBy, setGroupBy] = useState('day');
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setDate(d.getDate() - 7);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);
    const [selectedMetrics, setSelectedMetrics] = useState(['clicks', 'unique_clicks', 'conversions', 'revenue', 'real_revenue', 'cost', 'profit', 'cr']);
    const [filters, setFilters] = useState([]);
    const [showFilterModal, setShowFilterModal] = useState(false);
    const [newFilter, setNewFilter] = useState({ field: 'country_code', operator: 'contains', value: '' });
    const [loading, setLoading] = useState(true);
    const [chartData, setChartData] = useState(null);
    const [tableData, setTableData] = useState([]);

    const availableMetrics = [
        { key: 'clicks', label: t('trends.clicks'), color: '#3B82F6' },
        { key: 'unique_clicks', label: t('trends.uniqueClicks'), color: '#10B981' },
        { key: 'conversions', label: t('trends.conversions'), color: '#F59E0B' },
        { key: 'revenue', label: t('trends.revenue'), color: '#8B5CF6' },
        { key: 'real_revenue', label: 'Real Rev', color: '#4338CA' },
        { key: 'cost', label: t('trends.cost'), color: '#EF4444' },
        { key: 'profit', label: t('trends.profit'), color: '#06B6D4' },
        { key: 'real_roi', label: 'Real ROI', color: '#6366F1' },
        { key: 'ctr', label: t('trends.ctr'), color: '#EC4899' },
        { key: 'cr', label: t('trends.cr'), color: '#84CC16' }
    ];

    const filterFields = [
        { value: 'country_code', label: t('trends.country') },
        { value: 'device_type', label: t('trends.device') },
        { value: 'campaign_id', label: t('trends.campaignId') },
        { value: 'offer_id', label: t('trends.offerId') },
        { value: 'ip', label: t('trends.ipAddress') },
        { value: 'browser', label: t('trends.browser') },
        { value: 'os', label: t('trends.os') }
    ];

    const filterOperators = [
        { value: 'contains', label: t('trends.contains') },
        { value: 'not_contains', label: t('trends.notContains') },
        { value: 'equals', label: t('trends.equals') },
        { value: 'not_equals', label: t('trends.notEquals') },
        { value: 'starts_with', label: t('trends.startsWith') },
        { value: 'ends_with', label: t('trends.endsWith') },
        { value: 'regexp', label: t('trends.regexp') },
        { value: 'regexp_exclude', label: t('trends.regexpExclude') }
    ];

    const fetchTrends = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({
                action: 'trends', group_by: groupBy, date_from: dateFrom,
                date_to: dateTo, metrics: selectedMetrics.join(','), filters: JSON.stringify(filters)
            });
            const res = await fetch(`${API_URL}?${params}`);
            const data = await res.json();
            if (data.status === 'success') { setChartData(data.data.chart); setTableData(data.data.table); }
        } catch (e) { console.error('Error fetching trends:', e); }
        finally { setLoading(false); }
    };

    useEffect(() => { fetchTrends(); }, [groupBy, dateFrom, dateTo, JSON.stringify(filters)]);

    const addFilter = () => {
        if (newFilter.value.trim()) {
            setFilters([...filters, { ...newFilter, id: Date.now() }]);
            setNewFilter({ field: 'country_code', operator: 'contains', value: '' });
            setShowFilterModal(false);
        }
    };

    const removeFilter = (id) => setFilters(filters.filter(f => f.id !== id));

    const toggleMetric = (key) => {
        if (selectedMetrics.includes(key)) {
            if (selectedMetrics.length > 1) setSelectedMetrics(selectedMetrics.filter(m => m !== key));
        } else setSelectedMetrics([...selectedMetrics, key]);
    };

    const exportCSV = () => {
        if (!tableData.length) return;
        const headers = [t('trends.period'), ...selectedMetrics.map(m => availableMetrics.find(am => am.key === m)?.label || m)];
        const rows = tableData.map(row => [row.period, ...selectedMetrics.map(m => row[m] || 0)]);
        const csv = [headers.join(';'), ...rows.map(r => r.join(';'))].join('\n');
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `trends_${dateFrom}_${dateTo}.csv`;
        link.click();
    };

    const chartOptions = {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
        scales: { y: { beginAtZero: true } },
        interaction: { mode: 'nearest', axis: 'x', intersect: false }
    };

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_trends" title={t('help.trendsBannerTitle')}>
                <p>{t('help.trendsBanner')}</p>
            </InfoBanner>
            <div className="page-card">
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <PieChart className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <label className="form-label" style={{ margin: 0, marginBottom: 0 }}>{t('trends.grouping')}</label>
                        <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="form-select" style={{ width: 'auto', padding: '8px 12px' }}>
                            <option value="month">{t('trends.months')}</option>
                            <option value="day_of_week">{t('trends.daysOfWeek')}</option>
                            <option value="day">{t('trends.days')}</option>
                            <option value="hour">{t('trends.hours')}</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                        <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="form-input" style={{ width: 'auto', padding: '8px 12px' }} />
                    </div>
                    <button onClick={() => setShowFilterModal(true)} className="btn btn-secondary btn-sm">
                        <Filter className="w-4 h-4" />{t('trends.addFilter')}
                    </button>
                    <button onClick={exportCSV} className="btn btn-secondary btn-sm">
                        <Download className="w-4 h-4" />{t('trends.exportCsv')}
                    </button>
                </div>
                {filters.length > 0 && (
                    <div className="flex flex-wrap gap-2" style={{ marginTop: '12px' }}>
                        {filters.map(f => (
                            <span key={f.id} className="status-badge" style={{ background: 'var(--color-info-bg)', color: 'var(--color-info)' }}>
                                {filterFields.find(ff => ff.value === f.field)?.label}: {f.value}
                                <button onClick={() => removeFilter(f.id)} style={{ marginLeft: '4px', cursor: 'pointer' }}>×</button>
                            </span>
                        ))}
                    </div>
                )}
            </div>

            <div className="page-card">
                <div className="flex items-center gap-2" style={{ marginBottom: '12px' }}>
                    <TrendingUp className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                    <span className="form-label" style={{ margin: 0 }}>{t('trends.metrics')}</span>
                </div>
                <div className="flex flex-wrap gap-2">
                    {availableMetrics.map(metric => (
                        <button key={metric.key} onClick={() => toggleMetric(metric.key)}
                            className={`btn btn-sm ${selectedMetrics.includes(metric.key) ? '' : 'btn-secondary'}`}
                            style={selectedMetrics.includes(metric.key) ? { backgroundColor: metric.color, color: 'white' } : {}}>
                            {metric.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: '16px' }}>
                    <div className="flex items-center gap-2">
                        <BarChart3 className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.chartTitle')}</h3>
                    </div>
                </div>
                <div style={{ height: '320px' }}>
                    {loading ? (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                    ) : chartData ? (
                        <Line data={chartData} options={chartOptions} />
                    ) : (
                        <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.noData')}</p></div>
                    )}
                </div>
            </div>

            <div className="page-card" style={{ padding: 0 }}>
                <div className="page-header" style={{ padding: '16px 24px', marginBottom: 0 }}>
                    <div className="flex items-center gap-2">
                        <Clock className="w-5 h-5" style={{ color: 'var(--color-text-muted)' }} />
                        <h3 className="page-title">{t('trends.reportTitle')}</h3>
                    </div>
                    <span style={{ fontSize: '14px', color: 'var(--color-text-muted)' }}>{tableData.length} {t('trends.records')}</span>
                </div>
                <div className="overflow-x-auto">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('trends.period')}</th>
                                {selectedMetrics.map(m => (
                                    <th key={m} className="text-right">{availableMetrics.find(am => am.key === m)?.label || m}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan={selectedMetrics.length + 1} className="text-center" style={{ padding: '32px' }}>
                                    <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('trends.loading')}</p></div>
                                </td></tr>
                            ) : tableData.length === 0 ? (
                                <tr><td colSpan={selectedMetrics.length + 1} className="text-center" style={{ padding: '32px' }}>
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('trends.noDataTitle')}</p>
                                        <p className="empty-state-text">{t('trends.noDataPeriod')}</p>
                                    </div>
                                </td></tr>
                            ) : (
                                tableData.map((row, idx) => (
                                    <tr key={idx}>
                                        <td style={{ fontWeight: 500 }}>{row.period}</td>
                                        {selectedMetrics.map(m => (
                                            <td key={m} className="text-right" style={{ color: 'var(--color-text-secondary)' }}>
                                                {m === 'revenue' || m === 'real_revenue' || m === 'cost' || m === 'profit'
                                                    ? `$${Number(row[m] || 0).toFixed(2)}`
                                                    : m === 'ctr' || m === 'cr' || m === 'real_roi'
                                                        ? `${Number(row[m] || 0).toFixed(2)}%`
                                                        : Number(row[m] || 0).toLocaleString('ru-RU')}
                                            </td>
                                        ))}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {showFilterModal && (
                <div className="modal-overlay">
                    <div className="modal-content">
                        <div className="modal-header"><h3 className="modal-title">{t('trends.addFilterTitle')}</h3></div>
                        <div className="space-y-4">
                            <div>
                                <label className="form-label">{t('trends.field')}</label>
                                <select value={newFilter.field} onChange={(e) => setNewFilter({ ...newFilter, field: e.target.value })} className="form-select">
                                    {filterFields.map(f => (<option key={f.value} value={f.value}>{f.label}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('trends.condition')}</label>
                                <select value={newFilter.operator} onChange={(e) => setNewFilter({ ...newFilter, operator: e.target.value })} className="form-select">
                                    {filterOperators.map(o => (<option key={o.value} value={o.value}>{o.label}</option>))}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('trends.value')}</label>
                                <input type="text" value={newFilter.value} onChange={(e) => setNewFilter({ ...newFilter, value: e.target.value })} className="form-input" placeholder={t('trends.valuePlaceholder')} />
                            </div>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowFilterModal(false)} className="btn btn-secondary">{t('trends.cancel')}</button>
                            <button onClick={addFilter} className="btn btn-primary">{t('trends.add')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default TrendsPage;