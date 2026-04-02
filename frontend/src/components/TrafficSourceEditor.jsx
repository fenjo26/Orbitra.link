import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { X, Save, Plus, Trash2, Info } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const TrafficSourceEditor = ({ id, onClose, onSave }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(false);
    const [templates, setTemplates] = useState([]);
    const [formData, setFormData] = useState({
        name: '',
        template: '',
        postback_url: '',
        postback_statuses: 'lead,sale',
        parameters: [],
        notes: '',
        state: 'active',
        url: '',
        http_status: 'unknown',
        last_checked: null,
        status_message: null
    });

    useEffect(() => {
        // Load templates
        axios.get(`${API_URL}?action=traffic_source_templates`).then(res => {
            if (res.data.status === 'success') {
                setTemplates(res.data.data);
            }
        });

        // Load existing source if editing
        if (id) {
            setLoading(true);
            axios.get(`${API_URL}?action=get_traffic_source&id=${id}`).then(res => {
                if (res.data.status === 'success') {
                    const data = res.data.data;
                    setFormData({
                        name: data.name || '',
                        template: data.template || '',
                        postback_url: data.postback_url || '',
                        postback_statuses: data.postback_statuses || 'lead,sale',
                        parameters: data.parameters || [],
                        notes: data.notes || '',
                        state: data.state || 'active',
                        url: data.url || '',
                        http_status: data.http_status || 'unknown',
                        last_checked: data.last_checked || null,
                        status_message: data.status_message || null
                    });
                }
                setLoading(false);
            });
        }
    }, [id]);

    const handleTemplateChange = (templateName) => {
        const template = templates.find(t => t.name === templateName);
        if (template) {
            setFormData(prev => ({
                ...prev,
                template: templateName,
                name: prev.name || template.display_name,
                postback_url: template.postback_url || '',
                parameters: template.parameters ? [...template.parameters] : []
            }));
        } else {
            setFormData(prev => ({
                ...prev,
                template: '',
                parameters: []
            }));
        }
    };

    const handleParameterChange = (index, field, value) => {
        const newParams = [...formData.parameters];
        newParams[index] = { ...newParams[index], [field]: value };
        setFormData(prev => ({ ...prev, parameters: newParams }));
    };

    const addParameter = () => {
        setFormData(prev => ({
            ...prev,
            parameters: [...prev.parameters, { alias: '', param: '', macro: '' }]
        }));
    };

    const removeParameter = (index) => {
        const newParams = formData.parameters.filter((_, i) => i !== index);
        setFormData(prev => ({ ...prev, parameters: newParams }));
    };

    const handleSubmit = async () => {
        if (!formData.name) {
            alert(t('botSettings.fillName'));
            return;
        }

        try {
            const payload = { ...formData, id };
            await axios.post(`${API_URL}?action=traffic_sources`, payload);
            onSave();
        } catch (error) {
            console.error('Error saving traffic source:', error);
            alert(t('common.error'));
        }
    };

    const statusOptions = ['lead', 'sale', 'rejected', 'rebill', 'trash'];

    const toggleStatus = (status) => {
        const current = formData.postback_statuses.split(',').filter(s => s);
        if (current.includes(status)) {
            setFormData(prev => ({
                ...prev,
                postback_statuses: current.filter(s => s !== status).join(',')
            }));
        } else {
            setFormData(prev => ({
                ...prev,
                postback_statuses: [...current, status].join(',')
            }));
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '800px' }}>
                {/* Header */}
                <div className="modal-header">
                    <h2 className="modal-title">
                        {id ? t('sources.title') : t('sources.title')}
                    </h2>
                    <button onClick={onClose} className="action-btn">
                        <X size={20} />
                    </button>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto p-6">
                    {loading ? (
                        <div className="flex justify-center py-8">
                            <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                        </div>
                    ) : (
                        <div className="space-y-5">
                            {/* Template Selection */}
                            <div>
                                <label className="form-label">{t('sourceEditor.templateLabel')}</label>
                                <select
                                    value={formData.template}
                                    onChange={(e) => handleTemplateChange(e.target.value)}
                                    className="form-select"
                                >
                                    <option value="">⚡ Кастомный источник (без шаблона)</option>
                                    <option disabled>──────────</option>
                                    {templates.map(t => (
                                        <option key={t.name} value={t.name}>{t.display_name}</option>
                                    ))}
                                </select>
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                    {!formData.template
                                        ? "Создайте свой источник — просто укажите имя и URL ниже. Шаблон не обязателен."
                                        : t('sourceEditor.templateHint')
                                    }
                                </p>
                            </div>

                            {/* Name */}
                            <div>
                                <label className="form-label">{t('sourceEditor.nameLabel')}</label>
                                <input
                                    type="text"
                                    value={formData.name}
                                    onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                                    className="form-input"
                                    placeholder={t('sourceEditor.namePlaceholder')}
                                />
                            </div>

                            {/* URL для проверки доступности */}
                            <div>
                                <label className="form-label">URL (для проверки)</label>
                                <input
                                    type="url"
                                    value={formData.url || ''}
                                    onChange={(e) => setFormData(prev => ({ ...prev, url: e.target.value }))}
                                    className="form-input"
                                    placeholder="https://example.com"
                                />
                                {formData.http_status && formData.http_status !== 'unknown' && (
                                    <div className="flex items-center gap-2 mt-1">
                                        <span className={`text-xs ${formData.http_status === '200' ? 'text-green-600' : 'text-red-600'}`}>
                                            {formData.http_status === '200' ? '✓ Доступен' : `✗ ${formData.http_status}`}
                                        </span>
                                        {formData.last_checked && (
                                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                Проверено: {new Date(formData.last_checked).toLocaleString('ru-RU')}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* State */}
                            <div>
                                <label className="form-label">{t('sourceEditor.status')}</label>
                                <select
                                    value={formData.state}
                                    onChange={(e) => setFormData(prev => ({ ...prev, state: e.target.value }))}
                                    className="form-select"
                                >
                                    <option value="active">{t('sourceEditor.active')}</option>
                                    <option value="paused">{t('sourceEditor.paused')}</option>
                                </select>
                            </div>

                            {/* S2S Postback */}
                            <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                <h3 className="font-medium mb-3" style={{ color: 'var(--color-text-primary)' }}>S2S Postback</h3>
                                <p className="text-sm mb-3" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('sourceEditor.postbackDesc')}
                                </p>
                                <div className="space-y-3">
                                    <div>
                                        <label className="form-label">Postback URL</label>
                                        <input
                                            type="text"
                                            value={formData.postback_url}
                                            onChange={(e) => setFormData(prev => ({ ...prev, postback_url: e.target.value }))}
                                            className="form-input"
                                            placeholder="https://example.com/postback?clickid={external_id}&sum={payout}"
                                        />
                                    </div>
                                    <div>
                                        <label className="form-label">{t('sourceEditor.sendStatuses')}</label>
                                        <div className="flex flex-wrap gap-3">
                                            {statusOptions.map(status => (
                                                <label key={status} className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={formData.postback_statuses.split(',').includes(status)}
                                                        onChange={() => toggleStatus(status)}
                                                        className="rounded"
                                                    />
                                                    <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>{status}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                {/* Macros help */}
                                <div className="mt-3 p-3 rounded-xl text-xs" style={{ backgroundColor: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                    <p className="font-medium mb-1" style={{ color: 'var(--color-text-primary)' }}>{t('sourceEditor.availableMacros')}</p>
                                    <code style={{ color: 'var(--color-primary)' }}>
                                        {'{clickid} {external_id} {payout} {status} {offer_id} {campaign_id} {campaign_name}'}
                                    </code>
                                </div>
                            </div>

                            {/* Parameters */}
                            <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                <div className="flex items-center justify-between mb-3">
                                    <h3 className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('sourceEditor.parameters')}</h3>
                                    <button
                                        onClick={addParameter}
                                        className="btn btn-primary btn-sm"
                                    >
                                        <Plus size={14} />
                                        <span>{t('sourceEditor.addParam')}</span>
                                    </button>
                                </div>
                                <p className="text-sm mb-3" style={{ color: 'var(--color-text-secondary)' }}>
                                    {t('sourceEditor.paramsDesc')}
                                </p>

                                {formData.parameters.length > 0 ? (
                                    <div className="space-y-2">
                                        {/* Header */}
                                        <div className="grid grid-cols-12 gap-2 text-xs px-2" style={{ color: 'var(--color-text-muted)' }}>
                                            <div className="col-span-3">{t('sourceEditor.alias')}</div>
                                            <div className="col-span-4">{t('sourceEditor.param')}</div>
                                            <div className="col-span-4">{t('sourceEditor.sourceMacro')}</div>
                                            <div className="col-span-1"></div>
                                        </div>
                                        {/* Rows */}
                                        {formData.parameters.map((param, index) => (
                                            <div key={index} className="grid grid-cols-12 gap-2 items-center">
                                                <input
                                                    type="text"
                                                    value={param.alias || ''}
                                                    onChange={(e) => handleParameterChange(index, 'alias', e.target.value)}
                                                    className="form-input col-span-3 text-sm py-1.5"
                                                    placeholder="utm_source"
                                                />
                                                <input
                                                    type="text"
                                                    value={param.param || ''}
                                                    onChange={(e) => handleParameterChange(index, 'param', e.target.value)}
                                                    className="form-input col-span-4 text-sm py-1.5"
                                                    placeholder="sub_id_1"
                                                />
                                                <input
                                                    type="text"
                                                    value={param.macro || ''}
                                                    onChange={(e) => handleParameterChange(index, 'macro', e.target.value)}
                                                    className="form-input col-span-4 text-sm py-1.5"
                                                    placeholder="{source_id}"
                                                />
                                                <button
                                                    onClick={() => removeParameter(index)}
                                                    className="action-btn col-span-1 text-red"
                                                >
                                                    <Trash2 size={14} />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm italic" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('sourceEditor.noParams')}
                                    </p>
                                )}
                            </div>

                            {/* Notes */}
                            <div>
                                <label className="form-label">{t('sourceEditor.notes')}</label>
                                <textarea
                                    value={formData.notes}
                                    onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                                    rows={3}
                                    className="form-input resize-none"
                                    placeholder={t('sourceEditor.notesPlaceholder')}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="modal-footer">
                    <button onClick={onClose} className="btn btn-secondary">
                        {t('common.cancel')}
                    </button>
                    <button onClick={handleSubmit} className="btn btn-primary">
                        <Save size={18} />
                        <span>{t('common.save')}</span>
                    </button>
                </div>
            </div>
        </div>
    );
};

export default TrafficSourceEditor;