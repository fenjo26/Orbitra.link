import React from 'react';
import { UserCog, Palette, Map, Link, Settings as SettingsIcon, Plug, Server, FileStack, Archive, Upload, Trash2, Database, ArrowRightLeft, RefreshCw, AlertCircle, HardDrive, MessageSquare } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

import UsersPage from './UsersPage';

const API_URL = '/api.php';
import BrandingPage from './BrandingPage';
import GeoProfilesPage from './GeoProfilesPage';
import Settings from './Settings';
import IntegrationsPage from './IntegrationsPage';
import LogsPage from './LogsPage';
import ArchivePage from './ArchivePage';
import GeoDBPage from './GeoDBPage';
import MigrationsPage from './MigrationsPage';
import UpdatePage from './UpdatePage';
import AggregatorPage from './AggregatorPage';
import FeedbackPage from './FeedbackPage';

const StatusContent = () => {
    const { t } = useLanguage();
    const [statusData, setStatusData] = React.useState(null);
    const [loading, setLoading] = React.useState(true);

    React.useEffect(() => {
        fetch(`${API_URL}?action=system_status`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setStatusData(data.data);
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, []);

    const formatBytes = (bytes, decimals = 2) => {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    };

    const getProgressColor = (percent) => {
        if (percent > 90) return 'bg-red-500';
        if (percent > 75) return 'bg-yellow-500';
        return 'bg-green-500';
    };

    const getStatusIcon = (score) => {
        if (score >= 80) return { icon: '🟢', text: t('admin.systemNormal') };
        if (score >= 50) return { icon: '🟡', text: t('admin.attention') };
        return { icon: '🔴', text: t('admin.critical') };
    };

    if (loading) return <div className="page-card text-[var(--color-text-muted)]">{t('admin.detectingSystem')}</div>;
    if (!statusData) return <div className="page-card alert alert-danger">{t('admin.errorGettingStatus')}</div>;

    const statusInfo = getStatusIcon(statusData.capacity_score || 100);

    return (
        <div className="space-y-4">
            {/* Status Overview */}
            <div className="page-card">
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-2">
                        <Server size={18} className="text-[var(--color-primary)]" />
                        <h3 className="page-title m-0">{t('admin.systemStatus')}</h3>
                    </div>
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-lg">{statusInfo.icon}</span>
                        <span className="font-medium">{statusInfo.text}</span>
                        <span className="text-[var(--color-text-muted)]">({statusData.capacity_score || 100}%)</span>
                    </div>
                </div>

                {/* Warnings */}
                {statusData.warnings && statusData.warnings.length > 0 && (
                    <div className="mb-4 space-y-2">
                        {statusData.warnings.map((w, idx) => (
                            <div key={idx} className={`alert ${w.level === 'critical' ? 'alert-danger' : 'alert-warning'} flex items-start gap-2`}>
                                <span>{w.level === 'critical' ? '🔴' : '🟡'}</span>
                                <span>{w.messageKey ? t(`admin.${w.messageKey}`) : w.message}</span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Resource Bars */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    {/* Disk Usage */}
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)]">
                        <div className="flex justify-between items-center mb-2">
                            <span className="text-sm text-[var(--color-text-secondary)]">{t('admin.disk')}</span>
                            <span className="text-xs font-mono">{statusData.disk_used_percent}%</span>
                        </div>
                        <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div
                                className={`h-full transition-all ${getProgressColor(statusData.disk_used_percent)}`}
                                style={{ width: `${statusData.disk_used_percent}%` }}
                            />
                        </div>
                        <div className="text-xs text-[var(--color-text-muted)] mt-1">
                            {formatBytes(statusData.disk_free_bytes)} {t('admin.free')} из {formatBytes(statusData.disk_total_bytes)}
                        </div>
                    </div>

                    {/* Memory Usage */}
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)]">
                        <div className="flex justify-between items-center mb-2">
                            <span className="text-sm text-[var(--color-text-secondary)]">{t('admin.ram')}</span>
                            <span className="text-xs font-mono">{statusData.system_memory_used_percent || 0}%</span>
                        </div>
                        <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div
                                className={`h-full transition-all ${getProgressColor(statusData.system_memory_used_percent || 0)}`}
                                style={{ width: `${statusData.system_memory_used_percent || 0}%` }}
                            />
                        </div>
                        <div className="text-xs text-[var(--color-text-muted)] mt-1">
                            {statusData.system_total_memory ? formatBytes(statusData.system_free_memory) + ' ' + t('admin.free') : 'N/A'}
                        </div>
                    </div>

                    {/* CPU Load */}
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)]">
                        <div className="flex justify-between items-center mb-2">
                            <span className="text-sm text-[var(--color-text-secondary)]">{t('admin.cpuLoad')}</span>
                            <span className="text-xs font-mono">{statusData.cpu_load_per_core || statusData.cpu_load}</span>
                        </div>
                        <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div
                                className={`h-full transition-all ${getProgressColor(Math.min((statusData.cpu_load_per_core || statusData.cpu_load) * 50, 100))}`}
                                style={{ width: `${Math.min((statusData.cpu_load_per_core || statusData.cpu_load) * 50, 100)}%` }}
                            />
                        </div>
                        <div className="text-xs text-[var(--color-text-muted)] mt-1">
                            {statusData.cpu_cores} {t('admin.cores')} • LA: {statusData.cpu_load}, {statusData.cpu_load_5}, {statusData.cpu_load_15}
                        </div>
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-center">
                        <div className="text-2xl font-bold text-[var(--color-primary)]">{statusData.clicks?.toLocaleString('ru-RU') || 0}</div>
                        <div className="text-xs text-[var(--color-text-muted)]">{t('admin.clicks')}</div>
                    </div>
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-center">
                        <div className="text-2xl font-bold text-[var(--color-success)]">{statusData.conversions?.toLocaleString('ru-RU') || 0}</div>
                        <div className="text-xs text-[var(--color-text-muted)]">{t('admin.conversions')}</div>
                    </div>
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-center">
                        <div className="text-2xl font-bold text-[var(--color-text-primary)]">{formatBytes(statusData.db_size_bytes)}</div>
                        <div className="text-xs text-[var(--color-text-muted)]">{t('admin.dbSize')}</div>
                    </div>
                    <div className="p-3 rounded-lg bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-center">
                        <div className="text-2xl font-bold text-[var(--color-text-primary)]">{statusData.version}</div>
                        <div className="text-xs text-[var(--color-text-muted)]">{t('admin.version')}</div>
                    </div>
                </div>
            </div>

            {/* Components */}
            <div className="page-card">
                <h4 className="text-sm font-semibold mb-4 flex items-center gap-2">
                    <HardDrive size={16} className="text-[var(--color-primary)]" />
                    {t('admin.systemComponents')}
                </h4>
                <div className="overflow-x-auto rounded-xl border border-[var(--color-border)]">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('admin.component')}</th>
                                <th>{t('admin.versionDetails')}</th>
                                <th className="text-right">{t('common.size')}</th>
                                <th className="text-right">{t('common.status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {statusData.components && statusData.components.map((comp, idx) => (
                                <tr key={idx}>
                                    <td className="font-medium">{comp.name}</td>
                                    <td className="text-sm text-[var(--color-text-secondary)]">
                                        {comp.version && <span>v{comp.version}</span>}
                                        {comp.type && <span>{comp.type} {comp.version}</span>}
                                        {comp.journal_mode && <span>{comp.journal_mode} mode</span>}
                                        {comp.updated && <span className="text-xs">({comp.updated})</span>}
                                    </td>
                                    <td className="text-right font-mono text-xs">
                                        {comp.memory_bytes > 0 ? formatBytes(comp.memory_bytes) :
                                            (comp.size_bytes ? formatBytes(comp.size_bytes) : '-')}
                                    </td>
                                    <td className="text-right">
                                        <span className={`inline-flex items-center ${comp.status === 'running' || comp.status === 'ok' ? 'status-active' : 'status-inactive'}`}>
                                            <span className={`w-2 h-2 rounded-full mr-2 shadow-sm ${comp.status === 'running' || comp.status === 'ok' ? 'bg-[var(--color-success)]' : 'bg-[var(--color-danger)]'}`}></span>
                                            {comp.status === 'running' || comp.status === 'ok' ? 'OK' : 'Error'}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* PHP & Server Info */}
            <div className="page-card">
                <h4 className="text-sm font-semibold mb-4 flex items-center gap-2">
                    <Database size={16} className="text-[var(--color-primary)]" />
                    {t('admin.environment')}
                </h4>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div className="space-y-2">
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.phpVersion')}</span>
                            <span className="font-medium">{statusData.php_version}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.phpSapi')}</span>
                            <span className="font-medium">{statusData.php_sapi}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.memoryLimit')}</span>
                            <span className="font-medium">{statusData.php_memory_limit}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.phpMemory')}</span>
                            <span className="font-medium">{formatBytes(statusData.php_memory_bytes)} / {formatBytes(statusData.php_memory_peak_bytes)} peak</span>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.webServer')}</span>
                            <span className="font-medium">{statusData.web_server} {statusData.web_server_version}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.serverSoftware')}</span>
                            <span className="font-medium text-xs truncate max-w-[200px]" title={statusData.server_software}>{statusData.server_software}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.cpuCores')}</span>
                            <span className="font-medium">{statusData.cpu_cores}</span>
                        </div>
                        <div className="flex justify-between border-b border-[var(--color-border)] pb-2">
                            <span className="text-[var(--color-text-secondary)]">{t('admin.extensions')}</span>
                            <span className="font-medium text-xs">
                                {statusData.php_extensions && Object.entries(statusData.php_extensions).filter(([k, v]) => v).map(([k]) => k).join(', ')}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Recommendations */}
            {statusData.recommendations && statusData.recommendations.length > 0 && (
                <div className="page-card">
                    <h4 className="text-sm font-semibold mb-4 flex items-center gap-2">
                        💡 {t('admin.recommendations')}
                    </h4>
                    <div className="space-y-2">
                        {statusData.recommendations.map((r, idx) => (
                            <div key={idx} className="alert alert-info flex items-start gap-2">
                                <span>ℹ️</span>
                                <span>{r.message}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};


const ImportContent = () => {
    const { t } = useLanguage();
    const [csvData, setCsvData] = React.useState('');
    const [loading, setLoading] = React.useState(false);
    const [message, setMessage] = React.useState('');
    const [errorMsg, setErrorMsg] = React.useState('');

    const handleImport = async () => {
        if (!csvData.trim()) {
            setErrorMsg(t('admin.enterImportData'));
            return;
        }
        setLoading(true);
        setMessage('');
        setErrorMsg('');

        try {
            const res = await fetch(`${API_URL}?action=import_conversions`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csv_data: csvData })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage(data.message);
                if (data.errors && data.errors.length > 0) {
                    setErrorMsg(t('admin.errorsInRows') + '\n' + data.errors.join('\n'));
                } else {
                    setCsvData('');
                }
            } else {
                setErrorMsg(data.message || t('admin.networkError'));
            }
        } catch (e) {
            setErrorMsg(t('admin.networkErrorSending') + ' ' + e.message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <h3 className="page-title m-0">{t('admin.importTitle')}</h3>
            </div>

            <div className="mt-6 alert alert-info">
                <p className="mb-2">{t('admin.importDesc')}</p>
                <p className="font-medium mt-3 mb-1">{t('admin.formatList')}</p>
                <code>subid,payout,tid,status</code>

                <ul className="list-disc pl-5 mt-2 space-y-1">
                    <li><strong>subid</strong>: {t('admin.subid')}</li>
                    <li><strong>payout (revenue)</strong>: {t('admin.payoutOrRevenue')}</li>
                    <li><strong>tid</strong>: {t('admin.tidOptional')}</li>
                    <li><strong>status</strong>: {t('admin.statusField')}</li>
                </ul>

                <p className="font-medium mt-3 mb-1">{t('admin.exampleWithoutTid')}</p>
                <pre className="bg-[var(--color-bg-soft)] p-2 rounded text-xs">19j4mhg1br4sa6nhc0,100,,lead{'\n'}19j4mhg1br4sa7m1u1,2.5,,sale</pre>

                <p className="font-medium mt-3 mb-1">{t('admin.exampleWithTid')}</p>
                <pre className="bg-[var(--color-bg-soft)] p-2 rounded text-xs">19j4mhg1br4sa6nhc0,100,1234567890,lead{'\n'}19j4mhg1br4sa7m1u1,2.5,0987654321,sale</pre>

                <p className="font-medium mt-3 mb-1 text-[var(--color-danger)]">{t('admin.warningTitle')}</p>
                <ul className="list-disc pl-5 mt-1">
                    <li>{t('admin.warningText')} <code>subid,0,tid,rejected</code>.</li>
                </ul>
            </div>

            <div className="mt-6">
                <label className="form-label">{t('admin.csvData')}</label>
                <textarea
                    className="form-input"
                    rows={8}
                    placeholder="19j4mhg1br4sa6nhc0,100,,lead"
                    value={csvData}
                    onChange={(e) => setCsvData(e.target.value)}
                    style={{ fontFamily: 'monospace', fontSize: '13px', lineHeight: '1.5', whiteSpace: 'pre' }}
                />
            </div>

            {message && (
                <div className="alert alert-success mt-4">
                    {message}
                </div>
            )}

            {errorMsg && (
                <div className="alert alert-danger mt-4 whitespace-pre-wrap text-sm">
                    {errorMsg}
                </div>
            )}

            <div className="mt-6 flex justify-end">
                <button
                    onClick={handleImport}
                    disabled={loading}
                    className="btn btn-primary"
                >
                    <Upload size={18} />
                    {loading ? t('admin.importing') : t('admin.importButton')}
                </button>
            </div>
        </div>
    );
};

const CleanupContent = () => {
    const { t } = useLanguage();
    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <h3 className="page-title m-0">{t('admin.cleanupTitle')}</h3>
            </div>

            <div className="alert alert-danger mt-6 flex items-start gap-3">
                <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5" />
                <div>
                    <h4 className="font-medium">{t('admin.warningTitle')}</h4>
                    <p className="text-sm">{t('admin.cleanupWarning')}</p>
                </div>
            </div>

            <div className="mt-6 form-section">
                <div>
                    <label className="form-label">{t('admin.deleteData')}</label>
                    <select className="form-select">
                        <option>{t('admin.olderThan30')}</option>
                        <option>{t('admin.olderThan60')}</option>
                        <option>{t('admin.olderThan90')}</option>
                        <option>{t('admin.allStatistics')}</option>
                    </select>
                </div>
                <div className="flex gap-2 flex-wrap">
                    <button className="btn btn-secondary">{t('admin.clearClicks')}</button>
                    <button className="btn btn-secondary">{t('admin.clearConversions')}</button>
                    <button className="btn btn-danger">{t('admin.clearAll')}</button>
                </div>
            </div>
        </div>
    );
};

// Main AdminPage component - uses useLanguage for dynamic titles
const AdminPage = ({ page }) => {
    const { t } = useLanguage();

    const adminPages = {
        admin_users: {
            title: t('admin.users'),
            icon: <UserCog className="w-6 h-6" />,
            description: t('nav.adminUsers') || t('admin.users'),
            comingSoon: false,
            content: <UsersPage />
        },
        admin_branding: {
            title: t('admin.branding'),
            icon: <Palette className="w-6 h-6" />,
            description: t('nav.adminBranding') || t('admin.branding'),
            comingSoon: false,
            content: <BrandingPage />
        },
        admin_geo_profiles: {
            title: t('admin.geoProfiles'),
            icon: <Map className="w-6 h-6" />,
            description: t('nav.adminGeoProfiles') || t('admin.geoProfiles'),
            comingSoon: false,
            content: <GeoProfilesPage />
        },
        admin_settings: {
            title: t('admin.settings'),
            icon: <SettingsIcon className="w-6 h-6" />,
            description: t('nav.adminSettings') || t('admin.settings'),
            comingSoon: false,
            content: <Settings />
        },
        admin_integrations: {
            title: t('admin.integrations'),
            icon: <Plug className="w-6 h-6" />,
            description: t('nav.adminIntegrations') || t('admin.integrations'),
            comingSoon: false,
            content: <IntegrationsPage />
        },
        admin_status: {
            title: t('admin.status'),
            icon: <Server className="w-6 h-6" />,
            description: t('nav.adminStatus') || t('admin.status'),
            comingSoon: false,
            content: <StatusContent />
        },
        admin_logs: {
            title: t('admin.logs'),
            icon: <FileStack className="w-6 h-6" />,
            description: t('nav.adminLogs') || t('admin.logs'),
            comingSoon: false,
            content: <LogsPage />
        },
        admin_archive: {
            title: t('admin.archive'),
            icon: <Archive className="w-6 h-6" />,
            description: t('nav.adminArchive') || t('admin.archive'),
            comingSoon: false,
            content: <ArchivePage />
        },
        admin_import: {
            title: t('admin.import'),
            icon: <Upload className="w-6 h-6" />,
            description: t('nav.adminImport') || t('admin.import'),
            comingSoon: false,
            content: <ImportContent />
        },
        admin_geo_dbs: {
            title: t('admin.geoDbs'),
            icon: <Database className="w-6 h-6" />,
            description: t('nav.adminGeoDbs') || t('admin.geoDbs'),
            comingSoon: false,
            content: <GeoDBPage />
        },
        admin_migrations: {
            title: t('admin.migrations'),
            icon: <ArrowRightLeft className="w-6 h-6" />,
            description: t('nav.adminMigrations') || t('admin.migrations'),
            comingSoon: false,
            content: <MigrationsPage />
        },
        admin_cleanup: {
            title: t('admin.cleanup'),
            icon: <Trash2 className="w-6 h-6" />,
            description: t('nav.adminCleanup') || t('admin.cleanup'),
            comingSoon: false,
            content: <CleanupContent />
        },
        admin_geo_db: {
            title: t('admin.geoDbs'),
            icon: <Database className="w-6 h-6" />,
            description: t('nav.adminGeoDbs') || t('admin.geoDbs'),
            comingSoon: true
        },
        admin_migration: {
            title: t('admin.migrations'),
            icon: <ArrowRightLeft className="w-6 h-6" />,
            description: t('nav.adminMigrations') || t('admin.migrations'),
            comingSoon: true
        },
        admin_update: {
            title: t('admin.update'),
            icon: <RefreshCw className="w-6 h-6" />,
            description: t('nav.adminUpdate') || t('admin.update'),
            comingSoon: false,
            content: <UpdatePage />
        },
        admin_aggregator: {
            title: t('admin.aggregator'),
            icon: <Database className="w-6 h-6" />,
            description: t('nav.adminAggregator') || t('admin.aggregator'),
            comingSoon: false,
            content: <AggregatorPage />
        },
        admin_feedback: {
            title: t('adminMenu.feedback') || 'Feedback & Support',
            icon: <MessageSquare className="w-6 h-6" />,
            description: t('adminMenu.feedback') || 'Feedback & Support',
            comingSoon: false,
            content: <FeedbackPage />
        }
    };

    const config = adminPages[page] || adminPages.admin_settings;

    return (
        <div className="space-y-4">
            <div className="flex items-center space-x-3">
                <div className="p-2 bg-blue-100 rounded text-blue-600">
                    {config.icon}
                </div>
                <div>
                    <h1 className="text-xl font-bold text-gray-800">{config.title}</h1>
                </div>
            </div>

            {config.comingSoon ? (
                <div className="bg-white rounded border p-8 text-center">
                    <div className="text-gray-400 mb-4">
                        <SettingsIcon className="w-16 h-16 mx-auto opacity-50" />
                    </div>
                    <h3 className="text-lg font-medium text-gray-700 mb-2">{t('admin.sectionInDevelopment')}</h3>
                    <p className="text-gray-500">{t('admin.comingSoonText')}</p>
                </div>
            ) : (
                config.content
            )}
        </div>
    );
};

export default AdminPage;