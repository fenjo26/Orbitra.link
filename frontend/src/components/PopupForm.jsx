import React, { useMemo, useState } from 'react';
import { Check, ChevronDown, ChevronUp, Image as ImageIcon, RotateCcw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import MediaPicker from './common/MediaPicker';
import { POPUP_VERTICALS, POPUP_DEFAULTS, presetImage } from '../utils/popupDefaults';

/*
 * One popup's settings form — shared by the campaign editor's Popup tab and
 * the template in Settings → Plugins. `value` is the flat popup_* string map
 * the server stores (campaigns.popup_config / settings rows); `onChange`
 * receives a partial patch. Everything a beginner needs is on top (template,
 * text, picture, timing); the rest sits under "More settings".
 */

const DESIGNS = ['card', 'gift', 'alert'];
const ANIMATIONS = ['none', 'fade', 'zoom', 'slide'];
const POPUP_LANGS = ['en', 'ru', 'es', 'pt', 'fr', 'de', 'it', 'pl', 'tr', 'uk', 'zh'];
const FREQUENCIES = ['0', '1', '6', '24', '168'];
// Same defaults popup.js uses; money verticals quote dollars, the rest a percentage.
const DEFAULT_AMOUNT = { neutral: 100, gambling: 250, betting: 100, nutra: 50, ecom: 40, finance: 3000, crypto: 100 };
const MONEY = { neutral: true, gambling: true, betting: true, finance: true };
const DESIGN_ACCENT = { card: '#4f46e5', gift: '#e11d48', alert: '#ea580c' };



function parseTexts(json) {
    try {
        const v = JSON.parse(json || '{}');
        return v && typeof v === 'object' ? v : {};
    } catch {
        return {};
    }
}

const sectionTitle = { fontSize: 13, fontWeight: 600, color: 'var(--color-text)', margin: '0 0 10px' };
const hint = { fontSize: 12, color: 'var(--color-text-muted)', margin: '6px 0 0', lineHeight: 1.45 };

/** The popup as the visitor would see it (panel language, dollars). */
export function PopupPreview({ value }) {
    const { t } = useLanguage();
    const v = { ...POPUP_DEFAULTS, ...(value || {}) };
    const vertical = v.popup_vertical || 'neutral';
    const texts = parseTexts(v.popup_custom_texts);
    const design = String(v.popup_designs || 'card').split(',').find(d => DESIGNS.includes(d)) || 'card';
    const accent = v.popup_accent || DESIGN_ACCENT[design];
    const amount = String(v.popup_amount || DEFAULT_AMOUNT[vertical]);
    const fill = (s) => String(s || '').replaceAll('{amount}', amount).replaceAll('{currency}', '$').replaceAll('{country}', 'USA');
    const title = fill(texts.title || t(`popup.sample.${vertical}.title`));
    const text = fill(texts.text || t(`popup.sample.${vertical}.text`));
    const button = fill(texts.button || t(`popup.sample.${vertical}.button`));

    return (
        <div style={{
            borderRadius: 14, padding: '22px 14px', display: 'flex', justifyContent: 'center',
            background: 'repeating-linear-gradient(45deg, var(--color-bg-secondary), var(--color-bg-secondary) 10px, transparent 10px, transparent 20px)',
        }}>
            <div style={{
                width: '100%', maxWidth: 300, background: '#fff', color: '#0f172a', textAlign: 'center',
                borderRadius: design === 'alert' ? 14 : 20, border: design === 'alert' ? `3px solid ${accent}` : 'none',
                padding: 16, boxShadow: '0 18px 44px rgba(2,6,23,.28)',
                fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif',
            }}>
                <img src={v.popup_image || presetImage(v.popup_vertical)} alt=""
                    style={{ display: 'block', width: '100%', height: 130, objectFit: 'cover', borderRadius: 12, margin: '0 0 12px', background: '#f1f5f9' }} />
                <div style={{ fontSize: 16, fontWeight: 800, lineHeight: 1.25, marginBottom: 6, overflowWrap: 'anywhere' }}>{title}</div>
                <div style={{ fontSize: 12.5, lineHeight: 1.45, color: '#475569', marginBottom: 14, overflowWrap: 'anywhere' }}>{text}</div>
                <div style={{
                    padding: '11px 12px', borderRadius: 11, color: '#fff', fontWeight: 700, fontSize: 14,
                    background: `linear-gradient(135deg, ${accent}, ${accent}cc)`, overflowWrap: 'anywhere',
                }}>{button}</div>
            </div>
        </div>
    );
}

export default function PopupForm({ value, onChange }) {
    const { t } = useLanguage();
    const v = { ...POPUP_DEFAULTS, ...(value || {}) };
    const [moreOpen, setMoreOpen] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const vertical = v.popup_vertical || 'neutral';
    const texts = useMemo(() => parseTexts(v.popup_custom_texts), [v.popup_custom_texts]);
    const designs = String(v.popup_designs || '').split(',').filter(d => DESIGNS.includes(d));

    const set = (key, val) => onChange({ [key]: val });
    const setText = (field, val) => {
        const next = { ...texts, [field]: val };
        Object.keys(next).forEach(k => { if (!String(next[k] || '').trim()) delete next[k]; });
        set('popup_custom_texts', Object.keys(next).length ? JSON.stringify(next) : '');
    };
    const toggleDesign = (d) => {
        const next = designs.includes(d) ? designs.filter(x => x !== d) : [...designs, d];
        if (next.length) set('popup_designs', DESIGNS.filter(x => next.includes(x)).join(','));
    };
    const sample = (field) => t(`popup.sample.${vertical}.${field}`)
        .replaceAll('{amount}', String(v.popup_amount || DEFAULT_AMOUNT[vertical]))
        .replaceAll('{currency}', '$');

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
            {/* 1. Template */}
            <section>
                <p style={sectionTitle}>{t('popup.stepTemplate')}</p>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(96px, 1fr))', gap: 8 }}>
                    {POPUP_VERTICALS.map(vert => {
                        const active = (v.popup_vertical || '') === vert;
                        return (
                            <button key={vert || 'neutral'} type="button" onClick={() => set('popup_vertical', vert)}
                                aria-pressed={active}
                                style={{
                                    position: 'relative', padding: 6, borderRadius: 12, cursor: 'pointer', textAlign: 'center',
                                    border: active ? '2px solid var(--color-primary)' : '1px solid var(--color-border)',
                                    background: active ? 'var(--color-primary-light, var(--color-bg-secondary))' : 'var(--color-bg-card)',
                                }}>
                                <img src={presetImage(vert)} alt="" style={{ display: 'block', width: '100%', aspectRatio: '1 / 1', objectFit: 'cover', borderRadius: 8 }} />
                                <span style={{ display: 'block', marginTop: 6, fontSize: 12, fontWeight: 500, color: 'var(--color-text)', lineHeight: 1.2 }}>
                                    {t(`popup.vertical_${vert || 'neutral'}`)}
                                </span>
                                {active && (
                                    <span style={{ position: 'absolute', top: 10, right: 10, width: 20, height: 20, borderRadius: '50%', background: 'var(--color-primary)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <Check size={13} />
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </section>

            {/* 2. Text & amount */}
            <section>
                <p style={sectionTitle}>{t('popup.stepText')}</p>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    <div>
                        <label className="form-label">{t('popup.titleLabel')}</label>
                        <input className="form-input" value={texts.title || ''} placeholder={sample('title')}
                            onChange={e => setText('title', e.target.value)} maxLength={300} />
                    </div>
                    <div>
                        <label className="form-label">{t('popup.textLabel')}</label>
                        <textarea className="form-input" rows={2} value={texts.text || ''} placeholder={sample('text')}
                            onChange={e => setText('text', e.target.value)} maxLength={300} style={{ resize: 'vertical' }} />
                    </div>
                    <div>
                        <label className="form-label">{t('popup.buttonLabel')}</label>
                        <input className="form-input" value={texts.button || ''} placeholder={sample('button')}
                            onChange={e => setText('button', e.target.value)} maxLength={300} style={{ maxWidth: 280 }} />
                    </div>
                    <p style={{ ...hint, marginTop: 0 }}>{t('popup.textsHint')}</p>
                    <div>
                        <label className="form-label">{MONEY[vertical] ? t('popup.amountMoney') : t('popup.amountPercent')}</label>
                        <input className="form-input" type="number" min="0" style={{ maxWidth: 160 }}
                            value={v.popup_amount} placeholder={String(DEFAULT_AMOUNT[vertical])}
                            onChange={e => set('popup_amount', e.target.value)} />
                        <p style={hint}>{MONEY[vertical] ? t('popup.amountMoneyHint') : t('popup.amountPercentHint')}</p>
                    </div>
                </div>
            </section>

            {/* 3. Picture */}
            <section>
                <p style={sectionTitle}>{t('popup.stepImage')}</p>
                <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                    <img src={v.popup_image || presetImage(v.popup_vertical)} alt=""
                        style={{ width: 72, height: 72, objectFit: 'cover', borderRadius: 10, border: '1px solid var(--color-border)', background: '#f1f5f9' }} />
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 }}>
                        <span style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
                            {v.popup_image ? t('popup.imageOwn') : t('popup.imageTemplate')}
                        </span>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <button type="button" className="btn btn-secondary" onClick={() => setPickerOpen(true)}>
                                <ImageIcon size={15} /> {t('popup.pickImage')}
                            </button>
                            {v.popup_image && (
                                <button type="button" className="btn btn-secondary" onClick={() => set('popup_image', '')}>
                                    <RotateCcw size={15} /> {t('popup.useTemplateImage')}
                                </button>
                            )}
                        </div>
                    </div>
                </div>
                <p style={hint}>{t('popup.imageHint')}</p>
            </section>

            {/* 4. When */}
            <section>
                <p style={sectionTitle}>{t('popup.stepWhen')}</p>
                <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                    <div>
                        <label className="form-label">{t('popup.delay')}</label>
                        <input className="form-input" type="number" min="1" max="300" style={{ width: 120 }}
                            value={v.popup_delay} onChange={e => set('popup_delay', e.target.value)} />
                    </div>
                    <div style={{ minWidth: 200, flex: '1 1 200px', maxWidth: 280 }}>
                        <label className="form-label">{t('popup.frequency')}</label>
                        <select className="form-select" value={FREQUENCIES.includes(String(v.popup_frequency_hours)) ? String(v.popup_frequency_hours) : '24'}
                            onChange={e => set('popup_frequency_hours', e.target.value)}>
                            {FREQUENCIES.map(f => <option key={f} value={f}>{t(`popup.freq_${f}`)}</option>)}
                        </select>
                    </div>
                </div>
                <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', marginTop: 12, cursor: 'pointer', fontSize: 13, color: 'var(--color-text)' }}>
                    <input type="checkbox" checked={v.popup_require_activity === '1'} style={{ marginTop: 2 }}
                        onChange={e => set('popup_require_activity', e.target.checked ? '1' : '0')} />
                    <span>{t('popup.requireActivity')}</span>
                </label>
            </section>

            {/* More */}
            <section style={{ borderTop: '1px solid var(--color-border)', paddingTop: 12 }}>
                <button type="button" onClick={() => setMoreOpen(o => !o)}
                    style={{ display: 'flex', alignItems: 'center', gap: 6, background: 'none', border: 0, padding: 0, cursor: 'pointer', color: 'var(--color-text-secondary)', fontSize: 13, fontWeight: 600 }}>
                    {moreOpen ? <ChevronUp size={16} /> : <ChevronDown size={16} />} {t('popup.more')}
                </button>
                {moreOpen && (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 16, marginTop: 14 }}>
                        <div>
                            <label className="form-label">{t('popup.designs')}</label>
                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                {DESIGNS.map(d => (
                                    <label key={d} style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13, padding: '6px 10px', border: '1px solid var(--color-border)', borderRadius: 8, cursor: 'pointer' }}>
                                        <input type="checkbox" checked={designs.includes(d)} onChange={() => toggleDesign(d)} />
                                        {t(`popup.design_${d}`)}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
                            <div style={{ minWidth: 150 }}>
                                <label className="form-label">{t('popup.position')}</label>
                                <select className="form-select" value={v.popup_position} onChange={e => set('popup_position', e.target.value)}>
                                    <option value="center">{t('popup.pos_center')}</option>
                                    <option value="bottom">{t('popup.pos_bottom')}</option>
                                </select>
                            </div>
                            <div style={{ minWidth: 150 }}>
                                <label className="form-label">{t('popup.animation')}</label>
                                <select className="form-select" value={v.popup_animation} onChange={e => set('popup_animation', e.target.value)}>
                                    {ANIMATIONS.map(a => <option key={a} value={a}>{t(`popup.anim_${a}`)}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('popup.accent')}</label>
                                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                                    <input type="color" value={v.popup_accent || DESIGN_ACCENT[designs[0] || 'card']}
                                        onChange={e => set('popup_accent', e.target.value)}
                                        style={{ width: 40, height: 34, padding: 2, border: '1px solid var(--color-border)', borderRadius: 8, background: 'none' }} />
                                    {v.popup_accent && (
                                        <button type="button" className="btn btn-secondary" onClick={() => set('popup_accent', '')}>{t('popup.accentAuto')}</button>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div>
                            <label className="form-label">{t('popup.scrollDepth')}</label>
                            <input className="form-input" type="number" min="0" max="90" style={{ width: 120 }}
                                value={v.popup_scroll_depth} onChange={e => set('popup_scroll_depth', e.target.value)} />
                        </div>
                        <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', cursor: 'pointer', fontSize: 13, color: 'var(--color-text)' }}>
                            <input type="checkbox" checked={v.popup_exit_intent === '1'} style={{ marginTop: 2 }}
                                onChange={e => set('popup_exit_intent', e.target.checked ? '1' : '0')} />
                            <span>{t('popup.exitIntent')}</span>
                        </label>
                        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                            <div style={{ minWidth: 200 }}>
                                <label className="form-label">{t('popup.langMode')}</label>
                                <select className="form-select" value={v.popup_lang_mode} onChange={e => set('popup_lang_mode', e.target.value)}>
                                    <option value="auto">{t('popup.langAuto')}</option>
                                    <option value="forced">{t('popup.langForced')}</option>
                                </select>
                            </div>
                            {v.popup_lang_mode === 'forced' && (
                                <div style={{ minWidth: 100 }}>
                                    <label className="form-label">{t('popup.lang')}</label>
                                    <select className="form-select" value={v.popup_lang} onChange={e => set('popup_lang', e.target.value)}>
                                        {POPUP_LANGS.map(l => <option key={l} value={l}>{l.toUpperCase()}</option>)}
                                    </select>
                                </div>
                            )}
                        </div>
                        {v.popup_lang_mode !== 'forced' && (
                            <div>
                                <label className="form-label">{t('popup.languages')}</label>
                                <input className="form-input" value={v.popup_languages} placeholder="en, ru, es"
                                    onChange={e => set('popup_languages', e.target.value)} style={{ maxWidth: 280 }} />
                                <p style={hint}>{t('popup.languagesHint')}</p>
                            </div>
                        )}
                    </div>
                )}
            </section>

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
}
