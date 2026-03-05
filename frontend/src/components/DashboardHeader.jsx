import React, { useState } from 'react';
import { Calendar, Filter, Settings, ChevronDown, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import DatePicker from 'react-datepicker';
import "react-datepicker/dist/react-datepicker.css";

const DashboardHeader = ({ filters, setFilters, campaigns, onOpenSettings }) => {
    const { t } = useLanguage();
    const [showRangeMenu, setShowRangeMenu] = useState(false);
    const [showCustomRange, setShowCustomRange] = useState(false);

    const ranges = [
        { id: 'today', label: t('dashboard.today') },
        { id: 'yesterday', label: t('dashboard.yesterday') },
        { id: 'this_week', label: t('dashboard.thisWeek') },
        { id: 'last_7_days', label: t('dashboard.last7Days') },
        { id: 'this_month', label: t('dashboard.thisMonth') },
        { id: 'last_30_days', label: t('dashboard.last30Days') },
        { id: 'custom', label: t('dashboard.customRange') }
    ];

    const handleRangeSelect = (id) => {
        if (id === 'custom') {
            setShowCustomRange(true);
        } else {
            setFilters({ ...filters, date_range: id, custom_from: '', custom_to: '' });
            setShowCustomRange(false);
        }
        setShowRangeMenu(false);
    };

    const handleCustomApply = () => {
        setFilters({ ...filters, date_range: 'custom' });
        setShowCustomRange(false);
    };

    const handleCampaignChange = (e) => {
        setFilters({ ...filters, campaign_id: e.target.value });
    };

    const getCurrentRangeLabel = () => {
        if (filters.date_range === 'custom' && filters.custom_from && filters.custom_to) {
            return `${filters.custom_from.toLocaleDateString()} - ${filters.custom_to.toLocaleDateString()}`;
        }
        return ranges.find(r => r.id === (filters.date_range || 'today'))?.label || t('dashboard.allTime');
    };

    return (
        <div className="card shadow-sm p-5 mb-6 flex flex-col md:flex-row gap-4 items-center justify-between bg-white w-full rounded-[24px]">
            <div className="flex flex-col sm:flex-row gap-4 w-full md:w-auto">
                {/* Campaign Select */}
                <div className="relative w-full sm:w-[300px]">
                    <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-[var(--color-text-muted)]">
                        <Filter size={16} />
                    </div>
                    <select
                        value={filters.campaign_id}
                        onChange={handleCampaignChange}
                        className="w-full h-12 bg-[var(--color-bg-soft)] text-[var(--color-text-primary)] text-sm rounded-2xl pr-10 appearance-none cursor-pointer outline-none transition-all hover:bg-[var(--color-border)] focus:ring-2 focus:ring-[var(--color-border)]"
                        style={{ paddingLeft: '48px' }}
                    >
                        <option value="">{t('dashboard.allCampaigns')}</option>
                        {campaigns.map(c => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                    <div className="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[var(--color-text-muted)]">
                        <ChevronDown size={14} />
                    </div>
                </div>

                {/* Date Range Dropdown */}
                <div className="relative w-full sm:w-[300px]">
                    <div className="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-[var(--color-text-muted)]">
                        <Calendar size={16} />
                    </div>
                    <button
                        onClick={() => setShowRangeMenu(!showRangeMenu)}
                        className="w-full h-12 bg-[var(--color-bg-soft)] text-[var(--color-text-primary)] text-sm text-left rounded-2xl pr-10 appearance-none cursor-pointer outline-none transition-all hover:bg-[var(--color-border)] focus:ring-2 focus:ring-[var(--color-border)]"
                        style={{ paddingLeft: '48px' }}
                    >
                        <span className="block truncate">{getCurrentRangeLabel()}</span>
                    </button>
                    <div className="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[var(--color-text-muted)]">
                        <ChevronDown size={14} />
                    </div>

                    {showRangeMenu && (
                        <div className="absolute top-full left-0 mt-1 w-full sm:w-64 card shadow-lg z-50 py-1" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
                            {ranges.map(range => (
                                <button
                                    key={range.id}
                                    onClick={() => handleRangeSelect(range.id)}
                                    className="w-full text-left px-4 py-2 hover:bg-gray-50 text-sm text-gray-700 flex items-center justify-between"
                                >
                                    {range.label}
                                    {filters.date_range === range.id && <Check size={14} className="text-blue-500" />}
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <button
                onClick={onOpenSettings}
                className="btn-secondary transition shadow-sm flex items-center gap-2 text-sm font-medium w-full md:w-auto mt-2 md:mt-0 justify-center"
                title={t('dashboard.dashboardSettings')}
            >
                <Settings size={16} />
                <span className="md:hidden">{t('dashboard.dashboardSettings')}</span>
            </button>

            {/* Custom Date Modal */}
            {showCustomRange && (
                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50">
                    <div className="card shadow-xl p-6 w-96 relative" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
                        <h3 className="font-semibold mb-4 text-center" style={{ color: 'var(--color-text-primary)' }}>{t('dashboard.selectRangeTitle')}</h3>
                        <div className="flex flex-col gap-4">
                            <div>
                                <label className="block text-xs text-gray-500 mb-1">{t('dashboard.from')}</label>
                                <DatePicker
                                    selected={filters.custom_from}
                                    onChange={(date) => setFilters({ ...filters, custom_from: date })}
                                    className="w-full border rounded p-2 text-sm outline-none focus:border-blue-500"
                                    dateFormat="dd.MM.yyyy"
                                    placeholderText="DD.MM.YYYY"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-gray-500 mb-1">{t('dashboard.to')}</label>
                                <DatePicker
                                    selected={filters.custom_to}
                                    onChange={(date) => setFilters({ ...filters, custom_to: date })}
                                    className="w-full border rounded p-2 text-sm outline-none focus:border-blue-500"
                                    dateFormat="dd.MM.yyyy"
                                    placeholderText="DD.MM.YYYY"
                                />
                            </div>
                        </div>
                        <div className="mt-6 flex justify-end gap-2">
                            <button onClick={() => setShowCustomRange(false)} className="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded text-gray-800">{t('common.cancel')}</button>
                            <button onClick={handleCustomApply} className="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">{t('common.apply')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DashboardHeader;
