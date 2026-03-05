import React, { useState, useEffect } from 'react';
import { Save, Plus, Edit2, Trash2, HelpCircle, BarChart2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const emptyForm = {
    id: null,
    name: '',
    formula: '',
    format: 'number',
    decimals: 2
};

const CustomMetricsSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [metrics, setMetrics] = useState([]);
    const [showForm, setShowForm] = useState(false);
    const [formData, setFormData] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const fetchMetrics = async () => {
        try {
            const res = await fetch(`${API_URL}?action=custom_metrics`).then(r => r.json());
            if (res.status === 'success') {
                setMetrics(res.data || []);
            }
        } catch (e) {
            console.error('Error fetching custom metrics', e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchMetrics(); }, []);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    const handleSave = async () => {
        if (!formData.name.trim() || !formData.formula.trim()) {
            setMessage({ text: t('customMetrics.nameAndFormulaRequired'), type: 'error' });
            return;
        }

        setSaving(true);
        setMessage({ text: '', type: '' });

        try {
            const res = await fetch(`${API_URL}?action=custom_metrics`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: formData.id,
                    name: formData.name,
                    formula: formData.formula,
                    format: formData.format,
                    decimals: parseInt(formData.decimals) || 0
                })
            });
            const data = await res.json();

            if (data.status === 'success') {
                setMessage({ text: t('customMetrics.saved'), type: 'success' });
                setShowForm(false);
                fetchMetrics();
            } else {
                setMessage({ text: data.message || t('customMetrics.saveError'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('customMetrics.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async (id) => {
        if (!confirm(t('customMetrics.deleteConfirm'))) return;
        try {
            await fetch(`${API_URL}?action=custom_metrics`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id })
            });
            fetchMetrics();
        } catch (e) {
            alert(t('customMetrics.deleteError'));
        }
    };

    const handleEdit = (metric) => {
        setFormData(metric);
        setShowForm(true);
        setMessage({ text: '', type: '' });
    };

    const handleNew = () => {
        setFormData(emptyForm);
        setShowForm(true);
        setMessage({ text: '', type: '' });
    };

    const getFormatLabel = (format) => {
        switch (format) {
            case 'number': return t('customMetrics.number');
            case 'currency': return t('customMetrics.currency');
            case 'percent': return t('customMetrics.percent');
            default: return format;
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
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
                            <BarChart2 size={18} style={{ color: 'var(--color-primary)' }} />
                            <h3 className="page-title" style={{ margin: 0 }}>{t('customMetrics.title')}</h3>
                        </div>
                        <button onClick={handleNew} className="btn btn-primary btn-sm">
                            <Plus size={16} />
                            {t('customMetrics.createMetric')}
                        </button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>{t('customMetrics.name')}</th>
                                    <th>{t('customMetrics.formula')}</th>
                                    <th style={{ textAlign: 'center' }}>{t('customMetrics.format')}</th>
                                    <th style={{ width: '80px' }}></th>
                                </tr>
                            </thead>
                            <tbody>
                                {metrics.length === 0 ? (
                                    <tr>
                                        <td colSpan="4" className="text-center" style={{ padding: '32px', color: 'var(--color-text-muted)' }}>
                                            {t('customMetrics.noMetrics')}
                                        </td>
                                    </tr>
                                ) : (
                                    metrics.map(m => (
                                        <tr key={m.id}>
                                            <td style={{ fontWeight: 500 }}>{m.name}</td>
                                            <td style={{ fontFamily: 'monospace', fontSize: '12px', color: 'var(--color-text-secondary)' }}>{m.formula}</td>
                                            <td className="text-center" style={{ fontSize: '12px', color: 'var(--color-text-secondary)' }}>
                                                {getFormatLabel(m.format)} ({m.decimals})
                                            </td>
                                            <td>
                                                <div className="action-buttons">
                                                    <button onClick={() => handleEdit(m)} className="action-btn text-blue">
                                                        <Edit2 size={16} />
                                                    </button>
                                                    <button onClick={() => handleDelete(m.id)} className="action-btn text-red">
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
                        <h3 className="page-title" style={{ margin: 0 }}>{formData.id ? t('customMetrics.editMetric') : t('customMetrics.newMetric')}</h3>
                        <button onClick={() => setShowForm(false)} style={{ color: 'var(--color-text-secondary)', background: 'none', border: 'none', cursor: 'pointer', fontSize: '14px' }}>{t('common.cancel')}</button>
                    </div>
                    <div style={{ marginTop: '24px', maxWidth: '700px' }}>
                        <div style={{ marginBottom: '24px', padding: '16px', background: 'var(--color-primary-light)', borderRadius: '12px', border: '1px solid var(--color-primary)' }}>
                            <div style={{ display: 'flex', gap: '12px' }}>
                                <HelpCircle size={20} style={{ color: 'var(--color-primary)', flexShrink: 0, marginTop: '2px' }} />
                                <div>
                                    <p style={{ fontWeight: 500, marginBottom: '8px', color: 'var(--color-primary)' }}>{t('customMetrics.formulaVarsTitle')}</p>
                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '4px 16px', fontFamily: 'monospace', fontSize: '12px', color: 'var(--color-text-secondary)' }}>
                                        <span><code>clicks</code> — {t('customMetrics.totalClicks')}</span>
                                        <span><code>unique_clicks</code> — {t('customMetrics.uniqueClicks')}</span>
                                        <span><code>conversions</code> — {t('customMetrics.conversionsVar')}</span>
                                        <span><code>revenue</code> — {t('customMetrics.revenueVar')}</span>
                                        <span><code>cost</code> — {t('customMetrics.costVar')}</span>
                                    </div>
                                    <p style={{ fontSize: '12px', marginTop: '12px', color: 'var(--color-text-secondary)' }}>
                                        {t('customMetrics.operatorsHint')} <code style={{ background: 'var(--color-bg)', padding: '2px 6px', borderRadius: '4px' }}>(revenue - cost) / cost * 100</code>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '20px' }}>
                            <div style={{ gridColumn: '1 / -1' }}>
                                <label className="form-label">{t('customMetrics.metricNameInReports')}</label>
                                <input
                                    type="text"
                                    name="name"
                                    value={formData.name}
                                    onChange={handleChange}
                                    placeholder={t('customMetrics.placeholder')}
                                    className="form-input"
                                />
                            </div>
                            <div style={{ gridColumn: '1 / -1' }}>
                                <label className="form-label">{t('customMetrics.calculationFormula')}</label>
                                <input
                                    type="text"
                                    name="formula"
                                    value={formData.formula}
                                    onChange={handleChange}
                                    placeholder="revenue / clicks"
                                    className="form-input"
                                    style={{ fontFamily: 'monospace' }}
                                />
                            </div>
                            <div>
                                <label className="form-label">{t('customMetrics.outputFormat')}</label>
                                <select
                                    name="format"
                                    value={formData.format}
                                    onChange={handleChange}
                                    className="form-select"
                                >
                                    <option value="number">{t('customMetrics.number')}</option>
                                    <option value="currency">{t('customMetrics.currency')}</option>
                                    <option value="percent">{t('customMetrics.percent')}</option>
                                </select>
                            </div>
                            <div>
                                <label className="form-label">{t('customMetrics.decimalPlaces')}</label>
                                <input
                                    type="number"
                                    name="decimals"
                                    value={formData.decimals}
                                    onChange={handleChange}
                                    min="0"
                                    max="6"
                                    className="form-input"
                                />
                            </div>
                        </div>
                    </div>
                    <div style={{ marginTop: '24px', display: 'flex', justifyContent: 'flex-end' }}>
                        <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                            <Save size={18} />
                            {saving ? t('common.saving') : t('customMetrics.saveMetric')}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default CustomMetricsSettings;