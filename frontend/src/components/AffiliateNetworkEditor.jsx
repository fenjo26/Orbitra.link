import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X, Save, Copy, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

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
            const res = await axios.get(`${API_URL}?action=affiliate_network_templates`);
            if (res.data.status === 'success') {
                setTemplates(res.data.data);
            }
        } catch (err) {
            console.error(err);
        }
    };

    const fetchNetwork = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=get_affiliate_network&id=${networkId}`);
            if (res.data.status === 'success') {
                setFormData({
                    name: res.data.data.name || '',
                    template: res.data.data.template || 'generic',
                    offer_params: res.data.data.offer_params || '',
                    postback_url: res.data.data.postback_url || '',
                    notes: res.data.data.notes || '',
                    state: res.data.data.state || 'active'
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
            setFormData({
                ...formData,
                template: templateName,
                offer_params: template.offer_params_template || formData.offer_params
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

            const res = await axios.post(`${API_URL}?action=affiliate_networks`, payload);
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
                            <div className="bg-white p-4 rounded border border-gray-200 space-y-4">
                                <h3 className="font-medium text-gray-800 border-b pb-2">{t('networkEditor.basicSettings')}</h3>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            {t('networkEditor.nameLabel')}
                                        </label>
                                        <input
                                            type="text"
                                            value={formData.name}
                                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
                                            placeholder={t('networkEditor.namePlaceholder')}
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-1">
                                            {t('networkEditor.template')}
                                        </label>
                                        <select
                                            value={formData.template}
                                            onChange={(e) => handleTemplateChange(e.target.value)}
                                            className="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white"
                                        >
                                            {templates.map((t) => (
                                                <option key={t.name} value={t.name}>{t.display_name}</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        {t('networkEditor.status')}
                                    </label>
                                    <select
                                        value={formData.state}
                                        onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                                        className="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white"
                                    >
                                        <option value="active">{t('networkEditor.active')}</option>
                                        <option value="paused">{t('networkEditor.disabled')}</option>
                                    </select>
                                </div>
                            </div>

                            {/* Postback URL */}
                            <div className="bg-blue-50 p-4 rounded border border-blue-200 space-y-3">
                                <h3 className="font-medium text-blue-800">Postback URL</h3>
                                <p className="text-sm text-blue-600">
                                    {t('networkEditor.postbackHint')}
                                </p>
                                <div className="flex items-center space-x-2">
                                    <code className="flex-1 bg-white px-3 py-2 rounded border border-blue-200 text-sm">
                                        {getPostbackUrl()}
                                    </code>
                                    <button
                                        onClick={() => copyToClipboard(getPostbackUrl())}
                                        className="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition"
                                    >
                                        {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                                    </button>
                                </div>
                                <div className="text-xs text-blue-600">
                                    <strong>{t('networkEditor.paramsToAdd')}</strong> ?subid={'{макрос_субид}'}&status={'{макрос_статуса}'}&payout={'{макрос_суммы}'}
                                </div>
                            </div>

                            {/* Offer Parameters */}
                            <div className="bg-white p-4 rounded border border-gray-200 space-y-4">
                                <h3 className="font-medium text-gray-800 border-b pb-2">{t('networkEditor.offerParams')}</h3>
                                <p className="text-sm text-gray-500">
                                    {t('networkEditor.offerParamsDesc')}
                                </p>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        {t('networkEditor.offerParamsLabel')}
                                    </label>
                                    <input
                                        type="text"
                                        value={formData.offer_params}
                                        onChange={(e) => setFormData({ ...formData, offer_params: e.target.value })}
                                        className="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono"
                                        placeholder="&subid={subid}&sub2={ip}"
                                    />
                                    <p className="text-xs text-gray-400 mt-1">
                                        {t('networkEditor.example')} &sub1={'{subid}'}&ip={'{ip}'}
                                    </p>
                                </div>

                                {/* Available Macros */}
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        {t('networkEditor.availableMacros')}
                                    </label>
                                    <div className="grid grid-cols-2 gap-2">
                                        {availableMacros.map((m) => (
                                            <div
                                                key={m.macro}
                                                className="flex items-center justify-between bg-gray-50 px-2 py-1 rounded text-xs cursor-pointer hover:bg-gray-100"
                                                onClick={() => {
                                                    const input = document.querySelector('input[value="' + formData.offer_params + '"]');
                                                    navigator.clipboard.writeText(m.macro);
                                                }}
                                            >
                                                <code className="text-blue-600">{m.macro}</code>
                                                <span className="text-gray-400">{m.description}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Notes */}
                            <div className="bg-white p-4 rounded border border-gray-200 space-y-4">
                                <h3 className="font-medium text-gray-800 border-b pb-2">{t('networkEditor.notes')}</h3>
                                <textarea
                                    value={formData.notes}
                                    onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                                    className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
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