import React, { useState, useEffect } from 'react';
import { Save, Globe, Clock, Calendar, Lock, KeyRound, ShieldCheck, ShieldOff, Copy, CheckCircle2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { getStayInEditorAfterSave, setStayInEditorAfterSave } from '../utils/editorPreferences';
import { copyToClipboard } from '../utils/clipboard';

const API_URL = '/api.php';

const ProfileSettings = () => {
    const { t, setLanguage: setContextLanguage, language: currentLanguage } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });
    const [stayInEditor, setStayInEditor] = useState(getStayInEditorAfterSave);
    const [currentPasswordError, setCurrentPasswordError] = useState('');

    // Two-factor authentication (TOTP). totp_enabled arrives with
    // profile_settings; enabling runs setup → (secret + otpauth shown) →
    // code → totp_enable, disabling asks for the account password.
    const [totpEnabled, setTotpEnabled] = useState(false);
    const [totpSetup, setTotpSetup] = useState(null); // { secret, otpauth }
    const [totpCode, setTotpCode] = useState('');
    const [totpDisablePassword, setTotpDisablePassword] = useState('');
    const [totpBusy, setTotpBusy] = useState(false);
    const [totpMessage, setTotpMessage] = useState(null); // { text, type }
    const [totpCodeError, setTotpCodeError] = useState('');
    const [totpPasswordError, setTotpPasswordError] = useState('');
    const [totpCopiedKey, setTotpCopiedKey] = useState('');

    const currentUser = JSON.parse(localStorage.getItem('orbitra_user') || '{}');

    const [profile, setProfile] = useState({
        language: currentLanguage,
        timezone: 'Europe/Moscow',
        first_day_of_week: 1,
        current_password: '',
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
                        language: data.data.language || currentLanguage,
                        timezone: data.data.timezone || 'Europe/Moscow',
                        first_day_of_week: data.data.first_day_of_week || 1,
                    });
                    setTotpEnabled(Boolean(data.data.totp_enabled));
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
        setCurrentPasswordError('');
        if (profile.new_password && !profile.current_password) {
            setCurrentPasswordError(t('security.currentPasswordRequired'));
            return;
        }
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
                    new_password: profile.new_password,
                    // The backend requires the current password whenever a new
                    // one is set; sent only in that case.
                    ...(profile.new_password ? { current_password: profile.current_password } : {})
                })
            });
            const data = await res.json();

            if (data.status === 'success') {
                setMessage({ text: t('profile.saveSuccess'), type: 'success' });
                setProfile(prev => ({ ...prev, current_password: '', new_password: '', confirm_password: '' }));
                setContextLanguage(profile.language);

                // Update local storage user profile so language persists on reload
                if (currentUser) {
                    currentUser.language = profile.language;
                    localStorage.setItem('orbitra_user', JSON.stringify(currentUser));
                    window.dispatchEvent(new Event('userUpdated'));
                }
            } else if (data.code === 'bad_password') {
                setCurrentPasswordError(t('security.currentPasswordInvalid'));
            } else {
                setMessage({ text: data.message || t('common.error'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: (error?.message ? String(error.message) : t('common.networkError')), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    // ── Two-factor authentication (TOTP) ─────────────────────────────────
    const totpCopy = async (key, value) => {
        if (!value || !(await copyToClipboard(value))) return;
        setTotpCopiedKey(key);
        setTimeout(() => setTotpCopiedKey(''), 1500);
    };

    const totpPost = async (action, payload = {}) => {
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return res.json();
    };

    const handleTotpSetup = async () => {
        setTotpBusy(true);
        setTotpMessage(null);
        setTotpCodeError('');
        try {
            const data = await totpPost('totp_setup');
            if (data.status === 'success' && data.data) {
                setTotpSetup({ secret: data.data.secret || '', otpauth: data.data.otpauth || '' });
                setTotpCode('');
            } else {
                setTotpMessage({ text: data.message || t('security.totpSetupError'), type: 'error' });
            }
        } catch (error) {
            setTotpMessage({ text: t('common.networkError'), type: 'error' });
        } finally {
            setTotpBusy(false);
        }
    };

    const handleTotpEnable = async () => {
        if (!totpCode.trim()) {
            setTotpCodeError(t('security.totpCodeRequired'));
            return;
        }
        setTotpBusy(true);
        setTotpMessage(null);
        setTotpCodeError('');
        try {
            const data = await totpPost('totp_enable', { code: totpCode.trim() });
            if (data.status === 'success') {
                setTotpEnabled(true);
                setTotpSetup(null);
                setTotpCode('');
                setTotpMessage({ text: t('security.totpEnabledBanner'), type: 'success' });
            } else if (data.code === 'bad_code') {
                setTotpCodeError(t('security.totpBadCode'));
            } else {
                setTotpMessage({ text: data.message || t('security.totpEnableError'), type: 'error' });
            }
        } catch (error) {
            setTotpMessage({ text: t('common.networkError'), type: 'error' });
        } finally {
            setTotpBusy(false);
        }
    };

    const handleTotpDisable = async () => {
        if (!totpDisablePassword) {
            setTotpPasswordError(t('security.totpDisablePasswordRequired'));
            return;
        }
        setTotpBusy(true);
        setTotpMessage(null);
        setTotpPasswordError('');
        try {
            const data = await totpPost('totp_disable', { password: totpDisablePassword });
            if (data.status === 'success') {
                setTotpEnabled(false);
                setTotpDisablePassword('');
                setTotpMessage({ text: t('security.totpDisabledBanner'), type: 'success' });
            } else if (data.code === 'bad_password') {
                setTotpPasswordError(t('security.totpBadPassword'));
            } else {
                setTotpMessage({ text: data.message || t('security.totpDisableError'), type: 'error' });
            }
        } catch (error) {
            setTotpMessage({ text: t('common.networkError'), type: 'error' });
        } finally {
            setTotpBusy(false);
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
                            <Globe className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
                            <select
                                name="language"
                                value={profile.language}
                                onChange={handleChange}
                                className="form-select pl-12"
                            >
                                <option value="ru">Русский</option>
                                <option value="en">English</option>
                                <option value="uk">Українська</option>
                                <option value="es">Español</option>
                                <option value="zh">中文 (简体)</option>
                                <option value="fr">Français</option>
                                <option value="de">Deutsch</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="form-label">{t('profile.timezone')}</label>
                        <div className="relative">
                            <Clock className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
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
                                <option value="Asia/Kolkata">Asia/Kolkata (IST, UTC+5:30)</option>
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
                            <Calendar className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
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

                <div style={{ marginTop: '24px', paddingTop: '20px', borderTop: '1px solid var(--color-border)' }}>
                    <label className="flex items-start gap-3" style={{ cursor: 'pointer' }}>
                        <input
                            type="checkbox"
                            checked={stayInEditor}
                            onChange={(event) => {
                                const enabled = event.target.checked;
                                setStayInEditor(enabled);
                                setStayInEditorAfterSave(enabled);
                            }}
                            style={{ marginTop: '3px' }}
                        />
                        <span>
                            <span style={{ fontWeight: 600, color: 'var(--color-text-primary)' }}>
                                {t('profile.stayInEditorAfterSave')}
                            </span>
                            <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px', lineHeight: 1.55 }}>
                                {t('profile.stayInEditorHint')}
                            </p>
                        </span>
                    </label>
                </div>

                <div style={{ marginTop: '32px', paddingTop: '24px', borderTop: '1px solid var(--color-border)' }}>
                    <h4 style={{ fontWeight: 500, marginBottom: '16px', color: 'var(--color-text-primary)' }}>{t('profile.changePassword')}</h4>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '20px' }}>
                        <div>
                            <label className="form-label">{t('security.currentPassword')}</label>
                            <div className="relative">
                                <Lock className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
                                <input
                                    type="password"
                                    name="current_password"
                                    value={profile.current_password}
                                    onChange={(e) => { setCurrentPasswordError(''); handleChange(e); }}
                                    placeholder={t('security.currentPasswordPlaceholder')}
                                    className="form-input pl-12"
                                    autoComplete="current-password"
                                />
                            </div>
                            {currentPasswordError && (
                                <p style={{ fontSize: '12px', color: 'var(--color-danger)', marginTop: '6px' }}>{currentPasswordError}</p>
                            )}
                        </div>
                        <div>
                            <label className="form-label">{t('profile.newPassword')}</label>
                            <div className="relative">
                                <KeyRound className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
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
                                <Lock className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
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

                {/* Two-factor authentication (TOTP). The panel has no QR code:
                    the secret / otpauth URI are copied into an authenticator
                    app by hand, then confirmed with a code. */}
                <div style={{ marginTop: '32px', paddingTop: '24px', borderTop: '1px solid var(--color-border)' }}>
                    <h4 style={{ fontWeight: 500, marginBottom: '8px', color: 'var(--color-text-primary)', display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldCheck size={18} style={{ color: 'var(--color-primary)' }} />
                        {t('security.totpTitle')}
                    </h4>
                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '16px', lineHeight: 1.55 }}>
                        {t('security.totpDesc')}
                    </p>

                    {totpMessage && (
                        <div className={`alert ${totpMessage.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: '16px' }}>
                            {totpMessage.text}
                        </div>
                    )}

                    {!totpEnabled ? (
                        !totpSetup ? (
                            <button
                                type="button"
                                onClick={handleTotpSetup}
                                disabled={totpBusy}
                                className="btn btn-primary"
                            >
                                <ShieldCheck size={18} />
                                {totpBusy ? t('common.loading') : t('security.totpEnable')}
                            </button>
                        ) : (
                            <div style={{ maxWidth: '600px' }}>
                                <div>
                                    <label className="form-label">{t('security.totpSecretLabel')}</label>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                        <input
                                            type="text"
                                            readOnly
                                            value={totpSetup.secret}
                                            onFocus={(e) => e.target.select()}
                                            className="form-input"
                                            style={{ fontFamily: 'monospace' }}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => totpCopy('secret', totpSetup.secret)}
                                            className="btn btn-secondary btn-sm"
                                            title={t('common.copy')}
                                        >
                                            {totpCopiedKey === 'secret' ? <CheckCircle2 size={14} /> : <Copy size={14} />}
                                        </button>
                                    </div>
                                </div>

                                <div style={{ marginTop: '14px' }}>
                                    <label className="form-label">{t('security.totpOtpauthLabel')}</label>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                        <input
                                            type="text"
                                            readOnly
                                            value={totpSetup.otpauth}
                                            onFocus={(e) => e.target.select()}
                                            className="form-input"
                                            style={{ fontFamily: 'monospace', fontSize: '12px' }}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => totpCopy('otpauth', totpSetup.otpauth)}
                                            className="btn btn-secondary btn-sm"
                                            title={t('common.copy')}
                                        >
                                            {totpCopiedKey === 'otpauth' ? <CheckCircle2 size={14} /> : <Copy size={14} />}
                                        </button>
                                    </div>
                                </div>

                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '10px', lineHeight: 1.55 }}>
                                    {t('security.totpSecretHint')}
                                </p>

                                <div style={{ marginTop: '16px', maxWidth: '280px' }}>
                                    <label className="form-label">{t('security.totpCodeLabel')}</label>
                                    <input
                                        type="text"
                                        value={totpCode}
                                        onChange={(e) => { setTotpCode(e.target.value); setTotpCodeError(''); }}
                                        placeholder={t('security.totpCodePlaceholder')}
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        autoFocus
                                        className="form-input"
                                    />
                                    {totpCodeError && (
                                        <p style={{ fontSize: '12px', color: 'var(--color-danger)', marginTop: '6px' }}>{totpCodeError}</p>
                                    )}
                                </div>

                                <div style={{ marginTop: '16px', display: 'flex', gap: '10px' }}>
                                    <button
                                        type="button"
                                        onClick={handleTotpEnable}
                                        disabled={totpBusy}
                                        className="btn btn-primary"
                                    >
                                        <CheckCircle2 size={18} />
                                        {totpBusy ? t('common.loading') : t('security.totpConfirm')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { setTotpSetup(null); setTotpCode(''); setTotpCodeError(''); }}
                                        disabled={totpBusy}
                                        className="btn btn-secondary"
                                    >
                                        {t('common.cancel')}
                                    </button>
                                </div>
                            </div>
                        )
                    ) : (
                        <div style={{ maxWidth: '420px' }}>
                            <p style={{ fontSize: '13px', color: 'var(--color-text-muted)', marginBottom: '12px', lineHeight: 1.55 }}>
                                {t('security.totpDisableHint')}
                            </p>
                            <label className="form-label">{t('security.totpDisablePasswordLabel')}</label>
                            <div style={{ display: 'flex', alignItems: 'flex-start', gap: '10px' }}>
                                <input
                                    type="password"
                                    value={totpDisablePassword}
                                    onChange={(e) => { setTotpDisablePassword(e.target.value); setTotpPasswordError(''); }}
                                    placeholder={t('security.currentPasswordPlaceholder')}
                                    autoComplete="current-password"
                                    className="form-input"
                                    style={{ flex: 1 }}
                                />
                                <button
                                    type="button"
                                    onClick={handleTotpDisable}
                                    disabled={totpBusy}
                                    className="btn btn-secondary"
                                >
                                    <ShieldOff size={18} />
                                    {totpBusy ? t('common.loading') : t('security.totpDisable')}
                                </button>
                            </div>
                            {totpPasswordError && (
                                <p style={{ fontSize: '12px', color: 'var(--color-danger)', marginTop: '6px' }}>{totpPasswordError}</p>
                            )}
                        </div>
                    )}
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
