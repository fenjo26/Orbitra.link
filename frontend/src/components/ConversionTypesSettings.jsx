import React, { useState, useEffect } from 'react';
import { Save, Plus, Edit2, Trash2, RefreshCw, Pipette } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { CONVERSION_COLOR_SWATCHES, FALLBACK_CONVERSION_COLOR } from '../utils/conversionColors';

const API_URL = '/api.php';

const emptyForm = {
    id: null,
    name: '',
    status_values: '',
    next_statuses: '',
    record_conversion: 1,
    record_revenue: 1,
    send_postback: 1,
    affect_cap: 1,
    color: ''
};

// Valid hex or "not customized" — the API applies the same rule.
const normalizeColor = value => {
    const color = String(value || '').trim();
    return /^#[0-9a-fA-F]{6}$/.test(color) ? color : '';
};

const ConversionTypesSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [types, setTypes] = useState([]);
    const [showForm, setShowForm] = useState(false);
    const [formData, setFormData] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });
    const [unmappedStatuses, setUnmappedStatuses] = useState([]);
    const [unmappedLoading, setUnmappedLoading] = useState(false);

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

    const fetchUnmappedStatuses = async () => {
        setUnmappedLoading(true);
        try {
            const res = await fetch(`${API_URL}?action=unmapped_statuses`).then(r => r.json());
            if (res.status === 'success') {
                setUnmappedStatuses(res.data || []);
            }
        } catch (e) {
            console.error('Error fetching unmapped statuses', e);
        } finally {
            setUnmappedLoading(false);
        }
    };

    useEffect(() => { fetchTypes(); fetchUnmappedStatuses(); }, []);

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
                // After saving, trigger retroactive remapping for any new status values
                const newStatuses = formData.status_values.split(',').map(s => s.trim()).filter(s => s);
                let totalReclassified = 0;

                for (const status of newStatuses) {
                    try {
                        const remapRes = await fetch(`${API_URL}?action=retroactive_remap`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                original_status: status,
                                new_status: formData.name
                            })
                        });
                        const remapData = await remapRes.json();
                        if (remapData.status === 'success') {
                            totalReclassified += (remapData.data?.affected_conversions || 0);
                        }
                    } catch (e) {
                        // Continue with other statuses even if one fails
                    }
                }

                setMessage({
                    text: totalReclassified > 0
                        ? t('conversionTypes.mappedSuccessfully', { count: totalReclassified })
                        : t('common.success'),
                    type: 'success'
                });
                setShowForm(false);
                fetchTypes();
                fetchUnmappedStatuses();
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

    const handleMapUnmapped = async (originalStatus, targetTypeName) => {
        const targetType = types.find(type => type.name === targetTypeName);
        if (!targetType) return;

        // Add the status to the target type's status_values
        const currentValues = targetType.status_values ? targetType.status_values.split(',').map(v => v.trim()) : [];
        if (currentValues.map(v => v.toLowerCase()).includes(originalStatus.toLowerCase())) {
            setMessage({ text: t('conversionTypes.alreadyMapped'), type: 'error' });
            return;
        }

        const newValues = [...currentValues, originalStatus].join(',');

        try {
            // Update the conversion type
            const updateRes = await fetch(`${API_URL}?action=conversion_types`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...targetType,
                    status_values: newValues
                })
            });
            const updateData = await updateRes.json();

            if (updateData.status === 'success') {
                // Trigger retroactive remapping
                const remapRes = await fetch(`${API_URL}?action=retroactive_remap`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        original_status: originalStatus,
                        new_status: targetTypeName
                    })
                });
                const remapData = await remapRes.json();

                if (remapData.status === 'success') {
                    setMessage({
                        text: t('conversionTypes.mappedSuccessfully', {
                            count: remapData.data?.affected_conversions || 0
                        }),
                        type: 'success'
                    });
                } else {
                    setMessage({ text: remapData.message || t('common.error'), type: 'error' });
                }

                fetchTypes();
                fetchUnmappedStatuses();
            } else {
                setMessage({ text: updateData.message || t('common.error'), type: 'error' });
            }
        } catch (error) {
            setMessage({ text: t('common.networkError'), type: 'error' });
        }
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
                    <div className="page-header" style={{ borderBottom: '1px solid var(--color-border)', marginBottom: 0, padding: '20px 24px' }}>
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
                                            <td style={{ fontWeight: 500 }}>
                                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
                                                    <span title={normalizeColor(type.color) || t('conversionTypes.colorNotSet')} style={{
                                                        width: '10px',
                                                        height: '10px',
                                                        borderRadius: '50%',
                                                        flexShrink: 0,
                                                        backgroundColor: normalizeColor(type.color) || 'transparent',
                                                        border: normalizeColor(type.color) ? 'none' : '2px dashed var(--color-border)'
                                                    }} />
                                                    {type.name}
                                                </span>
                                            </td>
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

                {/* Unmapped statuses section */}
                {!showForm && (
                    <div className="page-card" style={{ padding: 0 }}>
                        <div className="page-header" style={{ borderBottom: '1px solid var(--color-border)', marginBottom: 0, padding: '20px 24px' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                <h3 className="page-title" style={{ margin: 0 }}>{t('conversionTypes.unmappedStatuses')}</h3>
                                {unmappedLoading && (
                                    <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                        {t('common.loading')}
                                    </span>
                                )}
                            </div>
                            <button onClick={fetchUnmappedStatuses} className="btn btn-secondary btn-sm">
                                <RefreshCw size={16} />
                            </button>
                        </div>
                        {unmappedStatuses.length === 0 ? (
                            <div style={{ padding: '32px', textAlign: 'center', color: 'var(--color-text-muted)' }}>
                                {t('conversionTypes.noUnmappedStatuses')}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="page-table">
                                    <thead>
                                        <tr>
                                            <th>{t('conversionTypes.originalStatus')}</th>
                                            <th>{t('conversionTypes.count')}</th>
                                            <th>{t('conversionTypes.firstSeen')}</th>
                                            <th>{t('conversionTypes.lastSeen')}</th>
                                            <th>{t('conversionTypes.mapTo')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {unmappedStatuses.map((row, idx) => (
                                            <tr key={row.original_status || idx}>
                                                <td>
                                                    <code style={{
                                                        background: 'var(--color-bg-soft)',
                                                        padding: '4px 8px',
                                                        borderRadius: '4px',
                                                        fontSize: '12px'
                                                    }}>
                                                        {row.original_status}
                                                    </code>
                                                </td>
                                                <td style={{ fontWeight: 500 }}>
                                                    {row.count}
                                                </td>
                                                <td style={{ color: 'var(--color-text-secondary)', fontSize: '14px' }}>
                                                    {row.first_seen ? new Date(row.first_seen).toLocaleString() : '-'}
                                                </td>
                                                <td style={{ color: 'var(--color-text-secondary)', fontSize: '14px' }}>
                                                    {row.last_seen ? new Date(row.last_seen).toLocaleString() : '-'}
                                                </td>
                                                <td>
                                                    <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap' }}>
                                                        {types.filter(t => t.name === 'lead' || t.name === 'sale' || t.name === 'rejected' || t.name === 'trash').map(type => (
                                                            <button
                                                                key={type.name}
                                                                onClick={() => handleMapUnmapped(row.original_status, type.name)}
                                                                className="btn btn-secondary btn-sm"
                                                                style={{ fontSize: '12px', padding: '4px 8px' }}
                                                                title={t('conversionTypes.mapToTitle', { type: type.name })}
                                                            >
                                                                {t('conversionTypes.mapToBtn', { type: type.name })}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
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

                            <div>
                                <label className="form-label">{t('conversionTypes.labelColor')}</label>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                                    {CONVERSION_COLOR_SWATCHES.map(swatch => {
                                        const selected = normalizeColor(formData.color) === swatch;
                                        return (
                                            <button
                                                key={swatch}
                                                type="button"
                                                title={swatch}
                                                onClick={() => setFormData(prev => ({ ...prev, color: selected ? '' : swatch }))}
                                                style={{
                                                    width: '24px',
                                                    height: '24px',
                                                    borderRadius: '50%',
                                                    backgroundColor: swatch,
                                                    cursor: 'pointer',
                                                    padding: 0,
                                                    border: 'none',
                                                    // Contrast ring instead of a fixed white one —
                                                    // the swatch grid sits on both light and dark themes.
                                                    outline: selected ? `2px solid var(--color-text-primary)` : `1px solid var(--color-border)`,
                                                    outlineOffset: selected ? '2px' : '0',
                                                    transform: selected ? 'scale(1.1)' : 'none'
                                                }}
                                            />
                                        );
                                    })}
                                    <label
                                        title={t('conversionTypes.customColor')}
                                        style={{
                                            width: '24px',
                                            height: '24px',
                                            borderRadius: '50%',
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            cursor: 'pointer',
                                            background: 'var(--color-bg-soft)',
                                            border: '1px dashed var(--color-border)',
                                            flexShrink: 0
                                        }}
                                    >
                                        <Pipette size={12} style={{ color: 'var(--color-text-secondary)' }} />
                                        <input
                                            type="color"
                                            value={normalizeColor(formData.color) || FALLBACK_CONVERSION_COLOR}
                                            onChange={e => setFormData(prev => ({ ...prev, color: e.target.value }))}
                                            style={{ opacity: 0, width: 0, height: 0, border: 'none', padding: 0, position: 'absolute' }}
                                        />
                                    </label>
                                </div>
                                {/* Live preview: the exact badge shape the conversions log renders. */}
                                <div style={{ marginTop: '10px', display: 'flex', alignItems: 'center', gap: '10px' }}>
                                    <span style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        padding: '3px 10px',
                                        fontSize: '12px',
                                        fontWeight: 600,
                                        borderRadius: '12px',
                                        color: normalizeColor(formData.color) || 'var(--color-text-secondary)',
                                        backgroundColor: normalizeColor(formData.color)
                                            ? `color-mix(in srgb, ${normalizeColor(formData.color)} 14%, transparent)`
                                            : 'var(--color-bg-soft)',
                                        border: normalizeColor(formData.color)
                                            ? `1px solid color-mix(in srgb, ${normalizeColor(formData.color)} 25%, transparent)`
                                            : '1px solid var(--color-border)'
                                    }}>
                                        <span style={{
                                            width: '6px',
                                            height: '6px',
                                            borderRadius: '50%',
                                            backgroundColor: normalizeColor(formData.color) || 'var(--color-text-muted)',
                                            marginRight: '6px'
                                        }} />
                                        {formData.name || t('conversionTypes.metricNamePlaceholder')}
                                    </span>
                                    <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                        {normalizeColor(formData.color) || t('conversionTypes.colorNotSet')}
                                    </span>
                                </div>
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