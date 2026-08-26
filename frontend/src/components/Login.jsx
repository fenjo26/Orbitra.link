import React, { useEffect, useRef, useState } from 'react';
import { Lock, User, Eye, EyeOff, Terminal, X, AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Login = ({ onLogin }) => {
    const { t } = useLanguage();
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [showRecoveryModal, setShowRecoveryModal] = useState(false);
    const [usernameReady, setUsernameReady] = useState(false);
    const [passwordReady, setPasswordReady] = useState(false);
    const usernameRef = useRef(null);
    const passwordRef = useRef(null);

    useEffect(() => {
        const syncAutofill = () => {
            const domUsername = usernameRef.current?.value || '';
            const domPassword = passwordRef.current?.value || '';

            if (domUsername) {
                setUsername(domUsername);
                setUsernameReady(true);
            }
            if (domPassword) {
                setPassword(domPassword);
                setPasswordReady(true);
            }
        };

        const t1 = setTimeout(syncAutofill, 80);
        const t2 = setTimeout(syncAutofill, 400);
        return () => {
            clearTimeout(t1);
            clearTimeout(t2);
        };
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        if (!username || !password) {
            setError(t('login.emptyFields'));
            setLoading(false);
            return;
        }

        try {
            const res = await fetch(`${API_URL}?action=login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();

            if (data.status === 'success') {
                // Save CSRF token to localStorage if provided
                if (data.data.csrf_token) {
                    localStorage.setItem('orbitra_csrf_token', data.data.csrf_token);
                }
                onLogin(data.data);
            } else {
                setError(data.message || t('login.invalidStatus'));
            }
        } catch (err) {
            setError((err?.message ? String(err.message) : t('common.networkError')));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-[var(--color-bg-main)] flex items-center justify-center p-4">
            <div className="w-full max-w-md">
                {/* Logo */}
                <div className="text-center mb-8">
                    <h1 className="text-3xl font-bold text-[var(--color-text-primary)] flex justify-center items-center">
                        <span className="text-[var(--color-primary)] mr-1">Orbitra</span>.link
                    </h1>
                    <p className="text-[var(--color-text-secondary)] mt-2">{t('login.subtitle')}</p>
                </div>

                {/* Login Form */}
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl p-8">
                    <h2 className="text-xl font-semibold text-[var(--color-text-primary)] mb-6 text-center">
                        {t('login.title')}
                    </h2>

                    {error && (
                        <div className="bg-[var(--color-danger-bg)] border border-[var(--color-danger-border)] text-[var(--color-danger)] px-4 py-3 rounded-lg mb-4 text-sm flex items-center gap-2">
                            <AlertCircle size={16} />
                            {error}
                        </div>
                    )}

                    <form onSubmit={handleSubmit}>
                        <div className="space-y-4">
                            {/* Username */}
                            <div>
                                <label className="block text-sm font-medium text-[var(--color-text-primary)] mb-1">
                                    {t('login.usernameLabel')}
                                </label>
                                <div className="relative">
                                    <User className="absolute left-3 top-2.5 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
                                    <input
                                        ref={usernameRef}
                                        type="text"
                                        name="username"
                                        id="username"
                                        autoComplete="username"
                                        autoCapitalize="none"
                                        autoCorrect="off"
                                        spellCheck={false}
                                        readOnly={!usernameReady}
                                        value={username}
                                        onFocus={() => setUsernameReady(true)}
                                        onMouseDown={() => setUsernameReady(true)}
                                        onChange={(e) => setUsername(e.target.value)}
                                        className="w-full !pl-10 pr-4 py-2.5 border border-[var(--color-border)] rounded-lg transition-all placeholder:text-[var(--color-text-muted)] focus:ring-2 focus:ring-[var(--color-primary)] focus:border-transparent"
                                        placeholder={t('login.usernamePlaceholder')}
                                    />
                                </div>
                            </div>

                            {/* Password */}
                            <div>
                                <label className="block text-sm font-medium text-[var(--color-text-primary)] mb-1">
                                    {t('login.passwordLabel')}
                                </label>
                                <div className="relative">
                                    <Lock className="absolute left-3 top-2.5 h-5 w-5 text-[var(--color-text-muted)] pointer-events-none" />
                                    <input
                                        ref={passwordRef}
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        id="password"
                                        autoComplete="current-password"
                                        autoCapitalize="none"
                                        autoCorrect="off"
                                        spellCheck={false}
                                        readOnly={!passwordReady}
                                        value={password}
                                        onFocus={() => setPasswordReady(true)}
                                        onMouseDown={() => setPasswordReady(true)}
                                        onChange={(e) => setPassword(e.target.value)}
                                        className="w-full !pl-10 !pr-10 py-2.5 border border-[var(--color-border)] rounded-lg transition-all placeholder:text-[var(--color-text-muted)] focus:ring-2 focus:ring-[var(--color-primary)] focus:border-transparent"
                                        placeholder={t('login.passwordPlaceholder')}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] focus:outline-none"
                                    >
                                        {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                                    </button>
                                </div>
                            </div>

                            {/* Remember me */}
                            <div className="flex items-center justify-between">
                                <label className="flex items-center">
                                    <input type="checkbox" className="w-4 h-4 accent-[var(--color-primary)] border-[var(--color-border)] rounded focus:ring-[var(--color-primary)]" />
                                    <span className="ml-2 text-sm text-[var(--color-text-secondary)]">{t('login.rememberMe')}</span>
                                </label>
                                <button
                                    type="button"
                                    onClick={() => setShowRecoveryModal(true)}
                                    className="text-sm text-[var(--color-primary)] hover:underline"
                                >
                                    {t('login.forgotPassword')}
                                </button>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={loading}
                                className="w-full py-2.5 bg-[var(--color-primary)] text-[var(--color-text-inverse)] rounded-lg hover:bg-[color-mix(in_srgb,var(--color-primary)_85%,black)] transition font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {loading ? (
                                    <span className="flex items-center justify-center">
                                        <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-[var(--color-text-inverse)] mr-2"></div>
                                        {t('login.loggingIn')}
                                    </span>
                                ) : t('login.loginButton')}
                            </button>
                        </div>
                    </form>
                </div>

                {/* Footer */}
                <p className="text-center text-[var(--color-text-secondary)] text-sm mt-6">
                    © 2026 Orbitra.link. {t('login.allRightsReserved')}
                </p>
            </div>

            {/* Password Recovery Modal */}
            {showRecoveryModal && (
                <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl w-full max-w-md p-6">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-[var(--color-text-primary)]">{t('login.recoveryTitle')}</h3>
                            <button
                                onClick={() => setShowRecoveryModal(false)}
                                className="text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        <div className="space-y-4">
                            <p className="text-[var(--color-text-secondary)] text-sm">
                                {t('login.recoveryInstruction')}
                            </p>

                            <div className="bg-gray-900 rounded-lg p-4">
                                <div className="flex items-center gap-2 text-gray-400 text-xs mb-2">
                                    <Terminal size={14} />
                                    <span>{t('login.terminal')}</span>
                                </div>
                                <code className="text-green-400 text-sm block overflow-x-auto whitespace-nowrap">
                                    {t('login.cliPhp')}
                                </code>
                            </div>

                            <div className="bg-[var(--color-warning-bg)] border border-[var(--color-warning-border)] rounded-lg p-3">
                                <p className="text-[var(--color-text-primary)] text-xs">
                                    <strong>{t('login.alternativeSqLite')}</strong>
                                </p>
                                <code className="text-[var(--color-text-primary)] text-xs block mt-1 overflow-x-auto whitespace-nowrap">
                                    {t('login.cliSqlite')}
                                </code>
                            </div>

                            <p className="text-[var(--color-text-secondary)] text-xs">
                                {t('login.recoveryFooter')}
                            </p>
                        </div>

                        <button
                            onClick={() => setShowRecoveryModal(false)}
                            className="w-full mt-4 py-2 bg-[var(--color-bg-soft)] text-[var(--color-text-primary)] rounded-lg hover:bg-[var(--color-bg-hover)] transition font-medium"
                        >
                            {t('common.close')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Login;
