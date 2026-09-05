import React, { useState, useEffect, useCallback, useRef } from 'react';
import { ShieldBan, Plus, Trash2, RotateCcw, Search, Upload, CheckCircle2, Save, AlertTriangle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { ignoredBotIspEntries, parseBotIspEntries } from '../utils/botIspList';

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

    // Global Bot ISP blacklist (settings.bot_isp_list) — managed client-side
    // as an array, stored as a comma/newline-separated string in global_settings.
    const [ispList, setIspList] = useState([]);
    const [ispSearch, setIspSearch] = useState('');
    const [newIspKeywords, setNewIspKeywords] = useState('');
    const [ispSaving, setIspSaving] = useState(false);
    const [ispSaved, setIspSaved] = useState(false);
    const fileInputIspRef = useRef(null);

    // Datacenter/crawler range feed (lord-alfred/ipranges). Status only: the
    // ranges are matched by IpRanges, not stored as blocklist rows — see the
    // bot_ips_feed endpoint. The panel could not previously tell whether the
    // lists existed at all, which matters on an install whose cron was never
    // wired up.
    const [feed, setFeed] = useState({ loading: true, updating: false, data: null });

    const endpointOf = (type) => (type === 'ip' ? 'bot_ips' : 'bot_signatures');

    const loadFeed = useCallback(async (update = false) => {
        setFeed(prev => ({ ...prev, loading: !update, updating: update }));
        try {
            const res = await fetch(`${API_URL}?action=bot_ips_feed`, update ? { method: 'POST' } : undefined);
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || `HTTP ${res.status}`);
            setFeed({ loading: false, updating: false, data: data.data });
        } catch (e) {
            setFeed({ loading: false, updating: false, data: null });
            if (update) alert(`${t('botSettings.feedUpdateError')}: ${e.message}`);
        }
    }, [t]);

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
                    sources: data.sources || null,
                },
            }));
        } catch (e) {
            setLists(prev => ({ ...prev, [type]: { ...prev[type], loading: false } }));
            alert(`${t('botSettings.loadError')}: ${e.message}`);
        }
    }, [t]);

    useEffect(() => {
        loadFeed();
    }, [loadFeed]);

    useEffect(() => {
        load('ip');
        load('sig');
    }, [load]);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const res = await fetch(`${API_URL}?action=global_settings`);
                const data = await res.json();
                if (!cancelled && data.status === 'success') {
                    const raw = data.data?.bot_isp_list ?? '';
                    // Same parser as the backend (CloakDetector::parseBotIspEntries):
                    // one provider per line, commas split only legacy lists.
                    const items = parseBotIspEntries(raw)
                        .map(s => s.trim().replace(/^"|"$/g, ''))
                        .filter(Boolean);
                    setIspList(items);
                }
            } catch (e) {
                // The list just starts empty; saving still works.
            }
        })();
        return () => { cancelled = true; };
    }, []);

    const saveIspList = async (itemsToSave = ispList) => {
        setIspSaving(true);
        try {
            const res = await fetch(`${API_URL}?action=global_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: { bot_isp_list: itemsToSave.join('\n') } })
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || data?.status !== 'success') {
                throw new Error(data?.message || `HTTP ${res.status}`);
            }
            setIspSaved(true);
            setIspList(itemsToSave);
            setTimeout(() => setIspSaved(false), 2000);
        } catch (e) {
            alert(`${t('botSettings.saveError')}: ${e.message}`);
        } finally {
            setIspSaving(false);
        }
    };

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

    // `origin` labels where the entries came from, so Clear All can later
    // remove one uploaded list without taking the operator's own typed entries
    // with it. Typed entries are 'manual'; an upload carries its file name.
    const handleAdd = async (type, rawText, origin = 'manual') => {
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
                const data = await mutate(type, { items: chunk, source: origin });
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
                // The file name is the label, trimmed to something a button can
                // print; a list uploaded twice keeps one label rather than two.
                const label = String(file.name || 'upload').replace(/[^\w.-]+/g, '_').slice(0, 40);
                handleAdd(type, text, label || 'upload');
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

    // `scope` is a source name, or 'all'. Clearing used to be one DELETE over
    // the whole table, so removing a few hand-added entries also wiped every
    // list ever uploaded beside them — thousands of rows, and no way back
    // except finding the file again.
    const handleClear = async (type, scope = 'all') => {
        if (!window.confirm(t('botSettings.confirmClear'))) return;
        try {
            await mutate(type, { action: 'clear_all', source: scope });
            alert(t('botSettings.cleared'));
            load(type, { search: lists[type].search });
        } catch (e) {
            alert(`${t('botSettings.clearError')}: ${e.message}`);
        }
    };

    // ISP list handlers (client-side management)
    const handleAddIsp = async (rawText = newIspKeywords) => {
        if (!rawText.trim()) return;
        // Same parser as the backend: one provider per line, so pasted
        // Keitaro lines like "Amazon.com, Inc." stay a single entry.
        const items = parseBotIspEntries(rawText)
            .map(s => s.trim().replace(/^"|"$/g, ''))
            .filter(Boolean);
        if (items.length === 0) {
            // Everything was ignored (too short / bare corporate suffix) — the
            // warning under the textarea already names the entries.
            setNewIspKeywords('');
            return;
        }

        // Deduplicate against existing list
        const existingSet = new Set(ispList.map(s => s.toLowerCase()));
        const newItems = items.filter(s => !existingSet.has(s.toLowerCase()));

        if (newItems.length === 0) {
            alert(t('botSettings.skippedDuplicates'));
            setNewIspKeywords('');
            return;
        }

        const updatedList = [...ispList, ...newItems];
        await saveIspList(updatedList);
        setNewIspKeywords('');

        alert(`${t('botSettings.addedCount')} ${newItems.length}`
            + (items.length - newItems.length ? ` (${t('botSettings.skippedDuplicates')} ${items.length - newItems.length})` : ''));
    };

    const handleIspFileUpload = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            const text = event.target?.result;
            if (typeof text === 'string') {
                handleAddIsp(text);
            }
        };
        reader.readAsText(file);
        e.target.value = '';
    };

    const handleDeleteIsp = async (keyword) => {
        const updatedList = ispList.filter(s => s !== keyword);
        await saveIspList(updatedList);
    };

    const handleClearIsp = async () => {
        if (!window.confirm(t('botSettings.confirmClear'))) return;
        await saveIspList([]);
    };

    const getFilteredIspList = () => {
        if (!ispSearch.trim()) return ispList;
        const searchLower = ispSearch.toLowerCase();
        return ispList.filter(s => s.toLowerCase().includes(searchLower));
    };

    // Mirrors CloakDetector::parseBotIspEntries(): entries the tracker will
    // silently drop from the pasted list (too short / bare corporate suffix).
    const ignoredIspPreview = [...new Set(ignoredBotIspEntries(newIspKeywords))];

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
            {/* Global Bot ISP blacklist */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldBan size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.ispTitle')}</h3>
                        <span className="badge badge-secondary">{ispList.length}</span>
                    </div>
                    <button onClick={handleClearIsp} className="btn btn-ghost btn-sm">
                        <RotateCcw size={14} />
                        {t('botSettings.clearAll')}
                    </button>
                </div>

                <div style={{ marginTop: '16px' }}>
                    <p className="text-xs" style={{ color: 'var(--color-text-muted)', marginBottom: '8px', lineHeight: 1.5 }}>
                        {t('botSettings.ispHint')}
                    </p>
                    <textarea
                        value={newIspKeywords}
                        onChange={(e) => setNewIspKeywords(e.target.value)}
                        placeholder={t('botSettings.ispPlaceholder')}
                        rows={4}
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    {ignoredIspPreview.length > 0 && (
                        <div style={{
                            marginTop: '8px',
                            padding: '8px 10px',
                            background: 'var(--color-warning-bg)',
                            borderRadius: '8px',
                            fontSize: '11px',
                            color: 'var(--color-warning)',
                            display: 'flex',
                            alignItems: 'flex-start',
                            gap: '6px',
                            lineHeight: 1.5
                        }}>
                            <AlertTriangle size={14} className="shrink-0" style={{ marginTop: '1px' }} />
                            <span>
                                {t('botSettings.ispIgnoredWarning')}:{' '}
                                <b>
                                    {ignoredIspPreview.slice(0, 8).join(', ')}
                                    {ignoredIspPreview.length > 8 ? ` +${ignoredIspPreview.length - 8}` : ''}
                                </b>
                            </span>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2 mt-2">
                        <button
                            type="button"
                            onClick={() => handleAddIsp()}
                            disabled={ispSaving}
                            className="btn btn-primary btn-sm flex items-center gap-1.5"
                        >
                            {ispSaved ? <CheckCircle2 size={14} /> : ispSaving ? <Save size={14} /> : <Plus size={14} />}
                            {ispSaved ? t('botSettings.saved') : ispSaving ? t('botSettings.saving') : t('botSettings.addIsp')}
                        </button>

                        <input
                            type="file"
                            ref={fileInputIspRef}
                            accept=".txt,.csv"
                            style={{ display: 'none' }}
                            onChange={handleIspFileUpload}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputIspRef.current?.click()}
                            disabled={ispSaving}
                            className="btn btn-secondary btn-sm flex items-center gap-1.5"
                        >
                            <Upload size={14} />
                            <span>Upload .txt / .csv</span>
                        </button>

                        {ispSaving && (
                            <span className="text-xs font-medium ml-2 text-blue-500 animate-pulse">
                                {t('botSettings.saving')}...
                            </span>
                        )}
                    </div>

                    {/* Search */}
                    <div style={{ marginTop: '16px', position: 'relative' }}>
                        <Search size={14} style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--color-text-muted)', pointerEvents: 'none' }} />
                        <input
                            type="text"
                            value={ispSearch}
                            onChange={(e) => setIspSearch(e.target.value)}
                            placeholder={t('botSettings.searchPlaceholder')}
                            className="form-input"
                            style={{ paddingLeft: '32px', fontSize: '13px' }}
                        />
                    </div>

                    {/* List */}
                    <div style={{ marginTop: '12px', maxHeight: '420px', overflowY: 'auto', border: '1px solid var(--color-border)', borderRadius: '12px' }}>
                        {getFilteredIspList().length === 0 ? (
                            <div style={{ padding: '24px', textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px' }}>
                                {ispSearch.trim() ? t('botSettings.noSearchResults') : t('botSettings.noItems')}
                            </div>
                        ) : (
                            <table className="page-table" style={{ margin: 0 }}>
                                <tbody>
                                    {getFilteredIspList().map((keyword, idx) => (
                                        <tr key={idx}>
                                            <td style={{ fontFamily: 'monospace', fontSize: '12px' }}>
                                                {keyword}
                                            </td>
                                            <td style={{ width: '40px', textAlign: 'right' }}>
                                                <button onClick={() => handleDeleteIsp(keyword)} className="btn btn-ghost btn-sm" style={{ color: 'var(--color-danger)', padding: '2px 6px' }}>
                                                    <Trash2 size={13} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            {/* IP Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldBan size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.ipTitle')}</h3>
                        <span className="badge badge-secondary">{lists.ip.total}</span>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap justify-end">
                        {/* One button per source once there is more than one, so
                            an uploaded list can be removed without taking the
                            operator's own typed entries with it. With a single
                            source there is nothing to choose between and only
                            the plain Clear all shows. */}
                        {Object.entries(lists.ip.sources || {}).length > 1
                            && Object.entries(lists.ip.sources || {}).map(([name, count]) => (
                                <button
                                    key={name}
                                    onClick={() => handleClear('ip', name)}
                                    className="btn btn-ghost btn-sm"
                                    title={t('botSettings.clearSourceHint')}
                                >
                                    <Trash2 size={13} />
                                    <span style={{ fontFamily: 'monospace', fontSize: '11px' }}>{name}</span>
                                    <span className="badge badge-secondary">{count}</span>
                                </button>
                            ))}
                        <button onClick={() => handleClear('ip')} className="btn btn-ghost btn-sm">
                            <RotateCcw size={14} />
                            {t('botSettings.clearAll')}
                        </button>
                    </div>
                </div>

                {/* Datacenter / crawler range feed. Status, not a list: these
                    ranges are matched by IpRanges (which understands CIDR and
                    indexes them), never stored as blocklist rows — 20k rows here
                    would look like rules and fire none. */}
                <div
                    className="mt-4 p-3 rounded-xl flex flex-wrap items-center gap-x-3 gap-y-2"
                    style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}
                >
                    <div className="min-w-0 flex-1">
                        <div className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                            {t('botSettings.feedTitle')}
                        </div>
                        <div className="text-[11px] mt-0.5" style={{ color: 'var(--color-text-muted)' }}>
                            {feed.loading
                                ? t('botSettings.loading')
                                : !feed.data?.available
                                    ? t('botSettings.feedMissing')
                                    : `${(feed.data.ipv4 || 0).toLocaleString()} IPv4 · ${(feed.data.ipv6 || 0).toLocaleString()} IPv6${feed.data.updated_at ? ` · ${feed.data.updated_at}` : ''}`}
                        </div>
                    </div>
                    {!feed.loading && feed.data?.available && (
                        <span className={`badge ${feed.data.fresh ? 'badge-success' : 'badge-warning'}`}>
                            {feed.data.fresh ? t('botSettings.feedFresh') : t('botSettings.feedStale')}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={() => loadFeed(true)}
                        disabled={feed.updating}
                        className="btn btn-secondary btn-sm flex items-center gap-1.5"
                    >
                        <RotateCcw size={14} />
                        {feed.updating ? t('botSettings.feedUpdating') : t('botSettings.feedUpdate')}
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