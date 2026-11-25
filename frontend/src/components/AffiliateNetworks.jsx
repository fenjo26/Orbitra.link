import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { Plus, Edit2, Trash2, Copy, Check, Filter, RefreshCw, Settings2, X, ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import InfoBanner from './InfoBanner';
import AffiliateNetworkEditor from './AffiliateNetworkEditor';
import MobileCards from './common/MobileCards';
import { ColumnResizeHandle } from './common/ColumnResize';
import { useIsDesktop, useResizableTableColumns } from './common/resizableColumns';
import { usePageTableSort } from './common/usePageTableSort';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard as copyText } from '../utils/clipboard';
import { cachedGet, cachedPost } from '../utils/apiCache';
import { canWriteResource } from '../utils/permissions';

const NETWORK_TABLE_COLUMNS = [
    { id: 'check', width: 42 },
    { id: 'name', width: 220 },
    { id: 'postback_url', width: 320 },
    { id: 'offer_params', width: 200 },
    { id: 'offers_count', width: 100 },
    { id: 'state', width: 110 },
    { id: 'actions', width: 100 },
];

const NetworkSortTh = ({ colId, label, sort, onSort, colResize, align = 'left', firstDir = 'asc' }) => {
    const active = sort.key === colId;
    const Icon = !active ? ChevronsUpDown : (sort.dir === 'asc' ? ChevronUp : ChevronDown);
    const icon = <Icon className="page-sort-icon" aria-hidden="true" />;
    const justify = align === 'right' ? 'flex-end' : (align === 'center' ? 'center' : 'flex-start');
    return (
        <th
            data-col={colId}
            className="resizable-th"
            aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
            style={{ textAlign: align }}
        >
            <button
                type="button"
                className="page-sort-btn"
                data-active={active ? 'true' : 'false'}
                style={{ justifyContent: justify, width: '100%' }}
                onClick={() => onSort(colId, firstDir)}
            >
                {align === 'right' && icon}
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{label}</span>
                {align !== 'right' && icon}
            </button>
            <ColumnResizeHandle rt={colResize} colId={colId} />
        </th>
    );
};

