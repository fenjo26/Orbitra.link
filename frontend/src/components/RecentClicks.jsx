import React, { useState } from 'react';
import { Monitor, Smartphone, Globe, AlertCircle, ArrowRight } from 'lucide-react';
import ClickDetailsModal from './ClickDetailsModal';
import { useLanguage } from '../contexts/LanguageContext';

const getDeviceIcon = (deviceType) => {
    switch (deviceType?.toLowerCase()) {
        case 'mobile':
            return <Smartphone size={16} className="text-gray-500" />;
        case 'desktop':
            return <Monitor size={16} className="text-gray-500" />;
        default:
            return <Globe size={16} className="text-gray-400" />;
    }
}

const RecentClicks = ({ logs, preferences, onShowAll }) => {
    const { t } = useLanguage();
    const isVisible = (col) => !preferences || !preferences.click_columns || preferences.click_columns.includes(col);
    const [selectedClickId, setSelectedClickId] = useState(null);

    return (
        <div className="card shadow-sm overflow-hidden mb-8" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            <div className="px-5 py-4 border-b flex justify-between items-center" style={{ borderColor: 'var(--color-border)' }}>
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{t('dashboard.recentClicksLog')}</h2>
                <div className="flex items-center space-x-2">
                    <span className="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>Live</span>
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b" style={{ backgroundColor: 'var(--color-bg-hover)', borderColor: 'var(--color-border)' }}>
                        <tr>
                            {isVisible('created_at') && <th className="px-5 py-3 font-semibold w-48" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colDate')}</th>}
                            {isVisible('campaign_name') && <th className="px-5 py-3 font-semibold w-48" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colCampaign')}</th>}
                            {isVisible('country_code') && <th className="px-5 py-3 font-semibold w-16 text-center" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colGeo')}</th>}
                            {isVisible('device_type') && <th className="px-5 py-3 font-semibold w-16 text-center" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colOs')}</th>}
                            {isVisible('ip') && <th className="px-5 py-3 font-semibold w-32" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colIp')}</th>}
                            {isVisible('user_agent') && <th className="px-5 py-3 font-semibold max-w-xs" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colUa')}</th>}
                            {isVisible('redirect_url') && <th className="px-5 py-3 font-semibold" style={{ color: 'var(--color-text-secondary)' }}>{t('dashboard.colDirection')}</th>}
                        </tr>
                    </thead>
                    <tbody style={{ divideColor: 'var(--color-border)' }}>
                        {(!logs || logs.length === 0) && (
                            <tr>
                                <td colSpan="6" className="px-5 py-12 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('dashboard.noLogs')}</td>
                            </tr>
                        )}
                        {logs && logs.slice(0, 20).map((log) => (
                            <tr key={log.id} className="hover:bg-blue-50/10 transition">
                                {isVisible('created_at') && (
                                    <td className="px-5 py-3 font-mono text-xs">
                                        <button onClick={() => setSelectedClickId(log.click_id || log.id)} className="text-blue-500 hover:text-blue-700 hover:underline font-medium text-left">
                                            {log.created_at}
                                        </button>
                                    </td>
                                )}
                                {isVisible('campaign_name') && <td className="px-5 py-3 font-medium" style={{ color: 'var(--color-text-primary)' }}>{log.campaign_name}</td>}
                                {isVisible('country_code') && (
                                    <td
                                        className="px-5 py-3 text-center text-xs font-mono align-middle"
                                        title={[log.country_code, log.region, log.city, log.geo_timezone].filter(Boolean).join(' / ')}
                                    >
                                        {log.country_code || '-'}
                                    </td>
                                )}
                                {isVisible('device_type') && <td className="px-5 py-3 text-center align-middle"><div className="flex justify-center">{getDeviceIcon(log.device_type)}</div></td>}
                                {isVisible('ip') && <td className="px-5 py-3 font-mono text-xs align-middle" style={{ color: 'var(--color-text-secondary)' }}>{log.ip}</td>}
                                {isVisible('user_agent') && (
                                    <td className="px-5 py-3 text-xs truncate max-w-[200px]" title={log.user_agent} style={{ color: 'var(--color-text-muted)' }}>
                                        {log.user_agent || '-'}
                                    </td>
                                )}
                                {isVisible('redirect_url') && (
                                    <td className="px-5 py-3">
                                        <div className="flex items-center space-x-2 text-xs">
                                            <ArrowRight size={14} className="text-gray-400" />
                                            <a href={log.redirect_url} className="text-blue-500 hover:underline truncate max-w-[250px] inline-block" target="_blank" rel="noreferrer" title={log.redirect_url}>
                                                {log.redirect_url}
                                            </a>
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="px-5 py-3 border-t flex justify-center" style={{ backgroundColor: 'var(--color-bg-hover)', borderColor: 'var(--color-border)' }}>
                <button
                    onClick={onShowAll}
                    className="text-blue-500 hover:text-blue-700 text-sm font-medium flex items-center gap-1 transition-colors"
                >
                    {t('dashboard.viewFullLogs')} <ArrowRight size={14} />
                </button>
            </div>

            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </div>
    );
};

export default RecentClicks;
