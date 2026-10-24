import React, { useState, useEffect, useCallback } from 'react';
import axios from 'axios';
import { Plus, Edit2, Power, Trash2, RefreshCw, Zap, X, Info } from 'lucide-react';

const API_URL = '/api.php';

const NETWORKS = [
    { engine: 'admaven', name: 'AdMaven', letter: 'AM', color: '#f97316' },
    { engine: 'hilltopads', name: 'HilltopAds', letter: 'HT', color: '#16a34a' },
    { engine: 'propellerads', name: 'PropellerAds', letter: 'PA', color: '#2563eb' },
    { engine: 'kadam', name: 'Kadam', letter: 'KD', color: '#7c3aed' },
    { engine: 'galaxion', name: 'Galaxion', letter: 'GX', color: '#0891b2' },
    { engine: 'adsterra', name: 'Adsterra', letter: 'AS', color: '#dc2626' },
    { engine: 'clickadu', name: 'Clickadu', letter: 'CA', color: '#ca8a04' },
];

// Networks whose API reports spend per campaign only (no zone breakdown that
// carries the campaign id): spend spreads over the campaign's clicks and
// Zone Killer cannot rank their zones by spend.
const CAMPAIGN_ONLY = ['hilltopads', 'adsterra', 'clickadu'];

// Galaxion logs in with the panel e-mail + password, or takes a ready token.
const credentialsMissing = (engine, fields, creds) => {
    const has = (k) => String(creds[k] || '').trim() !== '';
    if (engine === 'galaxion') return !(has('api_token') || (has('email') && has('password')));
    return fields.some(f => f.required && !has(f.key));
};

const networkOf = (engine) => NETWORKS.find(n => n.engine === engine) || { engine, name: engine, letter: '?', color: '#6b7280' };

const emptyForm = (engine) => ({
    engine,
    name: '',
    sync_interval_hours: 2,
    is_active: 1,
    credentials: {},
    field_mapping: {},
});

const Note = ({ children, tone = 'info' }) => (
    <div style={{
        display: 'flex', gap: '8px', alignItems: 'flex-start',
        padding: '10px 12px', borderRadius: '10px', fontSize: '12px', lineHeight: 1.5,
        background: tone === 'warn' ? 'var(--color-warning-bg)' : 'var(--color-bg-soft)',
        border: `1px solid ${tone === 'warn' ? 'var(--color-warning)' : 'var(--color-border)'}`,
        color: 'var(--color-text-secondary)'
    }}>
        <Info size={14} style={{ flexShrink: 0, marginTop: '2px' }} />
        <div>{children}</div>
    </div>
);

/**
 * "Push/Pop networks" card body in ⚙ → Integrations. One panel for every
 * push/pop cost engine: pick the network, paste the key, test, save. Shares
 * the aggregator connection API with the Facebook / TikTok cost panels; the
 * status helpers come from IntegrationsPage so all cost cards read alike.
 */
