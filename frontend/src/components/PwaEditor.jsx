import React, { useState, useEffect, useRef } from 'react';
import { X, Smartphone, Trash2, ExternalLink, Loader2, Plus, ImagePlus, GripVertical, Check } from 'lucide-react';
import MediaPicker from './common/MediaPicker';
import { useLanguage } from '../contexts/LanguageContext';
import axios from 'axios';

const API_URL = '/api.php';

// Phase-2 PWA landing wizard: three steps over the same config_json as the
// phase-1 form, with a live device preview rendered by the PRODUCTION
// generator (action=pwa_preview → core/PwaLanding.php::renderPreview) —
// preview and shipped page can never drift. Presets fill design & content
// but never the internal name. Reviews reorder via drag & drop.

const CATEGORIES = ['Casino', 'Gambling', 'Sport Betting', 'Entertainment', 'Games', 'Fitness', 'Dating', 'Other'];
const LANGS = ['en', 'ru', 'uk', 'es', 'de', 'fr', 'zh'];
const SCHEMES = [
    { id: 'green', label: 'Green' },
    { id: 'blue', label: 'Blue' },
    { id: 'purple', label: 'Purple' },
    { id: 'red', label: 'Red' },
    { id: 'orange', label: 'Orange' },
    { id: 'pink', label: 'Pink' },
];
const DOWNLOADS = ['500+', '1K+', '5K+', '10K+', '100K+', '1M+', '10M+', '50M+', '100M+', '500M+', '1B+'];
const TIMER_OPTIONS = [0, 30, 45, 60, 90, 120, 150, 180];

// Built-in starter packs (design + sample content + bundled sample assets).
// Assets live in /assets/pwa-presets/<id>/ — shipped files, no media-library
// dependency; the operator replaces them via the picker whenever they want.
// Names/labels are visitor-facing SAMPLE CONTENT, deliberately not i18n
// chrome — the operator edits them per app.
const presetAssets = (id) => ({
    icon_url: `/assets/pwa-presets/${id}/icon.png`,
    app_screen_image: `/assets/pwa-presets/${id}/app-hero.png`,
    screens: [
        `/assets/pwa-presets/${id}/screen-1.png`,
        `/assets/pwa-presets/${id}/screen-2.png`,
        `/assets/pwa-presets/${id}/screen-3.png`,
    ],
});

const SLOT_BOILERPLATE = {
    html: `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;padding:20px;background:radial-gradient(circle,#1a1a2e,#0f0f1b);color:#fff;text-align:center;font-family:sans-serif;">
  <h2 style="font-size:24px;color:#ffd700;margin-bottom:8px;text-shadow:0 0 10px rgba(255,215,0,0.5);">🎰 TRIPLE 777 SLOTS</h2>
  <p style="font-size:13px;color:#aaa;margin-bottom:20px;">Spin the lucky reels & win your exclusive bonus!</p>
  <div style="display:flex;gap:12px;background:#000;padding:16px 24px;border:3px solid #ffd700;border-radius:18px;font-size:48px;box-shadow:0 0 25px rgba(255,215,0,0.4);">
    <div id="slot-r1">🍒</div>
    <div id="slot-r2">💎</div>
    <div id="slot-r3">7️⃣</div>
  </div>
  <button id="custom-spin-btn" style="margin-top:24px;padding:14px 36px;font-size:18px;font-weight:bold;background:linear-gradient(135deg,#ff007f,#ff7700);color:#fff;border:none;border-radius:30px;cursor:pointer;box-shadow:0 6px 20px rgba(255,0,127,0.5);">SPIN NOW!</button>
</div>`,
    js: `document.getElementById('custom-spin-btn')?.addEventListener('click', function() {
  const symbols = ['🍒', '💎', '7️⃣', '👑', '🔔'];
  let count = 0;
  const btn = this;
  btn.disabled = true;
  btn.textContent = 'SPINNING...';
  const timer = setInterval(() => {
    document.getElementById('slot-r1').textContent = symbols[Math.floor(Math.random() * symbols.length)];
    document.getElementById('slot-r2').textContent = symbols[Math.floor(Math.random() * symbols.length)];
    document.getElementById('slot-r3').textContent = symbols[Math.floor(Math.random() * symbols.length)];
    count++;
    if (count > 15) {
      clearInterval(timer);
      document.getElementById('slot-r1').textContent = '7️⃣';
      document.getElementById('slot-r2').textContent = '7️⃣';
      document.getElementById('slot-r3').textContent = '7️⃣';
      btn.textContent = 'YOU WON! REDIRECTING...';
      setTimeout(() => {
        if (typeof window.orbitraRedirect === 'function') {
          window.orbitraRedirect();
        }
      }, 1200);
    }
  }, 100);
});`
};

const WHEEL_BOILERPLATE = {
    html: `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;padding:20px;background:radial-gradient(circle,#141e30,#243b55);color:#fff;text-align:center;font-family:sans-serif;">
  <h2 style="font-size:24px;color:#ffd700;margin-bottom:6px;text-shadow:0 0 10px rgba(255,215,0,0.5);">🎡 LUCKY BONUS WHEEL</h2>
  <p style="font-size:13px;color:#cbd5e1;margin-bottom:24px;">Spin the wheel to claim your $1,000 Welcome Pack!</p>
  <div style="position:relative;width:240px;height:240px;margin-bottom:24px;">
    <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);font-size:24px;color:#ffd700;z-index:10;">▼</div>
    <div id="roulette-wheel" style="width:100%;height:100%;border-radius:50%;background:conic-gradient(#e74c3c 0% 25%, #3498db 25% 50%, #2ecc71 50% 75%, #f1c40f 75% 100%);border:5px solid #fff;box-shadow:0 0 25px rgba(0,0,0,0.6);transition:transform 3s cubic-bezier(0.12,0.95,0.2,1);"></div>
  </div>
  <button id="custom-wheel-btn" style="padding:14px 36px;font-size:18px;font-weight:bold;background:#ffd700;color:#1e293b;border:none;border-radius:30px;cursor:pointer;box-shadow:0 6px 20px rgba(255,215,0,0.5);">SPIN WHEEL</button>
</div>`,
    js: `document.getElementById('custom-wheel-btn')?.addEventListener('click', function() {
  const wheel = document.getElementById('roulette-wheel');
  this.disabled = true;
  this.textContent = 'SPINNING...';
  if (wheel) {
    wheel.style.transform = 'rotate(' + (1440 + 45) + 'deg)';
  }
  setTimeout(() => {
    alert('CONGRATULATIONS! You won $1,000 Bonus!');
    if (typeof window.orbitraRedirect === 'function') {
      window.orbitraRedirect();
    }
  }, 3200);
});`
};

