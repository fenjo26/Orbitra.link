import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X, Download, Filter, BarChart3 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const CampaignReports = ({ campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [reportData, setReportData] = useState([]);
    const [groupBy, setGroupBy] = useState('country');
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setDate(d.getDate() - 7);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);

    const dimensions = [
        { value: 'country', label: t('campaignReports.geoCountry') },
        { value: 'device_type', label: t('campaignReports.deviceType') },
        { value: 'language', label: t('campaignReports.language') },
        { value: 'stream_id', label: t('campaignReports.stream') },
        { value: 'source_id', label: t('campaignReports.source') },
        { value: 'sub_id_1', label: 'Sub ID 1' },
        { value: 'sub_id_2', label: 'Sub ID 2' },
        { value: 'sub_id_3', label: 'Sub ID 3' },
        { value: 'sub_id_4', label: 'Sub ID 4' },
        { value: 'sub_id_5', label: 'Sub ID 5' },
    ];

    const fetchReport = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=campaign_report`, {
                params: { campaign_id: campaignId, group_by: groupBy, date_from: dateFrom, date_to: dateTo }
            });
            if (res.data.status === 'success') setReportData(res.data.data);
            else alert(t('campaignReports.loadError') + res.data.message);
        } catch (e) {
            console.error(e);
            alert(t('campaignReports.networkError'));
        } finally { setLoading(false); }
    };

    useEffect(() => { fetchReport(); }, [campaignId, groupBy, dateFrom, dateTo]);

    const exportToCSV = () => {
        if (!reportData.length) return;
        const headers = [
            dimensions.find(d => d.value === groupBy)?.label || groupBy,
            t('campaignReports.clicks'), t('campaignReports.unique'), t('campaignReports.conversions'),
            'CR (%)', 'EPC', t('campaignReports.revenue'), 'Real Rev', t('campaignReports.cost'), t('campaignReports.profit'), 'Real ROI (%)'
        ];
        const csvContent = [
            headers.join(','),
            ...reportData.map(row => [
                `"${String(row.dimension_name).replace(/"/g, '""')}"`,
                row.clicks, row.unique_clicks, row.conversions, row.cr, row.epc, row.revenue, row.real_revenue, row.cost, row.profit, row.real_roi
            ].join(','))
        ].join('\n');
        const bom = new Uint8Array([0xEF, 0xBB, 0xBF]);
        const blob = new Blob([bom, csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.setAttribute('href', URL.createObjectURL(blob));
        link.setAttribute('download', `report_campaign_${campaignId}_${groupBy}_${dateFrom}_to_${dateTo}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link); link.click(); document.body.removeChild(link);
    };

    return (
        <div className="fixed inset-0 z-[1100] flex bg-black bg-opacity-50">
            <div className="flex flex-col w-full h-full bg-[var(--color-bg-main)]" style={{ paddingTop: '88px' }}>
                <div className="flex justify-between items-center px-6 py-4 border-b shadow-sm" style={{ background: 'var(--color-bg-header)', color: 'var(--color-text-header)', borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-3">
                        <BarChart3 size={20} />
                        <div><h2 className="text-xl font-semibold">{t('campaignReports.report')} {campaignName}</h2></div>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={exportToCSV} className="btn btn-success flex items-center gap-2 text-sm font-medium">
                            <Download size={16} /> {t('campaignReports.exportCsv')}
                        </button>
                        <button onClick={onClose} className="btn btn-ghost btn-icon" title={t('campaignReports.close')}>
                            <X size={24} />
                        </button>
                    </div>
                </div>
                <div className="p-4 bg-[var(--color-bg-card)] border-b shadow-sm flex flex-wrap gap-4 items-center" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                    <div className="flex items-center gap-2">
                        <Filter size={16} style={{ color: 'var(--color-text-muted)' }} />
                        <span className="text-sm font-medium">{t('campaignReports.grouping')}</span>
                        <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="form-select">
                            {dimensions.map(d => (<option key={d.value} value={d.value}>{d.label}</option>))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2 ml-4">
                        <span className="text-sm font-medium">{t('campaignReports.period')}</span>
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="form-input" />
                        <span>-</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="form-input" />
                    </div>
                </div>
                <div className="flex-1 overflow-auto p-6" style={{ color: 'var(--color-text-primary)' }}>
                    {loading ? (
                        <div className="flex justify-center items-center h-64">
                            <div className="animate-spin rounded-full h-12 w-12 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                        </div>
                    ) : (
                        <div className="page-card" style={{ padding: 0, overflow: 'hidden' }}>
                            <table className="page-table">
                                <thead>
                                    <tr>
                                        <th>{dimensions.find(d => d.value === groupBy)?.label}</th>
                                        <th className="text-right">{t('campaignReports.clicks')}</th>
                                        <th className="text-right">{t('campaignReports.unique')}</th>
                                        <th className="text-right">{t('campaignReports.conversions')}</th>
                                        <th className="text-right">CR</th>
                                        <th className="text-right">EPC</th>
                                        <th className="text-right">{t('campaignReports.revenue')}</th>
                                        <th className="text-right" style={{ color: 'var(--color-primary)' }}>Real Rev</th>
                                        <th className="text-right">{t('campaignReports.cost')}</th>
                                        <th className="text-right">{t('campaignReports.profit')}</th>
                                        <th className="text-right" style={{ color: 'var(--color-primary)' }}>Real ROI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reportData.length === 0 ? (
                                        <tr><td colSpan="11" className="text-center p-8" style={{ color: 'var(--color-text-muted)' }}>{t('campaignReports.noDataFilters')}</td></tr>
                                    ) : (
                                        reportData.map((row, idx) => (
                                            <tr key={idx} className="text-sm">
                                                <td className="font-medium" style={{ color: 'var(--color-primary)' }}>{row.dimension_name}</td>
                                                <td className="text-right">{parseInt(row.clicks).toLocaleString('ru-RU')}</td>
                                                <td className="text-right" style={{ color: 'var(--color-text-secondary)' }}>{parseInt(row.unique_clicks).toLocaleString('ru-RU')}</td>
                                                <td className="text-right">{parseInt(row.conversions) > 0 ? <span className="font-medium" style={{ color: 'var(--color-success)' }}>{row.conversions}</span> : '0'}</td>
                                                <td className="text-right" style={{ color: 'var(--color-text-secondary)' }}>{row.cr}%</td>
                                                <td className="text-right" style={{ color: 'var(--color-text-secondary)' }}>{row.epc}</td>
                                                <td className="text-right font-medium" style={{ color: 'var(--color-success)' }}>{parseFloat(row.revenue).toFixed(2)}</td>
                                                <td className="text-right font-semibold" style={{ color: 'var(--color-primary)' }}>{parseFloat(row.real_revenue || 0).toFixed(2)}</td>
                                                <td className="text-right" style={{ color: 'var(--color-danger)' }}>{parseFloat(row.cost).toFixed(2)}</td>
                                                <td className={`text-right font-medium ${
                                                    parseFloat(row.profit) > 0 ? 'var(--color-success)' :
                                                    parseFloat(row.profit) < 0 ? 'var(--color-danger)' : ''
                                                }`} style={{
                                                    color: parseFloat(row.profit) > 0 ? 'var(--color-success)' :
                                                           parseFloat(row.profit) < 0 ? 'var(--color-danger)' : 'inherit'
                                                }}>
                                                    {parseFloat(row.profit) > 0 ? '+' : ''}{parseFloat(row.profit).toFixed(2)}
                                                </td>
                                                <td className={`text-right font-medium ${
                                                    parseFloat(row.real_roi || 0) > 0 ? 'var(--color-primary)' :
                                                    parseFloat(row.real_roi || 0) < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)'
                                                }`} style={{
                                                    color: parseFloat(row.real_roi || 0) > 0 ? 'var(--color-primary)' :
                                                           parseFloat(row.real_roi || 0) < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)'
                                                }}>
                                                    {parseFloat(row.real_roi || 0) > 0 ? '+' : ''}{parseFloat(row.real_roi || 0).toFixed(2)}%
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default CampaignReports;
