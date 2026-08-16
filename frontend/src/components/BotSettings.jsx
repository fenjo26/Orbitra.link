import React, { useState, useEffect, useCallback, useRef } from 'react';
import { ShieldBan, Plus, Trash2, RotateCcw, Search, Upload, FileText, CheckCircle2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
const PAGE_SIZE = 200;
const BATCH_CHUNK_SIZE = 2000;

const BotSettings = () => {
    const { t } = useLanguage();

    // A blacklist can hold tens of thousands of rows, so each list is searched
    // and paged on the server; the panel only ever holds what it has loaded.
    const emptyList = { items: [], total: 0, filtered: 0, search: '', loading: true };
    const [lists, setLists] = useState({ ip: { ...emptyList }, sig: { ...emptyList } });
    const [newIps, setNewIps] = useState('');
    const [newSigs, setNewSigs] = useState('');
    const [importing, setImporting] = useState({ active: false, type: null, current: 0, total: 0 });
    const searchTimers = useRef({});
    const fileInputIpRef = useRef(null);
    const fileInputSigRef = useRef(null);

    const endpointOf = (type) => (type === 'ip' ? 'bot_ips' : 'bot_signatures');

    const load = useCallback(async (type, { search = '', offset = 0, append = false } = {}) => {
        setLists(prev => ({ ...prev, [type]: { ...prev[type], loading: true } }));
        try {
            const qs = new URLSearchParams({ action: endpointOf(type), limit: String(PAGE_SIZE), offset: String(offset) });
            if (search) qs.set('search', search);
            const res = await fetch(`${API_URL}?${qs}`);
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || `HTTP ${res.status}`);
            setLists(prev => ({
                ...prev,
                [type]: {
                    items: append ? [...prev[type].items, ...(data.data || [])] : (data.data || []),
                    total: data.total ?? 0,
                    filtered: data.filtered ?? data.total ?? 0,
                    search,
                    loading: false,
                },
            }));
        } catch (e) {
            setLists(prev => ({ ...prev, [type]: { ...prev[type], loading: false } }));
            alert(`${t('botSettings.loadError')}: ${e.message}`);
        }
    }, [t]);

    useEffect(() => {
        load('ip');
        load('sig');
    }, [load]);

    // Debounced search
    const onSearch = (type, value) => {
        setLists(prev => ({ ...prev, [type]: { ...prev[type], search: value } }));
        clearTimeout(searchTimers.current[type]);
        searchTimers.current[type] = setTimeout(() => load(type, { search: value }), 300);
    };

    const mutate = async (type, payload) => {
        const action = type === 'ip' ? 'bot_ips' : 'bot_signatures';
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || data?.status !== 'success') {
            throw new Error(data?.message || `HTTP ${res.status}`);
        }
        return data;
    };

    const handleAdd = async (type, rawText) => {
        const source = rawText !== undefined ? rawText : (type === 'ip' ? newIps : newSigs);
        if (!source.trim()) return;
        const items = source.split(/[\r\n,]+/).map(s => s.trim()).filter(Boolean);
        if (items.length === 0) return;

        let totalAdded = 0;
        let totalSkipped = 0;

        setImporting({ active: true, type, current: 0, total: items.length });

        try {
            // Process in chunks to prevent HTTP payload size limits & timeouts
            for (let i = 0; i < items.length; i += BATCH_CHUNK_SIZE) {
                const chunk = items.slice(i, i + BATCH_CHUNK_SIZE);
                const data = await mutate(type, { items: chunk });
                totalAdded += (data.added || 0);
                totalSkipped += (data.skipped || 0);
                setImporting({ active: true, type, current: Math.min(items.length, i + BATCH_CHUNK_SIZE), total: items.length });
            }

            if (rawText === undefined) {
                (type === 'ip' ? setNewIps : setNewSigs)('');
            }
            load(type, { search: lists[type].search });

            alert(`${t('botSettings.addedCount')} ${totalAdded}`
                + (totalSkipped ? ` (${t('botSettings.skippedDuplicates')} ${totalSkipped})` : ''));
        } catch (e) {
            alert(`${t('botSettings.networkError')}: ${e.message}`);
        } finally {
            setImporting({ active: false, type: null, current: 0, total: 0 });
        }
    };

    const handleFileUpload = (type, e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            const text = event.target?.result;
            if (typeof text === 'string') {
                handleAdd(type, text);
            }
        };
        reader.readAsText(file);
        e.target.value = ''; // reset so same file can be chosen again
    };

    const handleDelete = async (type, id) => {
        try {
            await mutate(type, { action: 'delete', id });
            setLists(prev => ({
                ...prev,
                [type]: {
                    ...prev[type],
                    items: prev[type].items.filter(i => i.id !== id),
                    total: Math.max(0, prev[type].total - 1),
                    filtered: Math.max(0, prev[type].filtered - 1),
                },
            }));
        } catch (e) {
            alert(`${t('botSettings.deleteError')}: ${e.message}`);
        }
    };

    const handleClear = async (type) => {
        if (!window.confirm(t('botSettings.confirmClear'))) return;
        try {
            await mutate(type, { action: 'clear_all' });
            alert(t('botSettings.cleared'));
            load(type, { search: lists[type].search });
        } catch (e) {
            alert(`${t('botSettings.clearError')}: ${e.message}`);
        }
    };

    const renderList = (type) => {
        const list = lists[type];
        const hasMore = list.items.length < list.filtered;
        return (
            <>
                <div style={{ marginTop: '16px', position: 'relative' }}>
                    <Search size={14} style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--color-text-muted)', pointerEvents: 'none' }} />
                    <input
                        type="text"
                        value={list.search}
                        onChange={(e) => onSearch(type, e.target.value)}
                        placeholder={t('botSettings.searchPlaceholder')}
                        className="form-input"
                        style={{ paddingLeft: '32px', fontSize: '13px' }}
                    />
                </div>

                <div style={{ marginTop: '12px', maxHeight: '420px', overflowY: 'auto', border: '1px solid var(--color-border)', borderRadius: '12px' }}>
                    {list.loading && list.items.length === 0 ? (
                        <div style={{ padding: '24px', textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px' }}>
                            {t('common.loading')}
                        </div>
                    ) : list.items.length === 0 ? (
                        <div style={{ padding: '24px', textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px' }}>
                            {t('botSettings.noItems')}
                        </div>
                    ) : (
                        <table className="page-table" style={{ margin: 0 }}>
                            <tbody>
                                {list.items.map(item => (
                                    <tr key={item.id}>
                                        <td style={{ fontFamily: 'monospace', fontSize: '12px' }}>
                                            {item.value ?? item.ip_or_cidr ?? item.signature}
                                        </td>
                                        <td style={{ width: '40px', textAlign: 'right' }}>
                                            <button onClick={() => handleDelete(type, item.id)} className="btn btn-ghost btn-sm" style={{ color: 'var(--color-danger)', padding: '2px 6px' }}>
                                                <Trash2 size={13} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {hasMore && (
                    <button
                        onClick={() => load(type, { search: list.search, offset: list.items.length, append: true })}
                        className="btn btn-secondary btn-sm"
                        style={{ marginTop: '10px', width: '100%', borderStyle: 'dashed' }}
                        disabled={list.loading}
                    >
                        {t('botSettings.loadMore')} ({list.filtered - list.items.length})
                    </button>
                )}
            </>
        );
    };

    return (
        <div className="space-y-6">
            {/* IP Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldBan size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.ipTitle')}</h3>
                        <span className="badge badge-secondary">{lists.ip.total}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <button onClick={() => handleClear('ip')} className="btn btn-ghost btn-sm">
                            <RotateCcw size={14} />
                            {t('botSettings.clearAll')}
                        </button>
                    </div>
                </div>

                <div style={{ marginTop: '16px' }}>
                    <textarea
                        value={newIps}
                        onChange={(e) => setNewIps(e.target.value)}
                        placeholder={t('botSettings.ipPlaceholder')}
                        rows={4}
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    <div className="flex flex-wrap items-center gap-2 mt-2">
                        <button
                            type="button"
                            onClick={() => handleAdd('ip')}
                            disabled={importing.active}
                            className="btn btn-primary btn-sm flex items-center gap-1.5"
                        >
                            <Plus size={14} />
                            {t('botSettings.addIp')}
                        </button>

                        <input
                            type="file"
                            ref={fileInputIpRef}
                            accept=".txt,.csv"
                            style={{ display: 'none' }}
                            onChange={(e) => handleFileUpload('ip', e)}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputIpRef.current?.click()}
                            disabled={importing.active}
                            className="btn btn-secondary btn-sm flex items-center gap-1.5"
                        >
                            <Upload size={14} />
                            <span>Upload .txt / .csv</span>
                        </button>

                        {importing.active && importing.type === 'ip' && (
                            <span className="text-xs font-medium ml-2 text-blue-500 animate-pulse">
                                Importing {importing.current.toLocaleString()} / {importing.total.toLocaleString()}...
                            </span>
                        )}
                    </div>
                </div>

                {renderList('ip')}
            </div>

            {/* Signatures Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldBan size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.signaturesTitle')}</h3>
                        <span className="badge badge-secondary">{lists.sig.total}</span>
                    </div>
                    <button onClick={() => handleClear('sig')} className="btn btn-ghost btn-sm">
                        <RotateCcw size={14} />
                        {t('botSettings.clearAll')}
                    </button>
                </div>

                <div style={{ marginTop: '16px' }}>
                    <textarea
                        value={newSigs}
                        onChange={(e) => setNewSigs(e.target.value)}
                        placeholder={t('botSettings.signaturePlaceholder')}
                        rows={4}
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    <div className="flex flex-wrap items-center gap-2 mt-2">
                        <button
                            type="button"
                            onClick={() => handleAdd('sig')}
                            disabled={importing.active}
                            className="btn btn-primary btn-sm flex items-center gap-1.5"
                        >
                            <Plus size={14} />
                            {t('botSettings.addSignature')}
                        </button>

                        <input
                            type="file"
                            ref={fileInputSigRef}
                            accept=".txt,.csv"
                            style={{ display: 'none' }}
                            onChange={(e) => handleFileUpload('sig', e)}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputSigRef.current?.click()}
                            disabled={importing.active}
                            className="btn btn-secondary btn-sm flex items-center gap-1.5"
                        >
                            <Upload size={14} />
                            <span>Upload .txt / .csv</span>
                        </button>

                        {importing.active && importing.type === 'sig' && (
                            <span className="text-xs font-medium ml-2 text-blue-500 animate-pulse">
                                Importing {importing.current.toLocaleString()} / {importing.total.toLocaleString()}...
                            </span>
                        )}
                    </div>
                </div>

                {renderList('sig')}
            </div>
        </div>
    );
};

export default BotSettings;