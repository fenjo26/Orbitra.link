import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Globe2, Pencil, Plus, RefreshCw, Trash2, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const EMPTY_FORM = { name: '', service_key: 'custom', url: '', postback_url: '', state: 'active' };

// External PWA builders (stage 4.1): third-party services as stream targets.
// The service catalog (PWA.Group / TSL.app / custom) ships from the server;
// its templates are the starting point — the operator's own URL/postback
// values win. The return URL hint tells the operator what to paste into the
// service's settings, so installs come back to /eflow/<campaign>.
const ExternalPwasPage = () => {
    const { t } = useLanguage();
    const [rows, setRows] = useState([]);
    const [services, setServices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await axios.get(API_URL, { params: { action: 'external_pwas' } });
            if (res.data?.status === 'success') {
                setRows(res.data.data || []);
                setServices(res.data.services || []);
            } else {
                setError(res.data?.message || t('extPwa.errLoad', 'Load failed'));
            }
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        } finally {
            setLoading(false);
        }
    }, [t]);

    useEffect(() => { load(); }, [load]);

    const openForm = (row = null) => {
        setEditingId(row ? row.id : null);
        setForm(row
            ? { name: row.name, service_key: row.service_key, url: row.url || '', postback_url: row.postback_url || '', state: row.state || 'active' }
            : EMPTY_FORM);
        setShowForm(true);
    };

    const save = async () => {
        try {
            const res = await axios.post(API_URL, { ...form, id: editingId }, { params: { action: 'external_pwas' } });
            if (res.data?.status !== 'success') {
                setError(res.data?.message || t('extPwa.errSave', 'Save failed'));
                return;
            }
            setShowForm(false);
            load();
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        }
    };

    const remove = async (id) => {
        if (!confirm(t('extPwa.confirmDelete'))) return;
        await axios.post(API_URL, { action: 'delete', id }, { params: { action: 'external_pwas' } });
        load();
    };

    const service = (key) => services.find(s => s.key === key) || {};

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div>
                    <h3 className="page-title m-0 flex items-center gap-2"><Globe2 className="w-5 h-5" /> {t('extPwa.title')}</h3>
                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('extPwa.subtitle')}</p>
                </div>
                <div className="flex gap-2">
                    <button type="button" className="btn btn-sm" onClick={load}><RefreshCw className="w-3.5 h-3.5" /></button>
                    <button type="button" className="btn btn-primary" onClick={() => openForm()}><Plus className="w-4 h-4" /> {t('extPwa.add')}</button>
                </div>
            </div>

            {loading && <div className="py-8 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>}
            {error && <div className="alert alert-danger mb-3">{error}</div>}

            {!loading && rows.length === 0 && (
                <div className="card p-8 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('extPwa.empty')}</div>
            )}

            {!loading && rows.length > 0 && (
                <div className="table-container">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left' }}>{t('extPwa.name')}</th>
                                <th>{t('extPwa.service')}</th>
                                <th style={{ textAlign: 'left' }}>{t('extPwa.redirectUrl')}</th>
                                <th>{t('extPwa.installs')}</th>
                                <th>{t('extPwa.status')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(r => (
                                <tr key={r.id}>
                                    <td style={{ textAlign: 'left', fontWeight: 600 }}>{r.name}</td>
                                    <td>{service(r.service_key).name || r.service_key}</td>
                                    <td style={{ textAlign: 'left' }}>
                                        <div className="truncate" style={{ maxWidth: 320 }} title={r.effective_url}>{r.effective_url || '—'}</div>
                                        <div className="text-xs truncate" style={{ color: 'var(--color-text-muted)', maxWidth: 320 }} title={r.effective_postback_url}>
                                            ↩ {r.effective_postback_url || t('extPwa.noPostback')}
                                        </div>
                                    </td>
                                    <td>{Number(r.installs || 0)}</td>
                                    <td>{r.state === 'active' ? t('cc.active') : t('cc.paused')}</td>
                                    <td>
                                        <div className="flex gap-1">
                                            <button type="button" className="action-btn" title={t('common.edit')} onClick={() => openForm(r)}><Pencil className="w-4 h-4" /></button>
                                            <button type="button" className="action-btn text-red" title={t('common.delete')} onClick={() => remove(r.id)}><Trash2 className="w-4 h-4" /></button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {!loading && (
                <div className="card mt-4 p-4 text-sm" style={{ color: 'var(--color-text-muted)' }}>
                    <div className="font-semibold mb-1" style={{ color: 'var(--color-text-primary)' }}>{t('extPwa.setupTitle')}</div>
                    <div>1. {t('extPwa.step1')}</div>
                    <div>2. {t('extPwa.step2')}</div>
                    <div className="font-mono text-xs mt-1">https://&lt;{t('extPwa.yourDomain')}&gt;/eflow/&lt;{t('extPwa.campaignAlias')}&gt;?subid=&#123;subid&#125;&amp;eid=&#123;eid&#125;</div>
                    <div>3. {t('extPwa.step3')}</div>
                </div>
            )}

            {showForm && (
                <div className="modal-overlay" onClick={() => setShowForm(false)}>
                    <div className="modal-content" onClick={e => e.stopPropagation()} style={{ maxWidth: 560, maxHeight: '88vh', overflowY: 'auto' }}>
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-base font-bold m-0">{editingId ? t('extPwa.edit') : t('extPwa.add')}</h3>
                            <button type="button" className="action-btn" onClick={() => setShowForm(false)}><X className="w-5 h-5" /></button>
                        </div>
                        <div className="space-y-3">
                            <div>
                                <label className="form-label">{t('extPwa.name')}</label>
                                <input className="form-input w-full" value={form.name} maxLength={100}
                                       onChange={e => setForm({ ...form, name: e.target.value })} placeholder="PWA.Group — main" />
                            </div>
                            <div>
                                <label className="form-label">{t('extPwa.service')}</label>
                                <select className="form-select w-full" value={form.service_key}
                                        onChange={e => setForm({ ...form, service_key: e.target.value })}>
                                    {services.map(s => <option key={s.key} value={s.key}>{s.name}</option>)}
                                </select>
                                <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                    {service(form.service_key).notes || t('extPwa.serviceHint')}
                                </div>
                            </div>
                            <div>
                                <label className="form-label">{t('extPwa.redirectUrl')} ({t('extPwa.optional')})</label>
                                <input className="form-input w-full font-mono text-xs" value={form.url}
                                       onChange={e => setForm({ ...form, url: e.target.value })}
                                       placeholder={service(form.service_key).url_template || 'https://…?sub={subid}'} />
                                <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('extPwa.subidHint')}</div>
                            </div>
                            <div>
                                <label className="form-label">{t('extPwa.postbackUrl')} ({t('extPwa.optional')})</label>
                                <input className="form-input w-full font-mono text-xs" value={form.postback_url}
                                       onChange={e => setForm({ ...form, postback_url: e.target.value })}
                                       placeholder={service(form.service_key).postback_url_template || 'https://…?subid={subid}&eid={eid}&status={status}'} />
                            </div>
                            <div className="flex justify-end gap-2 pt-2">
                                <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>{t('common.cancel')}</button>
                                <button type="button" className="btn btn-primary" disabled={!form.name.trim()} onClick={save}>{t('common.save')}</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ExternalPwasPage;
