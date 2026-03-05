import React, { useState, useEffect } from 'react';
import { User, Lock, Globe, Clock, Eye, EyeOff, Check, AlertCircle, Terminal } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const SetupWizard = ({ onComplete }) => {
    const { t, setLanguage: setContextLanguage, language } = useLanguage();
    const [step, setStep] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [showPassword, setShowPassword] = useState(false);

    const [formData, setFormData] = useState({
        username: '',
        password: '',
        confirmPassword: '',
        timezone: 'Europe/Kyiv',
        language: language || 'ru'
    });

    const timezones = [
        { value: 'UTC', label: 'UTC' },
        { value: 'Europe/London', label: 'Europe/London (UTC+0)' },
        { value: 'Europe/Berlin', label: 'Europe/Berlin (UTC+1)' },
        { value: 'Europe/Kyiv', label: 'Europe/Kyiv (UTC+2)' },
        { value: 'Europe/Moscow', label: 'Europe/Moscow (UTC+3)' },
        { value: 'Asia/Dubai', label: 'Asia/Dubai (UTC+4)' },
        { value: 'Asia/Karachi', label: 'Asia/Karachi (UTC+5)' },
        { value: 'Asia/Almaty', label: 'Asia/Almaty (UTC+5)' },
        { value: 'Asia/Bangkok', label: 'Asia/Bangkok (UTC+7)' },
        { value: 'Asia/Shanghai', label: 'Asia/Shanghai (UTC+8)' },
        { value: 'Asia/Tokyo', label: 'Asia/Tokyo (UTC+9)' },
        { value: 'Australia/Sydney', label: 'Australia/Sydney (UTC+10)' },
        { value: 'Pacific/Auckland', label: 'Pacific/Auckland (UTC+12)' },
        { value: 'America/New_York', label: 'America/New_York (UTC-5)' },
        { value: 'America/Chicago', label: 'America/Chicago (UTC-6)' },
        { value: 'America/Denver', label: 'America/Denver (UTC-7)' },
        { value: 'America/Los_Angeles', label: 'America/Los_Angeles (UTC-8)' },
        { value: 'America/Sao_Paulo', label: 'America/Sao_Paulo (UTC-3)' },
    ];

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        // Validation
        if (step === 1) {
            if (formData.username.length < 3) {
                setError(t('common.error')); // Simple fallback
                return;
            }
            if (formData.password.length < 6) {
                setError(t('setup.passwordMin'));
                return;
            }
            if (formData.password !== formData.confirmPassword) {
                setError(t('profile.passwordsNotMatch'));
                return;
            }
            setStep(2);
            return;
        }

        // Step 2 - Submit
        setLoading(true);
        try {
            const res = await fetch(`${API_URL}?action=setup_first_user`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            const data = await res.json();

            if (data.status === 'success') {
                setStep(3);
                setContextLanguage(formData.language); // Ensure context is updated globally
            } else {
                setError(data.message || t('common.error'));
            }
        } catch (err) {
            setError(t('common.networkError'));
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));

        // Immediately change language context
        if (name === 'language') {
            setContextLanguage(value);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 flex items-center justify-center p-4 relative">
            {/* Global Language Toggle */}
            <div className="absolute top-4 right-4">
                <select
                    value={language}
                    onChange={(e) => setContextLanguage(e.target.value)}
                    className="bg-gray-800 text-gray-300 border border-gray-700 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-colors"
                >
                    <option value="en">🇺🇸 English</option>
                    <option value="ru">🇷🇺 Русский</option>
                </select>
            </div>

            <div className="w-full max-w-md">
                {/* Logo */}
                <div className="text-center mb-8">
                    <h1 className="text-3xl font-bold text-white flex justify-center items-center">
                        <span className="text-indigo-500 mr-1">Orbitra</span>.link
                    </h1>
                    <p className="text-slate-400 mt-2">{t('setup.title')}</p>
                </div>

                {/* Progress */}
                <div className="flex items-center justify-center mb-6">
                    {[1, 2, 3].map((s) => (
                        <React.Fragment key={s}>
                            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium ${step >= s
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-700 text-gray-400'
                                }`}>
                                {step > s ? <Check size={16} /> : s}
                            </div>
                            {s < 3 && (
                                <div className={`w-12 h-1 ${step > s ? 'bg-indigo-600' : 'bg-gray-700'}`} />
                            )}
                        </React.Fragment>
                    ))}
                </div>

                {/* Form Card */}
                <div className="bg-white rounded-xl shadow-2xl p-8">
                    {step === 1 && (
                        <>
                            <h2 className="text-xl font-semibold text-gray-800 mb-2 text-center">
                                {t('setup.step1Title')}
                            </h2>
                            <p className="text-gray-500 text-sm text-center mb-6">
                                {t('setup.step1Desc')}
                            </p>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        {t('setup.username')}
                                    </label>
                                    <div className="relative">
                                        <User className="absolute left-4 top-2.5 h-5 w-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type="text"
                                            id="username"
                                            name="username"
                                            value={formData.username}
                                            onChange={handleChange}
                                            autoComplete="username"
                                            className="w-full pl-12 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder="admin"
                                            autoFocus
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        {t('setup.password')}
                                    </label>
                                    <div className="relative">
                                        <Lock className="absolute left-4 top-2.5 h-5 w-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type={showPassword ? 'text' : 'password'}
                                            id="password"
                                            name="password"
                                            value={formData.password}
                                            onChange={handleChange}
                                            autoComplete="new-password"
                                            className="w-full pl-12 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder={t('setup.passwordMin')}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            className="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600"
                                        >
                                            {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        {t('setup.confirmPassword')}
                                    </label>
                                    <div className="relative">
                                        <Lock className="absolute left-4 top-2.5 h-5 w-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type={showPassword ? 'text' : 'password'}
                                            id="confirmPassword"
                                            name="confirmPassword"
                                            value={formData.confirmPassword}
                                            onChange={handleChange}
                                            autoComplete="new-password"
                                            className="w-full pl-12 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                            placeholder={t('setup.confirmPasswordPlaceholder')}
                                        />
                                    </div>
                                </div>

                                {error && (
                                    <div className="flex items-center gap-2 text-red-600 text-sm bg-red-50 p-3 rounded-lg">
                                        <AlertCircle size={16} />
                                        {error}
                                    </div>
                                )}

                                <button
                                    type="submit"
                                    className="w-full py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium"
                                >
                                    {t('setup.continue')}
                                </button>
                            </form>
                        </>
                    )}

                    {step === 2 && (
                        <>
                            <h2 className="text-xl font-semibold text-gray-800 mb-2 text-center">
                                {t('setup.step2Title')}
                            </h2>
                            <p className="text-gray-500 text-sm text-center mb-6">
                                {t('setup.step2Desc')}
                            </p>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        <Clock size={16} className="inline mr-1" />
                                        {t('setup.timezone')}
                                    </label>
                                    <select
                                        name="timezone"
                                        value={formData.timezone}
                                        onChange={handleChange}
                                        className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    >
                                        {timezones.map(tz => (
                                            <option key={tz.value} value={tz.value}>{tz.label}</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        <Globe size={16} className="inline mr-1" />
                                        {t('setup.language')}
                                    </label>
                                    <select
                                        name="language"
                                        value={formData.language}
                                        onChange={handleChange}
                                        className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    >
                                        <option value="ru">Русский</option>
                                        <option value="en">English</option>
                                    </select>
                                </div>

                                {error && (
                                    <div className="flex items-center gap-2 text-red-600 text-sm bg-red-50 p-3 rounded-lg">
                                        <AlertCircle size={16} />
                                        {error}
                                    </div>
                                )}

                                <div className="flex gap-3">
                                    <button
                                        type="button"
                                        onClick={() => setStep(1)}
                                        className="flex-1 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium"
                                    >
                                        {t('common.back')}
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={loading}
                                        className="flex-1 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium disabled:opacity-50"
                                    >
                                        {loading ? t('common.loading') : t('common.finish')}
                                    </button>
                                </div>
                            </form>
                        </>
                    )}

                    {step === 3 && (
                        <div className="text-center py-4">
                            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <Check size={32} className="text-green-600" />
                            </div>
                            <h2 className="text-xl font-semibold text-gray-800 mb-2">
                                {t('setup.step3Title')}
                            </h2>
                            <p className="text-gray-500 mb-6">
                                {t('setup.step3Desc')}
                            </p>
                            <button
                                onClick={onComplete}
                                className="w-full py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium"
                            >
                                {t('setup.login')}
                            </button>
                        </div>
                    )}
                </div>

                {/* Password Recovery Info */}
                {step === 1 && (
                    <div className="mt-4 p-4 bg-gray-800/50 rounded-lg border border-gray-700">
                        <div className="flex items-start gap-2 text-gray-400 text-xs">
                            <Terminal size={14} className="mt-0.5 flex-shrink-0" />
                            <div>
                                <p className="font-medium text-gray-300 mb-1">{t('setup.passwordRecovery')}</p>
                                <p className="font-mono text-gray-500">
                                    {t('setup.recoveryHint')}
                                </p>
                                <code className="block mt-1 p-2 bg-gray-900 rounded text-green-400 text-xs overflow-x-auto">
                                    {t('login.cliSqlite').replace("'login'", `'${formData.username || 'user'}'`)}
                                </code>
                            </div>
                        </div>
                    </div>
                )}

                <p className="text-center text-slate-500 text-sm mt-6">
                    © 2026 Orbitra.link
                </p>
            </div>
        </div>
    );
};

export default SetupWizard;