import React, { useState, useEffect } from 'react';
import { Save, Key, DollarSign } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const GeneralSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const [settings, setSettings] = useState({
        postback_key: '',
        currency: 'USD',
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
        const { name, value } = e.target;
        setSettings(prev => ({ ...prev, [name]: value }));
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
                        postback_key: settings.postback_key,
                        currency: settings.currency,
                    }
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('generalSettings.saveSuccess'), type: 'success' });
            } else {
                setMessage({ text: data.message || t('generalSettings.saveError'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('generalSettings.networkError'), type: 'error' });
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
                <h3 className="page-title" style={{ margin: 0 }}>{t('generalSettings.title')}</h3>
            </div>

            <div style={{ marginTop: '24px', maxWidth: '480px' }}>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: '16px' }}>
                        {message.text}
                    </div>
                )}

                <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
                    <div>
                        <label className="form-label">{t('generalSettings.postbackKey')}</label>
                        <div className="relative">
                            <Key className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="text"
                                name="postback_key"
                                value={settings.postback_key || ''}
                                onChange={handleChange}
                                className="form-input pl-12"
                            />
                        </div>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                            {t('generalSettings.postbackKeyHint')}
                        </p>
                    </div>

                    <div>
                        <label className="form-label">{t('generalSettings.defaultCurrency')}</label>
                        <div className="relative">
                            <DollarSign className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <select
                                name="currency"
                                value={settings.currency || 'USD'}
                                onChange={handleChange}
                                className="form-select pl-12"
                            >
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="RUB">RUB</option>
                                <option value="UAH">UAH</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'flex-end' }}>
                <button
                    onClick={handleSave}
                    disabled={saving}
                    className="btn btn-primary"
                >
                    <Save size={18} />
                    {saving ? t('common.saving') : t('common.save')}
                </button>
            </div>
        </div>
    );
};

export default GeneralSettings;