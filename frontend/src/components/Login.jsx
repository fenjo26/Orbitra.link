import React, { useEffect, useRef, useState } from 'react';
import { Lock, User, Eye, EyeOff, Terminal, X, AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

/* ── Flow diagram ────────────────────────────────────────────────────────
   The left column is a picture of what the tracker actually does: a CLICK
   node feeding a CLOAK router, which fans out to three offers along solid
   lanes and to a safe page along a dashed one. The pips travel the lanes
   on a fixed schedule — eight of them across the four output lanes on
   staggered (negative) delays, not random — so the visual density is
   constant and there are no timers and no React re-renders: the whole
   animation is SVG animateMotion running on the compositor. */

const REDUCE_MOTION = typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const DIAGRAM_NODES = {
    click: { cx: 80, cy: 210, w: 104, h: 52, label: 'CLICK' },
    cloak: { cx: 272, cy: 210, w: 124, h: 58, label: 'CLOAK' },
    offers: [
        { cx: 492, cy: 78, w: 124, h: 46, label: 'OFFER A' },
        { cx: 492, cy: 210, w: 124, h: 46, label: 'OFFER B' },
        { cx: 492, cy: 342, w: 124, h: 46, label: 'OFFER C' },
    ],
    safe: { cx: 272, cy: 398, w: 140, h: 44, label: 'SAFE PAGE' },
};

const DIAGRAM_LANES = [
    { d: 'M334,206 C386,206 380,78 430,78', tone: 'live' },
    { d: 'M334,210 L430,210', tone: 'live' },
    { d: 'M334,214 C386,214 380,342 430,342', tone: 'live' },
    { d: 'M272,239 L272,376', tone: 'safe' },
];

const DIAGRAM_PIPS = [
    { lane: 0, begin: '0s', dur: '3.4s' },
    { lane: 0, begin: '-1.7s', dur: '3.4s' },
    { lane: 1, begin: '-0.9s', dur: '3.4s' },
    { lane: 1, begin: '-2.6s', dur: '3.4s' },
    { lane: 2, begin: '-0.5s', dur: '3.4s' },
    { lane: 2, begin: '-2.2s', dur: '3.4s' },
    { lane: 3, begin: '-1.3s', dur: '4.2s' },
    { lane: 3, begin: '-3.4s', dur: '4.2s' },
];

const FlowDiagram = () => {
    const { click, cloak, offers, safe } = DIAGRAM_NODES;
    const rect = (n) => ({ x: n.cx - n.w / 2, y: n.cy - n.h / 2, w: n.w, h: n.h });
    const nodeBox = (n, { accent = false, dashed = false } = {}) => {
        const r = rect(n);
        return (
            <rect
                x={r.x} y={r.y} width={r.w} height={r.h} rx="10"
                fill="var(--color-bg-card)"
                stroke={accent ? 'var(--color-primary)' : 'var(--color-border-dark)'}
                strokeWidth={accent ? 1.5 : 1}
                strokeDasharray={dashed ? '5 5' : undefined}
            />
        );
    };
    const nodeLabel = (n, fill = 'var(--color-text-secondary)') => (
        <text x={n.cx} y={n.cy + 4} textAnchor="middle" fontSize="11" fontWeight="700" letterSpacing="2" fill={fill}>
            {n.label}
        </text>
    );

    return (
        <svg
            viewBox="0 0 560 440"
            className="w-full max-w-[560px]"
            role="img"
            aria-hidden="true"
        >
            {/* Lanes under the nodes. */}
            <path d={`M${click.cx + click.w / 2},${click.cy} L${cloak.cx - cloak.w / 2},${cloak.cy}`}
                stroke="var(--color-border-dark)" strokeWidth="1.5" fill="none" />
            {DIAGRAM_LANES.map((lane, i) => (
                <path key={i} d={lane.d}
                    stroke={lane.tone === 'safe' ? 'var(--color-danger)' : 'var(--color-border-dark)'}
                    strokeWidth="1.5" fill="none"
                    strokeDasharray={lane.tone === 'safe' ? '5 6' : undefined}
                    opacity={lane.tone === 'safe' ? 0.7 : 0.85} />
            ))}

            {/* Pips: fixed schedule, staggered negative begins. */}
            {!REDUCE_MOTION && DIAGRAM_PIPS.map((pip, i) => {
                const safeLane = DIAGRAM_LANES[pip.lane].tone === 'safe';
                const color = safeLane ? 'var(--color-danger)' : 'var(--color-primary)';
                return (
                    <circle key={i} r="3.5" fill={color}
                        style={{ filter: `drop-shadow(0 0 5px ${color})` }}>
                        <animateMotion dur={pip.dur} begin={pip.begin} repeatCount="indefinite" path={DIAGRAM_LANES[pip.lane].d} />
                    </circle>
                );
            })}

            {nodeBox(click)}
            {nodeLabel(click, 'var(--color-text-primary)')}
            {nodeBox(cloak, { accent: true })}
            {nodeLabel(cloak, 'var(--color-primary)')}
            {offers.map((o, i) => (
                <g key={i}>{nodeBox(o)}{nodeLabel(o)}</g>
            ))}
            {nodeBox(safe, { dashed: true })}
            {nodeLabel(safe)}
        </svg>
    );
};

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
    // Live "Tracker online" footer: 'checking' | 'online' | 'offline'.
    const [status, setStatus] = useState('checking');
    const [version, setVersion] = useState('');
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

    // Liveness probe: a cheap public action that answers with the running
    // version. Re-checked on a slow interval so the indicator stays live
    // without any other re-render pressure.
    useEffect(() => {
        let alive = true;
        const ping = async () => {
            try {
                const res = await fetch(`${API_URL}?action=ping`);
                const data = await res.json();
                if (!alive) return;
                setStatus(data?.status === 'success' ? 'online' : 'offline');
                if (typeof data?.version === 'string' && data.version) {
                    setVersion(data.version);
                }
            } catch {
                if (alive) setStatus('offline');
            }
        };
        ping();
        const iv = setInterval(ping, 60000);
        return () => { alive = false; clearInterval(iv); };
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
                // Known codes map through t(); anything else falls through
                // verbatim so unmigrated backend prose keeps rendering as-is.
                setError(data.code === 'invalid_credentials'
                    ? t('login.invalidStatus')
                    : (data.message || t('login.invalidStatus')));
            }
        } catch (err) {
            setError((err?.message ? String(err.message) : t('common.networkError')));
        } finally {
            setLoading(false);
        }
    };

    const statusMeta = {
        checking: { color: 'var(--color-text-muted)', label: t('login.trackerChecking') },
        online: { color: 'var(--color-success)', label: t('login.trackerOnline'), pulse: true },
        offline: { color: 'var(--color-danger)', label: t('login.trackerOffline') },
    }[status];

    return (
        <div className="min-h-screen bg-[var(--color-bg-main)] flex items-stretch">
            {/* Scoped styles: the diagram pip glow and the status-dot pulse
                are login-only; reduced motion flattens both. */}
            <style>{`
                @keyframes ob-login-pulse {
                    0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-success) 45%, transparent); }
                    50% { box-shadow: 0 0 0 5px transparent; }
                }
                .ob-login-dot-pulse { animation: ob-login-pulse 2.4s ease-in-out infinite; }
                @media (prefers-reduced-motion: reduce) {
                    .ob-login-dot-pulse { animation: none; }
                }
            `}</style>

            {/* Left column — the flow diagram. Desktop only: on a phone the
                form is the whole story. */}
            <div
                className="hidden lg:flex flex-col justify-center items-center gap-8 w-[46%] px-12 py-16"
                style={{
                    background: 'radial-gradient(60% 50% at 30% 20%, color-mix(in srgb, var(--color-primary) 7%, transparent), transparent 70%)',
                    borderRight: '1px solid var(--color-border)',
                }}
            >
                <div className="text-center">
                    <h2 className="text-2xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                        Orbitra<span style={{ color: 'var(--color-primary)' }}>.link</span>
                    </h2>
                    <p className="text-sm mt-2" style={{ color: 'var(--color-text-secondary)' }}>
                        {t('login.flowTitle')}
                    </p>
                </div>

                <FlowDiagram />

                <div className="flex flex-col gap-2 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                    <div className="flex items-center gap-3">
                        <span className="inline-block w-7 border-t-2" style={{ borderColor: 'var(--color-primary)' }} />
                        <span>{t('login.legendLive')}</span>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="inline-block w-7 border-t-2 border-dashed" style={{ borderColor: 'var(--color-danger)' }} />
                        <span>{t('login.legendSafe')}</span>
                    </div>
                </div>
            </div>

            {/* Right column — the form. */}
            <div className="flex-1 flex items-center justify-center p-4">
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

                    {/* Footer: live tracker status and the running version. */}
                    <div className="flex items-center justify-center gap-2 text-sm mt-6" style={{ color: 'var(--color-text-secondary)' }}>
                        <span
                            className={`inline-block w-2 h-2 rounded-full ${statusMeta.pulse ? 'ob-login-dot-pulse' : ''}`}
                            style={{ backgroundColor: statusMeta.color }}
                            aria-hidden="true"
                        />
                        <span>{statusMeta.label}</span>
                        {version && (
                            <>
                                <span style={{ color: 'var(--color-text-muted)' }}>·</span>
                                <span style={{ color: 'var(--color-text-muted)' }}>v{version}</span>
                            </>
                        )}
                    </div>

                    <p className="text-center text-[var(--color-text-secondary)] text-sm mt-3">
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
        </div>
    );
};

export default Login;
