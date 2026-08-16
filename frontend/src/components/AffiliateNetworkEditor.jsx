import React, { useState, useEffect } from 'react';
import { X, Save, Copy, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost } from '../utils/apiCache';

const AffiliateNetworkEditor = ({ networkId, onClose, postbackKey }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [templates, setTemplates] = useState([]);
    const [copied, setCopied] = useState(false);
    const [formData, setFormData] = useState({
        name: '',
        template: 'generic',
        offer_params: '&subid={subid}',
        postback_url: '',
        notes: '',
        state: 'active'
    });

    useEffect(() => {
        fetchTemplates();
        if (networkId) {
            fetchNetwork();
        }
    }, [networkId]);

    const fetchTemplates = async () => {
        try {
            const { data } = await cachedGet('affiliate_network_templates', {}, 60000); // Cache templates for 1 minute
            if (data.status === 'success') {
                setTemplates(data.data);
            }
        } catch (err) {
            console.error(err);
        }
    };

    const fetchNetwork = async () => {
        setLoading(true);
        try {
            const { data } = await cachedGet('get_affiliate_network', { id: networkId });
            if (data.status === 'success') {
                setFormData({
                    name: data.data.name || '',
                    template: data.data.template || 'generic',
                    offer_params: data.data.offer_params || '',
                    postback_url: data.data.postback_url || '',
                    notes: data.data.notes || '',
                    state: data.data.state || 'active'
                });
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleTemplateChange = (templateName) => {
        const template = templates.find(t => t.name === templateName);
        if (template) {
            let postbackUrl = '';
            if (template.postback_url_template) {
                const protocol = window.location.protocol;
                const host = window.location.host;
                postbackUrl = template.postback_url_template
                    .replace('{domain}', host)
                    .replace('{postback_key}', postbackKey);
            }
            setFormData({
                ...formData,
                template: templateName,
                offer_params: template.offer_params_template || '',
                postback_url: postbackUrl || formData.postback_url,
                notes: (template.notes_template && template.notes_template.startsWith('tpl.')) ? t(template.notes_template) : (template.notes_template || '')
            });
        }
    };

    const handleSave = async () => {
        if (!formData.name) {
            alert(t('botSettings.fillName'));
            return;
        }

        setSaving(true);
        try {
            const payload = { ...formData };
            if (networkId) {
                payload.id = networkId;
            }

            const res = await cachedPost('affiliate_networks', payload);
            if (res.data.status === 'success') {
                onClose(true);
            } else {
                alert(t('offerEditor.saveError') + ' ' + res.data.message);
            }
        } catch (err) {
            alert(t('offerEditor.networkError'));
        } finally {
            setSaving(false);
        }
    };

    const getPostbackUrl = () => {
        const protocol = window.location.protocol;
        const host = window.location.host;
        return `${protocol}//${host}/${postbackKey}/postback`;
    };

    const copyToClipboard = async (text) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            console.error(err);
        }
    };

    // Available macros for offer params
    const availableMacros = [
        { macro: '{subid}', description: t('networkEditor.macroSubid') },
        { macro: '{ip}', description: t('networkEditor.macroIp') },
        { macro: '{user_agent}', description: 'User Agent' },
        { macro: '{country}', description: t('networkEditor.macroCountry') },
        { macro: '{device}', description: t('networkEditor.macroDevice') },
        { macro: '{referer}', description: t('networkEditor.macroReferer') },
        { macro: '{keyword}', description: t('networkEditor.macroKeyword') },
        { macro: '{cost}', description: t('networkEditor.macroCost') },
        { macro: '{external_id}', description: t('networkEditor.macroExternalId') },
        { macro: '{creative}', description: t('networkEditor.macroCreative') },
        { macro: '{ad_campaign}', description: t('networkEditor.macroAdCampaign') },
    ];

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '700px' }}>
                {/* Header */}
                <div className="modal-header">
                    <h2 className="modal-title">
                        {networkId ? `${t('networks.title')}: ${formData.name}` : t('networks.title')}
                    </h2>
                    <button onClick={() => onClose(false)} className="action-btn">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto p-6">
                    {loading ? (
                        <div className="flex justify-center py-10">{t('common.loading')}</div>
                    ) : (
                        <div className="space-y-6">
                            {/* Basic Settings */}
                            <div className="p-4 rounded border space-y-4" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <h3 className="font-medium pb-2" style={{ color: 'var(--color-text-primary)', borderBottom: '1px solid var(--color-border)' }}>{t('networkEditor.basicSettings')}</h3>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="form-label">
                                            {t('networkEditor.nameLabel')}
                                        </label>
                                        <input
                                            type="text"
                                            value={formData.name}
                                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                            className="form-input"
                                            placeholder={t('networkEditor.namePlaceholder')}
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label">
                                            {t('networkEditor.template')}
                                        </label>
                                        <select
                                            value={formData.template}
                                            onChange={(e) => handleTemplateChange(e.target.value)}
                                            className="form-select"
                                        >
                                            {templates.map((tmpl) => (
                                                <option key={tmpl.name} value={tmpl.name}>{t('tpl.net_' + tmpl.name, tmpl.display_name)}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="form-label">
                                        {t('networkEditor.status')}
                                    </label>
                                    <select
                                        value={formData.state}
                                        onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="active">{t('networkEditor.active')}</option>
                                        <option value="paused">{t('networkEditor.disabled')}</option>
                                    </select>
                                </div>
                            </div>

                            {/* Postback URL */}
                            <div className="p-4 rounded border space-y-3" style={{
                                background: 'color-mix(in srgb, var(--color-primary) 8%, transparent)',
                                borderColor: 'color-mix(in srgb, var(--color-primary) 30%, transparent)'
                            }}>
                                <h3 className="font-medium" style={{ color: 'var(--color-primary)' }}>Postback URL</h3>
                                <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('networkEditor.postbackHint')}
                                </p>
                                <div className="flex items-center space-x-2">
                                    <code className="flex-1 px-3 py-2 rounded border text-sm" style={{
                                        background: 'var(--color-bg-card)',
                                        borderColor: 'var(--color-border)',
                                        color: 'var(--color-text-primary)'
                                    }}>
                                        {getPostbackUrl()}
                                    </code>
                                    <button onClick={() => copyToClipboard(getPostbackUrl())} className="btn btn-secondary btn-icon">
                                        {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                                    </button>
                                </div>
                                <div className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                    <strong>{t('networkEditor.paramsToAdd')}</strong> ?subid={'{subid_macro}'}&status={'{status_macro}'}&payout={'{payout_macro}'}
                                </div>
                            </div>

                            {/* Offer Parameters */}
                            <div className="p-4 rounded border space-y-4" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <h3 className="font-medium pb-2" style={{ color: 'var(--color-text-primary)', borderBottom: '1px solid var(--color-border)' }}>{t('networkEditor.offerParams')}</h3>
                                <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('networkEditor.offerParamsDesc')}
                                </p>
                                <div>
                                    <label className="form-label">
                                        {t('networkEditor.offerParamsLabel')}
                                    </label>
                                    <input
                                        type="text"
                                        value={formData.offer_params}
                                        onChange={(e) => setFormData({ ...formData, offer_params: e.target.value })}
                                        className="form-input font-mono"
                                        placeholder="&subid={subid}&sub2={ip}"
                                    />
                                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('networkEditor.example')} &sub1={'{subid}'}&ip={'{ip}'}
                                    </p>
                                </div>

                                {/* Available Macros */}
                                <div>
                                    <label className="form-label mb-2">
                                        {t('networkEditor.availableMacros')}
                                    </label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {availableMacros.map((m) => (
                                            <div
                                                key={m.macro}
                                                className="flex items-center justify-between px-2 py-1 rounded text-xs cursor-pointer"
                                                style={{
                                                    background: 'var(--color-bg-card)',
                                                    border: '1px solid var(--color-border)'
                                                }}
                                                onClick={() => {
                                                    const input = document.querySelector('input[value="' + formData.offer_params + '"]');
                                                    navigator.clipboard.writeText(m.macro);
                                                }}
                                            >
                                                <code style={{ color: 'var(--color-primary)' }}>{m.macro}</code>
                                                <span style={{ color: 'var(--color-text-muted)' }}>{m.description}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Notes */}
                            <div className="p-4 rounded border space-y-4" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                <h3 className="font-medium pb-2" style={{ color: 'var(--color-text-primary)', borderBottom: '1px solid var(--color-border)' }}>{t('networkEditor.notes')}</h3>
                                <textarea
                                    value={formData.notes}
                                    onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                                    className="form-input"
                                    rows={3}
                                    placeholder={t('networkEditor.notesPlaceholder')}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="modal-footer">
                    <button onClick={() => onClose(false)} className="btn btn-secondary">
                        {t('common.cancel')}
                    </button>
                    <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                        {saving ? t('common.saving') : t('common.save')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default AffiliateNetworkEditor;