import React, { useState, useEffect, useMemo } from 'react';
import { Plus, Edit2, Trash2, Copy, ExternalLink, Check, Filter, RefreshCw, Settings2, X } from 'lucide-react';
import InfoBanner from './InfoBanner';
import AffiliateNetworkEditor from './AffiliateNetworkEditor';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost, invalidateCache } from '../utils/apiCache';

const AffiliateNetworks = () => {
    const { t } = useLanguage();
    const [networks, setNetworks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editorOpen, setEditorOpen] = useState(false);
    const [editId, setEditId] = useState(null);
    const [copiedId, setCopiedId] = useState(null);
    const [postbackKey, setPostbackKey] = useState('');
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [stateFilter, setStateFilter] = useState('all');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        fetchNetworks();
        fetchPostbackKey();
    }, []);

    const fetchNetworks = async () => {
        try {
            const { data, fromCache } = await cachedGet('affiliate_networks');
            if (data.status === 'success') {
                setNetworks(data.data);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const handleRefresh = async () => {
        if (refreshing) return;
        setRefreshing(true);
        try {
            await fetchNetworks();
        } finally {
            setRefreshing(false);
        }
    };

    const fetchPostbackKey = async () => {
        try {
            const { data } = await cachedGet('settings');
            if (data.status === 'success') {
                setPostbackKey(data.data.postback_key || 'fd12e72');
            }
        } catch (err) {
            console.error(err);
        }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('networks.deleteConfirm'))) return;
        try {
            const res = await cachedPost('delete_affiliate_network', { id });
            if (res?.data?.status !== 'success') {
                alert(res?.data?.message || t('common.error'));
                return;
            }
            fetchNetworks();
        } catch (err) {
            console.error(err);
            alert(err?.response?.data?.message || err?.message || t('common.error'));
        }
    };

    const toggleSelected = (id, checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) {
                filteredNetworks.forEach(n => next.add(n.id));
            } else {
                filteredNetworks.forEach(n => next.delete(n.id));
            }
            return next;
        });
    };

    const filteredNetworks = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        return networks.filter(n => {
            if (q) {
                const name = String(n.name || '').toLowerCase();
                if (!name.includes(q)) return false;
            }
            if (stateFilter !== 'all') {
                const isActive = String(n.state || '') === 'active';
                if (stateFilter === 'active' && !isActive) return false;
                if (stateFilter === 'paused' && isActive) return false;
            }
            return true;
        });
    }, [networks, search, stateFilter]);

    const allSelected = filteredNetworks.length > 0 && filteredNetworks.every(n => selectedIds.has(n.id));
    const someSelected = filteredNetworks.some(n => selectedIds.has(n.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('networks.deleteConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await cachedPost('bulk_delete_affiliate_networks', { ids });
            setSelectedIds(new Set());
            fetchNetworks();
        } catch (err) {
            console.error(err);
            alert(t('common.error'));
        }
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'template', label: 'template' },
            { key: 'state', label: 'state' },
            { key: 'offers_count', label: 'offers_count' },
            { key: 'offer_params', label: 'offer_params' },
            { key: 'postback_url', label: 'postback_url' },
            { key: 'notes', label: 'notes' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = filteredNetworks.map(n => cols.map(c => escape(n[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `affiliate_networks_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const openEditor = (id = null) => {
        setEditId(id);
        setEditorOpen(true);
    };

    const closeEditor = (refresh = false) => {
        setEditorOpen(false);
        setEditId(null);
        if (refresh) fetchNetworks();
    };

    const getPostbackUrl = (network) => {
        const protocol = window.location.protocol;
        const host = window.location.host;
        return `${protocol}//${host}/${postbackKey}/postback`;
    };

    const copyToClipboard = async (text, id) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopiedId(id);
            setTimeout(() => setCopiedId(null), 2000);
        } catch (err) {
            console.error(err);
        }
    };

    if (loading) {
        return <div className="flex justify-center py-10">{t('common.loading')}</div>;
    }

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_affiliate_networks" title={t('help.affiliateNetworkBannerTitle')}>
                <p>{t('help.affiliateNetworkBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="flex justify-between items-center">
                <p className="text-sm text-gray-500">
                    {t('networks.headerDesc')}
                </p>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                        title={t('editor.filters')}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {(search || stateFilter !== 'all') ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[search, stateFilter !== 'all' ? '1' : ''].filter(Boolean).length}
                            </span>
                        ) : null}
                    </button>
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="btn btn-ghost btn-icon"
                        title={t('common.refresh')}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`w-5 h-5 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                    <button type="button" onClick={() => setSettingsOpen(true)} className="btn btn-ghost btn-icon" title={t('common.settings')}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                    {selectedIds.size > 0 && (
                        <button
                            onClick={handleBulkDeleteSelected}
                            className="btn btn-danger"
                            title={t('common.deleteSelected')}
                        >
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedIds.size})
                        </button>
                    )}
                    <button
                        onClick={() => openEditor()}
                        className="btn btn-primary"
                    >
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                </div>
            </div>

            {showFilters && (
                <div className="page-card" style={{ padding: '16px' }}>
                    <div className="flex flex-wrap gap-4 items-center">
                        <div className="flex items-center gap-2">
                            <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('common.search')}:</label>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="form-input"
                                style={{ width: 'auto', minWidth: '260px' }}
                                placeholder={t('common.searchPlaceholder')}
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                            <select value={stateFilter} onChange={(e) => setStateFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '160px' }}>
                                <option value="all">{t('common.all')}</option>
                                <option value="active">{t('sources.activePlural')}</option>
                                <option value="paused">{t('sources.pausedPlural')}</option>
                            </select>
                        </div>
                        {(search || stateFilter !== 'all') && (
                            <button type="button" onClick={() => { setSearch(''); setStateFilter('all'); }} className="btn btn-ghost btn-sm">
                                <X className="w-4 h-4" />
                                {t('common.clear')}
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Networks List */}
            {filteredNetworks.length === 0 ? (
                <div className="text-center py-10 text-gray-400 bg-white border border-dashed border-gray-300 rounded">
                    {t('networks.noNetworksAdd')}
                </div>
            ) : (
                <div className="bg-white rounded shadow overflow-hidden">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        ref={(el) => {
                                            if (el) el.indeterminate = !allSelected && someSelected;
                                        }}
                                        onChange={(e) => toggleSelectAll(e.target.checked)}
                                    />
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('editor.name')}</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('networks.postbackUrl')}</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('networks.offerParams')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('networks.offersCount')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('components.status')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {filteredNetworks.map((network) => (
                                <tr key={network.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            checked={selectedIds.has(network.id)}
                                            onChange={(e) => toggleSelected(network.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-gray-900">{network.name}</div>
                                        {network.template && (
                                            <div className="text-xs text-gray-400">{t('sources.template')}: {network.template}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center space-x-2">
                                            <code className="text-xs bg-gray-100 px-2 py-1 rounded max-w-xs truncate">
                                                {getPostbackUrl(network)}
                                            </code>
                                            <button
                                                onClick={() => copyToClipboard(getPostbackUrl(network), `pb-${network.id}`)}
                                                className="text-gray-400 hover:text-blue-600"
                                                title={t('common.copy')}
                                            >
                                                {copiedId === `pb-${network.id}` ? (
                                                    <Check className="w-4 h-4 text-green-500" />
                                                ) : (
                                                    <Copy className="w-4 h-4" />
                                                )}
                                            </button>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                                            {network.offer_params || <span className="text-gray-400">{t('common.notSet')}</span>}
                                        </code>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {network.offers_count || 0}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className={`inline-flex items-center px-2 py-1 rounded text-xs font-medium ${network.state === 'active'
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-600'
                                            }`}>
                                            {network.state === 'active' ? t('components.active') : t('common.disabled')}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <div className="flex justify-center space-x-2">
                                            <button
                                                onClick={() => openEditor(network.id)}
                                                className="text-gray-400 hover:text-blue-600"
                                                title={t('common.edit')}
                                            >
                                                <Edit2 className="w-4 h-4" />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(network.id)}
                                                className="text-gray-400 hover:text-red-600"
                                                title={t('common.delete')}
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Help Section */}
            <div className="bg-blue-50 border border-blue-200 rounded p-4">
                <h3 className="font-medium text-blue-800 mb-2">{t('networks.setupPostback')}</h3>
                <ol className="text-sm text-blue-700 space-y-1 list-decimal list-inside">
                    <li>{t('networks.step1')}</li>
                    <li>{t('networks.step2')}</li>
                    <li>{t('networks.step3')}</li>
                    <li>{t('networks.step4')}</li>
                    <li>{t('networks.step5')}</li>
                </ol>
                <div className="mt-3 text-xs text-blue-600">
                    <strong>{t('networks.examplePostback')}:</strong>
                    <code className="ml-2 bg-blue-100 px-2 py-1 rounded">
                        https://your-domain.com/{postbackKey}/postback?subid={'{subid}'}&status={'{status}'}&payout={'{payout}'}
                    </code>
                </div>
            </div>

            {/* Editor Modal */}
            {editorOpen && (
                <AffiliateNetworkEditor
                    networkId={editId}
                    onClose={closeEditor}
                    postbackKey={postbackKey}
                />
            )}

            {settingsOpen && (
                <div className="modal-overlay" onClick={() => setSettingsOpen(false)}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '560px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.settings')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setSettingsOpen(false)} title={t('common.close')}>
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-3">
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedIds(new Set()); }}>
                                {t('common.clearSelection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AffiliateNetworks;
