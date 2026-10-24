import React, { useEffect, useState } from 'react';
import { CheckCircle2, AlertTriangle, RotateCcw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { axios } from '../utils/apiCache';
import PopupForm, { PopupPreview } from './PopupForm';
import { POPUP_DEFAULTS } from '../utils/popupDefaults';

/*
 * Campaign editor → "Popup" tab. One switch, a plain-words status (will the
 * popup actually have somewhere to appear?), then the same form as the
 * template. The campaign's own popup lives in formData.popup_config; the
 * first time the switch goes on it is seeded from the template, so a
 * beginner gets a working popup with one click and edits from there.
 */
export default function CampaignPopupTab({ formData, setFormData, hasPage, onOpenIntegrations }) {
    const { t } = useLanguage();
    const [template, setTemplate] = useState(null);

    useEffect(() => {
        let alive = true;
        axios.get('/api.php?action=popup_template')
            .then(res => {
                if (alive) setTemplate({ ...POPUP_DEFAULTS, ...(res.data?.data || {}) });
            })
            .catch(() => { if (alive) setTemplate({ ...POPUP_DEFAULTS }); });
        return () => { alive = false; };
    }, []);

    const on = formData.popup_mode === 'on';
    const config = { ...POPUP_DEFAULTS, ...(template || {}), ...(formData.popup_config || {}) };

    const setOn = (checked) => setFormData(prev => ({
        ...prev,
        popup_mode: checked ? 'on' : 'off',
        popup_config: prev.popup_config || (checked && template ? { ...template } : prev.popup_config),
    }));
    const patch = (p) => setFormData(prev => ({
        ...prev,
        popup_config: { ...(template || POPUP_DEFAULTS), ...(prev.popup_config || {}), ...p },
    }));
    const reset = () => setFormData(prev => ({ ...prev, popup_config: template ? { ...template } : null }));

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            <label style={{
                display: 'flex', gap: 12, alignItems: 'flex-start', cursor: 'pointer', padding: 14, borderRadius: 12,
                border: `1px solid ${on ? 'var(--color-primary)' : 'var(--color-border)'}`, background: 'var(--color-bg-card)',
            }}>
                <input type="checkbox" checked={on} onChange={e => setOn(e.target.checked)} style={{ marginTop: 3, width: 18, height: 18 }} />
                <span>
                    <span style={{ display: 'block', fontWeight: 600, color: 'var(--color-text)' }}>{t('popup.switch')}</span>
                    <span style={{ display: 'block', fontSize: 12.5, color: 'var(--color-text-muted)', marginTop: 3, lineHeight: 1.45 }}>{t('popup.switchHint')}</span>
                </span>
            </label>

            {on && (
                hasPage ? (
                    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13, color: 'var(--color-success)' }}>
                        <CheckCircle2 size={16} style={{ flexShrink: 0, marginTop: 1 }} />
                        <span>{t('popup.statusOk')}</span>
                    </div>
                ) : (
                    <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start', fontSize: 13, padding: 12, borderRadius: 10, background: 'var(--color-bg-secondary)', color: 'var(--color-text-secondary)', lineHeight: 1.5 }}>
                        <AlertTriangle size={16} style={{ flexShrink: 0, marginTop: 2, color: 'var(--color-warning)' }} />
                        <div>
                            <div>{t('popup.statusNoPage')}</div>
                            {onOpenIntegrations && (
                                <button type="button" className="btn btn-secondary" style={{ marginTop: 8 }} onClick={onOpenIntegrations}>
                                    {t('popup.openIntegrations')}
                                </button>
                            )}
                        </div>
                    </div>
                )
            )}

            {on && template && (
                <>
                    <div>
                        <p style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text)', margin: '0 0 8px' }}>{t('popup.preview')}</p>
                        <PopupPreview value={config} />
                        <p style={{ fontSize: 12, color: 'var(--color-text-muted)', margin: '8px 0 0' }}>{t('popup.previewHint')}</p>
                    </div>
                    <PopupForm value={config} onChange={patch} />
                    <div>
                        <button type="button" className="btn btn-secondary" onClick={reset}>
                            <RotateCcw size={15} /> {t('popup.resetToTemplate')}
                        </button>
                        <p style={{ fontSize: 12, color: 'var(--color-text-muted)', margin: '6px 0 0' }}>{t('popup.saveWithCampaign')}</p>
                    </div>
                </>
            )}
        </div>
    );
}
