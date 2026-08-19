import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { Edit2, X, Zap } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

const API_URL = '/api.php';

/**
 * Pixel picker with a Pixel Vault shortcut. Renders a select of saved
 * profiles (list response never carries tokens), a "quick add" modal that saves a new profile
 * and auto-selects it, and a manual-entry escape hatch. The parent stays in
 * charge of the form: every choice is delivered as a patch object
 * ({ pixel_profile_id, pixel_id, token?, test_event_code? }) through onPick.
 */
export const PixelPicker = ({ label, value, profileId, trafficSource, resolveServerSide = false, onPick, onManage, error }) => {
    const { t } = useLanguage();
    const [profiles, setProfiles] = useState([]);
    const [loading, setLoading] = useState(false);
    const [quickOpen, setQuickOpen] = useState(false);
    const [isCustom, setIsCustom] = useState(false);
    const [savingQuick, setSavingQuick] = useState(false);
    const [quickError, setQuickError] = useState('');
    const [quickForm, setQuickForm] = useState({
        traffic_source: trafficSource || 'facebook', niche: '', name: '', pixel_id: '', token: '', test_event_code: ''
    });

    const fetchProfiles = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=pixel_profiles_list`);
            if (res.data.status === 'success') setProfiles(res.data.data || []);
        } catch { /* the picker degrades to manual entry */ }
        finally { setLoading(false); }
    }, []);

    useEffect(() => { fetchProfiles(); }, [fetchProfiles]);

    // Show all profiles matching the traffic source, including inactive ones (like Pixel Vault does)
    const savedProfiles = profiles.filter(profile =>
        !trafficSource || profile.traffic_source === trafficSource
    );
    const matched = savedProfiles.find(profile =>
        (profileId && String(profile.id) === String(profileId)) ||
        (!profileId && String(profile.pixel_id) === String(value || ''))
    );
    // Vault profiles from several networks can appear in one unfiltered picker
    // (the campaign editor), so each option spells out its source.
    // Inactive pixels are marked with [inactive] like Pixel Vault shows them with reduced opacity.
    const sourceLabels = { facebook: 'Facebook', tiktok: 'TikTok', google_ads: 'Google Ads', snapchat: 'Snapchat', pinterest: 'Pinterest' };
    const optionLabel = px => {
        const base = `${px.pixel_id} ( ${sourceLabels[px.traffic_source] || px.traffic_source || 'Facebook'} · ${px.niche || '—'} / ${px.name || '—'} )`;
        return Number(px.is_active ?? 1) === 1 ? base : `${base} [inactive]`;
    };

    const showCustom = isCustom || (!!value && !matched && !profileId);

    const applyProfile = async (profile) => {
        setIsCustom(false);
        onPick({
            pixel_profile_id: String(profile.id),
            pixel_id: profile.pixel_id,
            token: '',
            has_saved_token: !!profile.has_token,
            test_event_code: profile.test_event_code || ''
        });
        if (!resolveServerSide && profile.has_token) {
            try {
                const res = await axios.post(`${API_URL}?action=pixel_profile_reveal`, { id: profile.id });
                if (res.data.status === 'success' && res.data.data?.token) onPick({ token: res.data.data.token });
            } catch { /* the token can still be entered manually */ }
        }
    };

    const handleSelect = (val) => {
        if (val === '__add_new__') {
            setQuickError('');
            setQuickForm({ traffic_source: trafficSource || 'facebook', niche: '', name: '', pixel_id: '', token: '', test_event_code: '' });
            setQuickOpen(true);
            return;
        }
        if (val === '__custom__') {
            setIsCustom(true);
            onPick({ pixel_profile_id: '', pixel_id: '', token: '', has_saved_token: false, test_event_code: '' });
            return;
        }
        const profile = savedProfiles.find(p => String(p.id) === String(val));
        if (profile) applyProfile(profile);
        else onPick({ pixel_profile_id: '', pixel_id: '', token: '', has_saved_token: false, test_event_code: '' });
    };

    const handleSaveQuick = async (e) => {
        e.preventDefault();
        if (savingQuick) return;
        setSavingQuick(true);
        setQuickError('');
        try {
            const res = await axios.post(`${API_URL}?action=save_pixel_profile`, quickForm);
            if (res.data.status === 'success') {
                const saved = { ...quickForm, id: res.data.data?.id, has_token: !!quickForm.token };
                setProfiles(prev => [saved, ...prev.filter(p => p.id !== saved.id)]);
                setIsCustom(false);
                onPick({
                    pixel_profile_id: String(saved.id),
                    pixel_id: saved.pixel_id,
                    token: resolveServerSide ? '' : saved.token,
                    has_saved_token: saved.has_token,
                    test_event_code: saved.test_event_code || ''
                });
                setQuickOpen(false);
            } else {
                setQuickError(res.data.message || t('common.error'));
            }
        } catch (err) {
            setQuickError(err.response?.data?.message || err.message || t('common.error'));
        } finally {
            setSavingQuick(false);
        }
    };

    return (
        <div className="space-y-2">
            {(label !== undefined || onManage) && (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px' }}>
                    {label !== undefined && <label className="form-label mb-0">{label}</label>}
                    {onManage && (
                        <button type="button" onClick={onManage}
                            style={{ border: 0, padding: 0, background: 'transparent', color: 'var(--color-primary)', fontSize: '11px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                            <Edit2 size={11} /> {t('fbConv.manageInVault', 'Edit in Pixel Vault')}
                        </button>
                    )}
                </div>
            )}

            {!showCustom && savedProfiles.length > 0 ? (
                <select
                    className="form-select font-mono text-xs"
                    value={matched ? String(matched.id) : ''}
                    onChange={(e) => handleSelect(e.target.value)}
                >
                    <option value="">— {t('fbConv.selectPixelPrompt', 'Select saved pixel...')} —</option>
                    <option value="__add_new__">✨ + {t('fbConv.addNewPixel', 'Add New Pixel to Vault...')}</option>
                    <option disabled>──────────────────</option>
                    {savedProfiles.map(px => (
                        <option key={px.id || px.pixel_id} value={String(px.id)}>
                            {optionLabel(px)}
                        </option>
                    ))}
                    <option disabled>──────────────────</option>
                    <option value="__custom__">+ {t('fbConv.enterManually', 'Enter custom Pixel ID manually...')}</option>
                </select>
            ) : (
                <div style={{ display: 'flex', gap: '8px' }}>
                    <input
                        type="text"
                        className="form-input font-mono text-xs"
                        placeholder="1053688450967480"
                        value={value || ''}
                        onChange={(e) => onPick({ pixel_profile_id: '', pixel_id: e.target.value, has_saved_token: false })}
                    />
                    {savedProfiles.length > 0 && (
                        <button type="button" className="btn btn-secondary btn-sm"
                            onClick={() => {
                                setIsCustom(false);
                                onPick({ pixel_profile_id: '', pixel_id: '', token: '', has_saved_token: false, test_event_code: '' });
                            }}>
                            {t('fbConv.selectFromList', 'Choose from saved')}
                        </button>
                    )}
                </div>
            )}
            {loading && <p className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>}
            {error}

            {quickOpen && (
                <div className="modal-overlay" onClick={() => setQuickOpen(false)}>
                    <div className="modal-content" style={{ maxWidth: '460px' }} onClick={e => e.stopPropagation()}>
                        <div className="modal-header px-6 pt-5" style={{ flexShrink: 0 }}>
                            <h4 className="modal-title font-bold text-base m-0">✨ {t('pixelVault.quickAddTitle', 'Quick Add Pixel to Vault')}</h4>
                            <button type="button" onClick={() => setQuickOpen(false)} className="action-btn"><X size={16} /></button>
                        </div>
                        <form onSubmit={handleSaveQuick} className="p-6 space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="form-label text-xs">{t('pixelVault.trafficSource', 'Traffic Source')}</label>
                                    <select
                                        className="form-select text-xs"
                                        value={quickForm.traffic_source}
                                        disabled={!!trafficSource}
                                        onChange={e => setQuickForm({ ...quickForm, traffic_source: e.target.value })}
                                    >
                                        <option value="facebook">Facebook</option>
                                        <option value="tiktok">TikTok</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label text-xs">{t('pixelVault.nicheTag', 'Niche / Tag')}</label>
                                    <input type="text" placeholder="Nutra, Crypto..." value={quickForm.niche}
                                        onChange={e => setQuickForm({ ...quickForm, niche: e.target.value })}
                                        className="form-input text-xs" />
                                </div>
                            </div>
                            <div>
                                <label className="form-label text-xs">{t('pixelVault.pixelName', 'Pixel Name')} *</label>
                                <input type="text" required placeholder="Keto Diet Pixel 01" value={quickForm.name}
                                    onChange={e => setQuickForm({ ...quickForm, name: e.target.value })}
                                    className="form-input text-xs" />
                            </div>
                            <div>
                                <label className="form-label text-xs">{t('fbConv.pixelId')} *</label>
                                <input type="text" required placeholder="1053688450967480" value={quickForm.pixel_id}
                                    onChange={e => setQuickForm({ ...quickForm, pixel_id: e.target.value })}
                                    className="form-input font-mono text-xs" />
                            </div>
                            <div>
                                <label className="form-label text-xs">{t('fbConv.token')} *</label>
                                <input type="password" required placeholder="EAAG..." value={quickForm.token}
                                    onChange={e => setQuickForm({ ...quickForm, token: e.target.value })}
                                    className="form-input font-mono text-xs" />
                            </div>
                            <div>
                                <label className="form-label text-xs">{t('fbConv.testEventCode')}</label>
                                <input type="text" placeholder="TEST12345" value={quickForm.test_event_code}
                                    onChange={e => setQuickForm({ ...quickForm, test_event_code: e.target.value })}
                                    className="form-input font-mono text-xs" />
                            </div>
                            {quickError && (
                                <p className="text-xs" style={{ color: 'var(--color-danger)' }}>{quickError}</p>
                            )}
                            <div className="flex justify-end gap-2 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <button type="button" onClick={() => setQuickOpen(false)} className="btn btn-secondary btn-sm">{t('common.cancel')}</button>
                                <button type="submit" disabled={savingQuick} className="btn btn-primary btn-sm">
                                    <Zap size={13} /> {savingQuick ? t('common.saving') : t('pixelVault.saveAndSelect', 'Save & Select')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

export default PixelPicker;
