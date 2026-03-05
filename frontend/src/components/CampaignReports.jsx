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
        <div className="fixed inset-0 z-[100] flex bg-black bg-opacity-50">
            <div className="flex flex-col w-full h-full bg-[var(--color-bg-main)]">
                <div className="flex justify-between items-center px-6 py-4 border-b bg-[var(--color-bg-header)] text-[var(--color-text-header)] shadow-sm">
                    <div className="flex items-center gap-3">
                        <BarChart3 size={20} />
                        <div><h2 className="text-xl font-semibold">{t('campaignReports.report')} {campaignName}</h2></div>
                    </div>
                    <div className="flex gap-3">
                        <button onClick={exportToCSV} className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 flex items-center gap-2 text-sm font-medium transition-colors">
                            <Download size={16} /> {t('campaignReports.exportCsv')}
                        </button>
                        <button onClick={onClose} className="p-2 hover:bg-white/10 rounded transition-colors" title={t('campaignReports.close')}>
                            <X size={24} />
                        </button>
                    </div>
                </div>
                <div className="p-4 bg-[var(--color-bg-card)] border-b shadow-sm flex flex-wrap gap-4 items-center text-[var(--color-text-primary)]">
                    <div className="flex items-center gap-2">
                        <Filter size={16} className="text-gray-400" />
                        <span className="text-sm font-medium">{t('campaignReports.grouping')}</span>
                        <select value={groupBy} onChange={(e) => setGroupBy(e.target.value)} className="border rounded px-3 py-1.5 text-sm bg-white text-gray-800 outline-none">
                            {dimensions.map(d => (<option key={d.value} value={d.value}>{d.label}</option>))}
                        </select>
                    </div>
                    <div className="flex items-center gap-2 ml-4">
                        <span className="text-sm font-medium">{t('campaignReports.period')}</span>
                        <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="border rounded px-3 py-1.5 text-sm bg-white text-gray-800 outline-none" />
                        <span>-</span>
                        <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="border rounded px-3 py-1.5 text-sm bg-white text-gray-800 outline-none" />
                    </div>
                </div>
                <div className="flex-1 overflow-auto p-6 text-[var(--color-text-primary)]">
                    {loading ? (
                        <div className="flex justify-center items-center h-64">
                            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--color-primary)]"></div>
                        </div>
                    ) : (
                        <div className="card shadow-sm border border-[var(--color-primary-light)] overflow-hidden rounded">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-gray-50 border-b text-sm text-gray-500 uppercase">
                                        <th className="p-3 font-semibold">{dimensions.find(d => d.value === groupBy)?.label}</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.clicks')}</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.unique')}</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.conversions')}</th>
                                        <th className="p-3 font-semibold text-right">CR</th>
                                        <th className="p-3 font-semibold text-right">EPC</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.revenue')}</th>
                                        <th className="p-3 font-semibold text-right text-indigo-600 bg-indigo-50/50">Real Rev</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.cost')}</th>
                                        <th className="p-3 font-semibold text-right">{t('campaignReports.profit')}</th>
                                        <th className="p-3 font-semibold text-right text-indigo-600 bg-indigo-50/50">Real ROI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reportData.length === 0 ? (
                                        <tr><td colSpan="11" className="p-8 text-center text-gray-500">{t('campaignReports.noDataFilters')}</td></tr>
                                    ) : (
                                        reportData.map((row, idx) => (
                                            <tr key={idx} className="border-b last:border-0 hover:bg-gray-50/50 transition-colors text-sm">
                                                <td className="p-3 font-medium text-[var(--color-primary)]">{row.dimension_name}</td>
                                                <td className="p-3 text-right">{parseInt(row.clicks).toLocaleString('ru-RU')}</td>
                                                <td className="p-3 text-right text-gray-500">{parseInt(row.unique_clicks).toLocaleString('ru-RU')}</td>
                                                <td className="p-3 text-right">{parseInt(row.conversions) > 0 ? <span className="text-green-600 font-medium">{row.conversions}</span> : '0'}</td>
                                                <td className="p-3 text-right text-gray-500">{row.cr}%</td>
                                                <td className="p-3 text-right text-gray-500">{row.epc}</td>
                                                <td className="p-3 text-right text-green-600 font-medium">{parseFloat(row.revenue).toFixed(2)}</td>
                                                <td className="p-3 text-right text-indigo-600 font-semibold bg-indigo-50/30">{parseFloat(row.real_revenue || 0).toFixed(2)}</td>
                                                <td className="p-3 text-right text-red-500">{parseFloat(row.cost).toFixed(2)}</td>
                                                <td className={`p-3 text-right font-medium ${parseFloat(row.profit) > 0 ? 'text-green-600' : parseFloat(row.profit) < 0 ? 'text-red-500' : ''}`}>
                                                    {parseFloat(row.profit) > 0 ? '+' : ''}{parseFloat(row.profit).toFixed(2)}
                                                </td>
                                                <td className={`p-3 text-right font-medium bg-indigo-50/30 ${parseFloat(row.real_roi || 0) > 0 ? 'text-indigo-600' : parseFloat(row.real_roi || 0) < 0 ? 'text-red-500' : 'text-gray-500'}`}>
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