const PRESETS = [
    {
        id: 'lucky-casino',
        label: 'Lucky Casino',
        patch: {
            ...presetAssets('lucky-casino'),
            category: 'Casino',
            color_scheme: 'green',
            theme_mode: 'dark',
            button_text: 'Install',
            app_screen_title: 'Welcome Bonus: $1,000 + 100 FS',
            app_screen_text: 'Spin the lucky reels, hit the jackpot & withdraw instantly to your card!',
            app_screen_button: 'Claim Bonus & Play',
            downloads: '10M+',
            ads_label: 'Contains ads · In-app purchases',
            description: 'Play {value} and get your welcome bonus! Thousands of players already win every day with {value1} games. Spin, bet and hit the jackpot — free chips every hour.',
            tags: ['casino', 'slots', 'jackpot', 'bonus'],
            rating_counts: [342000, 27500, 11000, 3200, 1300],
            comments: [
                { name: 'Marco88', text: 'Withdrawn my first win in 2 days, app is smooth.', stars: 5, likes: 41, date: '2026-08-28', reply: 'Thanks for playing with us!' },
                { name: 'LuckyStar', text: 'Daily free spins are the best part.', stars: 4, likes: 12, date: '2026-08-25', reply: '' },
                { name: 'Anna_777', text: 'Good slots, fast support.', stars: 5, likes: 27, date: '2026-08-21', reply: '' },
            ],
        },
    },
    {
        id: 'bet-sport',
        label: 'BetMaster Sport',
        patch: {
            ...presetAssets('bet-sport'),
            category: 'Sport Betting',
            color_scheme: 'blue',
            theme_mode: 'light',
            button_text: 'Get',
            app_screen_title: 'Live Sportsbook · 100% Match Bonus',
            app_screen_text: 'High odds on live football, tennis & cyber sports. Instant 1-click cashouts.',
            app_screen_button: 'Claim Free Bet',
            downloads: '50M+',
            ads_label: 'Contains ads',
            description: '{value} — live scores, high odds and instant payouts. Bet on football, tennis, esports and more with {value1}.',
            tags: ['sport', 'betting', 'live', 'odds'],
            rating_counts: [538000, 46000, 15500, 5500, 2500],
            comments: [
                { name: 'Denis_K', text: 'Odds are better than in my old app.', stars: 5, likes: 33, date: '2026-08-30', reply: '' },
                { name: 'FootballFan', text: 'Live streaming works great.', stars: 4, likes: 15, date: '2026-08-26', reply: 'More leagues coming soon!' },
                { name: 'Ivan', text: 'Fast cashout, no issues.', stars: 5, likes: 8, date: '2026-08-19', reply: '' },
            ],
        },
    },
    {
        id: 'neon-slots',
        label: 'Neon Slots 777',
        patch: {
            ...presetAssets('neon-slots'),
            category: 'Gambling',
            color_scheme: 'purple',
            theme_mode: 'dark',
            button_text: 'Play Now',
            app_screen_title: 'Neon Vegas 777 · 1,000,000 Free Coins',
            app_screen_text: 'Spin the glowing cyberpunk reels and win mega jackpots every hour!',
            app_screen_button: 'Play Neon Slots',
            downloads: '5M+',
            ads_label: 'Contains ads',
            description: 'Neon lights, classic 777 slots and huge jackpots! {value} brings Vegas to your pocket — free coins every 4 hours from {value1}.',
            tags: ['slots', '777', 'vegas', 'free coins'],
            rating_counts: [84000, 7200, 2400, 900, 400],
            comments: [
                { name: 'SlotQueen', text: 'The 777 machine is my favorite.', stars: 5, likes: 19, date: '2026-08-29', reply: '' },
                { name: 'NightOwl', text: 'Runs fine even on my old phone.', stars: 4, likes: 6, date: '2026-08-24', reply: '' },
            ],
        },
    },
    {
        id: 'fit-club',
        label: 'FitClub Pro',
        patch: {
            ...presetAssets('fit-club'),
            category: 'Fitness',
            color_scheme: 'orange',
            theme_mode: 'light',
            button_text: 'Install',
            app_screen_title: 'Your Personal 30-Day Fitness Plan',
            app_screen_text: 'Transform your body with tailored daily workouts, timers and coach guidance.',
            app_screen_button: 'Start Workout Plan',
            downloads: '1M+',
            ads_label: 'No ads',
            description: 'Your personal trainer in your pocket. {value} builds a workout plan just for you — home or gym, with {value1} coaches.',
            tags: ['fitness', 'workout', 'health', 'trainer'],
            rating_counts: [40500, 3400, 1200, 450, 250],
            comments: [
                { name: 'KateFit', text: 'Lost 4 kg in a month with the plan.', stars: 5, likes: 52, date: '2026-08-27', reply: 'Amazing progress, keep going!' },
                { name: 'RunnerOne', text: 'Clean interface, no ads.', stars: 5, likes: 14, date: '2026-08-22', reply: '' },
            ],
        },
    },
];

const DEFAULT_CONFIG = {
    pwa: true,
    app_name: '',
    developer: '',
    category: 'Casino',
    lang: 'en',
    icon: '',
    icon_url: '',
    screens: [],
    description: '',
    downloads: '1M+',
    ads_label: 'Contains ads',
    button_text: 'Install',
    version: '1.0.0',
    updated: new Date().toISOString().slice(0, 10),
    tags: [],
    rating_counts: [4200, 480, 120, 30, 15],
    comments: [],
    whats_new_enabled: false,
    whats_new_text: '',
    support_enabled: false,
    support_email: '',
    support_address: '',
    verified_badge: true,
    theme_mode: 'light',
    color_scheme: 'green',
    store_style: 'auto',
    ios_flow: 'default',
    push_enabled: false,
    preloader: true,
    bottom_menu: false,
    show_header: true,
    show_share: false,
    auto_redirect: 0,
    decline_redirect: 0,
    install_redirect: 0,
    animation_glow: true,
    show_live_badge: true,
    sound_enabled: true,
    vibration_enabled: true,
    app_action: 'store',
    app_screen_type: 'lobby',
    app_screen_title: '',
    app_screen_text: '',
    app_screen_button: 'Play now',
    app_screen_image: '',
    app_screen_custom_html: '',
    app_screen_custom_js: '',
    custom_css: '',
    custom_head_code: '',
    custom_body_code: '',
    custom_js: '',
    action_target: 'to_offer',
    action_campaign_id: 0,
    action_url: '',
};

const ratingAvg = (counts) => {
    const sum = counts.reduce((acc, n, i) => acc + (Number(n) || 0) * (i + 1), 0);
    const total = counts.reduce((acc, n) => acc + (Number(n) || 0), 0);
    return total > 0 ? (sum / total).toFixed(1) : '—';
};

const Section = ({ title, children }) => (
    <div style={{ borderBottom: '1px solid var(--color-border)', padding: '16px 0' }}>
        <div className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)', marginBottom: '12px' }}>{title}</div>
        {children}
    </div>
);

const Field = ({ label, children, hint }) => (
    <div className="flex flex-col gap-1">
        <label className="form-label" style={{ marginBottom: 0 }}>{label}</label>
        {children}
        {hint && <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{hint}</span>}
    </div>
);

