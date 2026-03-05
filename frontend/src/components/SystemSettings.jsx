import React, { useState, useEffect } from 'react';
import { Save, HardDrive, Database, Archive, Shield } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const SystemSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const [settings, setSettings] = useState({
        stats_enabled: '1',
        stats_retention_days: '90',
        archive_retention_days: '60',
        admin_ip_access: '',
        ignore_prefetch: '1'
    });

    useEffect(() => {
        fetch(`${API_URL}?action=global_settings`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.data) {
                    setSettings(prev => ({ ...prev, ...data.data }));
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, []);

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        setSettings(prev => ({ ...prev, [name]: type === 'checkbox' ? (checked ? '1' : '0') : value }));
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
                        ignore_prefetch: settings.ignore_prefetch
                    }
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('systemSettings.saveSuccess'), type: 'success' });
            } else {
                setMessage({ text: data.message || t('systemSettings.saveError'), type: 'error' });
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
                    {/* Сбор статистики */}
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

                    {/* Игнорировать prefetch-запросы */}
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

                    {/* Срок хранения логов */}
                    <div>
                        <label className="form-label">{t('systemSettings.logRetention')}</label>
                        <div className="relative">
                            <Database className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="number"
                                name="stats_retention_days"
                                value={settings.stats_retention_days || 90}
                                onChange={handleChange}
                                className="form-input pl-12"
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.logRetentionHint')}</p>
                    </div>

                    {/* Срок хранения ресурсов в Архиве */}
                    <div>
                        <label className="form-label">{t('systemSettings.archiveRetention')}</label>
                        <div className="relative">
                            <Archive className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="number"
                                name="archive_retention_days"
                                value={settings.archive_retention_days || 60}
                                onChange={handleChange}
                                className="form-input pl-12"
                            />
                        </div>
                        <p className="form-hint">{t('systemSettings.archiveRetentionHint')}</p>
                    </div>

                    {/* Доступ к админ-панели */}
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