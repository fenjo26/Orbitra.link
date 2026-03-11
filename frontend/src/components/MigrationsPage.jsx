import React, { useState, useEffect } from 'react';
import { Database, CheckCircle, Clock, RotateCcw, Play } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const MigrationsPage = () => {
    const { t } = useLanguage();
    const [migrations, setMigrations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(null);
    const [kFile, setKFile] = useState(null);
    const [kDryRun, setKDryRun] = useState(true);
    const [kImportDomains, setKImportDomains] = useState(true);
    const [kImportOffers, setKImportOffers] = useState(true);
    const [kImportCompanies, setKImportCompanies] = useState(true);
    const [kImportTrafficSources, setKImportTrafficSources] = useState(false);
    const [kImportLandings, setKImportLandings] = useState(false);
    const [kImportCampaigns, setKImportCampaigns] = useState(false);
    const [kImportStreams, setKImportStreams] = useState(false);
    const [kImportCampaignPostbacks, setKImportCampaignPostbacks] = useState(false);
    const [kLoading, setKLoading] = useState(false);
    const [kError, setKError] = useState('');
    const [kResult, setKResult] = useState(null);

    const [purgeConfirm, setPurgeConfirm] = useState('');
    const [purgeLoading, setPurgeLoading] = useState(false);
    const [purgeError, setPurgeError] = useState('');
    const [purgeResult, setPurgeResult] = useState(null);

    const fetchMigrations = () => {
        setLoading(true);
        fetch(`${API_URL}?action=migrations`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setMigrations(data.data);
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => {
        fetchMigrations();
    }, []);

    const handleRunMigration = async (version) => {
        setActionLoading(version);
        try {
            const res = await fetch(`${API_URL}?action=run_migration`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version })
            });
            const data = await res.json();
            if (data.status === 'success') {
                fetchMigrations();
            } else {
                alert(t('common.error') + ': ' + (data.message || t('migrations.runError')));
            }
        } catch (e) {
            alert(t('common.networkError') + ': ' + e.message);
        } finally {
            setActionLoading(null);
        }
    };

    const handleKeitaroImport = async () => {
        if (!kFile) {
            setKError(t('migrations.keitaroNoFile'));
            return;
        }
        setKLoading(true);
        setKError('');
        setKResult(null);
        try {
            const fd = new FormData();
            fd.append('sql_file', kFile);
            fd.append('dry_run', kDryRun ? '1' : '0');
            fd.append('import_domains', kImportDomains ? '1' : '0');
            fd.append('import_offers', kImportOffers ? '1' : '0');
            fd.append('import_companies', kImportCompanies ? '1' : '0');
            fd.append('import_traffic_sources', kImportTrafficSources ? '1' : '0');
            fd.append('import_landings', kImportLandings ? '1' : '0');
            fd.append('import_campaigns', kImportCampaigns ? '1' : '0');
            fd.append('import_streams', kImportStreams ? '1' : '0');
            fd.append('import_campaign_postbacks', kImportCampaignPostbacks ? '1' : '0');

            const res = await fetch(`${API_URL}?action=keitaro_import_sql`, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.status !== 'success') {
                setKError(data.message || t('common.error'));
                return;
            }
            setKResult(data.data || null);
        } catch (e) {
            setKError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setKLoading(false);
        }
    };

    const handlePurgeMetadata = async () => {
        setPurgeLoading(true);
        setPurgeError('');
        setPurgeResult(null);
        try {
            const res = await fetch(`${API_URL}?action=purge_metadata`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm: purgeConfirm,
                    purge: {
                        companies: 1,
                        offers: 1,
                        domains: 1,
                        campaigns: 1,
                        streams: 1,
                        campaign_postbacks: 1,
                        campaign_pixels: 1,
                        traffic_sources: 1,
                        landings: 1,
                        groups: 1,
                    }
                })
            });
            const data = await res.json();
            if (data.status !== 'success') {
                setPurgeError(data.message || t('common.error'));
                return;
            }
            setPurgeResult(data.data || null);
        } catch (e) {
            setPurgeError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setPurgeLoading(false);
        }
    };

    if (loading && migrations.length === 0) {
        return (
            <div className="page-card">
                <div className="empty-state">
                    <p className="empty-state-title">{t('migrations.loadingMigrations')}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="page-card">
                {/* Header */}
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.title')}</h2>
                    </div>
                </div>

                {/* Info Block */}
                <div style={{
                    background: 'var(--color-info-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '24px'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-info)' }}>
                        {t('migrations.infoText')}
                    </p>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ width: '100px' }}>{t('migrations.colVersion')}</th>
                                <th>{t('migrations.colDescription')}</th>
                                <th style={{ width: '160px' }}>{t('migrations.colStatus')}</th>
                                <th style={{ width: '140px', textAlign: 'right' }}>{t('migrations.colActions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {migrations.map(m => {
                                const isCompleted = m.status === 'completed';
                                return (
                                    <tr key={m.version}>
                                        <td style={{
                                            fontFamily: 'monospace',
                                            fontSize: '14px',
                                            color: 'var(--color-text-muted)'
                                        }}>
                                            #{m.version}
                                        </td>
                                        <td style={{ fontWeight: 500 }}>{t(`migrations.descriptions.v${m.version}`)}</td>
                                        <td>
                                            {isCompleted ? (
                                                <span className="status-badge status-active">
                                                    <CheckCircle className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                    {t('migrations.completed')}
                                                </span>
                                            ) : (
                                                <span className="status-badge status-pending">
                                                    <Clock className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                    {t('migrations.pending')}
                                                </span>
                                            )}
                                            {m.executed_at && (
                                                <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                                    {m.executed_at}
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            <div className="action-buttons">
                                                <button
                                                    onClick={() => handleRunMigration(m.version)}
                                                    disabled={actionLoading === m.version}
                                                    className={`btn btn-sm ${isCompleted ? 'btn-secondary' : 'btn-primary'}`}
                                                >
                                                    {actionLoading === m.version ? (
                                                        <RotateCcw className="w-3.5 h-3.5 animate-spin" />
                                                    ) : isCompleted ? (
                                                        <RotateCcw className="w-3.5 h-3.5" />
                                                    ) : (
                                                        <Play className="w-3.5 h-3.5" />
                                                    )}
                                                    {actionLoading === m.version ? t('migrations.running') : isCompleted ? t('migrations.repeat') : t('migrations.execute')}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {migrations.length === 0 && !loading && (
                    <div className="empty-state">
                        <p className="empty-state-title">{t('migrations.noMigrations')}</p>
                    </div>
                )}
            </div>

            {/* Keitaro import */}
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.keitaroTitle')}</h2>
                    </div>
                </div>

                <div style={{
                    background: 'var(--color-info-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '16px'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-info)' }}>
                        {t('migrations.keitaroInfo')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.keitaroFile')}</label>
                        <input
                            type="file"
                            accept=".sql,.sql.gz"
                            className="form-input"
                            onChange={(e) => setKFile(e.target.files?.[0] || null)}
                        />
                        <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '8px' }}>
                            {t('migrations.keitaroFileHint')}
                        </div>
                    </div>

                    <div>
                        <label className="form-label">{t('migrations.keitaroOptions')}</label>
                        <div className="space-y-2">
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kDryRun} onChange={(e) => setKDryRun(e.target.checked)} />
                                <span>{t('migrations.keitaroDryRun')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCompanies} onChange={(e) => setKImportCompanies(e.target.checked)} />
                                <span>{t('migrations.keitaroCompanies')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportOffers} onChange={(e) => setKImportOffers(e.target.checked)} />
                                <span>{t('migrations.keitaroOffers')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportDomains} onChange={(e) => setKImportDomains(e.target.checked)} />
                                <span>{t('migrations.keitaroDomains')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportTrafficSources} onChange={(e) => setKImportTrafficSources(e.target.checked)} />
                                <span>{t('migrations.keitaroTrafficSources')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportLandings} onChange={(e) => setKImportLandings(e.target.checked)} />
                                <span>{t('migrations.keitaroLandings')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCampaigns} onChange={(e) => setKImportCampaigns(e.target.checked)} />
                                <span>{t('migrations.keitaroCampaigns')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportStreams} onChange={(e) => setKImportStreams(e.target.checked)} />
                                <span>{t('migrations.keitaroStreams')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCampaignPostbacks} onChange={(e) => setKImportCampaignPostbacks(e.target.checked)} />
                                <span>{t('migrations.keitaroCampaignPostbacks')}</span>
                            </label>
                        </div>
                    </div>
                </div>

                {kError && (
                    <div className="alert alert-danger mt-4">
                        {kError}
                    </div>
                )}

                {kResult && (
                    <div className="alert alert-success mt-4" style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                        {JSON.stringify(kResult, null, 2)}
                    </div>
                )}

                <div className="mt-4 flex items-center justify-end">
                    <button
                        className="btn btn-primary"
                        onClick={handleKeitaroImport}
                        disabled={kLoading}
                    >
                        {kLoading ? t('migrations.keitaroRunning') : (kDryRun ? t('migrations.keitaroPreviewBtn') : t('migrations.keitaroImportBtn'))}
                    </button>
                </div>
            </div>

            {/* Purge / reset metadata */}
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.purgeTitle')}</h2>
                    </div>
                </div>

                <div style={{
                    background: 'var(--color-warning-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '16px',
                    border: '1px solid var(--color-warning-border)'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-warning)' }}>
                        {t('migrations.purgeInfo')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.purgeConfirmLabel')}</label>
                        <input
                            type="text"
                            className="form-input"
                            value={purgeConfirm}
                            onChange={(e) => setPurgeConfirm(e.target.value)}
                            placeholder="DELETE"
                        />
                        <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '8px' }}>
                            {t('migrations.purgeConfirmHint')}
                        </div>
                    </div>
                    <div>
                        <label className="form-label">{t('migrations.purgeWhat')}</label>
                        <div style={{ fontSize: '13px', color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                            {t('migrations.purgeWhatHint')}
                        </div>
                    </div>
                </div>

                {purgeError && (
                    <div className="alert alert-danger mt-4">
                        {purgeError}
                    </div>
                )}

                {purgeResult && (
                    <div className="alert alert-success mt-4" style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                        {JSON.stringify(purgeResult, null, 2)}
                    </div>
                )}

                <div className="mt-4 flex items-center justify-end">
                    <button
                        className="btn btn-danger"
                        onClick={handlePurgeMetadata}
                        disabled={purgeLoading || String(purgeConfirm || '').trim().toUpperCase() !== 'DELETE'}
                        title={String(purgeConfirm || '').trim().toUpperCase() !== 'DELETE' ? t('migrations.purgeDisabledHint') : ''}
                    >
                        {purgeLoading ? t('migrations.purgeRunning') : t('migrations.purgeBtn')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default MigrationsPage;
