import React, { useState, useEffect } from 'react';
import { Save, Shield, Link2, AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const PrivacySettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const [settings, setSettings] = useState({
        privacy_enabled: '0',
        privacy_action: 'redirect',
        privacy_redirect_url: ''
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
                        privacy_enabled: settings.privacy_enabled,
                        privacy_action: settings.privacy_action,
                        privacy_redirect_url: settings.privacy_redirect_url,
                    }
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('privacy.saveSuccess'), type: 'success' });
            } else {
                setMessage({ text: data.message || t('privacy.saveError'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('privacy.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
            </div>
        );
    }

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <Shield size={18} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('privacy.title')}</h3>
                </div>
            </div>

            <div style={{ marginTop: '24px', maxWidth: '600px' }}>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: '16px' }}>
                        {message.text}
                    </div>
                )}

                <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
                    <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer' }}>
                        <input
                            type="checkbox"
                            name="privacy_enabled"
                            checked={settings.privacy_enabled === '1'}
                            onChange={handleChange}
                            style={{ marginTop: '2px' }}
                        />
                        <div>
                            <span style={{ fontWeight: 500 }}>{t('privacy.scanProtection')}</span>
                            <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', margin: '2px 0 0 0' }}>
                                {t('privacy.scanProtectionDesc')}
                            </p>
                        </div>
                    </label>

                    {settings.privacy_enabled === '1' && (
                        <div style={{ paddingLeft: '28px', borderLeft: '2px solid var(--color-primary-light)', display: 'flex', flexDirection: 'column', gap: '20px' }}>
                            <div>
                                <label className="form-label">{t('privacy.directAccessAction')}</label>
                                <div className="relative">
                                    <AlertCircle className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                                    <select
                                        name="privacy_action"
                                        value={settings.privacy_action || 'redirect'}
                                        onChange={handleChange}
                                        className="form-select pl-12"
                                    >
                                        <option value="redirect">{t('privacy.redirect302')}</option>
                                        <option value="404">{t('privacy.show404')}</option>
                                        <option value="blank">{t('privacy.blankPage')}</option>
                                    </select>
                                </div>
                            </div>

                            {settings.privacy_action === 'redirect' && (
                                <div>
                                    <label className="form-label">{t('privacy.redirectUrl')}</label>
                                    <div className="relative">
                                        <Link2 className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type="text"
                                            name="privacy_redirect_url"
                                            value={settings.privacy_redirect_url || ''}
                                            onChange={handleChange}
                                            placeholder="https://google.com"
                                            className="form-input pl-12"
                                        />
                                    </div>
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                        {t('privacy.redirectUrlHint')}
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'flex-end' }}>
                <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                    <Save size={18} />
                    {saving ? t('common.saving') : t('common.save')}
                </button>
            </div>
        </div>
    );
};

export default PrivacySettings;