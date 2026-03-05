import React, { useState, useEffect } from 'react';
import { Save, Plus, Edit2, Trash2, RefreshCw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const emptyForm = {
    id: null,
    name: '',
    status_values: '',
    next_statuses: '',
    record_conversion: 1,
    record_revenue: 1,
    send_postback: 1,
    affect_cap: 1
};

const ConversionTypesSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [types, setTypes] = useState([]);
    const [showForm, setShowForm] = useState(false);
    const [formData, setFormData] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const fetchTypes = async () => {
        try {
            const res = await fetch(`${API_URL}?action=conversion_types`).then(r => r.json());
            if (res.status === 'success') {
                setTypes(res.data || []);
            }
        } catch (e) {
            console.error('Error fetching conversion types', e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchTypes(); }, []);

    const handleChange = (e) => {
        const { name, value, type, checked } = e.target;
        setFormData(prev => ({ ...prev, [name]: type === 'checkbox' ? (checked ? 1 : 0) : value }));
    };

    const handleSave = async () => {
        if (!formData.name.trim() || !formData.status_values.trim()) {
            setMessage({ text: t('conversionTypes.nameAndMacrosRequired'), type: 'error' });
            return;
        }

        setSaving(true);
        setMessage({ text: '', type: '' });

        try {
            const res = await fetch(`${API_URL}?action=conversion_types`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            const data = await res.json();

            if (data.status === 'success') {
                setMessage({ text: t('common.success'), type: 'success' });
                setShowForm(false);
                fetchTypes();
            } else {
                setMessage({ text: data.message || t('common.error'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('common.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async (id) => {
        if (!confirm(t('conversionTypes.deleteConfirm'))) return;
        try {
            await fetch(`${API_URL}?action=conversion_types`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id })
            });
            fetchTypes();
        } catch (e) {
            alert(t('conversionTypes.deleteError'));
        }
    };

    const handleEdit = (type) => {
        setFormData(type);
        setShowForm(true);
        setMessage({ text: '', type: '' });
    };

    const handleNew = () => {
        setFormData(emptyForm);
        setShowForm(true);
        setMessage({ text: '', type: '' });
    };

    if (loading) {
        return (
            <div className="page-card">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('conversionTypes.loading')}</p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {message.text && (
                <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'}`}>
                    {message.text}
                </div>
            )}

            {!showForm ? (
                <div className="page-card" style={{ padding: 0 }}>
                    <div className="page-header" style={{ borderBottom: '1px solid var(--color-border)', marginBottom: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                            <RefreshCw size={18} style={{ color: 'var(--color-primary)' }} />
                            <h3 className="page-title" style={{ margin: 0 }}>{t('conversionTypes.title')}</h3>
                        </div>
                        <button onClick={handleNew} className="btn btn-primary btn-sm">
                            <Plus size={16} />
                            {t('conversionTypes.addType')}
                        </button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>{t('conversionTypes.name')}</th>
                                    <th>{t('conversionTypes.statusMacros')}</th>
                                    <th style={{ textAlign: 'center' }} title={t('conversionTypes.conversionTitle')}>{t('conversionTypes.conversion')}</th>
                                    <th style={{ textAlign: 'center' }} title={t('conversionTypes.profitTitle')}>{t('conversionTypes.profit')}</th>
                                    <th style={{ textAlign: 'center' }} title={t('conversionTypes.s2sTitle')}>{t('conversionTypes.s2s')}</th>
                                    <th style={{ textAlign: 'center' }} title={t('conversionTypes.capTitle')}>{t('conversionTypes.cap')}</th>
                                    <th style={{ width: '80px' }}></th>
                                </tr>
                            </thead>
                            <tbody>
                                {types.length === 0 ? (
                                    <tr>
                                        <td colSpan="7" className="text-center" style={{ padding: '32px', color: 'var(--color-text-muted)' }}>
                                            {t('conversionTypes.noTypes')}
                                        </td>
                                    </tr>
                                ) : (
                                    types.map(type => (
                                        <tr key={type.id}>
                                            <td style={{ fontWeight: 500 }}>{type.name}</td>
                                            <td style={{ fontFamily: 'monospace', fontSize: '12px', color: 'var(--color-text-secondary)' }}>{type.status_values}</td>
                                            <td className="text-center">{type.record_conversion === 1 ? '✅' : '❌'}</td>
                                            <td className="text-center">{type.record_revenue === 1 ? '✅' : '❌'}</td>
                                            <td className="text-center">{type.send_postback === 1 ? '✅' : '❌'}</td>
                                            <td className="text-center">{type.affect_cap === 1 ? '✅' : '❌'}</td>
                                            <td>
                                                <div className="action-buttons">
                                                    <button onClick={() => handleEdit(type)} className="action-btn text-blue">
                                                        <Edit2 size={16} />
                                                    </button>
                                                    <button onClick={() => handleDelete(type.id)} className="action-btn text-red">
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : (
                <div className="page-card">
                    <div className="page-header" style={{ borderBottom: '1px solid var(--color-border)', marginBottom: 0 }}>
                        <h3 className="page-title" style={{ margin: 0 }}>{formData.id ? t('conversionTypes.editType') : t('conversionTypes.newType')}</h3>
                        <button onClick={() => setShowForm(false)} style={{ color: 'var(--color-text-secondary)', background: 'none', border: 'none', cursor: 'pointer', fontSize: '14px' }}>{t('conversionTypes.cancel')}</button>
                    </div>
                    <div style={{ marginTop: '24px', maxWidth: '600px' }}>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
                            <div>
                                <label className="form-label">{t('conversionTypes.metricName')}</label>
                                <input
                                    type="text"
                                    name="name"
                                    value={formData.name}
                                    onChange={handleChange}
                                    placeholder={t('conversionTypes.metricNamePlaceholder')}
                                    className="form-input"
                                />
                            </div>
                            <div>
                                <label className="form-label">{t('conversionTypes.expectedMacros')}</label>
                                <input
                                    type="text"
                                    name="status_values"
                                    value={formData.status_values}
                                    onChange={handleChange}
                                    placeholder={t('conversionTypes.expectedMacrosPlaceholder')}
                                    className="form-input"
                                    style={{ fontFamily: 'monospace' }}
                                />
                                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '6px' }}>
                                    {t('conversionTypes.expectedMacrosHint')}
                                </p>
                            </div>

                            <div style={{ paddingTop: '16px', borderTop: '1px solid var(--color-border)', display: 'flex', flexDirection: 'column', gap: '16px' }}>
                                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        name="record_conversion"
                                        checked={formData.record_conversion === 1}
                                        onChange={handleChange}
                                        style={{ marginTop: '2px' }}
                                    />
                                    <div>
                                        <span style={{ fontWeight: 500 }}>{t('conversionTypes.affectConversions')}</span>
                                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', margin: '2px 0 0 0' }}>{t('conversionTypes.affectConversionsDesc')}</p>
                                    </div>
                                </label>
                                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        name="record_revenue"
                                        checked={formData.record_revenue === 1}
                                        onChange={handleChange}
                                        style={{ marginTop: '2px' }}
                                    />
                                    <div>
                                        <span style={{ fontWeight: 500 }}>{t('conversionTypes.affectProfit')}</span>
                                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', margin: '2px 0 0 0' }}>{t('conversionTypes.affectProfitDesc')}</p>
                                    </div>
                                </label>
                                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        name="send_postback"
                                        checked={formData.send_postback === 1}
                                        onChange={handleChange}
                                        style={{ marginTop: '2px' }}
                                    />
                                    <div>
                                        <span style={{ fontWeight: 500 }}>{t('conversionTypes.sendS2s')}</span>
                                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', margin: '2px 0 0 0' }}>{t('conversionTypes.sendS2sDesc')}</p>
                                    </div>
                                </label>
                                <label style={{ display: 'flex', alignItems: 'flex-start', gap: '12px', cursor: 'pointer' }}>
                                    <input
                                        type="checkbox"
                                        name="affect_cap"
                                        checked={formData.affect_cap === 1}
                                        onChange={handleChange}
                                        style={{ marginTop: '2px' }}
                                    />
                                    <span style={{ fontWeight: 500 }}>{t('conversionTypes.affectCap')}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'flex-end' }}>
                        <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                            <Save size={18} />
                            {saving ? t('common.saving') : t('conversionTypes.saveType')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ConversionTypesSettings;