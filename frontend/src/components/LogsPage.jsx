import React, { useState, useEffect } from 'react';
import { Activity, ArrowRightLeft, ShieldAlert, TerminalSquare, ServerCrash, FileStack } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const LogsPage = () => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('traffic');
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);

    const tabs = {
        traffic: { name: t('logs.traffic'), icon: <Activity className="w-4 h-4" /> },
        postbacks: { name: t('logs.incomingPostbacks'), icon: <ArrowRightLeft className="w-4 h-4" /> },
        s2s: { name: t('logs.sentS2s'), icon: <ServerCrash className="w-4 h-4" /> },
        system: { name: t('logs.systemLog'), icon: <TerminalSquare className="w-4 h-4" /> },
        audit: { name: t('logs.auditLog'), icon: <ShieldAlert className="w-4 h-4" /> }
    };

    useEffect(() => {
        setLoading(true);
        fetch(`${API_URL}?action=logs&type=${activeTab}&limit=100`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setLogs(data.data);
                } else {
                    setLogs([]);
                }
                setLoading(false);
            })
            .catch(() => {
                setLogs([]);
                setLoading(false);
            });
    }, [activeTab]);

    const renderTable = () => {
        if (loading) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.loadingLogs')}</div>;
        if (!logs.length) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.noData')}</div>;

        switch (activeTab) {
            case 'traffic':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colClickId')}</th>
                                <th>{t('logs.colSubid')}</th>
                                <th>{t('logs.colCampaign')}</th>
                                <th>{t('logs.colIp')}</th>
                                <th>{t('logs.colGeo')}</th>
                                <th>{t('logs.colDevice')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td className="font-mono text-xs">{log.click_id}</td>
                                    <td>{log.subid || '-'}</td>
                                    <td>{log.campaign_name || t('logs.direct')}</td>
                                    <td>{log.ip}</td>
                                    <td>
                                        <div>{log.country_code || '-'}</div>
                                        <div className="text-xs text-[var(--color-text-muted)]">
                                            {[log.region, log.city].filter(Boolean).join(', ') || '-'}
                                        </div>
                                        {log.geo_timezone ? (
                                            <div className="text-[11px] text-[var(--color-text-muted)]">{log.geo_timezone}</div>
                                        ) : null}
                                    </td>
                                    <td>{log.device_type || '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 'postbacks':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colClickId')}</th>
                                <th>{t('logs.colCampaign')}</th>
                                <th>{t('logs.colStatus')}</th>
                                <th>{t('logs.colOrigStatus')}</th>
                                <th className="text-right">{t('logs.colPayout')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td className="font-mono text-xs">{log.click_id}</td>
                                    <td>{log.campaign_name || '-'}</td>
                                    <td>
                                        <span className={`status-badge ${log.status === 'sale' || log.status === 'lead' ? 'status-active' : 'status-inactive'}`}>
                                            {log.status}
                                        </span>
                                    </td>
                                    <td>{log.original_status || '-'}</td>
                                    <td className={`text-right font-medium ${log.payout > 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-text-muted)]'}`}>
                                        {parseFloat(log.payout) > 0 ? `${log.payout} ${log.currency}` : '0.00'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 'system':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colLevel')}</th>
                                <th>{t('logs.colMessage')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td>
                                        <span className={`status-badge ${log.level === 'ERROR' ? 'status-inactive' :
                                            log.level === 'WARN' ? 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' :
                                                'status-active'
                                            }`}>{log.level}</span>
                                    </td>
                                    <td className="truncate max-w-md">{log.message}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 'audit':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colEvent')}</th>
                                <th>{t('logs.colResource')}</th>
                                <th>{t('logs.colIp')}</th>
                                <th className="text-right">{t('logs.colStatus')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td className="font-medium">{log.action}</td>
                                    <td>{log.resource} {log.resource_id ? `#${log.resource_id}` : ''}</td>
                                    <td>{log.ip}</td>
                                    <td className="text-right">
                                        <span className={`status-badge ${log.status_code === 200 ? 'status-active' : 'status-inactive'}`}>
                                            {log.status_code}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            case 's2s':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colConversionId')}</th>
                                <th>{t('logs.colUrl')}</th>
                                <th className="text-right">{t('logs.colResponseCode')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => (
                                <tr key={i}>
                                    <td>{log.created_at}</td>
                                    <td>#{log.conversion_id}</td>
                                    <td className="truncate max-w-sm" title={log.url}>{log.url}</td>
                                    <td className="text-right">
                                        <span className={`status-badge ${log.status_code >= 200 && log.status_code < 300 ? 'status-active' : 'status-inactive'}`}>
                                            {log.status_code || 'Err'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                );

            default:
                return null;
        }
    };

    return (
        <div className="page-card">
            <div className="flex items-center gap-2 mb-4">
                <FileStack size={18} className="text-[var(--color-primary)]" />
                <h3 className="page-title m-0">{t('logs.title')}</h3>
            </div>

            {/* Tabs */}
            <div className="flex items-center gap-1 mb-4 p-1 bg-[var(--color-bg-soft)] rounded-lg overflow-x-auto">
                {Object.entries(tabs).map(([id, tab]) => (
                    <button
                        key={id}
                        onClick={() => setActiveTab(id)}
                        className={`flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors ${activeTab === id
                            ? 'bg-[var(--color-bg)] text-[var(--color-primary)] shadow-sm'
                            : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                            }`}
                    >
                        {tab.icon}
                        <span>{tab.name}</span>
                    </button>
                ))}
            </div>

            {/* Table */}
            <div className="overflow-x-auto rounded-lg border border-[var(--color-border)]">
                {renderTable()}
            </div>

            {/* Info */}
            <div className="mt-4 text-xs text-[var(--color-text-muted)]">
                {t('logs.lastRecords')}
            </div>
        </div>
    );
};

export default LogsPage;
