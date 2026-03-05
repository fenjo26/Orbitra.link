import React, { useState, useEffect } from 'react';
import { Database, CheckCircle, Clock, RotateCcw, Play } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const MigrationsPage = () => {
    const { t } = useLanguage();
    const [migrations, setMigrations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(null);

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
        </div>
    );
};

export default MigrationsPage;