export default function PushCostsPanel({ t, connections, loading, onRefresh, costStatusBadge, costNextUpdate, costDiagText, syncIntervalOptions }) {
    const [editing, setEditing] = useState(null); // null | 'new' | id
    const [form, setForm] = useState(emptyForm('admaven'));
    const [fields, setFields] = useState({});
    const [test, setTest] = useState(null);
    const [testing, setTesting] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [message, setMessage] = useState(null);

    const loadFields = useCallback(async (engine) => {
        if (fields[engine]) return;
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_engine_fields&engine=${engine}`);
            if (res.data.status === 'success') setFields(prev => ({ ...prev, [engine]: res.data.data || [] }));
        } catch (err) { console.error(err); }
    }, [fields]);

    useEffect(() => { if (editing) loadFields(form.engine); }, [editing, form.engine, loadFields]);

    const engineFields = fields[form.engine] || [];
    const requiredMissing = engineFields.length === 0 || credentialsMissing(form.engine, engineFields, form.credentials);

    // The list endpoint strips credentials, so editing has to re-read the row.
    const loadConnection = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=aggregator_connection_detail&id=${id}`);
            if (res.data.status !== 'success' || !res.data.data) return null;
            const c = res.data.data;
            return {
                engine: c.engine,
                name: c.name || '',
                sync_interval_hours: c.sync_interval_hours || 2,
                is_active: c.is_active ? 1 : 0,
                credentials: { ...(c.credentials || {}) },
                field_mapping: { ...(c.field_mapping || {}) },
            };
        } catch (err) { console.error(err); return null; }
    };

    const saveConnection = async (data, id) => {
        const payload = { ...data, auth_type: 'token', deal_type: 'cpa', click_id_param: 'sub_id' };
        if (id && id !== 'new') payload.id = id;
        const res = await axios.post(`${API_URL}?action=aggregator_connections`, payload);
        if (res.data.status !== 'success') throw new Error(res.data.message || t('common.error'));
        return true;
    };

    const startNew = (engine) => {
        setForm({ ...emptyForm(engine), name: networkOf(engine).name });
        setTest(null);
        setMessage(null);
        setEditing('new');
    };

    const pickNetwork = (engine) => {
        // Keep a name the user typed; replace the default network name.
        const isDefaultName = !form.name || NETWORKS.some(n => n.name === form.name);
        setForm({ ...emptyForm(engine), name: isDefaultName ? networkOf(engine).name : form.name, sync_interval_hours: form.sync_interval_hours });
        setTest(null);
    };

    const handleTest = async () => {
        setTesting(true);
        setTest(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_test_connection`, { engine: form.engine, credentials: form.credentials });
            setTest(res.data.data || { success: false, message: res.data.message || 'No response' });
        } catch (err) { setTest({ success: false, message: err.response?.data?.message || err.message }); }
        finally { setTesting(false); }
    };

    const handleSave = async () => {
        if (!form.name || requiredMissing) return;
        try {
            await saveConnection(form, editing);
            setMessage({ type: 'success', text: t('pushCosts.saved') });
            setEditing(null);
            setTest(null);
            onRefresh();
        } catch (err) { setMessage({ type: 'error', text: err.message }); }
    };

    // Same window as the scheduled sync: 30 days on the first pass, then 5.
    const handleSyncNow = async (conn) => {
        setBusyId(conn.id);
        setMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=aggregator_sync`, {
                connection_id: conn.id,
                date_from: new Date(Date.now() - (conn.last_sync_at ? 5 : 30) * 86400000).toISOString().slice(0, 10),
                date_to: new Date().toISOString().slice(0, 10),
            });
            const d = res.data;
            if (d.status === 'success') {
                const matched = d.matched ?? d.data?.matched ?? 0;
                const fetched = d.fetched ?? d.data?.fetched ?? 0;
                if (fetched === 0) {
                    setMessage({ type: 'success', text: t('pushCosts.syncedEmpty') });
                } else if (matched === 0) {
                    setMessage({ type: 'error', text: costDiagText(t, { fetched, ...(d.data?.diagnostic ?? d.diagnostic ?? {}) }, t('pushCosts.syncedNoMatch', { n: fetched })) });
                } else {
                    setMessage({ type: 'success', text: t('pushCosts.syncedOk', { n: fetched, m: matched }) });
                }
            } else {
                setMessage({ type: 'error', text: d.message || t('pushCosts.syncFailed') });
            }
            onRefresh();
        } catch (err) { setMessage({ type: 'error', text: err.response?.data?.message || err.message }); }
        finally { setBusyId(null); }
    };

    const handleToggle = async (conn) => {
        // Re-read first: the save endpoint rewrites credentials from the payload.
        const full = await loadConnection(conn.id);
        if (!full) return;
        try {
            await saveConnection({ ...full, is_active: conn.is_active ? 0 : 1 }, conn.id);
        } catch (err) { setMessage({ type: 'error', text: err.message }); }
        onRefresh();
    };

    const handleEdit = async (conn) => {
        const full = await loadConnection(conn.id);
        if (!full) return;
        setForm(full);
        setTest(null);
        setMessage(null);
        setEditing(conn.id);
    };

    const handleDelete = async (conn) => {
        if (!window.confirm(t('pushCosts.confirmDelete'))) return;
        await axios.post(`${API_URL}?action=aggregator_connections`, { action: 'delete', id: conn.id });
        setMessage({ type: 'success', text: t('pushCosts.deleted') });
        onRefresh();
    };

    const net = networkOf(form.engine);

    return (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            {message && (
                <div style={{
                    padding: '10px 14px', borderRadius: '10px', fontSize: '13px', marginBottom: '16px',
                    background: message.type === 'success' ? '#dcfce7' : '#fee2e2',
                    color: message.type === 'success' ? '#166534' : '#991b1b',
                    border: `1px solid ${message.type === 'success' ? '#86efac' : '#fca5a5'}`
                }}>{message.text}</div>
            )}

            {editing ? (
                <div style={{ border: '1px solid var(--color-primary)', borderRadius: '16px', padding: '20px', background: 'var(--color-bg-card)', maxWidth: '760px' }}>
                    <h4 style={{ fontWeight: 600, marginBottom: '16px' }}>
                        {editing === 'new' ? t('pushCosts.add') : t('pushCosts.edit')}
                    </h4>
                    <div className="space-y-3">
                        {/* Step 1 — the network. Fixed once saved: credentials differ per engine. */}
                        <div>
                            <label className="form-label">{t('pushCosts.network')}</label>
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '8px' }}>
                                {NETWORKS.map(n => {
                                    const active = form.engine === n.engine;
                                    const locked = editing !== 'new' && !active;
                                    return (
                                        <button key={n.engine} type="button" disabled={locked}
                                            onClick={() => editing === 'new' && pickNetwork(n.engine)}
                                            style={{
                                                display: 'flex', alignItems: 'center', gap: '10px', padding: '10px 12px',
                                                borderRadius: '12px', textAlign: 'left', cursor: locked ? 'not-allowed' : 'pointer',
                                                border: `1px solid ${active ? 'var(--color-primary)' : 'var(--color-border)'}`,
                                                background: active ? 'color-mix(in srgb, var(--color-primary) 10%, transparent)' : 'var(--color-bg-card)',
                                                opacity: locked ? 0.45 : 1, color: 'var(--color-text-primary)'
                                            }}>
                                            <span style={{
                                                width: '30px', height: '30px', borderRadius: '8px', flexShrink: 0,
                                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                                fontSize: '11px', fontWeight: 800, color: '#fff', background: n.color
                                            }}>{n.letter}</span>
                                            <span style={{ fontSize: '13px', fontWeight: 600 }}>{n.name}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <Note>{t(`pushCosts.keyHint.${form.engine}`)}</Note>

                        <div>
                            <label className="form-label">{t('pushCosts.name')}</label>
                            <input type="text" className="form-input" value={form.name}
                                onChange={e => setForm({ ...form, name: e.target.value })} />
                        </div>

                        {engineFields.map(field => (
                            <div key={field.key}>
                                <label className="form-label">
                                    {t(field.label_key, field.label)}{field.required ? ' *' : ''}
                                </label>
                                <input
                                    type={field.type === 'password' ? 'password' : 'text'}
                                    className="form-input"
                                    autoComplete="off"
                                    placeholder={field.placeholder || ''}
                                    value={form.credentials[field.key] || ''}
                                    onChange={e => setForm({ ...form, credentials: { ...form.credentials, [field.key]: e.target.value } })} />
                            </div>
                        ))}

                        <div>
                            <label className="form-label">{t('pushCosts.interval')}</label>
                            <select className="form-select" value={form.sync_interval_hours}
                                onChange={e => setForm({ ...form, sync_interval_hours: parseFloat(e.target.value) })}>
                                {syncIntervalOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </div>

                        <Note>{t('pushCosts.linkHint', { network: net.name })}</Note>
                        {CAMPAIGN_ONLY.includes(form.engine) && <Note tone="warn">{t('pushCosts.campaignOnly', { network: net.name })}</Note>}

                        {test && (
                            <div style={{
                                padding: '10px 14px', borderRadius: '10px', fontSize: '13px',
                                background: test.success ? '#dcfce7' : '#fee2e2',
                                color: test.success ? '#166534' : '#991b1b',
                                border: `1px solid ${test.success ? '#86efac' : '#fca5a5'}`
                            }}>{test.message}</div>
                        )}

                        <div style={{ display: 'flex', gap: '8px', justifyContent: 'space-between', flexWrap: 'wrap' }}>
                            <button onClick={handleTest} className="btn btn-secondary btn-sm" disabled={testing || requiredMissing}>
                                <Zap size={14} /> {testing ? t('pushCosts.testing') : t('pushCosts.test')}
                            </button>
                            <div style={{ display: 'flex', gap: '8px' }}>
                                <button onClick={() => { setEditing(null); setTest(null); }} className="btn btn-secondary btn-sm">
                                    <X size={14} /> {t('common.cancel')}
                                </button>
                                <button onClick={handleSave} className="btn btn-primary btn-sm" disabled={!form.name || requiredMissing}>
                                    {t('common.save')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : (
                <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap' }}>
                    <button onClick={() => startNew('admaven')} className="btn btn-primary">
                        <Plus size={16} /> {t('pushCosts.add')}
                    </button>
                </div>
            )}

            {loading ? (
                <div className="flex justify-center py-10">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                </div>
            ) : (
                <div className="space-y-3" style={{ marginTop: editing ? '20px' : 0 }}>
                    {connections.map(conn => {
                        const badge = costStatusBadge(conn, 'pushCosts');
                        const n = networkOf(conn.engine);
                        return (
                            <div key={conn.id} style={{
                                border: '1px solid var(--color-border)', borderRadius: '16px', padding: '16px',
                                background: conn.is_active ? 'var(--color-bg-card)' : 'var(--color-bg-soft)',
                                opacity: conn.is_active ? 1 : 0.7
                            }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px', gap: '12px', flexWrap: 'wrap' }}>
                                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', minWidth: 0 }}>
                                        <span style={{
                                            width: '30px', height: '30px', borderRadius: '8px', flexShrink: 0,
                                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                                            fontSize: '11px', fontWeight: 800, color: '#fff', background: n.color
                                        }}>{n.letter}</span>
                                        <div style={{ minWidth: 0 }}>
                                            <div style={{ fontWeight: 600, fontSize: '14px' }}>{conn.name} <span style={{ fontWeight: 400, color: 'var(--color-text-muted)', fontSize: '12px' }}>· {n.name}</span></div>
                                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                                {t('pushCosts.nextUpdate')}: {costNextUpdate(conn, 'pushCosts')}
                                                {conn.last_sync_at && <> · {t('pushCosts.lastSync')}: {conn.last_sync_at}</>}
                                            </div>
                                        </div>
                                    </div>
                                    <span style={{ fontSize: '11px', padding: '2px 8px', borderRadius: '6px', background: badge.bg, color: badge.fg, whiteSpace: 'nowrap' }}>
                                        {badge.label}
                                    </span>
                                </div>

                                {conn.last_sync_status === 'error' && conn.last_sync_error && (
                                    <div style={{ fontSize: '12px', color: '#ef4444', marginBottom: '10px', wordBreak: 'break-word' }}>
                                        {conn.last_sync_error}
                                    </div>
                                )}

                                <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                    <button onClick={() => handleSyncNow(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }} disabled={busyId === conn.id}>
                                        <RefreshCw size={12} className={busyId === conn.id ? 'animate-spin' : ''} /> {t('pushCosts.syncNow')}
                                    </button>
                                    <button onClick={() => handleEdit(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                        <Edit2 size={12} /> {t('common.edit')}
                                    </button>
                                    <button onClick={() => handleToggle(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px' }}>
                                        <Power size={12} /> {conn.is_active ? t('pushCosts.pause') : t('pushCosts.resume')}
                                    </button>
                                    <button onClick={() => handleDelete(conn)} className="btn btn-secondary btn-sm" style={{ fontSize: '11px', color: '#ef4444' }} aria-label={t('common.delete')}>
                                        <Trash2 size={12} />
                                    </button>
                                </div>
                            </div>
                        );
                    })}

                    {connections.length === 0 && !editing && (
                        <div style={{ textAlign: 'center', padding: '30px 0' }}>
                            <p style={{ color: 'var(--color-text-muted)', fontSize: '13px', marginBottom: '14px' }}>{t('pushCosts.empty')}</p>
                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'center', flexWrap: 'wrap' }}>
                                {NETWORKS.map(n => (
                                    <button key={n.engine} onClick={() => startNew(n.engine)} className="btn btn-secondary btn-sm">
                                        <Plus size={12} /> {n.name}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
