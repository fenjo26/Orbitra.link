import React, { useState, useEffect } from 'react';
import { Activity, ArrowRightLeft, ShieldAlert, TerminalSquare, ServerCrash, FileStack, Filter, ChevronDown } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const LogsPage = () => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('traffic');
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    // W2: Cloak observability filters
    const [filters, setFilters] = useState({
        campaign_id: '',
        route: 'all', // 'all', 'money', 'safe'
        reason: ''
    });
    const [showFilters, setShowFilters] = useState(false);

    const tabs = {
        traffic: { name: t('logs.traffic'), icon: <Activity className="w-4 h-4" /> },
        postbacks: { name: t('logs.incomingPostbacks'), icon: <ArrowRightLeft className="w-4 h-4" /> },
        s2s: { name: t('logs.sentS2s'), icon: <ServerCrash className="w-4 h-4" /> },
        system: { name: t('logs.systemLog'), icon: <TerminalSquare className="w-4 h-4" /> },
        audit: { name: t('logs.auditLog'), icon: <ShieldAlert className="w-4 h-4" /> }
    };

    useEffect(() => {
        setLoading(true);
        // W2: Build URL with filter parameters for traffic tab
        const params = new URLSearchParams({
            action: 'logs',
            type: activeTab,
            limit: '100'
        });

        if (activeTab === 'traffic') {
            if (filters.campaign_id) params.append('campaign_id', filters.campaign_id);
            if (filters.route !== 'all') params.append('route', filters.route);
            if (filters.reason) params.append('reason', filters.reason);
        }

        fetch(`${API_URL}?${params.toString()}`)
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
    }, [activeTab, filters]);

    const renderTable = () => {
        if (loading) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.loadingLogs')}</div>;
        if (!logs.length) return <div className="p-8 text-center text-[var(--color-text-muted)]">{t('logs.noData')}</div>;

        switch (activeTab) {
            case 'traffic':
                return (
                    <>
                        {/* W2: Filter bar for traffic logs */}
                        <div className="mb-4 p-3 rounded-xl border" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                            <div className="flex items-center gap-2 mb-2">
                                <button
                                    onClick={() => setShowFilters(!showFilters)}
                                    className="flex items-center gap-2 text-xs font-medium"
                                    style={{ color: 'var(--color-text-secondary)' }}
                                >
                                    <Filter className="w-4 h-4" />
                                    {showFilters ? <ChevronDown className="w-4 h-4" /> : <ChevronDown className="w-4 h-4 rotate-[-90deg]" />}
                                    {t('logs.filterByCampaign')}
                                </button>
                            </div>
                            {showFilters && (
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <input
                                        type="number"
                                        placeholder={t('logs.filterByCampaign')}
                                        value={filters.campaign_id}
                                        onChange={e => setFilters({ ...filters, campaign_id: e.target.value })}
                                        className="form-input text-xs py-1.5 rounded-lg"
                                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                                    />
                                    <select
                                        value={filters.route}
                                        onChange={e => setFilters({ ...filters, route: e.target.value })}
                                        className="form-select text-xs py-1.5 rounded-lg"
                                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                                    >
                                        <option value="all">{t('logs.routeFilterAll')}</option>
                                        <option value="money">{t('logs.routeFilterMoney')}</option>
                                        <option value="safe">{t('logs.routeFilterSafe')}</option>
                                    </select>
                                    <input
                                        type="text"
                                        placeholder={t('logs.filterByReason')}
                                        value={filters.reason}
                                        onChange={e => setFilters({ ...filters, reason: e.target.value })}
                                        className="form-input text-xs py-1.5 rounded-lg"
                                        style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
                                    />
                                </div>
                            )}
                        </div>

                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>{t('logs.colTime')}</th>
                                    <th>{t('logs.colClickId')}</th>
                                    <th>{t('logs.colSubid')}</th>
                                    <th>{t('logs.colCampaign')}</th>
                                    <th>{t('logs.colRoute')}</th>
                                    <th>{t('logs.colReason')}</th>
                                    <th>{t('logs.colDestination')}</th>
                                    <th>{t('logs.colIp')}</th>
                                    <th>{t('logs.colGeo')}</th>
                                    <th>{t('logs.colDevice')}</th>
                                    <th>{t('logs.colIsp')}</th>
                                    <th>{t('logs.colAsn')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.map((log, i) => {
                                    // W2: Render route badge
                                    const renderRouteBadge = () => {
                                        if (!log.cloak_verdict && !log.is_safe_page) {
                                            return <span className="text-xs text-[var(--color-text-muted)]">{t('logs.routeNone')}</span>;
                                        }
                                        const isMoney = !log.is_safe_page;
                                        const label = isMoney ? t('logs.routeMoney') : t('logs.routeSafe');
                                        const className = isMoney
                                            ? 'status-active'
                                            : 'status-inactive';
                                        return (
                                            <span className={`status-badge ${className} text-[11px]`}>
                                                {label}
                                            </span>
                                        );
                                    };

                                    // W2: Render reason chips
                                    const renderReasonChips = () => {
                                        if (!log.cloak_reasons) return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                                        const reasons = log.cloak_reasons.split(',').filter(Boolean);
                                        if (reasons.length === 0) return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                                        return (
                                            <div className="flex flex-wrap gap-1">
                                                {reasons.map((reason, idx) => (
                                                    <span
                                                        key={idx}
                                                        className="text-[10px] px-1.5 py-0.5 rounded"
                                                        style={{
                                                            backgroundColor: 'var(--color-bg-soft)',
                                                            color: 'var(--color-text-secondary)',
                                                            border: '1px solid var(--color-border)'
                                                        }}
                                                        title={t(`cloakReasons.${reason.trim()}`, reason)}
                                                    >
                                                        {reason}
                                                    </span>
                                                ))}
                                            </div>
                                        );
                                    };

                                    // W2: Render destination
                                    const renderDestination = () => {
                                        if (log.landing_name) return log.landing_name;
                                        if (log.offer_name) return log.offer_name;
                                        return <span className="text-xs text-[var(--color-text-muted)]">-</span>;
                                    };

                                    return (
                                        <tr key={i}>
                                            <td>{log.created_at}</td>
                                            <td className="font-mono text-xs">{log.click_id}</td>
                                            <td>{log.subid || '-'}</td>
                                            <td>{log.campaign_name || t('logs.direct')}</td>
                                            <td>{renderRouteBadge()}</td>
                                            <td>{renderReasonChips()}</td>
                                            <td className="text-xs">{renderDestination()}</td>
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
                                            <td className="text-xs text-[var(--color-text-muted)]">{log.isp || '-'}</td>
                                            <td className="text-xs font-mono text-[var(--color-text-muted)]">{log.asn || '-'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </>
                );

            case 'postbacks':
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colSourceIp')}</th>
                                <th>{t('logs.colClickId')}</th>
                                <th>{t('logs.colCampaign')}</th>
                                <th>{t('logs.colStatus')}</th>
                                <th>{t('logs.colOrigStatus')}</th>
                                <th className="text-right">{t('logs.colPayout')}</th>
                                <th>{t('logs.colResult')}</th>
                                <th>{t('logs.colError')}</th>
                                <th>{t('logs.colSource')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => {
                                const resultLabels = {
                                    recorded: t('logs.resultRecorded'),
                                    updated: t('logs.resultUpdated'),
                                    rejected: t('logs.resultRejected'),
                                    error: t('logs.resultError')
                                };
                                const resultClasses = {
                                    recorded: 'status-active',
                                    updated: 'status-pending',
                                    rejected: 'status-inactive',
                                    error: 'status-inactive'
                                };
                                const sourceLabels = {
                                    postback: t('logs.sourcePostback'),
                                    pixel: t('logs.sourcePixel')
                                };
                                return (
                                    <tr key={i}>
                                        <td>{log.created_at}</td>
                                        <td className="font-mono text-xs">{log.remote_ip || '-'}</td>
                                        <td className="font-mono text-xs">{log.click_id || '-'}</td>
                                        <td>{log.campaign_name || '-'}</td>
                                        <td>
                                            {log.status ? (
                                                <span className={`status-badge ${log.status === 'sale' || log.status === 'lead' ? 'status-active' : 'status-inactive'}`}>
                                                    {log.status}
                                                </span>
                                            ) : '-'}
                                        </td>
                                        <td>{log.original_status || '-'}</td>
                                        <td className={`text-right font-medium ${log.payout > 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-text-muted)]'}`}>
                                            {parseFloat(log.payout) > 0 ? `${log.payout} ${log.currency}` : '0.00'}
                                        </td>
                                        <td>
                                            <span className={`status-badge ${resultClasses[log.result] || 'status-inactive'}`}>
                                                {resultLabels[log.result] || log.result}
                                            </span>
                                        </td>
                                        <td className="truncate max-w-xs" title={log.error || ''}>{log.error || '-'}</td>
                                        <td>
                                            <span className="text-xs text-[var(--color-text-muted)]">
                                                {sourceLabels[log.source] || log.source}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
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

            case 's2s': {
                // Rows are queue entries written by postback.php and updated by
                // postback_queue_cron.php. Delivery state lives in `status` / `http_code`;
                // `status_code` is the pre-0.9.6 column and may be null on new rows.
                const statusLabels = {
                    pending: t('postbackQueue.statusPending'),
                    in_flight: t('postbackQueue.statusInFlight'),
                    delivered: t('postbackQueue.statusDelivered'),
                    failed: t('postbackQueue.statusFailed'),
                };
                const statusClasses = {
                    pending: 'status-pending',
                    in_flight: 'status-pending',
                    delivered: 'status-active',
                    failed: 'status-inactive',
                };
                return (
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('logs.colTime')}</th>
                                <th>{t('logs.colConversionId')}</th>
                                <th>{t('logs.colUrl')}</th>
                                <th>{t('postbackQueue.status')}</th>
                                <th className="text-right">{t('postbackQueue.attempts')}</th>
                                <th className="text-right">{t('postbackQueue.httpCode')}</th>
                                <th>{t('postbackQueue.lastError')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.map((log, i) => {
                                // Legacy rows (pre-queue) carry no status: treat a 2xx/3xx
                                // status_code as delivered so old logs still read correctly.
                                const legacyCode = Number(log.status_code);
                                const status = log.status
                                    || (legacyCode >= 200 && legacyCode < 400 ? 'delivered' : 'failed');
                                const httpCode = log.http_code ?? log.status_code;
                                return (
                                    <tr key={i}>
                                        <td>{log.created_at}</td>
                                        <td>{log.conversion_id ? `#${log.conversion_id}` : '-'}</td>
                                        <td className="truncate max-w-sm" title={log.url}>{log.url}</td>
                                        <td>
                                            <span className={`status-badge ${statusClasses[status] || 'status-inactive'}`}>
                                                {statusLabels[status] || status}
                                            </span>
                                            {status === 'pending' && log.next_retry_at && (
                                                <div className="text-xs text-[var(--color-text-muted)] mt-1">
                                                    {t('postbackQueue.nextRetry')}: {log.next_retry_at}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-right">{Number(log.attempts ?? 0)}</td>
                                        <td className="text-right">{httpCode ? Number(httpCode) : '-'}</td>
                                        <td className="truncate max-w-xs" title={log.last_error || ''}>
                                            {log.last_error || '-'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                );
            }

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
