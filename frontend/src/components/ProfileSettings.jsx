import React, { useState, useEffect } from 'react';
import { Save, Globe, Clock, Calendar, Lock, KeyRound } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const ProfileSettings = () => {
    const { t, setLanguage: setContextLanguage } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const currentUser = JSON.parse(localStorage.getItem('orbitra_user') || '{}');

    const [profile, setProfile] = useState({
        language: 'ru',
        timezone: 'Europe/Moscow',
        first_day_of_week: 1,
        new_password: '',
        confirm_password: ''
    });

    useEffect(() => {
        const userId = currentUser.id || 1;
        fetch(`${API_URL}?action=profile_settings&user_id=${userId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.data) {
                    setProfile({
                        ...profile,
                        language: data.data.language || 'ru',
                        timezone: data.data.timezone || 'Europe/Moscow',
                        first_day_of_week: data.data.first_day_of_week || 1,
                    });
                }
                setLoading(false);
            })
            .catch(err => {
                console.error('Error loading profile:', err);
                setLoading(false);
            });
    }, []);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setProfile(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async () => {
        if (profile.new_password && profile.new_password !== profile.confirm_password) {
            setMessage({ text: t('profile.passwordsNotMatch'), type: 'error' });
            return;
        }

        setSaving(true);
        setMessage({ text: '', type: '' });

        try {
            const userId = currentUser.id || 1;
            const res = await fetch(`${API_URL}?action=profile_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: userId,
                    language: profile.language,
                    timezone: profile.timezone,
                    first_day_of_week: parseInt(profile.first_day_of_week),
                    new_password: profile.new_password
                })
            });
            const data = await res.json();

            if (data.status === 'success') {
                setMessage({ text: t('profile.saveSuccess'), type: 'success' });
                setProfile(prev => ({ ...prev, new_password: '', confirm_password: '' }));
                setContextLanguage(profile.language);

                // Update local storage user profile so language persists on reload
                if (currentUser) {
                    currentUser.language = profile.language;
                    localStorage.setItem('orbitra_user', JSON.stringify(currentUser));
                    window.dispatchEvent(new Event('userUpdated'));
                }
            } else {
                setMessage({ text: data.message || t('common.error'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('common.networkError'), type: 'error' });
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
                <h3 className="page-title" style={{ margin: 0 }}>{t('profile.title')}</h3>
            </div>

            <div style={{ marginTop: '24px', maxWidth: '600px' }}>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: '16px' }}>
                        {message.text}
                    </div>
                )}

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '20px' }}>
                    <div>
                        <label className="form-label">{t('profile.language')}</label>
                        <div className="relative">
                            <Globe className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <select
                                name="language"
                                value={profile.language}
                                onChange={handleChange}
                                className="form-select pl-12"
                            >
                                <option value="ru">Русский</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="form-label">{t('profile.timezone')}</label>
                        <div className="relative">
                            <Clock className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <select
                                name="timezone"
                                value={profile.timezone}
                                onChange={handleChange}
                                className="form-select pl-12"
                            >
                                <option value="UTC">UTC</option>
                                <option value="Europe/London">Europe/London (UTC+0)</option>
                                <option value="Europe/Berlin">Europe/Berlin (UTC+1)</option>
                                <option value="Europe/Kyiv">Europe/Kyiv (UTC+2)</option>
                                <option value="Europe/Moscow">Europe/Moscow (UTC+3)</option>
                                <option value="Asia/Dubai">Asia/Dubai (UTC+4)</option>
                                <option value="Asia/Karachi">Asia/Karachi (UTC+5)</option>
                                <option value="Asia/Almaty">Asia/Almaty (UTC+5)</option>
                                <option value="Asia/Bangkok">Asia/Bangkok (UTC+7)</option>
                                <option value="Asia/Shanghai">Asia/Shanghai (UTC+8)</option>
                                <option value="Asia/Tokyo">Asia/Tokyo (UTC+9)</option>
                                <option value="Australia/Sydney">Australia/Sydney (UTC+10)</option>
                                <option value="Pacific/Auckland">Pacific/Auckland (UTC+12)</option>
                                <option value="America/New_York">America/New_York (UTC-5)</option>
                                <option value="America/Chicago">America/Chicago (UTC-6)</option>
                                <option value="America/Denver">America/Denver (UTC-7)</option>
                                <option value="America/Los_Angeles">America/Los_Angeles (UTC-8)</option>
                                <option value="America/Sao_Paulo">America/Sao_Paulo (UTC-3)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="form-label">{t('profile.firstDayOfWeek')}</label>
                        <div className="relative">
                            <Calendar className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <select
                                name="first_day_of_week"
                                value={profile.first_day_of_week}
                                onChange={handleChange}
                                className="form-select pl-12"
                            >
                                <option value="1">{t('profile.monday')}</option>
                                <option value="0">{t('profile.sunday')}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style={{ marginTop: '32px', paddingTop: '24px', borderTop: '1px solid var(--color-border)' }}>
                    <h4 style={{ fontWeight: 500, marginBottom: '16px', color: 'var(--color-text-primary)' }}>{t('profile.changePassword')}</h4>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '20px' }}>
                        <div>
                            <label className="form-label">{t('profile.newPassword')}</label>
                            <div className="relative">
                                <KeyRound className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                                <input
                                    type="password"
                                    name="new_password"
                                    value={profile.new_password}
                                    onChange={handleChange}
                                    placeholder={t('profile.newPasswordPlaceholder')}
                                    className="form-input pl-12"
                                />
                            </div>
                        </div>
                        <div>
                            <label className="form-label">{t('profile.confirmPassword')}</label>
                            <div className="relative">
                                <Lock className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                                <input
                                    type="password"
                                    name="confirm_password"
                                    value={profile.confirm_password}
                                    onChange={handleChange}
                                    placeholder={t('profile.confirmPasswordPlaceholder')}
                                    className="form-input pl-12"
                                />
                            </div>
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

export default ProfileSettings;