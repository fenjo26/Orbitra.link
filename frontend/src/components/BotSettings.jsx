import React, { useState, useEffect, useCallback, useRef } from 'react';
import { ShieldBan, Plus, Trash2, RotateCcw, Search } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
const PAGE_SIZE = 200;

const BotSettings = () => {
    const { t } = useLanguage();

    // A blacklist can hold tens of thousands of rows, so each list is searched
    // and paged on the server; the panel only ever holds what it has loaded.
    const emptyList = { items: [], total: 0, filtered: 0, search: '', loading: true };
    const [lists, setLists] = useState({ ip: { ...emptyList }, sig: { ...emptyList } });
    const [newIps, setNewIps] = useState('');
    const [newSigs, setNewSigs] = useState('');
    const searchTimers = useRef({});

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
                    // Older builds of the API answered without "filtered".
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

    const fetchData = useCallback(() => {
        load('ip', { search: lists.ip.search });
        load('sig', { search: lists.sig.search });
    }, [load, lists.ip.search, lists.sig.search]);

    useEffect(() => {
        load('ip');
        load('sig');
    }, [load]);

    // Debounced so typing an IP does not fire a query per keystroke.
    const onSearch = (type, value) => {
        setLists(prev => ({ ...prev, [type]: { ...prev[type], search: value } }));
        clearTimeout(searchTimers.current[type]);
        searchTimers.current[type] = setTimeout(() => load(type, { search: value }), 300);
    };

    // One place that speaks the API's contract, so a mismatch cannot creep back
    // into three separate handlers. Anything but an explicit success is surfaced:
    // these operations used to fail silently while still reporting success.
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

    const handleAdd = async (type) => {
        const source = type === 'ip' ? newIps : newSigs;
        if (!source.trim()) return;
        const items = source.split('\n').map(s => s.trim()).filter(Boolean);
        try {
            const data = await mutate(type, { items });
            (type === 'ip' ? setNewIps : setNewSigs)('');
            load(type, { search: lists[type].search });
            const skipped = data.skipped || 0;
            alert(`${t('botSettings.addedCount')} ${data.added ?? 0}`
                + (skipped ? ` (${t('botSettings.skippedDuplicates')} ${skipped})` : ''));
        } catch (e) {
            alert(`${t('botSettings.networkError')}: ${e.message}`);
        }
    };

    const handleAddIps = () => handleAdd('ip');
    const handleAddSigs = () => handleAdd('sig');

    const handleDelete = async (type, id) => {
        try {
            await mutate(type, { action: 'delete', id });
            // Drop the row locally so a delete deep in a long list does not
            // reset the reader's scroll position back to the first page.
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
                        style={{ paddingLeft: '30px', fontFamily: 'monospace', fontSize: '13px' }}
                    />
                </div>

                <div style={{ marginTop: '10px', fontSize: '12px', color: 'var(--color-text-muted)' }}>
                    {list.loading
                        ? t('botSettings.loading')
                        : `${t('botSettings.showing')} ${list.items.length} ${t('botSettings.of')} ${list.filtered}`
                          + (list.search ? ` (${t('botSettings.ofTotal')} ${list.total})` : '')}
                </div>

                <div style={{ marginTop: '8px', maxHeight: '340px', overflowY: 'auto' }}>
                    {list.items.length === 0 ? (
                        <p style={{ color: 'var(--color-text-muted)', fontSize: '14px' }}>{t('botSettings.noRecords')}</p>
                    ) : (
                        <table className="page-table">
                            <tbody>
                                {list.items.map(item => (
                                    <tr key={item.id}>
                                        <td style={{ fontFamily: 'monospace', fontSize: '13px' }}>
                                            {item.value ?? item.ip_or_cidr ?? item.signature}
                                        </td>
                                        <td style={{ width: '40px', textAlign: 'right' }}>
                                            <button onClick={() => handleDelete(type, item.id)} className="btn btn-ghost btn-sm" style={{ color: 'var(--color-danger)' }}>
                                                <Trash2 size={14} />
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
                    </div>
                    <button onClick={() => handleClear('ip')} className="btn btn-ghost btn-sm">
                        <RotateCcw size={14} />
                        {t('botSettings.clearAll')}
                    </button>
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
                    <button onClick={handleAddIps} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                        <Plus size={14} />
                        {t('botSettings.addIp')}
                    </button>
                </div>

                {renderList('ip')}
            </div>

            {/* Signatures Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.signaturesTitle')}</h3>
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
                    <button onClick={handleAddSigs} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                        <Plus size={14} />
                        {t('botSettings.addSignature')}
                    </button>
                </div>

                {renderList('sig')}
            </div>
        </div>
    );
};

export default BotSettings;