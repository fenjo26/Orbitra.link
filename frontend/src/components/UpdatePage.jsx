import React, { useState, useEffect } from 'react';
import { RefreshCw, Download, AlertCircle, CheckCircle, Info, ExternalLink } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const UpdatePage = () => {
    const { t } = useLanguage();
    const [updateInfo, setUpdateInfo] = useState(null);
    const [loading, setLoading] = useState(true);
    const [updating, setUpdating] = useState(false);
    const [updateStep, setUpdateStep] = useState(''); // 'downloading', 'installing', 'complete'
    const [updateSuccess, setUpdateSuccess] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    const checkUpdate = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=check_update`);
            if (res.data.status === 'success') {
                setUpdateInfo(res.data.data);
            }
        } catch (e) {
            setError(t('update.checkError'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        checkUpdate();
    }, []);

    const handleUpdate = async () => {
        if (updateInfo && updateInfo.update_available === false) {
            // Version-based checker may say "up to date" even when the user just wants to pull latest commits.
            // Allow it for admins, but ask for confirmation to avoid confusing clicks.
            const ok = window.confirm(t('update.forceConfirm'));
            if (!ok) return;
        }

        setUpdating(true);
        setUpdateSuccess(false);
        setMessage('');
        setError('');
        setUpdateStep('downloading');

        try {
            // Simulate progress steps
            await new Promise(r => setTimeout(r, 800));
            setUpdateStep('installing');

            const res = await axios.post(`${API_URL}?action=run_update`);
            const data = res.data;

            if (data.status === 'success') {
                setUpdateStep('complete');
                setUpdateSuccess(true);
                setMessage(data.message || t('update.updateSuccess'));

                // Re-check version after update
                setTimeout(() => {
                    checkUpdate();
                }, 1500);
            } else {
                setUpdateStep('');
                setError(data.message || t('update.updateError'));
            }
        } catch (e) {
            setUpdateStep('');
            setError(t('common.networkError') + ': ' + e.message);
        } finally {
            setUpdating(false);
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px' }}>
                    <RefreshCw className="w-8 h-8 animate-spin" style={{ color: 'var(--color-primary)' }} />
                    <p style={{ marginTop: '16px', color: 'var(--color-text-secondary)' }}>{t('update.checkingUpdates')}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Current Version */}
            <div className="page-card">
                <div className="page-header">
                    <h2 className="page-title">{t('update.versionInfo')}</h2>
                    <button
                        onClick={checkUpdate}
                        className="btn btn-ghost"
                    >
                        <RefreshCw className="w-4 h-4" />
                        {t('update.checkUpdates')}
                    </button>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '24px' }}>
                    <div>
                        <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', marginBottom: '4px' }}>{t('update.currentVersion')}</p>
                        <p style={{ fontSize: '24px', fontWeight: 600, color: 'var(--color-text-primary)' }}>
                            {updateInfo?.current_version || '—'}
                        </p>
                    </div>
                    <div>
                        <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', marginBottom: '4px' }}>{t('update.latestVersion')}</p>
                        <p style={{ fontSize: '24px', fontWeight: 600, color: 'var(--color-text-primary)' }}>
                            {updateInfo?.latest_version || '—'}
                        </p>
                    </div>
                </div>
            </div>

            {/* Update Status */}
            {updateInfo?.update_available ? (
                <div style={{
                    background: 'var(--color-warning-bg)',
                    borderRadius: '16px',
                    padding: '16px'
                }}>
                    <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                        <AlertCircle className="w-6 h-6 flex-shrink-0" style={{ color: 'var(--color-warning)' }} />
                        <div>
                            <h4 style={{ fontWeight: 500, color: 'var(--color-warning)', marginBottom: '4px' }}>
                                {t('update.updateAvailable')}
                            </h4>
                            <p style={{ fontSize: '14px', color: 'var(--color-warning)', opacity: 0.8 }}>
                                {t('update.newVersionText')} {updateInfo.latest_version}. {t('update.recommendUpdate')}
                            </p>
                            {updateInfo.release_notes && (
                                <div style={{
                                    marginTop: '12px',
                                    padding: '12px',
                                    background: 'var(--color-bg-card)',
                                    borderRadius: '12px',
                                    fontSize: '14px',
                                    color: 'var(--color-text-primary)'
                                }}>
                                    <p style={{ fontWeight: 500, marginBottom: '8px' }}>{t('update.changelog')}</p>
                                    <pre style={{ whiteSpace: 'pre-wrap', fontFamily: 'inherit', margin: 0 }}>{updateInfo.release_notes}</pre>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            ) : (
                <div style={{
                    background: 'var(--color-success-bg)',
                    borderRadius: '16px',
                    padding: '16px'
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                        <CheckCircle className="w-6 h-6" style={{ color: 'var(--color-success)' }} />
                        <div>
                            <h4 style={{ fontWeight: 500, color: 'var(--color-success)', marginBottom: '4px' }}>
                                {t('update.upToDate')}
                            </h4>
                            <p style={{ fontSize: '14px', color: 'var(--color-success)', opacity: 0.8 }}>
                                {t('update.noUpdates')}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Update Success Message */}
            {updateSuccess && (
                <div style={{
                    background: 'var(--color-success-bg)',
                    borderRadius: '16px',
                    padding: '20px',
                    border: '1px solid var(--color-success)'
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                        <div style={{
                            width: '48px',
                            height: '48px',
                            background: 'var(--color-success)',
                            borderRadius: '50%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center'
                        }}>
                            <CheckCircle className="w-6 h-6" style={{ color: 'white' }} />
                        </div>
                        <div>
                            <h4 style={{ fontWeight: 600, color: 'var(--color-success)', marginBottom: '4px', fontSize: '16px' }}>
                                ✅ {t('update.updateComplete')}
                            </h4>
                            <p style={{ fontSize: '14px', color: 'var(--color-success)', opacity: 0.9 }}>
                                {t('update.updateCompleteDesc')}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Messages */}
            {message && !updateSuccess && (
                <div style={{
                    background: 'var(--color-success-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    color: 'var(--color-success)',
                    fontSize: '14px'
                }}>
                    {message}
                </div>
            )}

            {error && (
                <div style={{
                    background: 'var(--color-danger-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    color: 'var(--color-danger)',
                    fontSize: '14px'
                }}>
                    {error}
                </div>
            )}

            {/* Update Methods */}
            <div className="page-card">
                <div className="page-header">
                    <h2 className="page-title">{t('update.updateMethods')}</h2>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                    {/* Auto Update */}
                    <div style={{
                        border: updating ? '2px solid var(--color-primary)' : '1px solid var(--color-border)',
                        borderRadius: '16px',
                        padding: '16px',
                        transition: 'all 0.3s ease'
                    }}>
                        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '12px' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                                <div style={{
                                    width: '40px',
                                    height: '40px',
                                    background: updating ? 'var(--color-primary)' : 'var(--color-bg-soft)',
                                    borderRadius: '10px',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    transition: 'all 0.3s ease'
                                }}>
                                    {updating ? (
                                        <RefreshCw className="w-5 h-5 animate-spin" style={{ color: 'white' }} />
                                    ) : (
                                        <Download className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                                    )}
                                </div>
                                <div>
                                    <p style={{ fontWeight: 500, color: 'var(--color-text-primary)' }}>
                                        {updating ? t('update.updatingTitle') : t('update.autoUpdate')}
                                    </p>
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                        {updating ? (
                                            updateStep === 'downloading' ? t('update.downloading') :
                                                updateStep === 'installing' ? t('update.installing') :
                                                    updateStep === 'complete' ? t('update.complete') : ''
                                        ) : t('update.autoUpdateDesc')}
                                    </p>
                                </div>
                            </div>
                            <button
                                onClick={handleUpdate}
                                disabled={updating}
                                className="btn btn-primary"
                                style={{ minWidth: '140px' }}
                            >
                                {updating ? (
                                    <>
                                        <RefreshCw className="w-4 h-4 animate-spin" />
                                        {t('update.updating')}
                                    </>
                                ) : (
                                    <>
                                        <Download className="w-4 h-4" />
                                        {t('update.updateBtn')}
                                    </>
                                )}
                            </button>
                        </div>

                        {/* Progress bar during update */}
                        {updating && (
                            <div style={{ marginTop: '16px' }}>
                                <div style={{
                                    height: '4px',
                                    background: 'var(--color-bg-soft)',
                                    borderRadius: '2px',
                                    overflow: 'hidden'
                                }}>
                                    <div style={{
                                        height: '100%',
                                        background: 'var(--color-primary)',
                                        borderRadius: '2px',
                                        width: updateStep === 'downloading' ? '33%' :
                                            updateStep === 'installing' ? '66%' : '100%',
                                        transition: 'width 0.5s ease'
                                    }} />
                                </div>
                                <div style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    marginTop: '8px',
                                    fontSize: '11px',
                                    color: 'var(--color-text-muted)'
                                }}>
                                    <span style={{ color: updateStep === 'downloading' ? 'var(--color-primary)' : 'var(--color-text-muted)' }}>
                                        1. {t('update.stepDownload')}
                                    </span>
                                    <span style={{ color: updateStep === 'installing' ? 'var(--color-primary)' : 'var(--color-text-muted)' }}>
                                        2. {t('update.stepInstall')}
                                    </span>
                                    <span style={{ color: updateStep === 'complete' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                        3. {t('update.stepComplete')}
                                    </span>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Manual Update via Git */}
                    <div style={{
                        border: '1px solid var(--color-border)',
                        borderRadius: '16px',
                        padding: '16px'
                    }}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                            <div style={{
                                width: '32px',
                                height: '32px',
                                background: 'var(--color-bg-soft)',
                                borderRadius: '10px',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center'
                            }}>
                                <span style={{ fontSize: '16px' }}>📦</span>
                            </div>
                            <div style={{ flex: 1 }}>
                                <p style={{ fontWeight: 500, color: 'var(--color-text-primary)', marginBottom: '4px' }}>{t('update.gitUpdate')}</p>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '12px' }}>
                                    {t('update.gitUpdateDesc')}
                                </p>
                                <div style={{
                                    background: '#1a1a1a',
                                    borderRadius: '12px',
                                    padding: '12px',
                                    fontFamily: 'monospace',
                                    fontSize: '13px',
                                    color: '#a3e635'
                                }}>
                                    <code>cd /path/to/tracker</code><br />
                                    <code>git pull origin main</code>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Manual Update via Download */}
                    <div style={{
                        border: '1px solid var(--color-border)',
                        borderRadius: '16px',
                        padding: '16px'
                    }}>
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                            <div style={{
                                width: '32px',
                                height: '32px',
                                background: 'var(--color-bg-soft)',
                                borderRadius: '10px',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center'
                            }}>
                                <ExternalLink className="w-4 h-4" style={{ color: 'var(--color-text-muted)' }} />
                            </div>
                            <div style={{ flex: 1 }}>
                                <p style={{ fontWeight: 500, color: 'var(--color-text-primary)', marginBottom: '4px' }}>{t('update.downloadArchive')}</p>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '12px' }}>
                                    {t('update.downloadArchiveDesc')}
                                </p>
                                <a
                                    href="#"
                                    style={{ fontSize: '14px', color: 'var(--color-primary)' }}
                                    onClick={(e) => e.preventDefault()}
                                >
                                    {t('update.downloadLink')}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Update Server Configuration */}
            <div style={{
                background: 'var(--color-info-bg)',
                borderRadius: '16px',
                padding: '16px'
            }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                    <Info className="w-5 h-5 flex-shrink-0" style={{ color: 'var(--color-info)' }} />
                    <div style={{ fontSize: '14px', color: 'var(--color-info)' }}>
                        <p style={{ fontWeight: 500, marginBottom: '4px' }}>{t('update.serverConfig')}</p>
                        <p style={{ opacity: 0.8 }}>
                            {t('update.serverConfigDesc')}{' '}
                            <code style={{
                                background: 'var(--color-bg-card)',
                                padding: '2px 6px',
                                borderRadius: '6px',
                                fontSize: '13px'
                            }}>api.php</code>{' '}
                            {t('update.serverConfigBlock')}{' '}
                            <code style={{
                                background: 'var(--color-bg-card)',
                                padding: '2px 6px',
                                borderRadius: '6px',
                                fontSize: '13px'
                            }}>case 'check_update'</code>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default UpdatePage;
