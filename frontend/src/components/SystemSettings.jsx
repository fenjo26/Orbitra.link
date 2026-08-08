import React, { useState, useEffect } from 'react';
import { Save, HardDrive, Database, Archive, Shield, KeyRound, AlertTriangle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const SystemSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const [settings, setSettings] = useState({
        stats_enabled: '1',
        stats_retention_days: '256',
        archive_retention_days: '30',
        admin_ip_access: '',
        ignore_prefetch: '1',
        admin_path: ''
    });

    // The path the panel was loaded from, so we can tell the user where it moved
    // to — and warn them before they navigate away from a page they can only
    // reach again at the new URL.
    const [savedAdminPath, setSavedAdminPath] = useState('');

    useEffect(() => {
        fetch(`${API_URL}?action=global_settings`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.data) {
                    setSettings(prev => ({ ...prev, ...data.data }));
                    setSavedAdminPath(data.data.admin_path || '');
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, []);

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        setSettings(prev => ({ ...prev, [name]: type === 'checkbox' ? (checked ? '1' : '0') : value }));
    };

    // The API returns a code rather than a sentence so the panel can translate it.
    const adminPathError = (code) => {
        if (code === 'admin_path_invalid') return t('systemSettings.adminPathInvalid');
        if (code === 'admin_path_reserved') return t('systemSettings.adminPathReserved');
        if (code === 'admin_path_alias_taken') return t('systemSettings.adminPathAliasTaken');
        return code;
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=global_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    settings: {
                        stats_enabled: settings.stats_enabled,
                        stats_retention_days: settings.stats_retention_days,
                        archive_retention_days: settings.archive_retention_days,
                        admin_ip_access: settings.admin_ip_access,
                        ignore_prefetch: settings.ignore_prefetch,
                        admin_path: (settings.admin_path || '').trim()
                    }
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                const moved = typeof data.admin_path === 'string' && data.admin_path !== savedAdminPath;
                if (moved) {
                    setSavedAdminPath(data.admin_path);
                    setMessage({
                        text: t('systemSettings.adminPathMoved') + ' ' + window.location.origin + data.admin_url,
                        type: 'success'
                    });
                } else {
                    setMessage({ text: t('systemSettings.saveSuccess'), type: 'success' });
                }
            } else {
                setMessage({ text: adminPathError(data.message) || t('systemSettings.saveError'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('systemSettings.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <p className="text-[var(--color-text-muted)]">{t('systemSettings.loading')}</p>
            </div>
        );
    }

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <div className="flex items-center gap-2">
                    <HardDrive size={18} className="text-[var(--color-primary)]" />
                    <h3 className="page-title m-0">{t('systemSettings.title')}</h3>
                </div>
            </div>

            <div className="mt-6" style={{ maxWidth: '600px' }}>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'} mb-4`}>
                        {message.text}
                    </div>
                )}

                <div className="form-section">
                    {/* Statistics collection */}
                    <label className="form-checkbox-label">
                        <input
                            type="checkbox"
                            name="stats_enabled"
                            checked={settings.stats_enabled === '1'}
                            onChange={handleChange}
                        />
                        <div className="form-checkbox-content">
                            <span className="form-checkbox-title">{t('systemSettings.statsCollection')}</span>
                            <p className="form-checkbox-description">
                                {t('systemSettings.statsCollectionDesc')}
                            </p>
                        </div>
                    </label>

                    {/* Ignore prefetch requests */}
                    <label className="form-checkbox-label">
                        <input
                            type="checkbox"
                            name="ignore_prefetch"
                            checked={settings.ignore_prefetch === '1'}
                            onChange={handleChange}
                        />
                        <div className="form-checkbox-content">
                            <span className="form-checkbox-title">{t('systemSettings.ignorePrefetch')}</span>
                            <p className="form-checkbox-description">
                                {t('systemSettings.ignorePrefetchDesc')}
                            </p>
                        </div>
                    </label>

                    {/* Log retention period */}
                    <div>
                        <label className="form-label">{t('systemSettings.logRetention')}</label>
                        <div className="relative">
                            <Database className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="number"
                                name="stats_retention_days"
                                value={settings.stats_retention_days}
                                onChange={handleChange}
                                className="form-input pl-12"
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.logRetentionHint')}</p>
                    </div>

                    {/* Archive resource retention period */}
                    <div>
                        <label className="form-label">{t('systemSettings.archiveRetention')}</label>
                        <div className="relative">
                            <Archive className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="number"
                                name="archive_retention_days"
                                value={settings.archive_retention_days}
                                onChange={handleChange}
                                className="form-input pl-12"
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.archiveRetentionHint')}</p>
                    </div>

                    {/* Admin panel access */}
                    <div>
                        <label className="form-label">{t('systemSettings.adminAccess')}</label>
                        <div className="relative">
                            <Shield className="absolute left-4 top-4 h-5 w-5 text-gray-400 pointer-events-none" />
                            <textarea
                                name="admin_ip_access"
                                value={settings.admin_ip_access || ''}
                                onChange={handleChange}
                                rows={3}
                                placeholder={t('systemSettings.adminAccessPlaceholder')}
                                className="form-input pl-12"
                                style={{ fontFamily: 'monospace', fontSize: '13px', resize: 'none' }}
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.adminAccessHint')}</p>
                    </div>

                    {/* Secret admin path */}
                    <div>
                        <label className="form-label">{t('systemSettings.adminPath')}</label>
                        <div className="relative">
                            <KeyRound className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="text"
                                name="admin_path"
                                value={settings.admin_path || ''}
                                onChange={handleChange}
                                placeholder={t('systemSettings.adminPathPlaceholder')}
                                className="form-input pl-12"
                                style={{ fontFamily: 'monospace' }}
                                autoComplete="off"
                                spellCheck="false"
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.adminPathHint')}</p>
                        <div
                            className="alert mt-3"
                            style={{
                                display: 'flex',
                                gap: '10px',
                                alignItems: 'flex-start',
                                background: 'var(--color-warning-light, #fef3c7)',
                                color: 'var(--color-warning-text, #92400e)'
                            }}
                        >
                            <AlertTriangle size={18} style={{ flexShrink: 0, marginTop: '1px' }} />
                            <div style={{ fontSize: '13px', lineHeight: 1.5 }}>
                                <div>{t('systemSettings.adminPathWarning')}</div>
                                <code style={{ display: 'inline-block', marginTop: '6px' }}>
                                    php /var/www/orbitra/cli/admin_path.php reset
                                </code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mt-6 flex justify-end">
                <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                    <Save size={18} />
                    {saving ? t('common.saving') : t('common.save')}
                </button>
            </div>
        </div>
    );
};

export default SystemSettings;