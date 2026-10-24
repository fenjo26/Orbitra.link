import React, { useState, useEffect, useMemo } from 'react';
import { Save, Puzzle, MessageSquareWarning, Image as ImageIcon, Trash2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import MediaPicker from './common/MediaPicker';

const API_URL = '/api.php';

const VERTICALS = ['', 'nutra', 'gambling', 'betting', 'ecom', 'finance', 'crypto'];
const DESIGNS = ['card', 'gift', 'alert'];
const ANIMATIONS = ['none', 'fade', 'zoom', 'slide'];
const POPUP_LANGS = ['en', 'ru', 'es', 'pt', 'fr', 'de', 'it', 'pl', 'tr', 'uk', 'zh'];

// The accent popup.js falls back to per design when the operator sets none.
const DESIGN_ACCENT = { card: '#4f46e5', gift: '#e11d48', alert: '#ea580c' };

const DEFAULTS = {
    popup_enabled: '0',
    popup_vertical: '',
    popup_delay: '12',
    popup_require_activity: '1',
    popup_frequency_hours: '24',
    popup_scroll_depth: '0',
    popup_exit_intent: '0',
    popup_designs: 'card,gift,alert',
    popup_image: '',
    popup_amount: '',
    popup_languages: '',
    popup_animation: 'zoom',
    popup_position: 'center',
    popup_accent: '',
    popup_lang_mode: 'auto',
    popup_lang: 'en',
    popup_custom_texts: '',
};

// Settings → Plugins "where it works" table. Order = how common the setup is.
const WHERE_ROWS = [
    { key: 'landing', tone: 'ok' },
    { key: 'script', tone: 'ok' },
    { key: 'kclientPhp', tone: 'warn', code: '<?php echo $client->popupScript(); ?>' },
    { key: 'redirect', tone: 'no' },
];

const PopupSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });
    const [settings, setSettings] = useState({ ...DEFAULTS });
    const [pickerOpen, setPickerOpen] = useState(false);
    const [custom, setCustom] = useState({ title: '', text: '', button: '' });

    useEffect(() => {
        fetch(`${API_URL}?action=global_settings`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.data) {
                    setSettings(prev => ({ ...prev, ...data.data }));
                    // Custom texts live as one JSON string in the settings row;
                    // the form edits them as three plain fields.
                    try {
                        const parsed = JSON.parse(data.data.popup_custom_texts || '{}');
                        if (parsed && typeof parsed === 'object') {
                            setCustom({
                                title: parsed.title || '',
                                text: parsed.text || '',
                                button: parsed.button || '',
                            });
                        }
                    } catch { /* empty override stays empty */ }
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, []);

    const set = (name, value) => setSettings(prev => ({ ...prev, [name]: value }));

    const setCustomField = (field, value) => setCustom(prev => ({ ...prev, [field]: value }));

    const handleSave = async () => {
        setSaving(true);
        setMessage({ text: '', type: '' });
        // Only non-empty override fields travel; an all-empty form saves ''
        // and the dictionary takes over again.
        const customFilled = custom.title.trim() || custom.text.trim() || custom.button.trim();
        const customJson = customFilled
            ? JSON.stringify(Object.fromEntries(
                Object.entries(custom).filter(([, v]) => String(v).trim() !== '')
            ))
            : '';
        try {
            const res = await fetch(`${API_URL}?action=global_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    settings: {
                        ...settings,
                        popup_custom_texts: customJson,
                    }
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('popup.saveSuccess'), type: 'success' });
                setSettings(prev => ({ ...prev, popup_custom_texts: customJson }));
            } else {
                const codeText = data.code === 'invalid_popup_custom_texts' ? t('popup.errCustomTexts') : '';
                setMessage({ text: codeText || data.message || t('popup.saveError'), type: 'error' });
            }
        } catch {
            setMessage({ text: t('popup.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    const enabled = settings.popup_enabled === '1';
    const designs = useMemo(
        () => String(settings.popup_designs || 'card').split(',').filter(d => DESIGNS.includes(d)),
        [settings.popup_designs]
    );
    const previewDesign = designs[0] || 'card';
    const previewAccent = settings.popup_accent || DESIGN_ACCENT[previewDesign];

    const sample = useMemo(() => {
        const vertical = settings.popup_vertical || 'neutral';
        // The panel language when it is among the popup dictionary's, else EN:
        // the preview is a sample of the look, not a mirror of visitor GEO.
        const amount = settings.popup_amount || '100';
        const cur = '$';
        return {
            title: custom.title || t(`popup.sample.${vertical}.title`).replace('{amount}', amount).replace('{currency}', cur),
            text: custom.text || t(`popup.sample.${vertical}.text`).replace('{amount}', amount).replace('{currency}', cur),
            button: custom.button || t(`popup.sample.${vertical}.button`),
        };
    }, [settings.popup_vertical, settings.popup_amount, custom, t]);

    if (loading) {
        return (
            <div className="page-card">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
            </div>
        );
    }

    const inputStyle = { maxWidth: 120 };

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <Puzzle size={18} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('popup.title')}</h3>
                </div>
            </div>

            <div style={{ marginTop: '20px', display: 'flex', gap: '28px', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 460px', minWidth: 320 }}>
                    {message.text && (
                        <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: '16px' }}>
                            {message.text}
                        </div>
                    )}

                    {/* Where it works — the four ways a campaign can meet a visitor,
                        each with what the operator has to do. A beginner reading
                        this should know whether their campaign will show it. */}
                    <div className="alert" style={{ marginBottom: '20px', display: 'flex', gap: '10px', background: 'var(--color-bg-secondary)' }}>
                        <MessageSquareWarning size={18} style={{ flexShrink: 0, marginTop: 2, color: 'var(--color-warning)' }} />
                        <div style={{ fontSize: '13px', color: 'var(--color-text-secondary)', flex: 1, minWidth: 0 }}>
                            <div style={{ fontWeight: 600, color: 'var(--color-text)', marginBottom: '8px' }}>{t('popup.where.title')}</div>
                            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', columnGap: '16px', rowGap: '6px', alignItems: 'baseline' }}>
                                {WHERE_ROWS.map(row => (
                                    <React.Fragment key={row.key}>
                                        <span>{t(`popup.where.${row.key}`)}</span>
                                        <span style={{ fontWeight: 500, whiteSpace: 'nowrap', color: row.tone === 'ok' ? 'var(--color-success)' : row.tone === 'no' ? 'var(--color-danger)' : 'var(--color-warning)' }}>
                                            {t(`popup.where.${row.key}Status`)}
                                        </span>
                                        {row.code && (
                                            <code style={{ gridColumn: '1 / -1', fontSize: '12px', padding: '4px 8px', borderRadius: '6px', background: 'var(--color-bg)', border: '1px solid var(--color-border)', overflowX: 'auto', whiteSpace: 'nowrap', marginTop: '-2px' }}>
                                                {row.code}
                                            </code>
                                        )}
                                    </React.Fragment>
                                ))}
                            </div>
                            <div style={{ marginTop: '8px', color: 'var(--color-text-muted)', fontSize: '12px' }}>{t('popup.where.bots')}</div>
                        </div>
                    </div>

                    <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer', marginBottom: '20px' }}>
                        <input
                            type="checkbox"
                            checked={enabled}
                            onChange={e => set('popup_enabled', e.target.checked ? '1' : '0')}
                            style={{ marginTop: '2px' }}
                        />
                        <div>
                            <span style={{ fontWeight: 500 }}>{t('popup.enable')}</span>
                            <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', margin: '2px 0 0 0' }}>
                                {t('popup.enableDesc')}
                            </p>
                        </div>
                    </label>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: '20px', paddingLeft: enabled ? '28px' : 0, borderLeft: enabled ? '2px solid var(--color-primary-light)' : 'none', transition: 'all .2s' }}>
                        {enabled && (<>
                            <div>
                                <label className="form-label">{t('popup.vertical')}</label>
                                <select
                                    className="form-select"
                                    value={settings.popup_vertical}
                                    onChange={e => set('popup_vertical', e.target.value)}
                                    style={{ maxWidth: 320 }}
                                >
                                    {VERTICALS.map(v => (
                                        <option key={v} value={v}>{v === '' ? t('popup.verticalNeutral') : t(`popup.vertical_${v}`)}</option>
                                    ))}
                                </select>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                    {t('popup.verticalHint')}
                                </p>
                            </div>

                            <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
                                <div>
                                    <label className="form-label">{t('popup.delay')}</label>
                                    <input
                                        type="number" min="1" max="300" className="form-input"
                                        style={inputStyle}
                                        value={settings.popup_delay}
                                        onChange={e => set('popup_delay', e.target.value)}
                                    />
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>1–300 s</p>
                                </div>
                                <div>
                                    <label className="form-label">{t('popup.frequency')}</label>
                                    <input
                                        type="number" min="0" max="720" className="form-input"
                                        style={inputStyle}
                                        value={settings.popup_frequency_hours}
                                        onChange={e => set('popup_frequency_hours', e.target.value)}
                                    />
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                        {t('popup.frequencyHint')}
                                    </p>
                                </div>
                                <div>
                                    <label className="form-label">{t('popup.scrollDepth')}</label>
                                    <input
                                        type="number" min="0" max="90" className="form-input"
                                        style={inputStyle}
                                        value={settings.popup_scroll_depth}
                                        onChange={e => set('popup_scroll_depth', e.target.value)}
                                    />
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>0–90 %, 0 = {t('popup.off')}</p>
                                </div>
                            </div>

                            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '10px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        checked={settings.popup_require_activity === '1'}
                                        onChange={e => set('popup_require_activity', e.target.checked ? '1' : '0')}
                                    />
                                    <span>{t('popup.requireActivity')}</span>
                                </label>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '10px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        checked={settings.popup_exit_intent === '1'}
                                        onChange={e => set('popup_exit_intent', e.target.checked ? '1' : '0')}
                                    />
                                    <span>{t('popup.exitIntent')} <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>— {t('popup.exitIntentHint')}</span></span>
                                </label>
                            </div>

                            <div>
                                <label className="form-label">{t('popup.designs')}</label>
                                <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
                                    {DESIGNS.map(d => (
                                        <label key={d} style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer' }}>
                                            <input
                                                type="checkbox"
                                                checked={designs.includes(d)}
                                                onChange={e => {
                                                    const next = e.target.checked
                                                        ? [...designs, d]
                                                        : designs.filter(x => x !== d);
                                                    set('popup_designs', (next.length ? next : [d]).join(','));
                                                }}
                                            />
                                            <span>{t(`popup.design_${d}`)}</span>
                                        </label>
                                    ))}
                                </div>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                    {t('popup.designsHint')}
                                </p>
                            </div>

                            <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
                                <div>
                                    <label className="form-label">{t('popup.animation')}</label>
                                    <select className="form-select" style={{ maxWidth: 160 }}
                                        value={settings.popup_animation}
                                        onChange={e => set('popup_animation', e.target.value)}
                                    >
                                        {ANIMATIONS.map(a => <option key={a} value={a}>{t(`popup.anim_${a}`)}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('popup.position')}</label>
                                    <select className="form-select" style={{ maxWidth: 160 }}
                                        value={settings.popup_position}
                                        onChange={e => set('popup_position', e.target.value)}
                                    >
                                        <option value="center">{t('popup.pos_center')}</option>
                                        <option value="bottom">{t('popup.pos_bottom')}</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('popup.accent')}</label>
                                    <input
                                        type="color" className="form-input" style={{ width: 60, height: 38, padding: 2, cursor: 'pointer' }}
                                        value={settings.popup_accent || DESIGN_ACCENT[previewDesign]}
                                        onChange={e => set('popup_accent', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap' }}>
                                <div>
                                    <label className="form-label">{t('popup.langMode')}</label>
                                    <select className="form-select" style={{ maxWidth: 200 }}
                                        value={settings.popup_lang_mode}
                                        onChange={e => set('popup_lang_mode', e.target.value)}
                                    >
                                        <option value="auto">{t('popup.langAuto')}</option>
                                        <option value="forced">{t('popup.langForced')}</option>
                                    </select>
                                </div>
                                {settings.popup_lang_mode === 'forced' && (
                                    <div>
                                        <label className="form-label">{t('popup.lang')}</label>
                                        <select className="form-select" style={{ maxWidth: 120 }}
                                            value={settings.popup_lang}
                                            onChange={e => set('popup_lang', e.target.value)}
                                        >
                                            {POPUP_LANGS.map(l => <option key={l} value={l}>{l.toUpperCase()}</option>)}
                                        </select>
                                    </div>
                                )}
                                <div style={{ flex: '1 1 200px' }}>
                                    <label className="form-label">{t('popup.languages')}</label>
                                    <input
                                        type="text" className="form-input"
                                        placeholder="en, es, pt-BR"
                                        value={settings.popup_languages}
                                        onChange={e => set('popup_languages', e.target.value)}
                                    />
                                    <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                        {t('popup.languagesHint')}
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label className="form-label">{t('popup.amount')}</label>
                                <input
                                    type="text" className="form-input" style={inputStyle}
                                    placeholder={t('popup.amountPh')}
                                    value={settings.popup_amount}
                                    onChange={e => set('popup_amount', e.target.value)}
                                />
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                    {t('popup.amountHint')}
                                </p>
                            </div>

                            <div>
                                <label className="form-label">{t('popup.image')}</label>
                                <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                                    <button className="btn btn-secondary" onClick={() => setPickerOpen(true)}>
                                        <ImageIcon size={16} />
                                        {settings.popup_image ? t('popup.replaceImage') : t('popup.pickImage')}
                                    </button>
                                    {settings.popup_image && (
                                        <>
                                            <code style={{ fontSize: 12, color: 'var(--color-text-muted)', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                {settings.popup_image}
                                            </code>
                                            <button className="btn btn-secondary" onClick={() => set('popup_image', '')} title={t('popup.clearImage')}>
                                                <Trash2 size={16} />
                                            </button>
                                        </>
                                    )}
                                </div>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                    {t('popup.imageHint')}
                                </p>
                            </div>

                            <div>
                                <label className="form-label">{t('popup.customTexts')}</label>
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxWidth: 480 }}>
                                    <input className="form-input" placeholder={t('popup.customTitle')} style={{ maxWidth: 320 }}
                                        value={custom.title} onChange={e => setCustomField('title', e.target.value)} />
                                    <input className="form-input" placeholder={t('popup.customText')}
                                        value={custom.text} onChange={e => setCustomField('text', e.target.value)} />
                                    <input className="form-input" placeholder={t('popup.customButton')} style={{ maxWidth: 220 }}
                                        value={custom.button} onChange={e => setCustomField('button', e.target.value)} />
                                </div>
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                    {t('popup.customTextsHint')}
                                </p>
                            </div>
                        </>)}
                    </div>

                    <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'flex-end' }}>
                        <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                            <Save size={18} />
                            {saving ? t('common.saving') : t('common.save')}
                        </button>
                    </div>
                </div>

                {enabled && (
                    <div style={{ flex: '0 0 340px' }}>
                        <p style={{ fontSize: '12px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--color-text-muted)', margin: '0 0 10px' }}>
                            {t('popup.preview')}
                        </p>
                        <div
                            style={{
                                position: 'relative', borderRadius: 14, padding: '28px 16px', background:
                                    'repeating-linear-gradient(45deg, var(--color-bg-secondary), var(--color-bg-secondary) 10px, transparent 10px, transparent 20px)',
                                display: 'flex', justifyContent: 'center',
                            }}
                        >
                            <div
                                key={previewDesign}
                                style={{
                                    width: 280, background: '#fff', borderRadius: previewDesign === 'alert' ? 14 : 20,
                                    border: previewDesign === 'alert' ? `3px solid ${previewAccent}` : 'none',
                                    padding: '20px 16px 16px', textAlign: 'center', color: '#0f172a',
                                    boxShadow: '0 18px 44px rgba(2,6,23,.28)',
                                    fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif',
                                    animation: settings.popup_animation === 'none' ? 'none' : undefined,
                                }}
                            >
                                {/* Same picture the visitor gets: the Gallery pick, or the
                                    vertical's preset photo (assets/popup-presets). */}
                                <img
                                    src={settings.popup_image || `/assets/popup-presets/${settings.popup_vertical || 'neutral'}.webp`}
                                    alt=""
                                    style={{ display: 'block', width: '100%', height: 140, objectFit: 'cover', borderRadius: 12, margin: '0 0 12px', background: '#f1f5f9' }}
                                />
                                <div style={{ fontSize: 16, fontWeight: 800, lineHeight: 1.25, marginBottom: 6 }}>{sample.title}</div>
                                <div style={{ fontSize: 12.5, lineHeight: 1.45, color: '#475569', marginBottom: 14 }}>{sample.text}</div>
                                <div style={{
                                    padding: '11px 12px', borderRadius: 11, color: '#fff', fontWeight: 700, fontSize: 14,
                                    background: `linear-gradient(135deg, ${previewAccent}, ${previewAccent}cc)`,
                                }}>
                                    {sample.button}
                                </div>
                                <div style={{ marginTop: 10, fontSize: 11, color: '#94a3b8' }}>
                                    {t(`popup.design_${previewDesign}`)} · {settings.popup_animation} · {settings.popup_position === 'bottom' ? t('popup.pos_bottom') : t('popup.pos_center')}
                                </div>
                            </div>
                        </div>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '10px' }}>
                            {t('popup.previewHint')}
                        </p>
                    </div>
                )}
            </div>

            <MediaPicker
                open={pickerOpen}
                onClose={() => setPickerOpen(false)}
                onSelect={asset => {
                    set('popup_image', asset.url || '');
                    setPickerOpen(false);
                }}
            />
        </div>
    );
};

export default PopupSettings;