const AffiliateNetworks = ({ user }) => {
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

    // Mirrors the backend gate in core/resource_access.php: POST
    // affiliate_networks (save), delete_affiliate_network and
    // bulk_delete_affiliate_networks are all write actions.
    const canWriteNetworks = canWriteResource(user, 'networks');

    useEffect(() => {
        fetchNetworks();
        fetchPostbackKey();
    }, []);

    const fetchNetworks = async () => {
        try {
            const { data } = await cachedGet('affiliate_networks');
            if (data.status === 'success') {
                setNetworks(Array.isArray(data.data) ? data.data : []);
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

    const isDesktop = useIsDesktop();
    const colResize = useResizableTableColumns({
        tableId: 'affiliate_networks',
        enabled: isDesktop,
        columns: NETWORK_TABLE_COLUMNS,
    });

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

    const getPostbackUrl = useCallback((network) => {
        if (network?.postback_url) {
            return network.postback_url;
        }
        const protocol = window.location.protocol;
        const host = window.location.host;
        return `${protocol}//${host}/${postbackKey}/postback`;
    }, [postbackKey]);

    const networkSortValue = useCallback((row, key) => {
        if (key === 'offers_count') return Number(row.offers_count) || 0;
        if (key === 'postback_url') return getPostbackUrl(row);
        if (key === 'offer_params') return row.offer_params || '';
        return row[key] ?? '';
    }, [getPostbackUrl]);

    const { sorted: sortedNetworks, sort, onSort: handleSort } = usePageTableSort(filteredNetworks, {
        defaultKey: 'name',
        defaultDir: 'asc',
        getValue: networkSortValue,
        storageKey: 'orbitra_networks_sort',
    });

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
        const lines = sortedNetworks.map(n => cols.map(c => escape(n[c.key])).join(','));
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

    // Goes through utils/clipboard (Clipboard API on HTTPS, execCommand
    // fallback on plain HTTP/IP installs) — navigator.clipboard alone is
    // blocked by browsers in non-secure contexts.
    const copyToClipboard = async (text, id) => {
        const ok = await copyText(text);
        if (ok) {
            setCopiedId(id);
            setTimeout(() => setCopiedId(null), 2000);
        }
    };

    // Shared renderers: the desktop table and the mobile cards call the same
    // helpers, so the two views cannot drift apart.
    const renderNetworkState = (network) => (
        <span className={`status-badge ${network.state === 'active' ? 'status-active' : 'status-pending'}`}>
            {network.state === 'active' ? t('components.active') : t('common.disabled')}
        </span>
    );

    const renderNetworkActions = (network) => {
        if (!canWriteNetworks) return null;
        return (
            <div className="action-buttons">
                <button
                    type="button"
                    onClick={() => openEditor(network.id)}
                    className="action-btn"
                    title={t('common.edit')}
                >
                    <Edit2 size={16} />
                </button>
                <button
                    type="button"
                    onClick={() => handleDelete(network.id)}
                    className="action-btn text-red"
                    title={t('common.delete')}
                >
                    <Trash2 size={16} />
                </button>
            </div>
        );
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
                <p className="text-sm text-[var(--color-text-secondary)]">
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
                    {selectedIds.size > 0 && canWriteNetworks && (
                        <button
                            onClick={handleBulkDeleteSelected}
                            className="btn btn-danger"
                            title={t('common.deleteSelected')}
                        >
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedIds.size})
                        </button>
                    )}
                    {canWriteNetworks && (
                        <button
                            onClick={() => openEditor()}
                            className="btn btn-primary"
                        >
                            <Plus className="w-4 h-4" />
                            {t('common.create')}
                        </button>
                    )}
                </div>
            </div>

            {showFilters && (
                <div className="page-card" style={{ padding: '16px' }}>
                    <div className="flex flex-wrap gap-4 items-center">
                        <div className="flex items-center gap-2">
                            <label className="text-sm tb-hide-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('common.search')}:</label>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="form-input tb-release"
                                style={{ width: 'auto', minWidth: '260px' }}
                                placeholder={t('common.searchPlaceholder')}
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm tb-hide-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                            <select value={stateFilter} onChange={(e) => setStateFilter(e.target.value)} className="form-select tb-release" style={{ width: 'auto', minWidth: '160px' }}>
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
            <div className="page-card overflow-hidden" style={{ padding: 0 }}>
                {/* overflow-x-auto: postback URLs are wider than a tablet
                    viewport — without a scroll container the column was
                    simply cut off. Below lg the table is replaced by
                    stacked cards. */}
                <div className="hidden lg:block overflow-x-auto">
                    <table className="page-table tracker-table networks-table" style={{ fontVariantNumeric: 'tabular-nums', ...colResize.tableStyle }}>
                        {colResize.colgroup}
                        <thead>
                            <tr>
                                <th data-col="check" style={{ textAlign: 'center' }}>
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        ref={(el) => {
                                            if (el) el.indeterminate = !allSelected && someSelected;
                                        }}
                                        onChange={(e) => toggleSelectAll(e.target.checked)}
                                    />
                                </th>
                                <NetworkSortTh colId="name" label={t('editor.name')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                <NetworkSortTh colId="postback_url" label={t('networks.postbackUrl')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                <NetworkSortTh colId="offer_params" label={t('networks.offerParams')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                <NetworkSortTh colId="offers_count" label={t('networks.offersCount')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                <NetworkSortTh colId="state" label={t('components.status')} sort={sort} onSort={handleSort} colResize={colResize} align="center" firstDir="asc" />
                                <th data-col="actions" className="resizable-th" style={{ textAlign: 'center' }}>
                                    {t('common.actions')}
                                    <ColumnResizeHandle rt={colResize} colId="actions" />
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {sortedNetworks.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('networks.noNetworksAdd')}
                                    </td>
                                </tr>
                            ) : (
                                sortedNetworks.map((network) => {
                                    const postbackUrl = getPostbackUrl(network);
                                    return (
                                        <tr key={network.id}>
                                            <td style={{ textAlign: 'center' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.has(network.id)}
                                                    onChange={(e) => toggleSelected(network.id, e.target.checked)}
                                                />
                                            </td>
                                            <td
                                                title={network.template ? `${network.name}\n${t('sources.template')}: ${network.template}` : network.name}
                                                style={{ textAlign: 'left' }}
                                            >
                                                <div className="font-medium truncate" style={{ color: 'var(--color-text-primary)' }}>{network.name}</div>
                                                {network.template && (
                                                    <div className="text-[11px] truncate" style={{ color: 'var(--color-text-muted)' }}>{t('sources.template')}: {network.template}</div>
                                                )}
                                            </td>
                                            <td title={postbackUrl} style={{ textAlign: 'left' }}>
                                                <div className="flex items-center gap-1.5 min-w-0">
                                                    <code
                                                        onClick={() => copyToClipboard(postbackUrl, `pb-${network.id}`)}
                                                        className="text-xs px-2 py-0.5 rounded truncate min-w-0 flex-1 cursor-pointer select-all"
                                                        style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}
                                                        title={postbackUrl}
                                                    >
                                                        {postbackUrl}
                                                    </code>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyToClipboard(postbackUrl, `pb-${network.id}`)}
                                                        className="flex-shrink-0 hover:text-[var(--color-primary)] transition"
                                                        style={{ color: 'var(--color-text-muted)' }}
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
                                            <td title={network.offer_params || ''} style={{ textAlign: 'left' }}>
                                                {network.offer_params ? (
                                                    <code
                                                        className="text-xs px-2 py-0.5 rounded truncate block max-w-full"
                                                        style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}
                                                    >
                                                        {network.offer_params}
                                                    </code>
                                                ) : (
                                                    <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('common.notSet')}</span>
                                                )}
                                            </td>
                                            <td style={{ textAlign: 'right' }}>
                                                {network.offers_count || 0}
                                            </td>
                                            <td style={{ textAlign: 'center' }}>
                                                {renderNetworkState(network)}
                                            </td>
                                            <td style={{ textAlign: 'center' }}>
                                                {renderNetworkActions(network)}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Mobile: stacked cards (below lg). Status and actions come
                    from the same renderers the table uses. */}
                <div className="lg:hidden">
                    <MobileCards
                        rows={sortedNetworks}
                        getId={(n) => n.id}
                        renderTitle={(n) => (
                            <>
                                <input
                                    type="checkbox"
                                    checked={selectedIds.has(n.id)}
                                    onChange={(e) => toggleSelected(n.id, e.target.checked)}
                                    className="rounded flex-shrink-0"
                                    style={{ accentColor: 'var(--color-primary)' }}
                                    aria-label={n.name}
                                />
                                <span className="font-semibold text-sm flex-1 min-w-0 line-clamp-2 break-words" style={{ color: 'var(--color-text-primary)' }}>{n.name}</span>
                            </>
                        )}
                        renderSubtitle={(n) => n.template ? `${t('sources.template')}: ${n.template}` : `#${n.id}`}
                        renderHeaderRight={renderNetworkActions}
                        fields={[
                            {
                                id: 'postback',
                                label: t('networks.postbackUrl'),
                                render: (n) => (
                                    <code
                                        onClick={() => copyToClipboard(getPostbackUrl(n), `pb-${n.id}`)}
                                        className="text-xs px-2 py-1 rounded max-w-full truncate inline-block cursor-pointer select-all"
                                        style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}
                                        title={getPostbackUrl(n)}
                                    >
                                        {getPostbackUrl(n)}
                                    </code>
                                ),
                            },
                            {
                                id: 'params',
                                label: t('networks.offerParams'),
                                render: (n) => n.offer_params ? (
                                    <code className="text-xs px-2 py-1 rounded max-w-full truncate inline-block" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}>{n.offer_params}</code>
                                ) : (
                                    <span style={{ color: 'var(--color-text-muted)' }}>{t('common.notSet')}</span>
                                ),
                            },
                            { id: 'offers', label: t('networks.offersCount'), render: (n) => n.offers_count || 0 },
                            { id: 'state', label: t('components.status'), render: renderNetworkState },
                        ]}
                        primaryIds={['offers', 'state']}
                        emptyState={<div className="text-center py-10" style={{ color: 'var(--color-text-secondary)' }}>{t('networks.noNetworksAdd')}</div>}
                    />
                </div>
            </div>

            {/* Help Section */}
            <div className="page-card mt-6" style={{ borderLeft: '4px solid var(--color-primary)' }}>
                <h3 className="font-semibold mb-2" style={{ color: 'var(--color-primary)', fontSize: '15px' }}>{t('networks.setupPostback')}</h3>
                <ol className="text-sm space-y-2 list-decimal list-inside" style={{ color: 'var(--color-text-primary)' }}>
                    <li>{t('networks.step1')}</li>
                    <li>{t('networks.step2')}</li>
                    <li>{t('networks.step3')}</li>
                    <li>{t('networks.step4')}</li>
                    <li>{t('networks.step5')}</li>
                </ol>
                <div className="mt-4 text-sm flex flex-wrap items-center gap-2">
                    <strong style={{ color: 'var(--color-text-secondary)' }}>{t('networks.examplePostback')}:</strong>
                    <code className="px-3 py-1.5 rounded text-xs font-mono" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}>
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
