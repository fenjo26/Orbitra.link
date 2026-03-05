import React, { useState } from 'react';
import { X, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const DashboardSettingsModal = ({ preferences, setPreferences, onClose }) => {
    const { t } = useLanguage();
    const [localPrefs, setLocalPrefs] = useState(() => ({
        visible_metrics: preferences?.visible_metrics || ['clicks', 'unique_clicks', 'conversions', 'revenue', 'cost', 'profit', 'roi', 'real_revenue', 'real_roi', 'ctr', 'cpc', 'cpa'],
        visible_blocks: preferences?.visible_blocks || ['campaigns', 'offers', 'landings', 'sources'],
        click_columns: preferences?.click_columns || ['created_at', 'campaign_name', 'country_code', 'ip', 'device_type', 'user_agent', 'redirect_url']
    }));

    const toggleArrayItem = (array, item) => {
        if (!array) array = [];
        if (array.includes(item)) {
            return array.filter(i => i !== item);
        }
        return [...array, item];
    };

    const handleToggleMetric = (id) => {
        setLocalPrefs(prev => ({
            ...prev,
            visible_metrics: toggleArrayItem(prev.visible_metrics, id)
        }));
    };

    const handleToggleBlock = (id) => {
        setLocalPrefs(prev => ({
            ...prev,
            visible_blocks: toggleArrayItem(prev.visible_blocks, id)
        }));
    };

    const handleToggleColumn = (id) => {
        setLocalPrefs(prev => ({
            ...prev,
            click_columns: toggleArrayItem(prev.click_columns, id)
        }));
    };

    const handleSave = () => {
        setPreferences(localPrefs);
        onClose();
    };

    const metricsList = [
        { id: 'clicks', label: t('metrics.clicks') },
        { id: 'unique_clicks', label: t('metrics.uniqueClicks') },
        { id: 'conversions', label: t('metrics.conversions') },
        { id: 'revenue', label: t('metrics.revenue') },
        { id: 'cost', label: t('metrics.cost') },
        { id: 'profit', label: t('metrics.profit') },
        { id: 'roi', label: t('metrics.roi') },
        { id: 'real_revenue', label: t('metrics.realRevenue') || 'Real Rev' },
        { id: 'real_roi', label: t('metrics.realRoi') || 'Real ROI' },
        { id: 'ctr', label: t('metrics.ctr') || 'CTR' },
        { id: 'cpc', label: t('metrics.cpc') },
        { id: 'cpa', label: t('metrics.cpa') },
    ];

    const blocksList = [
        { id: 'campaigns', label: t('nav.campaigns') },
        { id: 'offers', label: t('nav.offers') },
        { id: 'landings', label: t('nav.landings') },
        { id: 'sources', label: t('nav.sources') },
    ];

    const columnsList = [
        { id: 'created_at', label: t('dashboard.colDate') },
        { id: 'campaign_name', label: t('dashboard.colCampaign') },
        { id: 'country_code', label: t('dashboard.colGeo') },
        { id: 'device_type', label: t('dashboard.colOs') },
        { id: 'ip', label: t('dashboard.colIp') },
        { id: 'user_agent', label: t('dashboard.colUa') },
        { id: 'redirect_url', label: t('dashboard.colUrl') },
    ];

    const CheckboxItem = ({ checked, onChange, label }) => (
        <label onClick={(e) => { e.preventDefault(); onChange(); }} className="flex items-center space-x-3 p-2 hover:bg-gray-50 rounded cursor-pointer transition select-none">
            <div className={`w-5 h-5 rounded border flex items-center justify-center transition-colors ${checked ? 'bg-blue-600 border-blue-600' : 'border-gray-300 bg-white'}`}>
                {checked && <Check size={14} className="text-white" />}
            </div>
            <span className="text-gray-700 text-sm font-medium">{label}</span>
        </label>
    );

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50">
            <div className="bg-white rounded-lg shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div className="flex justify-between items-center p-5 border-b border-gray-100">
                    <h2 className="text-xl font-bold text-gray-800">{t('dashboard.dashboardSettings')}</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 p-1 rounded transition">
                        <X size={24} />
                    </button>
                </div>

                <div className="overflow-y-auto p-6 space-y-8 flex-1">
                    <div>
                        <h3 className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100">{t('dashboard.metricsCards')}</h3>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            {metricsList.map(m => (
                                <CheckboxItem
                                    key={m.id}
                                    label={m.label}
                                    checked={localPrefs.visible_metrics?.includes(m.id)}
                                    onChange={() => handleToggleMetric(m.id)}
                                />
                            ))}
                        </div>
                    </div>

                    <div>
                        <h3 className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100">{t('dashboard.dataBlocks')}</h3>
                        <div className="grid grid-cols-2 gap-2">
                            {blocksList.map(b => (
                                <CheckboxItem
                                    key={b.id}
                                    label={b.label}
                                    checked={localPrefs.visible_blocks?.includes(b.id)}
                                    onChange={() => handleToggleBlock(b.id)}
                                />
                            ))}
                        </div>
                    </div>

                    <div>
                        <h3 className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4 pb-2 border-b border-gray-100">{t('dashboard.recentClicksColumns')}</h3>
                        <div className="grid grid-cols-2 gap-2">
                            {columnsList.map(c => (
                                <CheckboxItem
                                    key={c.id}
                                    label={c.label}
                                    checked={localPrefs.click_columns?.includes(c.id)}
                                    onChange={() => handleToggleColumn(c.id)}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="p-5 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
                    <button onClick={onClose} className="px-5 py-2 text-gray-700 bg-white border border-gray-300 rounded font-medium hover:bg-gray-50 transition">
                        {t('common.cancel')}
                    </button>
                    <button onClick={handleSave} className="px-5 py-2 text-white bg-blue-600 rounded font-medium hover:bg-blue-700 transition shadow-sm">
                        {t('dashboard.saveSettings')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default DashboardSettingsModal;