const Toggle = ({ label, checked, onChange }) => (
    <label className="flex items-center gap-2 cursor-pointer select-none">
        <input
            type="checkbox"
            checked={checked}
            onChange={(e) => onChange(e.target.checked)}
            style={{ width: '16px', height: '16px', accentColor: 'var(--color-primary)' }}
        />
        <span className="text-sm">{label}</span>
    </label>
);

export default function PwaEditor({ landingId, onClose }) {
    const { t } = useLanguage();
    const [name, setName] = useState('');
    const [state, setState] = useState('active');
    const [groupId, setGroupId] = useState(null);
    const [config, setConfig] = useState(DEFAULT_CONFIG);
    // 'icon' | 'screens' | null — which MediaPicker is open. The picker is the
    // single media source: assets live in the media library and the config
    // stores their URLs (PWA is a "URL world" page, unlike ZIP offers).
    const [pickerMode, setPickerMode] = useState(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [previewUrl, setPreviewUrl] = useState('');
    const [savedId, setSavedId] = useState(landingId || null);

    // Wizard + live preview state.
    const [step, setStep] = useState(0);
    const [maxStep, setMaxStep] = useState(0);
    const [campaigns, setCampaigns] = useState([]);
    const [previewHtml, setPreviewHtml] = useState('');
    const [previewPlatform, setPreviewPlatform] = useState('auto'); // 'auto' | 'ios'
    const [previewView, setPreviewView] = useState('auto'); // 'auto' | 'store' | 'screen'
    const [previewLoading, setPreviewLoading] = useState(false);
    const previewSeq = useRef(0);

    // Reviews drag & drop.
    const [dragIdx, setDragIdx] = useState(null);
    const [overIdx, setOverIdx] = useState(null);

    useEffect(() => {
        axios.get(`${API_URL}?action=campaigns`).then((res) => {
            if (res.data?.status === 'success') {
                setCampaigns(res.data.data || []);
            }
        }).catch(() => {});
    }, []);

    useEffect(() => {
        if (!landingId) return;
        (async () => {
            try {
                const res = await axios.get(`${API_URL}`, { params: { action: 'pwa_config_get', id: landingId } });
                const data = res.data?.data;
                if (data) {
                    setName(data.name || '');
                    setState(data.state || 'active');
                    setGroupId(data.group_id ?? null);
                    setConfig({ ...DEFAULT_CONFIG, ...(data.config || {}) });
                }
            } catch {
                setError(t('pwa.loadFailed'));
            }
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [landingId]);

    // Live preview: debounce keystrokes, render the DRAFT through the
    // production generator (action=pwa_preview). A sequence counter drops
    // stale responses when the operator types faster than the round-trip.
    useEffect(() => {
        const seq = ++previewSeq.current;
        const timer = setTimeout(async () => {
            setPreviewLoading(true);
            try {
                const effectiveView = previewView !== 'auto'
                    ? previewView
                    : ((step === 1 && config.app_action === 'screen') ? 'screen' : 'store');
                const res = await axios.post(`${API_URL}?action=pwa_preview`, {
                    config,
                    platform: previewPlatform,
                    view: effectiveView,
                });
                if (res.data?.status === 'success' && previewSeq.current === seq) {
                    setPreviewHtml(res.data.data.html || '');
                }
            } catch {
                // A failed preview render must not blank the last good one.
            } finally {
                if (previewSeq.current === seq) setPreviewLoading(false);
            }
        }, 500);
        return () => clearTimeout(timer);
    }, [config, previewPlatform, previewView, step]);

    const set = (key, value) => setConfig((c) => ({ ...c, [key]: value }));

    const canLeaveGeneral = name.trim() !== '' && config.app_name.trim() !== '';

    const goToStep = (next) => {
        if (next <= maxStep) {
            setStep(next);
            return;
        }
        // Stepping forward is allowed one step at a time, gated on step 1's
        // required fields (Adset parity: reviews unlock after the basics).
        if (next === maxStep + 1 && (next !== 1 || canLeaveGeneral)) {
            setMaxStep(next);
            setStep(next);
            setError('');
        }
    };

    const handleNext = () => {
        // A blocked step must SAY so: a silently disabled button reads as a
        // broken one (demo feedback 2026-09-01).
        if (!stepValid(step)) {
            setError(t('pwa.nameRequired'));
            return;
        }
        setError('');
        goToStep(step + 1);
    };

    const onMediaPicked = (asset) => {
        const mode = pickerMode;
        setPickerMode(null);
        // multiple mode resolves a LIST of assets; single resolves one.
        const assets = Array.isArray(asset) ? asset : [asset];
        const usable = assets.filter(a => a?.url);
        if (!usable.length) return;
        if (mode === 'icon') {
            set('icon_url', usable[usable.length - 1].url);
        } else if (mode === 'apphero') {
            set('app_screen_image', usable[usable.length - 1].url);
        } else if (mode === 'screens') {
            setConfig((c) => ({
                ...c,
                screens: [...(c.screens || []).filter(Boolean), ...usable.map(a => a.url)].slice(0, 10),
            }));
        }
    };

    const handleSave = async () => {
        if (saving) return;
        setError('');
        if (!name.trim() || !config.app_name.trim()) {
            setError(t('pwa.nameRequired'));
            return;
        }
        setSaving(true);
        try {
            const nextConfig = {
                ...config,
                screens: (Array.isArray(config.screens) ? config.screens : []).filter(Boolean),
                tags: (Array.isArray(config.tags) ? config.tags : []).filter(Boolean),
            };

            const res = await axios.post(`${API_URL}?action=pwa_config_save`, {
                id: savedId ?? undefined,
                name: name.trim(),
                state,
                group_id: groupId,
                config: nextConfig,
            });
            if (res.data?.status !== 'success') {
                throw new Error(res.data?.message || 'save failed');
            }
            setSavedId(res.data.data.id);
            setConfig(nextConfig);
            setPreviewUrl(res.data.data.preview_url || '');
        } catch (e) {
            setError(e?.response?.data?.message || e?.message || t('pwa.saveFailed'));
        } finally {
            setSaving(false);
        }
    };

    const handleClose = () => onClose(previewUrl !== '' || savedId !== null);

    const applyPreset = (patch) => {
        setConfig((c) => ({ ...c, ...patch }));
    };

    // --- Reviews drag & drop -------------------------------------------------
    const reorderComments = (from, to) => {
        setConfig((c) => {
            const comments = [...(c.comments || [])];
            if (from === to || from < 0 || to < 0 || from >= comments.length || to >= comments.length) {
                return c;
            }
            const [moved] = comments.splice(from, 1);
            comments.splice(to, 0, moved);
            return { ...c, comments };
        });
    };
    const resetDrag = () => {
        setDragIdx(null);
        setOverIdx(null);
    };
    const updateComment = (idx, key, value) => {
        setConfig((c) => {
            const comments = [...(c.comments || [])];
            comments[idx] = { ...comments[idx], [key]: value };
            return { ...c, comments };
        });
    };
    const removeComment = (idx) => {
        setConfig((c) => ({ ...c, comments: (c.comments || []).filter((_, i) => i !== idx) }));
        resetDrag();
    };
    const addComment = () => {
        setConfig((c) => ({
            ...c,
            comments: [...(c.comments || []), { name: '', text: '', stars: 5, likes: 0, date: new Date().toISOString().slice(0, 10), reply: '' }],
        }));
    };

    const steps = [t('pwa.stepGeneral'), t('pwa.stepApp'), t('pwa.stepReviews')];
    const stepValid = (i) => (i === 0 ? canLeaveGeneral : true);

    const stepper = (
        <div className="flex items-center gap-2">
            {steps.map((label, i) => {
                const reachable = i <= maxStep && (i === 0 || stepValid(0));
                const active = i === step;
                return (
                    <React.Fragment key={label}>
                        {i > 0 && <span style={{ width: 24, height: 1, background: 'var(--color-border)' }} />}
                        <button
                            type="button"
                            onClick={() => reachable && goToStep(i)}
                            className="flex items-center gap-2 text-sm rounded-full transition"
                            style={{
                                padding: '6px 14px',
                                border: '1px solid ' + (active ? 'var(--color-primary)' : 'var(--color-border)'),
                                background: active ? 'var(--color-primary-light)' : 'transparent',
                                color: active ? 'var(--color-primary)' : (reachable ? 'var(--color-text-primary)' : 'var(--color-text-muted)'),
                                cursor: reachable ? 'pointer' : 'not-allowed',
                                fontWeight: active ? 600 : 400,
                            }}
                        >
                            <span
                                className="flex items-center justify-center rounded-full"
                                style={{
                                    width: 20, height: 20, fontSize: 11,
                                    background: i < step ? 'var(--color-primary)' : 'transparent',
                                    border: i < step ? 'none' : '1px solid currentColor',
                                    color: i < step ? 'var(--color-text-inverse)' : 'inherit',
                                }}
                            >
                                {i < step ? <Check className="w-3 h-3" /> : i + 1}
                            </span>
                            {label}
                        </button>
                    </React.Fragment>
                );
            })}
        </div>
    );

    return (
        <div className="modal-overlay" onClick={handleClose}>
            <div
                className="modal-content"
                onClick={(e) => e.stopPropagation()}
                style={{
                    maxWidth: '1500px',
                    width: '97vw',
                    height: '92vh',
                    overflow: 'visible',
                    display: 'flex',
                    flexDirection: 'column',
                }}
            >
                <div className="modal-header">
                    <h3 className="modal-title flex items-center gap-2">
                        <Smartphone className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                        {t('pwa.title')}{savedId ? ` #${savedId}` : ''}
                    </h3>
                    {stepper}
                    <button type="button" className="btn btn-ghost btn-icon" onClick={handleClose} title={t('common.close')}>
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div className="flex" style={{ flex: 1, minHeight: 0 }}>
                    {/* Steps pane */}
                    <div style={{ overflowY: 'auto', padding: '4px 24px 20px', flex: 1, minWidth: 0 }}>
                        {error && (
                            <div className="alert" style={{ background: 'color-mix(in srgb, var(--color-danger) 12%, transparent)', color: 'var(--color-danger)', margin: '12px 0', fontSize: '13px' }}>
                                {error}
                            </div>
                        )}

                        {/* ============ STEP 1 — General ============ */}
                        {step === 0 && (
                            <>
                                <Section title={t('pwa.presets')}>
                                    <span className="text-xs block mb-2" style={{ color: 'var(--color-text-muted)' }}>{t('pwa.presetsHint')}</span>
                                    <div className="flex flex-wrap gap-2">
                                        {PRESETS.map((p) => (
                                            <button
                                                key={p.id}
                                                type="button"
                                                className="btn btn-secondary text-sm"
                                                onClick={() => applyPreset(p.patch)}
                                            >
                                                {p.label}
                                            </button>
                                        ))}
                                    </div>
                                </Section>

                                <Section title={t('pwa.sectionBasic')}>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <Field label={t('pwa.internalName')} hint={t('pwa.internalNameHint')}>
                                            <input className="form-input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Lucky Spin PWA" />
                                        </Field>
                                        <Field label={t('pwa.state')}>
                                            <select className="form-select" value={state} onChange={(e) => setState(e.target.value)}>
                                                <option value="active">{t('components.active')}</option>
                                                <option value="paused">{t('components.paused')}</option>
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.appName')}>
                                            <input className="form-input" value={config.app_name} onChange={(e) => set('app_name', e.target.value)} />
                                        </Field>
                                        <Field label={t('pwa.developer')}>
                                            <input className="form-input" value={config.developer} onChange={(e) => set('developer', e.target.value)} />
                                        </Field>
                                        <Field label={t('pwa.category')}>
                                            <select className="form-select" value={config.category} onChange={(e) => set('category', e.target.value)}>
                                                {CATEGORIES.map((cat) => <option key={cat} value={cat}>{cat}</option>)}
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.language')} hint={t('pwa.languageHint')}>
                                            <input
                                                className="form-input"
                                                list="pwa-lang-codes"
                                                value={config.lang}
                                                maxLength={10}
                                                onChange={(e) => set('lang', e.target.value.trim())}
                                                placeholder="en"
                                            />
                                            <datalist id="pwa-lang-codes">
                                                {LANGS.map((lng) => <option key={lng} value={lng} />)}
                                            </datalist>
                                        </Field>
                                    </div>
                                </Section>

                                <Section title={t('pwa.sectionDesign')}>
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <Field label={t('pwa.storeStyle')}>
                                            <select className="form-select" value={config.store_style || 'auto'} onChange={(e) => set('store_style', e.target.value)}>
                                                <option value="auto">{t('pwa.storeStyleAuto')}</option>
                                                <option value="google_play">{t('pwa.storeStyleGooglePlay')}</option>
                                                <option value="app_store">{t('pwa.storeStyleAppStore')}</option>
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.themeMode')}>
                                            <select className="form-select" value={config.theme_mode} onChange={(e) => set('theme_mode', e.target.value)}>
                                                <option value="light">{t('pwa.themeLight')}</option>
                                                <option value="dark">{t('pwa.themeDark')}</option>
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.colorScheme')}>
                                            <select className="form-select" value={config.color_scheme} onChange={(e) => set('color_scheme', e.target.value)}>
                                                {SCHEMES.map((sch) => <option key={sch.id} value={sch.id}>{sch.label}</option>)}
                                            </select>
                                        </Field>
                                    </div>
                                    <div className="flex flex-wrap gap-4 mt-4">
                                        <Toggle label={t('pwa.verifiedBadge')} checked={config.verified_badge} onChange={(v) => set('verified_badge', v)} />
                                        <Toggle label={t('pwa.preloader')} checked={config.preloader} onChange={(v) => set('preloader', v)} />
                                        <Toggle label={t('pwa.showHeader')} checked={config.show_header} onChange={(v) => set('show_header', v)} />
                                        <Toggle label={t('pwa.bottomMenu')} checked={config.bottom_menu} onChange={(v) => set('bottom_menu', v)} />
                                        <Toggle label={t('pwa.showShare')} checked={config.show_share} onChange={(v) => set('show_share', v)} />
                                        <Toggle label={t('pwa.animationGlow')} checked={config.animation_glow} onChange={(v) => set('animation_glow', v)} />
                                        <Toggle label={t('pwa.showLiveBadge')} checked={config.show_live_badge} onChange={(v) => set('show_live_badge', v)} />
                                        <Toggle label={t('pwa.soundEnabled')} checked={config.sound_enabled} onChange={(v) => set('sound_enabled', v)} />
                                        <Toggle label={t('pwa.vibrationEnabled')} checked={config.vibration_enabled} onChange={(v) => set('vibration_enabled', v)} />
                                    </div>
                                </Section>

                                <Section title={t('pwa.sectionFunnel')}>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <Field label={t('pwa.iosFlow')} hint={t('pwa.iosFlowHint')}>
                                            <select className="form-select" value={config.ios_flow} onChange={(e) => set('ios_flow', e.target.value)}>
                                                <option value="default">{t('pwa.iosFlowDefault')}</option>
                                                <option value="instruction">{t('pwa.iosFlowInstruction')}</option>
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.autoRedirect')}>
                                            <select className="form-select" value={config.auto_redirect} onChange={(e) => set('auto_redirect', parseInt(e.target.value, 10))}>
                                                {TIMER_OPTIONS.map((sec) => <option key={sec} value={sec}>{sec === 0 ? t('pwa.timerOff') : `${sec}s`}</option>)}
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.declineRedirect')}>
                                            <select className="form-select" value={config.decline_redirect} onChange={(e) => set('decline_redirect', parseInt(e.target.value, 10))}>
                                                {TIMER_OPTIONS.map((sec) => <option key={sec} value={sec}>{sec === 0 ? t('pwa.timerOff') : `${sec}s`}</option>)}
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.installRedirect')} hint={t('pwa.installRedirectHint')}>
                                            <select className="form-select" value={config.install_redirect} onChange={(e) => set('install_redirect', parseInt(e.target.value, 10))}>
                                                {TIMER_OPTIONS.map((sec) => <option key={sec} value={sec}>{sec === 0 ? t('pwa.timerImmediate') : `${sec}s`}</option>)}
                                            </select>
                                        </Field>
                                    </div>
                                    <div className="flex flex-wrap gap-4 mt-4">
                                        <Toggle label={t('pwa.pushEnabled')} checked={config.push_enabled} onChange={(v) => set('push_enabled', v)} />
                                        <span className="text-xs self-center" style={{ color: 'var(--color-text-muted)' }}>{t('pwa.pushEnabledHint')}</span>
                                    </div>
                                    <div className="mt-4">
                                        <Toggle label={t('pwa.supportEnabled')} checked={config.support_enabled} onChange={(v) => set('support_enabled', v)} />
                                    </div>
                                    {config.support_enabled && (
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                            <input className="form-input" placeholder={t('pwa.supportEmail')} value={config.support_email} onChange={(e) => set('support_email', e.target.value)} />
                                            <input className="form-input" placeholder={t('pwa.supportAddress')} value={config.support_address} onChange={(e) => set('support_address', e.target.value)} />
                                        </div>
                                    )}
                                </Section>
                            </>
                        )}

                        {/* ============ STEP 2 — App ============ */}
                        {step === 1 && (
                            <>
                                <Section title={t('pwa.actionTarget')}>
                                    <span className="text-xs block mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('pwa.actionTargetHint')}</span>
                                    <div className="space-y-4">
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <Field label={t('pwa.actionTarget')}>
                                                <select className="form-select" value={config.action_target || 'to_offer'} onChange={(e) => set('action_target', e.target.value)}>
                                                    <option value="to_offer">{t('pwa.targetOffer')}</option>
                                                    <option value="to_campaign">{t('pwa.targetCampaign')}</option>
                                                    <option value="to_url">{t('pwa.targetUrl')}</option>
                                                    <option value="not_found">{t('pwa.targetNotFound')}</option>
                                                </select>
                                            </Field>

                                            {config.action_target === 'to_campaign' && (
                                                <Field label={t('pwa.targetCampaignLabel')}>
                                                    <select
                                                        className="form-select"
                                                        value={config.action_campaign_id || ''}
                                                        onChange={(e) => set('action_campaign_id', Number(e.target.value) || 0)}
                                                    >
                                                        <option value="">{t('pwa.targetCampaignPlaceholder')}</option>
                                                        {campaigns.map((c) => (
                                                            <option key={c.id} value={c.id}>
                                                                {c.name} (#{c.id})
                                                            </option>
                                                        ))}
                                                    </select>
                                                </Field>
                                            )}

                                            {config.action_target === 'to_url' && (
                                                <Field label={t('pwa.targetUrlLabel')}>
                                                    <input
                                                        type="url"
                                                        className="form-input font-mono text-xs"
                                                        placeholder={t('pwa.targetUrlPlaceholder')}
                                                        value={config.action_url || ''}
                                                        onChange={(e) => set('action_url', e.target.value)}
                                                    />
                                                </Field>
                                            )}
                                        </div>

                                        {config.action_target === 'not_found' && (
                                            <div className="p-3 rounded-xl border text-xs" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>
                                                {t('pwa.targetNotFoundHint')}
                                            </div>
                                        )}
                                    </div>
                                </Section>

                                <Section title={t('pwa.appAction')}>
                                    <span className="text-xs block mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('pwa.appActionHint')}</span>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <Field label={t('pwa.appAction')}>
                                            <select className="form-select" value={config.app_action || 'store'} onChange={(e) => set('app_action', e.target.value)}>
                                                <option value="store">{t('pwa.appActionStore')}</option>
                                                <option value="offer">{t('pwa.appActionOffer')}</option>
                                                <option value="screen">{t('pwa.appActionScreen')}</option>
                                            </select>
                                        </Field>

                                        {config.app_action === 'screen' && (
                                            <Field label={t('pwa.appScreenType')} hint={t('pwa.appScreenTypeHint')}>
                                                <select className="form-select" value={config.app_screen_type || 'lobby'} onChange={(e) => set('app_screen_type', e.target.value)}>
                                                    <option value="lobby">🏠 {t('pwa.screenTypeLobby')}</option>
                                                    <option value="slot">🎰 {t('pwa.screenTypeSlot')}</option>
                                                    <option value="wheel">🎡 {t('pwa.screenTypeWheel')}</option>
                                                    <option value="custom">💻 {t('pwa.screenTypeCustom')}</option>
                                                </select>
                                            </Field>
                                        )}
                                    </div>

                                    {config.app_action === 'screen' && config.app_screen_type === 'custom' && (
                                        <div className="space-y-4 mt-4 p-4 rounded-xl border" style={{ borderColor: 'var(--color-border)', background: 'var(--color-bg-soft)' }}>
                                            <div className="flex flex-wrap items-center justify-between gap-2 pb-2 border-b" style={{ borderColor: 'var(--color-border)' }}>
                                                <span className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                                    {t('pwa.screenTypeCustom')}
                                                </span>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        className="btn btn-secondary btn-sm text-xs"
                                                        onClick={() => {
                                                            set('app_screen_custom_html', SLOT_BOILERPLATE.html);
                                                            set('app_screen_custom_js', SLOT_BOILERPLATE.js);
                                                        }}
                                                    >
                                                        🎰 {t('pwa.insertSlotBoilerplate')}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-secondary btn-sm text-xs"
                                                        onClick={() => {
                                                            set('app_screen_custom_html', WHEEL_BOILERPLATE.html);
                                                            set('app_screen_custom_js', WHEEL_BOILERPLATE.js);
                                                        }}
                                                    >
                                                        🎡 {t('pwa.insertWheelBoilerplate')}
                                                    </button>
                                                </div>
                                            </div>

                                            <Field label={t('pwa.customHtmlLabel')}>
                                                <textarea
                                                    className="form-input font-mono text-xs"
                                                    rows={6}
                                                    placeholder={t('pwa.customHtmlPlaceholder')}
                                                    value={config.app_screen_custom_html || ''}
                                                    onChange={(e) => set('app_screen_custom_html', e.target.value)}
                                                    style={{ whiteSpace: 'pre', tabSize: 2 }}
                                                />
                                            </Field>

                                            <Field label={t('pwa.customJsLabel')} hint={t('pwa.customJsTip')}>
                                                <textarea
                                                    className="form-input font-mono text-xs"
                                                    rows={6}
                                                    placeholder={t('pwa.customJsPlaceholder')}
                                                    value={config.app_screen_custom_js || ''}
                                                    onChange={(e) => set('app_screen_custom_js', e.target.value)}
                                                    style={{ whiteSpace: 'pre', tabSize: 2 }}
                                                />
                                            </Field>
                                        </div>
                                    )}

                                    {config.app_action === 'screen' && config.app_screen_type !== 'custom' && (
                                        <div className="space-y-4 mt-4">
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <Field label={t('pwa.appScreenTitle')}>
                                                    <input className="form-input" placeholder="e.g. Welcome Bonus: $1,000" value={config.app_screen_title || ''} onChange={(e) => set('app_screen_title', e.target.value)} />
                                                </Field>
                                                <Field label={t('pwa.appScreenButton')}>
                                                    <input className="form-input" placeholder="e.g. Claim Bonus & Play" value={config.app_screen_button || ''} onChange={(e) => set('app_screen_button', e.target.value)} />
                                                </Field>
                                            </div>

                                            {config.app_screen_type === 'lobby' && (
                                                <Field label={t('pwa.heroImage') || t('pwa.pickHero')}>
                                                    <div className="flex items-center gap-3">
                                                        {config.app_screen_image ? (
                                                            <img
                                                                src={config.app_screen_image}
                                                                alt=""
                                                                onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }}
                                                                style={{ width: 64, height: 42, borderRadius: 8, objectFit: 'cover', border: '1px solid var(--color-border)' }}
                                                            />
                                                        ) : (
                                                            <div style={{ width: 64, height: 42, borderRadius: 8, border: '1px dashed var(--color-border)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--color-text-muted)' }}>
                                                                <ImagePlus className="w-4 h-4" />
                                                            </div>
                                                        )}
                                                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setPickerMode('apphero')}>
                                                            <ImagePlus className="w-4 h-4" />
                                                            {t('pwa.pickHero')}
                                                        </button>
                                                        {config.app_screen_image && (
                                                            <button
                                                                type="button"
                                                                className="btn btn-ghost btn-sm text-xs"
                                                                style={{ color: 'var(--color-danger)' }}
                                                                onClick={() => set('app_screen_image', '')}
                                                            >
                                                                {t('common.delete')}
                                                            </button>
                                                        )}
                                                    </div>
                                                </Field>
                                            )}

                                            <Field label={t('pwa.appScreenText')}>
                                                <textarea className="form-input" rows={2} placeholder="e.g. Spin the lucky reels, hit the jackpot & withdraw instantly!" value={config.app_screen_text || ''} onChange={(e) => set('app_screen_text', e.target.value)} />
                                            </Field>
                                        </div>
                                    )}
                                </Section>

                                <Section title={t('pwa.sectionMedia')}>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <Field label={t('pwa.icon')} hint={t('pwa.iconHint')}>
                                            <div className="flex items-center gap-3">
                                                {(config.icon_url || config.icon) ? (
                                                    <img
                                                        src={config.icon_url || config.icon}
                                                        alt=""
                                                        onError={(e) => { e.currentTarget.style.visibility = 'hidden'; }}
                                                        style={{ width: 64, height: 64, borderRadius: 14, objectFit: 'cover', border: '1px solid var(--color-border)' }}
                                                    />
                                                ) : (
                                                    <div style={{ width: 64, height: 64, borderRadius: 14, border: '1px dashed var(--color-border)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--color-text-muted)' }}>
                                                        <ImagePlus className="w-5 h-5" />
                                                    </div>
                                                )}
                                                <div className="flex flex-col gap-2">
                                                    <button type="button" className="btn btn-secondary btn-sm" style={{ width: 'fit-content' }} onClick={() => setPickerMode('icon')}>
                                                        <ImagePlus className="w-4 h-4" />
                                                        {t('pwa.pickIcon')}
                                                    </button>
                                                    {(config.icon_url || config.icon) && (
                                                        <button
                                                            type="button"
                                                            className="btn btn-ghost btn-sm text-xs"
                                                            style={{ width: 'fit-content', color: 'var(--color-danger)' }}
                                                            onClick={() => set('icon_url', '')}
                                                        >
                                                            {t('common.delete')}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </Field>
                                        <Field label={t('pwa.screens')} hint={t('pwa.screensHint')}>
                                            <div className="flex flex-wrap gap-2 mb-2">
                                                {(config.screens || []).filter(Boolean).map((shot, i) => (
                                                    <span key={`${shot}-${i}`} className="text-xs px-2 py-1 rounded flex items-center gap-1" style={{ background: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                                        {String(shot).startsWith('/') ? (
                                                            <img src={shot} alt="" style={{ width: 22, height: 22, borderRadius: 4, objectFit: 'cover' }} />
                                                        ) : shot}
                                                        <button type="button" onClick={() => set('screens', config.screens.filter((_, j) => j !== i))}>
                                                            <X className="w-3 h-3" />
                                                        </button>
                                                    </span>
                                                ))}
                                            </div>
                                            <button type="button" className="btn btn-secondary btn-sm" style={{ width: 'fit-content' }} onClick={() => setPickerMode('screens')}>
                                                <ImagePlus className="w-4 h-4" />
                                                {t('pwa.pickScreens')}
                                            </button>
                                        </Field>
                                    </div>
                                </Section>

                                <Section title={t('pwa.sectionContent')}>
                                    <div className="flex flex-col gap-4">
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <Field label={t('pwa.buttonText')}>
                                                <input className="form-input" value={config.button_text} onChange={(e) => set('button_text', e.target.value)} />
                                            </Field>
                                            <Field label={t('pwa.downloads')}>
                                                <select className="form-select" value={config.downloads} onChange={(e) => set('downloads', e.target.value)}>
                                                    {DOWNLOADS.map((d) => <option key={d} value={d}>{d}</option>)}
                                                </select>
                                            </Field>
                                            <Field label={t('pwa.version')}>
                                                <input className="form-input" value={config.version} onChange={(e) => set('version', e.target.value)} />
                                            </Field>
                                        </div>
                                        <Field label={t('pwa.adsLabel')}>
                                            <select className="form-select" value={config.ads_label} onChange={(e) => set('ads_label', e.target.value)}>
                                                <option value="Contains ads">Contains ads</option>
                                                <option value="No ads">No ads</option>
                                                <option value="Contains ads · In-app purchases">Contains ads · In-app purchases</option>
                                            </select>
                                        </Field>
                                        <Field label={t('pwa.description')} hint={t('pwa.descriptionHint')}>
                                            <textarea className="form-input" rows={4} value={config.description} onChange={(e) => set('description', e.target.value)} />
                                        </Field>
                                        <Field label={t('pwa.tags')} hint={t('pwa.tagsHint')}>
                                            <input
                                                className="form-input"
                                                value={(config.tags || []).join(', ')}
                                                onChange={(e) => set('tags', e.target.value.split(',').map((s) => s.trim()).filter(Boolean))}
                                            />
                                        </Field>
                                        <div>
                                            <div className="form-label">{t('pwa.rating')} — {t('pwa.ratingAvg')}: {ratingAvg((config.rating_counts || []).map(Number))}</div>
                                            <div className="grid grid-cols-5 gap-2 mt-1">
                                                {[5, 4, 3, 2, 1].map((star) => (
                                                    <div key={star} className="flex flex-col items-center gap-1">
                                                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{star}★</span>
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            className="form-input"
                                                            value={(config.rating_counts || [])[star - 1] ?? 0}
                                                            onChange={(e) => {
                                                                const counts = [...(config.rating_counts || [0, 0, 0, 0, 0])];
                                                                counts[star - 1] = Math.max(0, parseInt(e.target.value || '0', 10));
                                                                set('rating_counts', counts);
                                                            }}
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                        <div>
                                            <Toggle label={t('pwa.whatsNew')} checked={config.whats_new_enabled} onChange={(v) => set('whats_new_enabled', v)} />
                                        </div>
                                        {config.whats_new_enabled && (
                                            <textarea className="form-input" rows={2} placeholder={t('pwa.whatsNewText')} value={config.whats_new_text} onChange={(e) => set('whats_new_text', e.target.value)} />
                                        )}
                                    </div>
                                </Section>

                                <Section title={t('pwa.sectionCustomScripts')}>
                                    <div className="space-y-4">
                                        <Field label={t('pwa.customCssLabel')} hint={t('pwa.customCssHint')}>
                                            <textarea
                                                className="form-input font-mono text-xs"
                                                rows={4}
                                                placeholder={t('pwa.customCssPlaceholder')}
                                                value={config.custom_css || ''}
                                                onChange={(e) => set('custom_css', e.target.value)}
                                                style={{ whiteSpace: 'pre', tabSize: 2 }}
                                            />
                                        </Field>
                                        <Field label={t('pwa.customHeadLabel')}>
                                            <textarea
                                                className="form-input font-mono text-xs"
                                                rows={4}
                                                placeholder={t('pwa.customHeadPlaceholder')}
                                                value={config.custom_head_code || ''}
                                                onChange={(e) => set('custom_head_code', e.target.value)}
                                                style={{ whiteSpace: 'pre', tabSize: 2 }}
                                            />
                                        </Field>
                                        <Field label={t('pwa.customJsGlobalLabel')}>
                                            <textarea
                                                className="form-input font-mono text-xs"
                                                rows={4}
                                                placeholder={t('pwa.customJsGlobalPlaceholder')}
                                                value={config.custom_js || ''}
                                                onChange={(e) => set('custom_js', e.target.value)}
                                                style={{ whiteSpace: 'pre', tabSize: 2 }}
                                            />
                                        </Field>
                                    </div>
                                </Section>
                            </>
                        )}

                        {/* ============ STEP 3 — Reviews ============ */}
                        {step === 2 && (
                            <Section title={t('pwa.sectionComments')}>
                                <span className="text-xs block mb-3" style={{ color: 'var(--color-text-muted)' }}>{t('pwa.dragHint')}</span>
                                <div className="flex flex-col gap-3">
                                    {(config.comments || []).map((cm, idx) => (
                                        <div
                                            key={idx}
                                            draggable
                                            onDragStart={(e) => {
                                                setDragIdx(idx);
                                                e.dataTransfer.effectAllowed = 'move';
                                            }}
                                            onDragOver={(e) => {
                                                e.preventDefault();
                                                if (overIdx !== idx) setOverIdx(idx);
                                            }}
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                if (dragIdx !== null) reorderComments(dragIdx, idx);
                                                resetDrag();
                                            }}
                                            onDragEnd={resetDrag}
                                            style={{
                                                border: '1px solid var(--color-border)',
                                                borderTopWidth: overIdx === idx && dragIdx !== null && dragIdx !== idx ? 3 : 1,
                                                borderTopColor: overIdx === idx && dragIdx !== null && dragIdx !== idx ? 'var(--color-primary)' : 'var(--color-border)',
                                                borderRadius: 12,
                                                padding: 12,
                                                opacity: dragIdx === idx ? 0.5 : 1,
                                                background: dragIdx === idx ? 'var(--color-bg-card)' : 'transparent',
                                            }}
                                        >
                                            <div className="flex items-center gap-2 mb-2">
                                                <span className="cursor-grab" title={t('pwa.dragHint')}>
                                                    <GripVertical className="w-4 h-4 pointer-events-none" style={{ color: 'var(--color-text-muted)' }} />
                                                </span>
                                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>#{idx + 1}</span>
                                            </div>
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-2 mb-2">
                                                <input className="form-input" placeholder={t('pwa.commentName')} value={cm.name} onChange={(e) => updateComment(idx, 'name', e.target.value)} />
                                                <input className="form-input" placeholder={t('pwa.commentDate')} value={cm.date} onChange={(e) => updateComment(idx, 'date', e.target.value)} />
                                                <select className="form-select" value={cm.stars} onChange={(e) => updateComment(idx, 'stars', parseInt(e.target.value, 10))}>
                                                    {[5, 4, 3, 2, 1].map((s) => <option key={s} value={s}>{s}★</option>)}
                                                </select>
                                                <div className="flex gap-2">
                                                    <input type="number" min="0" className="form-input" placeholder="♡" value={cm.likes} onChange={(e) => updateComment(idx, 'likes', parseInt(e.target.value || '0', 10))} />
                                                    <button type="button" className="btn btn-ghost btn-icon" onClick={() => removeComment(idx)} title={t('common.delete')}>
                                                        <Trash2 className="w-4 h-4" style={{ color: 'var(--color-danger)' }} />
                                                    </button>
                                                </div>
                                            </div>
                                            <textarea className="form-input" rows={2} placeholder={t('pwa.commentText')} value={cm.text} onChange={(e) => updateComment(idx, 'text', e.target.value)} />
                                            <input className="form-input mt-2" placeholder={t('pwa.commentReply')} value={cm.reply} onChange={(e) => updateComment(idx, 'reply', e.target.value)} />
                                        </div>
                                    ))}
                                    <button type="button" className="btn btn-secondary text-sm" style={{ width: 'fit-content' }} onClick={addComment}>
                                        <Plus /> {t('pwa.addComment')}
                                    </button>
                                </div>
                            </Section>
                        )}

                        {/* Step navigation */}
                        <div className="flex items-center justify-between" style={{ padding: '16px 0' }}>
                            <button
                                type="button"
                                className="btn btn-secondary text-sm"
                                onClick={() => goToStep(step - 1)}
                                disabled={step === 0}
                                style={{ visibility: step === 0 ? 'hidden' : 'visible' }}
                            >
                                {t('pwa.back')}
                            </button>
                            <button
                                type="button"
                                className="btn btn-primary text-sm"
                                onClick={handleNext}
                                disabled={step === 2}
                                style={stepValid(step) ? undefined : { opacity: 0.55 }}
                            >
                                {t('pwa.next')}
                            </button>
                        </div>
                    </div>

                    {/* Live preview pane (desktop) */}
                    <div
                        className="hidden lg:flex flex-col"
                        style={{
                            width: '400px',
                            flex: 'none',
                            borderLeft: '1px solid var(--color-border)',
                            background: 'var(--color-bg-card)',
                            padding: '16px',
                            gap: 10,
                        }}
                    >
                        <div className="flex flex-col gap-2">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-semibold">{t('pwa.preview')}</span>
                                <div className="flex rounded-lg overflow-hidden" style={{ border: '1px solid var(--color-border)' }}>
                                    {['auto', 'ios'].map((p) => (
                                        <button
                                            key={p}
                                            type="button"
                                            onClick={() => setPreviewPlatform(p)}
                                            className="text-xs"
                                            style={{
                                                padding: '4px 10px',
                                                background: previewPlatform === p ? 'var(--color-primary)' : 'transparent',
                                                color: previewPlatform === p ? 'var(--color-text-inverse)' : 'var(--color-text-muted)',
                                                fontWeight: previewPlatform === p ? 600 : 400,
                                            }}
                                        >
                                            {p === 'auto' ? 'Android' : 'iOS'}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {config.app_action === 'screen' && (
                                <div className="flex rounded-lg overflow-hidden self-end" style={{ border: '1px solid var(--color-border)' }}>
                                    {[
                                        { id: 'store', label: t('pwa.previewStore') || 'Store' },
                                        { id: 'screen', label: t('pwa.previewApp') || 'App screen' }
                                    ].map((v) => {
                                        const effectiveView = previewView !== 'auto'
                                            ? previewView
                                            : ((step === 1 && config.app_action === 'screen') ? 'screen' : 'store');
                                        const isActive = effectiveView === v.id;
                                        return (
                                            <button
                                                key={v.id}
                                                type="button"
                                                onClick={() => setPreviewView(v.id)}
                                                className="text-xs"
                                                style={{
                                                    padding: '3px 9px',
                                                    background: isActive ? 'var(--color-primary)' : 'transparent',
                                                    color: isActive ? 'var(--color-text-inverse)' : 'var(--color-text-muted)',
                                                    fontWeight: isActive ? 600 : 400,
                                                }}
                                            >
                                                {v.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                        <div
                            className="flex justify-center"
                            style={{ flex: 1, minHeight: 0, overflow: 'hidden' }}
                        >
                            <div
                                style={{
                                    width: '100%',
                                    maxWidth: '340px',
                                    borderRadius: 28,
                                    border: '8px solid #1c1c1e',
                                    background: '#000',
                                    overflow: 'hidden',
                                    position: 'relative',
                                    boxShadow: '0 12px 32px rgba(0,0,0,.25)',
                                }}
                            >
                                <div style={{ height: 24, background: '#1c1c1e', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                    <span style={{ width: 80, height: 6, borderRadius: 3, background: '#3a3a3c' }} />
                                </div>
                                <div style={{ position: 'relative', height: 'calc(100% - 24px)', background: '#fff' }}>
                                    {previewHtml ? (
                                        <iframe
                                            title="PWA preview"
                                            srcDoc={previewHtml}
                                            sandbox="allow-scripts"
                                            className="w-full h-full border-0"
                                        />
                                    ) : (
                                        <div className="w-full h-full flex items-center justify-center text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                            …
                                        </div>
                                    )}
                                    {previewLoading && (
                                        <div className="absolute top-2 right-2">
                                            <Loader2 className="w-4 h-4 animate-spin" style={{ color: 'var(--color-primary)' }} />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="modal-footer" style={{ borderTop: '1px solid var(--color-border)', padding: '12px 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12 }}>
                    <div className="flex items-center gap-2 text-sm">
                        {saving && (
                            <span className="flex items-center gap-2" style={{ color: 'var(--color-text-muted)' }}>
                                <Loader2 className="w-4 h-4 animate-spin" />
                                {t('pwa.saving')}
                            </span>
                        )}
                        {!saving && previewUrl && (
                            <a href={previewUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1" style={{ color: 'var(--color-primary)' }}>
                                <ExternalLink className="w-4 h-4" />
                                {t('pwa.openPreview')}
                            </a>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="btn btn-secondary" onClick={handleClose} disabled={saving}>
                            {t('common.cancel')}
                        </button>
                        <button type="button" className="btn btn-primary" onClick={handleSave} disabled={saving}>
                            {t('common.save')}
                        </button>
                    </div>
                </div>

                <MediaPicker
                    open={pickerMode !== null}
                    onClose={() => setPickerMode(null)}
                    onSelect={onMediaPicked}
                    multiple={pickerMode === 'screens'}
                    sizeContract={pickerMode === 'icon' ? { width: 512, height: 512, crop: true, label: '512×512' } : null}
                />
            </div>
        </div>
    );
}
