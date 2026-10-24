import React, { useEffect, useState } from 'react';
import { Puzzle, Save, ArrowRight } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import PopupForm, { PopupPreview } from './PopupForm';
import { POPUP_DEFAULTS, presetImage } from '../utils/popupDefaults';

const API_URL = '/api.php';

/** Ask App to open a campaign (optionally at an editor tab), or a top tab. */
function navigate(detail) {
    window.dispatchEvent(new CustomEvent('orbitra:open-campaign', { detail }));
}

/*
 * Settings → Plugins → Conversion popups. Popups are switched on and tuned in
 * each campaign (editor → "Popup" tab); this page answers "where is it on and
 * how is it doing?" and holds the template every new popup starts from.
 */
const PopupSettings = () => {
    const { t } = useLanguage();
    const [template, setTemplate] = useState(null);
    const [items, setItems] = useState(null);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    useEffect(() => {
        fetch(`${API_URL}?action=popup_template`).then(r => r.json())
            .then(d => setTemplate({ ...POPUP_DEFAULTS, ...(d.data || {}) }))
            .catch(() => setTemplate({ ...POPUP_DEFAULTS }));
        fetch(`${API_URL}?action=popup_overview`).then(r => r.json())
            .then(d => setItems(d.data?.items || []))
            .catch(() => setItems([]));
    }, []);

    const save = async () => {
        setSaving(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=global_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: template }),
            });
            const data = await res.json();
            setMessage(data.status === 'success'
                ? { text: t('popup.saveSuccess'), type: 'success' }
                : { text: data.message || t('popup.saveError'), type: 'error' });
        } catch {
            setMessage({ text: t('popup.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    const cell = { padding: '10px 8px', borderBottom: '1px solid var(--color-border)', fontSize: 13, verticalAlign: 'middle' };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            <div className="page-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <Puzzle size={18} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('popup.title')}</h3>
                </div>
                <p style={{ fontSize: 13.5, color: 'var(--color-text-secondary)', lineHeight: 1.55, margin: '10px 0 0', maxWidth: 760 }}>
                    {t('popup.intro')}
                </p>

                <h4 style={{ fontSize: 14, fontWeight: 600, margin: '22px 0 8px', color: 'var(--color-text)' }}>{t('popup.campaignsTitle')}</h4>
                {items === null ? (
                    <p style={{ fontSize: 13, color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
                ) : items.length === 0 ? (
                    <div style={{ padding: 16, borderRadius: 12, background: 'var(--color-bg-secondary)', fontSize: 13, color: 'var(--color-text-secondary)', display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                        <span style={{ flex: '1 1 260px' }}>{t('popup.campaignsEmpty')}</span>
                        <button type="button" className="btn btn-secondary" onClick={() => navigate({ tab: 'campaigns' })}>
                            {t('popup.goCampaigns')} <ArrowRight size={15} />
                        </button>
                    </div>
                ) : (
                    <div style={{ overflowX: 'auto' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 520 }}>
                            <thead>
                                <tr style={{ textAlign: 'left', color: 'var(--color-text-muted)', fontSize: 12 }}>
                                    <th style={{ ...cell, fontWeight: 500 }}>{t('popup.colCampaign')}</th>
                                    <th style={{ ...cell, fontWeight: 500 }}>{t('popup.colTemplate')}</th>
                                    <th style={{ ...cell, fontWeight: 500, textAlign: 'right' }}>{t('popup.colShown')}</th>
                                    <th style={{ ...cell, fontWeight: 500, textAlign: 'right' }}>{t('popup.colClicked')}</th>
                                    <th style={{ ...cell, fontWeight: 500, textAlign: 'right' }}>CTR</th>
                                    <th style={cell} />
                                </tr>
                            </thead>
                            <tbody>
                                {items.map(it => (
                                    <tr key={it.id}>
                                        <td style={{ ...cell, fontWeight: 500, color: 'var(--color-text)' }}>{it.name}</td>
                                        <td style={cell}>
                                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                                                <img src={presetImage(it.vertical)} alt="" style={{ width: 24, height: 24, borderRadius: 6, objectFit: 'cover' }} />
                                                {t(`popup.vertical_${it.vertical || 'neutral'}`)}
                                            </span>
                                        </td>
                                        <td style={{ ...cell, textAlign: 'right' }}>{it.shown}</td>
                                        <td style={{ ...cell, textAlign: 'right' }}>{it.clicked}</td>
                                        <td style={{ ...cell, textAlign: 'right' }}>{it.ctr === null ? '—' : `${it.ctr}%`}</td>
                                        <td style={{ ...cell, textAlign: 'right' }}>
                                            <button type="button" className="btn btn-secondary" onClick={() => navigate({ id: it.id, tab: 'popup' })}>
                                                {t('popup.configure')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="page-card">
                <h4 style={{ fontSize: 15, fontWeight: 600, margin: 0, color: 'var(--color-text)' }}>{t('popup.templateTitle')}</h4>
                <p style={{ fontSize: 13, color: 'var(--color-text-muted)', lineHeight: 1.5, margin: '6px 0 18px', maxWidth: 760 }}>
                    {t('popup.templateHint')}
                </p>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`} style={{ marginBottom: 16 }}>
                        {message.text}
                    </div>
                )}
                {template === null ? (
                    <p style={{ fontSize: 13, color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
                ) : (
                    <div style={{ display: 'flex', gap: 28, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                        <div style={{ flex: '1 1 420px', minWidth: 0 }}>
                            <PopupForm value={template} onChange={p => setTemplate(prev => ({ ...prev, ...p }))} />
                            <div style={{ marginTop: 20 }}>
                                <button type="button" className="btn btn-primary" onClick={save} disabled={saving}>
                                    <Save size={16} /> {t('popup.save')}
                                </button>
                            </div>
                        </div>
                        <div style={{ flex: '0 1 320px', minWidth: 260, position: 'sticky', top: 16 }}>
                            <p style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text)', margin: '0 0 8px' }}>{t('popup.preview')}</p>
                            <PopupPreview value={template} />
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default PopupSettings;
