import React, { useState, useEffect, useRef } from 'react';
import { Save, X, Upload, Plus, Trash2, Info, ChevronDown, ChevronUp } from 'lucide-react';
import axios from 'axios';
import GeoSelector from './GeoSelector';
import HelpTooltip from './HelpTooltip';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const OfferEditor = ({ offerId, onClose }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(false);
    const [activeTab, setActiveTab] = useState('general');

    // Select options
    const [groups, setGroups] = useState([]);
    const [affiliateNetworks, setAffiliateNetworks] = useState([]);
    const [allOffers, setAllOffers] = useState([]);

    // Form state
    const [formData, setFormData] = useState({
        name: '',
        group_id: '',
        affiliate_network_id: '',
        url: '',
        redirect_type: 'redirect',
        is_local: false,
        geo: '',
        payout_type: 'cpa',
        payout_value: 0,
        payout_auto: false,
        allow_rebills: false,
        capping_limit: 0,
        capping_timezone: 'UTC',
        alt_offer_id: '',
        notes: '',
        values: [],
        state: 'active'
    });

    // Local offer files
    const [files, setFiles] = useState([]);
    const [uploadingZip, setUploadingZip] = useState(false);
    const fileInputRef = useRef(null);

    // Show/hide advanced sections
    const [showCapping, setShowCapping] = useState(false);

    useEffect(() => {
        const fetchDeps = async () => {
            try {
                const [gRes, anRes, oRes] = await Promise.all([
                    axios.get(`${API_URL}?action=offer_groups`),
                    axios.get(`${API_URL}?action=affiliate_networks`),
                    axios.get(`${API_URL}?action=all_offers`)
                ]);
                if (gRes.data.status === 'success') setGroups(gRes.data.data);
                if (anRes.data.status === 'success') setAffiliateNetworks(anRes.data.data);
                if (oRes.data.status === 'success') setAllOffers(oRes.data.data.filter(o => o.id !== offerId));
            } catch (err) {
                console.error(err);
            }
        };
        fetchDeps();

        if (offerId) {
            setLoading(true);
            axios.get(`${API_URL}?action=get_offer&id=${offerId}`)
                .then(res => {
                    if (res.data.status === 'success') {
                        const data = res.data.data;
                        setFormData({
                            name: data.name || '',
                            group_id: data.group_id || '',
                            affiliate_network_id: data.affiliate_network_id || '',
                            url: data.url || '',
                            redirect_type: data.redirect_type || 'redirect',
                            is_local: !!data.is_local,
                            geo: data.geo || '',
                            payout_type: data.payout_type || 'cpa',
                            payout_value: parseFloat(data.payout_value) || 0,
                            payout_auto: !!data.payout_auto,
                            allow_rebills: !!data.allow_rebills,
                            capping_limit: data.capping_limit || 0,
                            capping_timezone: data.capping_timezone || 'UTC',
                            alt_offer_id: data.alt_offer_id || '',
                            notes: data.notes || '',
                            values: data.values || [],
                            state: data.state || 'active'
                        });
                        if (data.capping_limit > 0) setShowCapping(true);
                    }
                })
                .finally(() => setLoading(false));
        }
    }, [offerId]);

    const handleSave = async () => {
        if (!formData.name) {
            alert(t('offerEditor.fillName'));
            return;
        }
        if (!formData.is_local && !formData.url) {
            alert(t('offerEditor.fillUrl'));
            return;
        }

        try {
            setLoading(true);
            const payload = { ...formData };
            if (offerId) payload.id = offerId;

            const res = await axios.post(`${API_URL}?action=save_offer`, payload);
            if (res.data.status === 'success') {
                onClose(true);
            } else {
                alert(t('offerEditor.saveError') + " " + res.data.message);
            }
        } catch (err) {
            alert(t('offerEditor.networkError'));
        } finally {
            setLoading(false);
        }
    };

    const addValue = () => {
        if (formData.values.length >= 10) {
            alert(t('offerEditor.maxValues'));
            return;
        }
        setFormData({
            ...formData,
            values: [...formData.values, { name: '', value: '' }]
        });
    };

    const updateValue = (index, field, value) => {
        const newValues = [...formData.values];
        newValues[index][field] = value;
        setFormData({ ...formData, values: newValues });
    };

    const removeValue = (index) => {
        const newValues = [...formData.values];
        newValues.splice(index, 1);
        setFormData({ ...formData, values: newValues });
    };

    const timezones = [
        'UTC', 'Europe/Moscow', 'Europe/Kiev', 'Europe/London', 'Europe/Berlin',
        'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Tokyo'
    ];

    if (loading && offerId && !formData.name) {
        return (
            <div className="modal-overlay">
                <div className="modal-content" style={{ maxWidth: '300px' }}>
                    <div className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
                </div>
            </div>
        );
    }

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '800px', width: '100%' }}>
                <div className="modal-header">
                    <h2 className="modal-title">
                        {offerId ? `${t('offers.title')}: ${formData.name}` : t('offers.title')}
                    </h2>
                    <button onClick={() => onClose(false)} className="action-btn">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Tabs */}
                <div className="flex px-5 pt-1 gap-6" style={{ borderBottom: '1px solid var(--color-border)' }}>
                    {['general', 'settings', 'values', 'notes'].map(tab => (
                        <button
                            key={tab}
                            className="pb-3 px-1 font-medium text-sm transition border-b-2"
                            style={{
                                borderColor: activeTab === tab ? 'var(--color-primary)' : 'transparent',
                                color: activeTab === tab ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                            }}
                            onClick={() => setActiveTab(tab)}
                        >
                            {tab === 'general' && t('editor.general')}
                            {tab === 'settings' && t('editor.params')}
                            {tab === 'values' && t('editor.notes')}
                            {tab === 'notes' && t('editor.notes')}
                        </button>
                    ))}
                </div>

                {/* Content Area */}
                <div className="flex-1 overflow-y-auto p-6">
                    {activeTab === 'general' && (
                        <div className="space-y-5">
                            <div>
                                <label className="form-label">
                                    {t('offerEditor.nameLabel')} <span style={{ color: 'var(--color-danger)' }}>*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={formData.name}
                                    onChange={e => setFormData({ ...formData, name: e.target.value })}
                                    className="form-input"
                                    placeholder={t('offerEditor.namePlaceholder')}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="form-label">{t('offerEditor.group')}</label>
                                    <div className="flex">
                                        <select
                                            value={formData.group_id}
                                            onChange={e => setFormData({ ...formData, group_id: e.target.value })}
                                            className="form-select rounded-r-none"
                                        >
                                            <option value="">{t('offerEditor.noGroup')}</option>
                                            {groups.map(g => (
                                                <option key={g.id} value={g.id}>{g.name}</option>
                                            ))}
                                        </select>
                                        <button className="btn btn-secondary rounded-l-none border-l-0">
                                            <Plus className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">
                                        {t('offerEditor.affiliateNetwork')}
                                        <span className="ml-1 relative group cursor-pointer inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold rounded-full" style={{ color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
                                            ?
                                            <div className="absolute bottom-full mb-2 hidden group-hover:block w-48 rounded-xl p-2 z-10 shadow-lg text-xs" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}>
                                                {t('offerEditor.networkTooltip')}
                                            </div>
                                        </span>
                                    </label>
                                    <div className="flex">
                                        <select
                                            value={formData.affiliate_network_id}
                                            onChange={e => setFormData({ ...formData, affiliate_network_id: e.target.value })}
                                            className="form-select rounded-r-none"
                                        >
                                            <option value="">{t('offerEditor.noNetwork')}</option>
                                            {affiliateNetworks.map(an => (
                                                <option key={an.id} value={an.id}>{an.name}</option>
                                            ))}
                                        </select>
                                        <button className="btn btn-secondary rounded-l-none border-l-0">
                                            <Plus className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {/* Offer Type Buttons */}
                            <div>
                                <label className="form-label">{t('offerEditor.redirectType')} <HelpTooltip textKey="help.redirectTypeTooltip" /></label>
                                <div className="flex rounded-xl overflow-hidden mb-3" style={{ border: '1px solid var(--color-border)' }}>
                                    <button
                                        onClick={() => setFormData({ ...formData, redirect_type: 'local', is_local: true })}
                                        className="flex-1 px-4 py-2 text-sm font-medium transition"
                                        style={{
                                            backgroundColor: formData.is_local ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                            color: formData.is_local ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                            borderRight: '1px solid var(--color-border)'
                                        }}
                                    >
                                        {t('offers.local')}
                                    </button>
                                    <button
                                        onClick={() => setFormData({ ...formData, redirect_type: 'redirect', is_local: false })}
                                        className="flex-1 px-4 py-2 text-sm font-medium transition"
                                        style={{
                                            backgroundColor: !formData.is_local && formData.redirect_type === 'redirect' ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                            color: !formData.is_local && formData.redirect_type === 'redirect' ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                            borderRight: '1px solid var(--color-border)'
                                        }}
                                    >
                                        {t('offers.redirect')}
                                    </button>
                                    <button
                                        onClick={() => setFormData({ ...formData, redirect_type: 'preload', is_local: false })}
                                        className="flex-1 px-4 py-2 text-sm font-medium transition"
                                        style={{
                                            backgroundColor: !formData.is_local && formData.redirect_type === 'preload' ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                            color: !formData.is_local && formData.redirect_type === 'preload' ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                            borderRight: '1px solid var(--color-border)'
                                        }}
                                    >
                                        {t('landingEditor.preload').split(' ')[0]}
                                    </button>
                                    <button
                                        onClick={() => setFormData({ ...formData, redirect_type: 'action', is_local: false })}
                                        className="flex-1 px-4 py-2 text-sm font-medium transition"
                                        style={{
                                            backgroundColor: !formData.is_local && formData.redirect_type === 'action' ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                            color: !formData.is_local && formData.redirect_type === 'action' ? 'var(--color-primary)' : 'var(--color-text-primary)'
                                        }}
                                    >
                                        {t('editor.action')}
                                    </button>
                                </div>
                                <select
                                    value={formData.redirect_type}
                                    onChange={e => setFormData({ ...formData, redirect_type: e.target.value })}
                                    className="form-select"
                                >
                                    <option value="redirect">{t('offerEditor.httpRedirect')}</option>
                                    <option value="frame">Iframe</option>
                                    <option value="preload">{t('offerEditor.preloadCurl')}</option>
                                </select>
                            </div>

                            {!formData.is_local && (
                                <div>
                                    <label className="form-label">
                                        URL <span style={{ color: 'var(--color-danger)' }}>*</span>
                                    </label>
                                    <input
                                        type="url"
                                        required
                                        value={formData.url}
                                        onChange={e => setFormData({ ...formData, url: e.target.value })}
                                        className="form-input"
                                        placeholder="https://offer.example.com/?subid={subid}"
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    {/* Settings Tab */}
                    {activeTab === 'settings' && (
                        <div className="space-y-5">
                            <div>
                                <label className="form-label">{t('offerEditor.countries')}</label>
                                <GeoSelector
                                    value={formData.geo}
                                    onChange={geo => setFormData({ ...formData, geo })}
                                    placeholder={t('offerEditor.countriesPlaceholder')}
                                />
                            </div>

                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <h4 className="text-sm font-bold mb-3" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.payouts')}</h4>
                                <div className="flex gap-4 mb-3">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payout_type"
                                            value="cpa"
                                            checked={formData.payout_type === 'cpa'}
                                            onChange={() => setFormData({ ...formData, payout_type: 'cpa' })}
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>CPA</span>
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payout_type"
                                            value="cpc"
                                            checked={formData.payout_type === 'cpc'}
                                            onChange={() => setFormData({ ...formData, payout_type: 'cpc' })}
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>CPC</span>
                                    </label>
                                </div>

                                <div className="flex gap-4 items-center">
                                    <div className="flex-1 max-w-[200px] relative">
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={formData.payout_value}
                                            onChange={e => setFormData({ ...formData, payout_value: parseFloat(e.target.value) || 0 })}
                                            className="form-input pr-8"
                                            disabled={formData.payout_auto}
                                        />
                                        <span className="absolute right-3 top-2" style={{ color: 'var(--color-text-muted)' }}>$</span>
                                    </div>
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.payout_auto}
                                            onChange={e => setFormData({ ...formData, payout_auto: e.target.checked })}
                                            className="rounded"
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.payoutByParam')}</span>
                                    </label>
                                </div>
                            </div>

                            <div className="pt-2 flex items-center justify-between" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.rebills')}</h4>
                                <label className="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="sr-only peer"
                                        checked={formData.allow_rebills}
                                        onChange={e => setFormData({ ...formData, allow_rebills: e.target.checked })}
                                    />
                                    <div className="w-9 h-5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-4 after:w-4 after:transition-all" style={{ backgroundColor: formData.allow_rebills ? 'var(--color-primary)' : 'var(--color-bg-hover)', '--tw-bg-opacity': 1, after: { backgroundColor: 'white' } }}>
                                        <div className="absolute top-[2px] left-[2px] w-4 h-4 rounded-full transition-transform" style={{ backgroundColor: 'white', transform: formData.allow_rebills ? 'translateX(16px)' : 'translateX(0)' }}></div>
                                    </div>
                                </label>
                            </div>

                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <div className="flex items-center justify-between mb-3">
                                    <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.conversionCap')}</h4>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="sr-only peer"
                                            checked={showCapping}
                                            onChange={() => setShowCapping(!showCapping)}
                                        />
                                        <div className="w-9 h-5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-4 after:w-4 after:transition-all" style={{ backgroundColor: showCapping ? 'var(--color-primary)' : 'var(--color-bg-hover)' }}>
                                            <div className="absolute top-[2px] left-[2px] w-4 h-4 rounded-full transition-transform" style={{ backgroundColor: 'white', transform: showCapping ? 'translateX(16px)' : 'translateX(0)' }}></div>
                                        </div>
                                    </label>
                                </div>

                                {showCapping && (
                                    <div className="space-y-4 p-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                        <div className="flex gap-4">
                                            <div className="flex-1">
                                                <label className="form-label">{t('offerEditor.dailyLimit')}</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    value={formData.capping_limit}
                                                    onChange={e => setFormData({ ...formData, capping_limit: parseInt(e.target.value) || 0 })}
                                                    className="form-input"
                                                />
                                            </div>
                                            <div className="flex-1">
                                                <label className="form-label">{t('offerEditor.timezone')}</label>
                                                <select
                                                    value={formData.capping_timezone}
                                                    onChange={e => setFormData({ ...formData, capping_timezone: e.target.value })}
                                                    className="form-select"
                                                >
                                                    {timezones.map(tz => (
                                                        <option key={tz} value={tz}>{tz}</option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div>
                                            <label className="form-label">{t('offerEditor.altOffer')}</label>
                                            <select
                                                value={formData.alt_offer_id}
                                                onChange={e => setFormData({ ...formData, alt_offer_id: e.target.value })}
                                                className="form-select"
                                            >
                                                <option value="">{t('offerEditor.notSelected')}</option>
                                                {allOffers.map(o => (
                                                    <option key={o.id} value={o.id}>{o.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Values Tab */}
                    {activeTab === 'values' && (
                        <div className="space-y-4">
                            <div className="flex justify-between items-center pb-2">
                                <div>
                                    <h3 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.valuesTitle')}</h3>
                                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('offerEditor.valuesDesc')}
                                    </p>
                                </div>
                                <button
                                    onClick={addValue}
                                    disabled={formData.values.length >= 10}
                                    className="btn btn-secondary btn-sm"
                                >
                                    <Plus className="w-3.5 h-3.5" />
                                    {t('offerEditor.add')}
                                </button>
                            </div>

                            {formData.values.length === 0 ? (
                                <div className="text-center py-10 rounded-xl border-2 border-dashed" style={{ color: 'var(--color-text-muted)', backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                    <p className="text-sm">{t('offerEditor.noValues')}</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {formData.values.map((val, idx) => (
                                        <div key={idx} className="flex items-center gap-3">
                                            <div className="flex-1">
                                                <input
                                                    type="text"
                                                    value={val.name}
                                                    onChange={e => updateValue(idx, 'name', e.target.value)}
                                                    className="form-input"
                                                    placeholder={t('offerEditor.paramNamePlaceholder')}
                                                />
                                            </div>
                                            <div className="flex-[2]">
                                                <input
                                                    type="text"
                                                    value={val.value}
                                                    onChange={e => updateValue(idx, 'value', e.target.value)}
                                                    className="form-input"
                                                    placeholder={t('offerEditor.paramValuePlaceholder')}
                                                />
                                            </div>
                                            <button
                                                onClick={() => removeValue(idx)}
                                                className="action-btn text-red"
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {formData.values.length > 0 && (
                                <div className="mt-4 pt-4" style={{ borderTop: '1px solid var(--color-border)' }}>
                                    <h4 className="font-semibold text-xs mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.usageExamples')}</h4>
                                    <ul className="text-xs space-y-1 font-mono p-3 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', color: 'var(--color-text-secondary)' }}>
                                        {formData.values.filter(v => v.name).map((v, idx) => (
                                            <li key={idx}>{`{offer_value:${v.name}}`} → {v.value || t('offerEditor.empty')}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Notes Tab */}
                    {activeTab === 'notes' && (
                        <div>
                            <label className="form-label">{t('editor.notes')}</label>
                            <textarea
                                rows={8}
                                value={formData.notes}
                                onChange={e => setFormData({ ...formData, notes: e.target.value })}
                                className="form-input resize-none"
                                placeholder={t('offerEditor.notesPlaceholder')}
                            />
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="modal-footer">
                    <div className="flex gap-3">
                        <button onClick={() => onClose(false)} className="btn btn-secondary">
                            {t('common.cancel')}
                        </button>
                        <button onClick={handleSave} disabled={loading} className="btn btn-primary">
                            {offerId ? t('common.save') : t('common.create')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default OfferEditor;