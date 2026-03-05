import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';
import { Database, Plus, Trash2, RefreshCw, CheckCircle, XCircle, Clock, Settings, ChevronDown, ChevronUp, Zap, Link2, AlertTriangle, BarChart3, FileText, Download } from 'lucide-react';
import InfoBanner from './InfoBanner';

const API = '/api.php';

export default function AggregatorPage() {
    const { t } = useLanguage();

    const [activeTab, setActiveTab] = useState('connections');
    const [connections, setConnections] = useState([]);
    const [revenueRecords, setRevenueRecords] = useState([]);
    const [syncLogs, setSyncLogs] = useState([]);
    const [totals, setTotals] = useState({ total: 0, total_amount: 0, matched_count: 0 });
    const [networks, setNetworks] = useState([]);
    const [loading, setLoading] = useState(false);
    const [showForm, setShowForm] = useState(false);
    const [editingConn, setEditingConn] = useState(null);
    const [engineFields, setEngineFields] = useState([]);
    const [syncingId, setSyncingId] = useState(null);
    const [testResult, setTestResult] = useState(null);
    const [dateFrom, setDateFrom] = useState(() => {
        const d = new Date(); d.setDate(d.getDate() - 30);
        return d.toISOString().split('T')[0];
    });
    const [dateTo, setDateTo] = useState(() => new Date().toISOString().split('T')[0]);

    const [form, setForm] = useState({
        name: '', engine: 'generic', affiliate_network_id: '',
        auth_type: 'api_key', credentials: {}, base_url: '',
        deal_type: 'cpa', baseline: 0, click_id_param: 'sub_id',
        field_mapping: {}, sync_interval_hours: 2, is_active: 1
    });

    useEffect(() => { loadConnections(); loadNetworks(); }, []);
    useEffect(() => { if (activeTab === 'revenue') loadRevenue(); }, [activeTab, dateFrom, dateTo]);
    useEffect(() => { if (activeTab === 'logs') loadSyncLogs(); }, [activeTab]);

    const loadConnections = async () => {
        try {
            const res = await axios.get(`${API}?action=aggregator_connections`);
            setConnections(res.data.data || []);
        } catch (e) { console.error(e); }
    };

    const loadNetworks = async () => {
        try {
            const res = await axios.get(`${API}?action=affiliate_networks`);
            setNetworks(res.data.data || []);
        } catch (e) { console.error(e); }
    };

    const loadRevenue = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API}?action=aggregator_revenue&date_from=${dateFrom}&date_to=${dateTo}`);
            setRevenueRecords(res.data.data || []);
            setTotals(res.data.totals || {});
        } catch (e) { console.error(e); }
        setLoading(false);
    };

    const exportRevenueCSV = () => {
        window.location.href = `${API}?action=aggregator_revenue_export&date_from=${dateFrom}&date_to=${dateTo}`;
    };

    const loadSyncLogs = async () => {
        try {
            const res = await axios.get(`${API}?action=aggregator_sync_logs`);
            setSyncLogs(res.data.data || []);
        } catch (e) { console.error(e); }
    };

    const loadEngineFields = async (engine) => {
        try {
            const res = await axios.get(`${API}?action=aggregator_engine_fields&engine=${engine}`);
            setEngineFields(res.data.data || []);
        } catch (e) { console.error(e); }
    };

    const openNewForm = () => {
        setEditingConn(null);
        setForm({ name: '', engine: 'generic', affiliate_network_id: '', auth_type: 'api_key', credentials: {}, base_url: '', deal_type: 'cpa', baseline: 0, click_id_param: 'sub_id', field_mapping: {}, sync_interval_hours: 2, is_active: 1 });
        setTestResult(null);
        loadEngineFields('generic');
        setShowForm(true);
    };

    const openEditForm = async (conn) => {
        try {
            const res = await axios.get(`${API}?action=aggregator_connection_detail&id=${conn.id}`);
            const data = res.data.data;
            setEditingConn(data);
            setForm({
                name: data.name, engine: data.engine, affiliate_network_id: data.affiliate_network_id || '',
                auth_type: data.auth_type, credentials: data.credentials || {}, base_url: data.base_url || '',
                deal_type: data.deal_type, baseline: data.baseline, click_id_param: data.click_id_param,
                field_mapping: data.field_mapping || {}, sync_interval_hours: data.sync_interval_hours, is_active: data.is_active
            });
            setTestResult(null);
            loadEngineFields(data.engine);
            setShowForm(true);
        } catch (e) { console.error(e); }
    };

    const saveConnection = async () => {
        setLoading(true);
        try {
            await axios.post(`${API}?action=aggregator_connections`, { ...form, id: editingConn?.id || null });
            setShowForm(false);
            loadConnections();
        } catch (e) { console.error(e); }
        setLoading(false);
    };

    const deleteConnection = async (id) => {
        if (!confirm(t('aggregator.confirmDelete'))) return;
        try {
            await axios.post(`${API}?action=aggregator_connections`, { action: 'delete', id });
            loadConnections();
        } catch (e) { console.error(e); }
    };

    const testConnection = async () => {
        setTestResult(null);
        try {
            const res = await axios.post(`${API}?action=aggregator_test_connection`, { credentials: form.credentials, engine: form.engine });
            setTestResult(res.data.data);
        } catch (e) { setTestResult({ success: false, message: e.message }); }
    };

    const syncNow = async (connId) => {
        setSyncingId(connId);
        try {
            const res = await axios.post(`${API}?action=aggregator_sync`, { connection_id: connId, date_from: dateFrom, date_to: dateTo });
            if (res.data.status === 'success') {
                alert(`${t('aggregator.syncSuccess')}: ${res.data.data.fetched} ${t('aggregator.fetched')}, ${res.data.data.matched} ${t('aggregator.matched')}, ${res.data.data.new} ${t('aggregator.new')}`);
            } else {
                alert(`${t('aggregator.syncError')}: ${res.data.message}`);
            }
            loadConnections();
        } catch (e) { alert(e.message); }
        setSyncingId(null);
    };

    const updateCredential = (key, value) => {
        setForm(prev => ({ ...prev, credentials: { ...prev.credentials, [key]: value } }));
    };

    const StatusBadge = ({ status }) => {
        if (status === 'success') return <span className="badge badge-success" style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><CheckCircle size={12} /> OK</span>;
        if (status === 'error') return <span className="badge badge-danger" style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><XCircle size={12} /> Error</span>;
        return <span className="badge badge-secondary" style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} /> {t('aggregator.pending')}</span>;
    };

    const tabs = [
        { key: 'connections', label: t('aggregator.connections'), icon: <Link2 size={16} /> },
        { key: 'revenue', label: t('aggregator.revenue'), icon: <BarChart3 size={16} /> },
        { key: 'logs', label: t('aggregator.syncLogs'), icon: <FileText size={16} /> },
    ];

    return (
        <div style={{ padding: '0' }}>
            <InfoBanner storageKey="help_aggregator" title={t('aggregator.manualTitle')}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '16px', marginTop: '8px' }}>
                    <div>
                        <h4 style={{ margin: '0 0 8px 0', fontSize: '0.95rem', color: 'var(--text-primary)' }}>{t('aggregator.manualStep1')}</h4>
                        <p style={{ margin: 0, fontSize: '0.85rem', color: 'var(--text-secondary)' }}>{t('aggregator.manualStep1Desc')}</p>
                    </div>
                    <div>
                        <h4 style={{ margin: '0 0 8px 0', fontSize: '0.95rem', color: 'var(--text-primary)' }}>{t('aggregator.manualStep2')}</h4>
                        <p style={{ margin: 0, fontSize: '0.85rem', color: 'var(--text-secondary)' }}>{t('aggregator.manualStep2Desc')}</p>
                    </div>
                    <div>
                        <h4 style={{ margin: '0 0 8px 0', fontSize: '0.95rem', color: 'var(--text-primary)' }}>{t('aggregator.manualStep3')}</h4>
                        <p style={{ margin: 0, fontSize: '0.85rem', color: 'var(--text-secondary)' }}>{t('aggregator.manualStep3Desc')}</p>
                    </div>
                    <div>
                        <h4 style={{ margin: '0 0 8px 0', fontSize: '0.95rem', color: 'var(--text-primary)' }}>{t('aggregator.manualStep4')}</h4>
                        <p style={{ margin: 0, fontSize: '0.85rem', color: 'var(--text-secondary)' }}>{t('aggregator.manualStep4Desc')}</p>
                    </div>
                </div>
            </InfoBanner>

            {/* Header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <Database size={24} style={{ color: 'var(--primary)' }} />
                    <h2 style={{ margin: 0, fontSize: '1.4rem' }}>{t('aggregator.title')}</h2>
                    <span className="badge badge-primary" style={{ fontSize: '0.7rem' }}>BETA</span>
                </div>
            </div>

            {/* Tabs */}
            <div style={{ display: 'flex', gap: 0, marginBottom: 24, borderBottom: '2px solid var(--card-border, #e0e0e0)' }}>
                {tabs.map(tab => (
                    <button
                        key={tab.key}
                        onClick={() => setActiveTab(tab.key)}
                        style={{
                            padding: '10px 20px', border: 'none', background: 'none', cursor: 'pointer',
                            display: 'flex', alignItems: 'center', gap: 8, fontSize: '0.9rem', fontWeight: 500,
                            color: activeTab === tab.key ? 'var(--primary)' : 'var(--text-secondary)',
                            borderBottom: activeTab === tab.key ? '2px solid var(--primary)' : '2px solid transparent',
                            marginBottom: -2, transition: 'all 0.2s'
                        }}
                    >
                        {tab.icon} {tab.label}
                    </button>
                ))}
            </div>

            {/* Tab: Connections */}
            {activeTab === 'connections' && (
                <div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
                        <p style={{ color: 'var(--text-secondary)', margin: 0, fontSize: '0.85rem' }}>{t('aggregator.connectionsDesc')}</p>
                        <button className="btn btn-primary" onClick={openNewForm} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                            <Plus size={16} /> {t('aggregator.addConnection')}
                        </button>
                    </div>

                    {connections.length === 0 ? (
                        <div className="card" style={{ textAlign: 'center', padding: '60px 20px', color: 'var(--text-secondary)' }}>
                            <Database size={48} style={{ opacity: 0.3, marginBottom: 12 }} />
                            <p style={{ fontSize: '1rem' }}>{t('aggregator.noConnections')}</p>
                            <button className="btn btn-primary" onClick={openNewForm} style={{ marginTop: 8 }}>
                                <Plus size={16} /> {t('aggregator.addFirst')}
                            </button>
                        </div>
                    ) : (
                        <div className="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{t('aggregator.connectionName')}</th>
                                        <th>{t('aggregator.engine')}</th>
                                        <th>{t('aggregator.network')}</th>
                                        <th>{t('aggregator.dealType')}</th>
                                        <th>{t('aggregator.lastSync')}</th>
                                        <th>{t('aggregator.status')}</th>
                                        <th>{t('aggregator.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connections.map(conn => (
                                        <tr key={conn.id}>
                                            <td style={{ fontWeight: 600 }}>{conn.name}</td>
                                            <td><span className="badge badge-secondary">{conn.engine}</span></td>
                                            <td>{conn.network_name || '—'}</td>
                                            <td>{conn.deal_type?.toUpperCase()}</td>
                                            <td style={{ fontSize: '0.8rem', color: 'var(--text-secondary)' }}>{conn.last_sync_at || t('aggregator.never')}</td>
                                            <td><StatusBadge status={conn.last_sync_status} /></td>
                                            <td>
                                                <div style={{ display: 'flex', gap: 6 }}>
                                                    <button className="btn btn-sm" onClick={() => syncNow(conn.id)} disabled={syncingId === conn.id} title={t('aggregator.syncNow')}
                                                        style={{ padding: '4px 8px', display: 'flex', alignItems: 'center', gap: 4 }}>
                                                        <RefreshCw size={14} className={syncingId === conn.id ? 'spin' : ''} />
                                                    </button>
                                                    <button className="btn btn-sm" onClick={() => openEditForm(conn)} title={t('aggregator.edit')} style={{ padding: '4px 8px' }}>
                                                        <Settings size={14} />
                                                    </button>
                                                    <button className="btn btn-sm btn-danger" onClick={() => deleteConnection(conn.id)} title={t('aggregator.delete')} style={{ padding: '4px 8px' }}>
                                                        <Trash2 size={14} />
                                                    </button>
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

            {/* Tab: Revenue */}
            {activeTab === 'revenue' && (
                <div>
                    {/* Stats Cards */}
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 16, marginBottom: 20 }}>
                        <div className="card" style={{ padding: 16, textAlign: 'center' }}>
                            <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: 4 }}>{t('aggregator.totalRecords')}</div>
                            <div style={{ fontSize: '1.6rem', fontWeight: 700 }}>{totals.total || 0}</div>
                        </div>
                        <div className="card" style={{ padding: 16, textAlign: 'center' }}>
                            <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: 4 }}>{t('aggregator.totalRevenue')}</div>
                            <div style={{ fontSize: '1.6rem', fontWeight: 700, color: 'var(--success, #10b981)' }}>${parseFloat(totals.total_amount || 0).toFixed(2)}</div>
                        </div>
                        <div className="card" style={{ padding: 16, textAlign: 'center' }}>
                            <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: 4 }}>{t('aggregator.matchedClicks')}</div>
                            <div style={{ fontSize: '1.6rem', fontWeight: 700, color: 'var(--primary)' }}>{totals.matched_count || 0}</div>
                        </div>
                        <div className="card" style={{ padding: 16, textAlign: 'center' }}>
                            <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: 4 }}>{t('aggregator.matchRate')}</div>
                            <div style={{ fontSize: '1.6rem', fontWeight: 700 }}>{totals.total > 0 ? ((totals.matched_count / totals.total) * 100).toFixed(1) : 0}%</div>
                        </div>
                    </div>

                    {/* Date Filters */}
                    <div style={{ display: 'flex', gap: 12, marginBottom: 16, alignItems: 'center', flexWrap: 'wrap' }}>
                        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                            <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className="input" />
                            <span style={{ color: 'var(--text-secondary)' }}>→</span>
                            <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className="input" />
                            <button className="btn btn-sm" onClick={loadRevenue} title={t('aggregator.refresh')}><RefreshCw size={14} /></button>
                        </div>
                        <div style={{ marginLeft: 'auto' }}>
                            <button className="btn btn-sm btn-secondary" onClick={exportRevenueCSV} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <Download size={14} /> Export CSV
                            </button>
                        </div>
                    </div>

                    {/* Records Table */}
                    <div className="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>{t('aggregator.date')}</th>
                                    <th>{t('aggregator.connection')}</th>
                                    <th>Click ID</th>
                                    <th>Player ID</th>
                                    <th>{t('aggregator.eventType')}</th>
                                    <th>{t('aggregator.amount')}</th>
                                    <th>{t('aggregator.country')}</th>
                                    <th>{t('aggregator.matched')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revenueRecords.map(rec => (
                                    <tr key={rec.id}>
                                        <td style={{ fontSize: '0.8rem' }}>{rec.event_date}</td>
                                        <td>{rec.connection_name}</td>
                                        <td style={{ fontFamily: 'monospace', fontSize: '0.75rem' }}>{rec.click_id || '—'}</td>
                                        <td style={{ fontSize: '0.8rem' }}>{rec.player_id || '—'}</td>
                                        <td><span className="badge badge-secondary">{rec.event_type}</span></td>
                                        <td style={{ fontWeight: 600, color: 'var(--success, #10b981)' }}>${parseFloat(rec.amount).toFixed(2)}</td>
                                        <td>{rec.country || '—'}</td>
                                        <td>{rec.is_matched ? <CheckCircle size={16} style={{ color: 'var(--success, #10b981)' }} /> : <XCircle size={16} style={{ color: 'var(--text-secondary)', opacity: 0.4 }} />}</td>
                                    </tr>
                                ))}
                                {revenueRecords.length === 0 && (
                                    <tr><td colSpan={8} style={{ textAlign: 'center', padding: 40, color: 'var(--text-secondary)' }}>{t('aggregator.noRecords')}</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Tab: Sync Logs */}
            {activeTab === 'logs' && (
                <div className="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>{t('aggregator.date')}</th>
                                <th>{t('aggregator.connection')}</th>
                                <th>{t('aggregator.status')}</th>
                                <th>{t('aggregator.fetched')}</th>
                                <th>{t('aggregator.matched')}</th>
                                <th>{t('aggregator.new')}</th>
                                <th>{t('aggregator.duration')}</th>
                                <th>{t('aggregator.error')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {syncLogs.map(log => (
                                <tr key={log.id}>
                                    <td style={{ fontSize: '0.8rem' }}>{log.created_at}</td>
                                    <td>{log.connection_name}</td>
                                    <td><StatusBadge status={log.status} /></td>
                                    <td>{log.records_fetched}</td>
                                    <td>{log.records_matched}</td>
                                    <td>{log.records_new}</td>
                                    <td style={{ fontSize: '0.8rem' }}>{log.duration_ms}ms</td>
                                    <td style={{ fontSize: '0.75rem', color: 'var(--danger, #ef4444)', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis' }}>{log.error_message || '—'}</td>
                                </tr>
                            ))}
                            {syncLogs.length === 0 && (
                                <tr><td colSpan={8} style={{ textAlign: 'center', padding: 40, color: 'var(--text-secondary)' }}>{t('aggregator.noLogs')}</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Modal: Add/Edit Connection */}
            {showForm && (
                <div className="modal-overlay" onClick={() => setShowForm(false)}>
                    <div className="modal-content" onClick={e => e.stopPropagation()} style={{ maxWidth: 640, maxHeight: '85vh', overflow: 'auto' }}>
                        <h3 style={{ marginTop: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <Zap size={20} style={{ color: 'var(--primary)' }} />
                            {editingConn ? t('aggregator.editConnection') : t('aggregator.addConnection')}
                        </h3>

                        <div style={{ display: 'grid', gap: 16 }}>
                            {/* Name */}
                            <div>
                                <label className="form-label">{t('aggregator.connectionName')}</label>
                                <input className="input" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} placeholder="Pin-Up Partners CPA" />
                            </div>

                            {/* Network + Engine */}
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                <div>
                                    <label className="form-label">{t('aggregator.network')}</label>
                                    <select className="input" value={form.affiliate_network_id} onChange={e => setForm({ ...form, affiliate_network_id: e.target.value })}>
                                        <option value="">{t('aggregator.selectNetwork')}</option>
                                        {networks.map(n => <option key={n.id} value={n.id}>{n.name}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">{t('aggregator.engine')}</label>
                                    <select className="input" value={form.engine} onChange={e => { setForm({ ...form, engine: e.target.value }); loadEngineFields(e.target.value); }}>
                                        <option value="generic">Generic API</option>
                                        <option value="referon">ReferOn</option>
                                        <option value="affilka">Affilka (SoftSwiss)</option>
                                        <option value="custom">Custom</option>
                                    </select>
                                </div>
                            </div>

                            {/* Deal Type + Baseline */}
                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
                                <div>
                                    <label className="form-label">{t('aggregator.dealType')}</label>
                                    <select className="input" value={form.deal_type} onChange={e => setForm({ ...form, deal_type: e.target.value })}>
                                        <option value="cpa">CPA</option>
                                        <option value="revshare">RevShare</option>
                                        <option value="cpl">CPL</option>
                                        <option value="hybrid">Hybrid</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="form-label">Baseline ($)</label>
                                    <input type="number" className="input" value={form.baseline} onChange={e => setForm({ ...form, baseline: e.target.value })} />
                                </div>
                                <div>
                                    <label className="form-label">{t('aggregator.syncInterval')}</label>
                                    <select className="input" value={form.sync_interval_hours} onChange={e => setForm({ ...form, sync_interval_hours: parseInt(e.target.value) })}>
                                        <option value={1}>1h</option>
                                        <option value={2}>2h</option>
                                        <option value={4}>4h</option>
                                        <option value={6}>6h</option>
                                        <option value={12}>12h</option>
                                        <option value={24}>24h</option>
                                    </select>
                                </div>
                            </div>

                            {/* Click ID Param */}
                            <div>
                                <label className="form-label">{t('aggregator.clickIdParam')}</label>
                                <input className="input" value={form.click_id_param} onChange={e => setForm({ ...form, click_id_param: e.target.value })} placeholder="sub_id" />
                                <small style={{ color: 'var(--text-secondary)' }}>{t('aggregator.clickIdParamHint')}</small>
                            </div>

                            {/* Dynamic Credentials Fields */}
                            <div style={{ borderTop: '1px solid var(--card-border, #e0e0e0)', paddingTop: 16 }}>
                                <label className="form-label" style={{ fontWeight: 700, fontSize: '0.95rem' }}>{t('aggregator.credentials')}</label>
                                {engineFields.map(field => (
                                    <div key={field.key} style={{ marginBottom: 12 }}>
                                        <label className="form-label" style={{ fontSize: '0.8rem' }}>{field.label}{field.required && ' *'}</label>
                                        {field.type === 'textarea' ? (
                                            <textarea className="input" rows={3} value={form.credentials[field.key] || ''} onChange={e => updateCredential(field.key, e.target.value)} placeholder={field.placeholder} />
                                        ) : field.type === 'select' ? (
                                            <select className="input" value={form.credentials[field.key] || field.options?.[0]} onChange={e => updateCredential(field.key, e.target.value)}>
                                                {field.options?.map(opt => <option key={opt} value={opt}>{opt}</option>)}
                                            </select>
                                        ) : (
                                            <input className="input" type={field.type === 'password' ? 'password' : 'text'} value={form.credentials[field.key] || ''} onChange={e => updateCredential(field.key, e.target.value)} placeholder={field.placeholder} />
                                        )}
                                    </div>
                                ))}
                            </div>

                            {/* Test Result */}
                            {testResult && (
                                <div className="card" style={{ padding: 12, display: 'flex', alignItems: 'center', gap: 8, background: testResult.success ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)', border: `1px solid ${testResult.success ? '#10b981' : '#ef4444'}` }}>
                                    {testResult.success ? <CheckCircle size={18} style={{ color: '#10b981' }} /> : <AlertTriangle size={18} style={{ color: '#ef4444' }} />}
                                    <span style={{ fontSize: '0.85rem' }}>{testResult.message}</span>
                                </div>
                            )}

                            {/* Buttons */}
                            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8 }}>
                                <button className="btn" onClick={testConnection} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                    <Zap size={16} /> {t('aggregator.testConnection')}
                                </button>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <button className="btn" onClick={() => setShowForm(false)}>{t('aggregator.cancel')}</button>
                                    <button className="btn btn-primary" onClick={saveConnection} disabled={!form.name}>
                                        {editingConn ? t('aggregator.save') : t('aggregator.create')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <style>{`
                .spin { animation: spin 1s linear infinite; }
                @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            `}</style>
        </div>
    );
}
