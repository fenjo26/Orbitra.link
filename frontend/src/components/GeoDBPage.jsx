import React, { useState, useEffect, useRef } from 'react';
import { Database, RefreshCw, CheckCircle, XCircle, AlertTriangle, Upload, Info } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const GeoDBPage = () => {
    const { t } = useLanguage();
    const [dbs, setDbs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [updateProgress, setUpdateProgress] = useState({});
    const [uploadLoading, setUploadLoading] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [maxmindKey, setMaxmindKey] = useState('');
    const [maxmindAccountId, setMaxmindAccountId] = useState('');
    const [ip2locationToken, setIp2locationToken] = useState('');
    const [savingKey, setSavingKey] = useState(false);
    const fileInputRef = useRef(null);

    const fetchDbs = () => {
        setLoading(true);
        fetch(`${API_URL}?action=geo_dbs`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setDbs(data.data);
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    const fetchSettings = () => {
        fetch(`${API_URL}?action=global_settings`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.data) {
                    setMaxmindKey(data.data.maxmind_license_key || '');
                    setMaxmindAccountId(data.data.maxmind_account_id || '');
                    setIp2locationToken(data.data.ip2location_token || '');
                }
            })
            .catch(console.error);
    };

    useEffect(() => {
        fetchDbs();
        fetchSettings();
    }, []);

    const formatBytes = (bytes) => {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const decimals = 2;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
    };

    const handleUpdate = async (id) => {
        setUpdateProgress(prev => ({ ...prev, [id]: { status: 'loading', progress: 0 } }));
        setMessage('');
        setError('');
        try {
            const res = await fetch(`${API_URL}?action=geo_db_update`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage(t('geoDb.updateSuccess') || 'База успешно обновлена');
                setUpdateProgress(prev => ({ ...prev, [id]: { status: 'success', progress: 100 } }));
                fetchDbs();
            } else {
                setError(data.message || t('geoDb.updateError'));
                setUpdateProgress(prev => ({ ...prev, [id]: { status: 'error', message: data.message } }));
            }
        } catch (e) {
            setError(t('common.networkError') + ': ' + e.message);
            setUpdateProgress(prev => ({ ...prev, [id]: { status: 'error', message: e.message } }));
        }
    };

    const handleFileUpload = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        setUploadLoading(true);
        setMessage('');
        setError('');

        const formData = new FormData();
        formData.append('file', file);
        formData.append('db_id', 'sypex_city_lite');

        try {
            const res = await fetch(`${API_URL}?action=geo_db_upload`, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage(data.message || t('geoDb.updateSuccess'));
                fetchDbs();
            } else {
                setError(data.message || t('geoDb.updateError'));
            }
        } catch (e) {
            setError(t('common.networkError') + ': ' + e.message);
        } finally {
            setUploadLoading(false);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const handleSaveKey = async () => {
        setSavingKey(true);
        setMessage('');
        setError('');
        try {
            const res = await fetch(`${API_URL}?action=global_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: { maxmind_license_key: maxmindKey, maxmind_account_id: maxmindAccountId, ip2location_token: ip2locationToken } })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage(t('geoDb.keysSaved'));
            } else {
                setError(data.message || t('geoDb.keySaveError'));
            }
        } catch (e) {
            setError(t('common.networkError') + ': ' + e.message);
        } finally {
            setSavingKey(false);
        }
    };

    if (loading && dbs.length === 0) {
        return (
            <div className="page-card">
                <div className="empty-state">
                    <p className="empty-state-title">{t('geoDb.loadingDbs')}</p>
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
                        <h2 className="page-title">{t('geoDb.title')}</h2>
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
                        {t('geoDb.infoText')}
                    </p>
                </div>

                {/* Supported Geo Databases Info */}
                <div style={{
                    background: 'var(--color-bg-soft)',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '24px'
                }}>
                    <h4 style={{ fontWeight: 500, marginBottom: '12px', color: 'var(--color-text-primary)' }}>
                        {t('geoDb.supportedTitle')}
                    </h4>

                    <div style={{ marginBottom: '12px' }}>
                        <p style={{ fontSize: '12px', fontWeight: 500, color: 'var(--color-success)', marginBottom: '4px' }}>{t('geoDb.recommendedFree')}</p>
                        <ul style={{ fontSize: '13px', color: 'var(--color-text-secondary)', marginLeft: '16px' }}>
                            <li>• <strong>Sypex Geo City Lite</strong> — {t('geoDb.sypexDesc')}</li>
                            <li>• <strong>IP2Location LITE (DB11 IPv4+IPv6)</strong> — {t('geoDb.ip2locLiteDesc')}</li>
                        </ul>
                    </div>

                    <div style={{ marginBottom: '12px' }}>
                        <p style={{ fontSize: '12px', fontWeight: 500, color: 'var(--color-accent-turquoise)', marginBottom: '4px' }}>{t('geoDb.paidDbs')}</p>
                        <ul style={{ fontSize: '13px', color: 'var(--color-text-secondary)', marginLeft: '16px' }}>
                            <li>• <strong>IP2Location DB4</strong> — {t('geoDb.ip2locDb4Desc')}</li>
                            <li>• <strong>IP2Location PX2</strong> — {t('geoDb.ip2locPx2Desc')}</li>
                            <li>• <strong>Sypex Geo City</strong> — {t('geoDb.sypexFullDesc')}</li>
                            <li>• <strong>MaxMind City</strong> — {t('geoDb.maxmindFullDesc')}</li>
                        </ul>
                    </div>

                    <div style={{
                        marginTop: '12px',
                        padding: '8px 12px',
                        background: 'var(--color-warning-bg)',
                        borderRadius: '12px',
                        fontSize: '13px',
                        color: 'var(--color-warning)'
                    }}>
                        <strong>{t('geoDb.downloadFrom')}</strong>{' '}
                        <a href="https://sypexgeo.net/files/SxGeoCity_utf8.zip" target="_blank" rel="noopener" style={{ color: 'var(--color-primary)' }}>{t('geoDb.sypexFree')}</a>,{' '}
                        <a href="https://lite.ip2location.com/" target="_blank" rel="noopener" style={{ color: 'var(--color-primary)' }}>IP2Location LITE</a>,{' '}
                        <a href="https://www.maxmind.com/" target="_blank" rel="noopener" style={{ color: 'var(--color-primary)' }}>MaxMind</a>
                    </div>
                </div>

                {/* MaxMind & IP2Location Keys Section */}
                <div style={{ marginBottom: '24px' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                        <div>
                            <label className="form-label">MaxMind Account ID</label>
                            <div style={{ display: 'flex', gap: '12px', alignItems: 'flex-start' }}>
                                <input
                                    type="text"
                                    value={maxmindAccountId}
                                    onChange={(e) => setMaxmindAccountId(e.target.value)}
                                    placeholder={t('geoDb.maxmindAccountIdPlaceholder')}
                                    className="form-input"
                                    style={{ maxWidth: '400px' }}
                                />
                            </div>
                            <p className="form-hint">{t('geoDb.maxmindAccountIdHint')}</p>
                        </div>

                        <div>
                            <label className="form-label">MaxMind License Key</label>
                            <div style={{ display: 'flex', gap: '12px', alignItems: 'flex-start' }}>
                                <input
                                    type="text"
                                    value={maxmindKey}
                                    onChange={(e) => setMaxmindKey(e.target.value)}
                                    placeholder={t('geoDb.maxmindPlaceholder')}
                                    className="form-input"
                                    style={{ maxWidth: '400px' }}
                                />
                            </div>
                            <p className="form-hint">{t('geoDb.maxmindHint')}</p>
                        </div>

                        <div>
                            <label className="form-label">IP2Location Download Token</label>
                            <div style={{ display: 'flex', gap: '12px', alignItems: 'flex-start' }}>
                                <input
                                    type="text"
                                    value={ip2locationToken}
                                    onChange={(e) => setIp2locationToken(e.target.value)}
                                    placeholder={t('geoDb.ip2locationPlaceholder')}
                                    className="form-input"
                                    style={{ maxWidth: '400px' }}
                                />
                                <button
                                    onClick={handleSaveKey}
                                    disabled={savingKey}
                                    className="btn btn-secondary"
                                >
                                    {savingKey ? t('geoDb.savingKeys') : t('geoDb.saveKeys')}
                                </button>
                            </div>
                            <p className="form-hint">{t('geoDb.ip2locationHint')}</p>
                        </div>
                    </div>
                </div>

                {/* Messages */}
                {message && (
                    <div style={{
                        padding: '12px 16px',
                        background: 'var(--color-success-bg)',
                        color: 'var(--color-success)',
                        borderRadius: '12px',
                        marginBottom: '16px',
                        fontSize: '14px'
                    }}>
                        {message}
                    </div>
                )}

                {error && (
                    <div style={{
                        padding: '12px 16px',
                        background: 'var(--color-danger-bg)',
                        color: 'var(--color-danger)',
                        borderRadius: '12px',
                        marginBottom: '16px',
                        fontSize: '14px'
                    }}>
                        {error}
                    </div>
                )}

                {/* Upload Section */}
                <div style={{
                    padding: '16px',
                    background: 'var(--color-bg-soft)',
                    borderRadius: '16px',
                    marginBottom: '24px'
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '16px', flexWrap: 'wrap' }}>
                        <input
                            type="file"
                            ref={fileInputRef}
                            onChange={handleFileUpload}
                            accept=".dat,.zip,.bin"
                            className="hidden"
                        />
                        <button
                            onClick={() => fileInputRef.current?.click()}
                            disabled={uploadLoading}
                            className="btn btn-secondary"
                        >
                            <Upload className="w-4 h-4" />
                            {uploadLoading ? t('geoDb.uploading') : t('geoDb.uploadFile')}
                        </button>
                        <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                            {t('geoDb.downloadAndUpload')} <a href="https://sypexgeo.net/files/SxGeoCity_utf8.zip" target="_blank" rel="noopener" style={{ color: 'var(--color-primary)' }}>SxGeoCity_utf8.zip</a> {t('geoDb.andUploadHere')}
                        </span>
                    </div>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('geoDb.colName')}</th>
                                <th>{t('geoDb.colType')}</th>
                                <th>{t('geoDb.colStatus')}</th>
                                <th>{t('geoDb.colUpdated')}</th>
                                <th>{t('geoDb.colSize')}</th>
                                <th style={{ textAlign: 'right' }}>{t('geoDb.colActions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {dbs.map(db => (
                                <tr key={db.id}>
                                    <td style={{ fontWeight: 500 }}>{db.name}</td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{db.type}</td>
                                    <td>
                                        {db.status === 'OK' ? (
                                            <span className="status-badge status-active">
                                                <CheckCircle className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                OK
                                            </span>
                                        ) : (
                                            <span className="status-badge status-pending">
                                                <AlertTriangle className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                {db.status}
                                            </span>
                                        )}
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>
                                        {db.updated_at ? (
                                            <span style={{
                                                padding: '4px 10px',
                                                background: 'var(--color-bg-soft)',
                                                borderRadius: '8px',
                                                fontSize: '12px'
                                            }}>
                                                {db.updated_at}
                                            </span>
                                        ) : (
                                            <span style={{ color: 'var(--color-text-muted)', fontStyle: 'italic' }}>{t('geoDb.never')}</span>
                                        )}
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>
                                        {db.size > 0 ? formatBytes(db.size) : '0 B'}
                                    </td>
                                    <td>
                                        <div className="action-buttons">
                                            <button
                                                onClick={() => handleUpdate(db.id)}
                                                disabled={updateProgress[db.id]?.status === 'loading'}
                                                className="btn btn-primary btn-sm"
                                            >
                                                <RefreshCw className={`w-3.5 h-3.5 ${updateProgress[db.id]?.status === 'loading' ? 'animate-spin' : ''}`} />
                                                {updateProgress[db.id]?.status === 'loading' ? t('geoDb.downloading') : t('geoDb.update')}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {dbs.length === 0 && !loading && (
                    <div className="empty-state">
                        <p className="empty-state-title">{t('geoDb.noDbs')}</p>
                    </div>
                )}
            </div>
        </div>
    );
};

export default GeoDBPage;
