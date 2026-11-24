import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { canWriteResource } from '../utils/permissions';
import { Plus, Edit, Trash2, Search, RefreshCw, ExternalLink, Copy, Settings2, Filter, X, Upload, Globe, ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import InfoBanner from './InfoBanner';
import TrafficSourceEditor from './TrafficSourceEditor';
import BulkImportSources from './BulkImportSources';
import MobileCards from './common/MobileCards';
import { ColumnResizeHandle } from './common/ColumnResize';
import { useIsDesktop, useResizableTableColumns } from './common/resizableColumns';
import { usePageTableSort } from './common/usePageTableSort';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const SourceSortTh = ({ colId, label, sort, onSort, colResize, align = 'left', firstDir = 'asc' }) => {
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

const TrafficSources = ({ refreshData, user, autoOpenId, onAutoOpenHandled }) => {
    const { t } = useLanguage();
    const [sources, setSources] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [stateFilter, setStateFilter] = useState('all');
    const [showEditor, setShowEditor] = useState(false);
    const [editId, setEditId] = useState(null);
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [showFilters, setShowFilters] = useState(true);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [showBulkImport, setShowBulkImport] = useState(false);
    const [checkingUrls, setCheckingUrls] = useState(false);
    // One-shot section highlight handed to the editor by a cross-page open
    // (the reports' attribution banner) and consumed on editor close.
    const [pendingHighlight, setPendingHighlight] = useState(null);

    const fetchSources = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=traffic_sources`);
            if (res.data.status === 'success') {
                setSources(res.data.data);
            }
        } catch (error) {
            console.error('Error fetching traffic sources:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleRefresh = async () => {
        if (refreshing) return;
        setRefreshing(true);
        try {
            await fetchSources();
            refreshData && await Promise.resolve(refreshData());
        } finally {
            setRefreshing(false);
        }
    };

    useEffect(() => {
        fetchSources();
    }, []);

    // Cross-page "Fix" from the reports' attribution banner: open this page's
    // editor straight on the offending source (params tab highlighted).
    useEffect(() => {
        const openSource = (e) => {
            const id = e.detail?.id;
            if (!id) return;
            setEditId(id);
            setPendingHighlight(e.detail?.highlight || null);
            setShowEditor(true);
        };
        window.addEventListener('orbitra:edit-source', openSource);
        return () => window.removeEventListener('orbitra:edit-source', openSource);
    }, []);

    // Dashboard "data block" jump: land here with this source's editor open.
    // One-shot — the prop is cleared right away so re-entering the tab later
    // is a plain list view again.
    useEffect(() => {
        if (autoOpenId === null || autoOpenId === undefined) return;
        setEditId(autoOpenId);
        setPendingHighlight(null);
        setShowEditor(true);
        onAutoOpenHandled && onAutoOpenHandled();
        // One-shot on the jump id only — the clear-callback re-running this
        // must not re-open an editor the user has just closed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoOpenId]);

    const isDesktop = useIsDesktop();
    const colResize = useResizableTableColumns({
        tableId: 'traffic_sources',
        enabled: isDesktop,
        columns: [
            { id: 'check', width: 42 },
            { id: 'name', width: 240 },
            { id: 'url', width: 220 },
            { id: 'template', width: 140 },
            { id: 'campaigns_count', width: 110 },
            { id: 'clicks', width: 100 },
            { id: 'conversions', width: 120 },
            { id: 'state', width: 110 },
            { id: 'actions', width: 100 },
        ],
    });

    const filteredSources = useMemo(() => sources.filter(s => {
        const matchesSearch = s.name.toLowerCase().includes(search.toLowerCase());
        const matchesState = stateFilter === 'all' ||
            (stateFilter === 'active' && s.state === 'active') ||
            (stateFilter === 'paused' && s.state !== 'active');
        return matchesSearch && matchesState;
    }), [sources, search, stateFilter]);

    const { sortedRows: sortedSources, sort, handleSort } = usePageTableSort(
        filteredSources,
        { key: 'clicks', dir: 'desc' },
        (row, key) => {
            if (key === 'campaigns_count' || key === 'clicks' || key === 'conversions') {
                return Number(row[key]) || 0;
            }
            if (key === 'template') return row.template || '';
            if (key === 'url') return row.url || '';
            return row[key] ?? '';
        }
    );

    const handleDelete = async (id) => {
        if (!confirm(t('sources.deleteConfirm'))) return;
        try {
            const res = await axios.post(`${API_URL}?action=delete_traffic_source`, { id });
            if (res?.data?.status !== 'success') {
                alert(res?.data?.message || t('common.error'));
                return;
            }
            fetchSources();
            refreshData && refreshData();
        } catch (error) {
            console.error('Error deleting traffic source:', error);
            alert(error?.response?.data?.message || error?.message || t('common.error'));
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

    const toggleSelectAllFiltered = (checked) => {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (checked) {
                filteredSources.forEach(s => next.add(s.id));
            } else {
                filteredSources.forEach(s => next.delete(s.id));
            }
            return next;
        });
    };

    const allFilteredSelected = filteredSources.length > 0 && filteredSources.every(s => selectedIds.has(s.id));
    const someFilteredSelected = filteredSources.some(s => selectedIds.has(s.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('sources.deleteConfirm')).replace('{count}', String(ids.length));
        if (!confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_traffic_sources`, { ids });
            setSelectedIds(new Set());
            fetchSources();
            refreshData && refreshData();
        } catch (error) {
            console.error('Error deleting traffic sources:', error);
        }
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'template', label: 'template' },
            { key: 'campaigns_count', label: 'campaigns_count' },
            { key: 'clicks', label: 'clicks' },
            { key: 'conversions', label: 'conversions' },
            { key: 'state', label: 'state' },
            { key: 'notes', label: 'notes' },
            { key: 'postback_url', label: 'postback_url' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = filteredSources.map(s => cols.map(c => escape(s[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `traffic_sources_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleEdit = (id) => {
        setEditId(id);
        setShowEditor(true);
    };

    const handleCreate = () => {
        setEditId(null);
        setShowEditor(true);
    };

    const handleEditorSave = (_saved, shouldClose = true) => {
        if (shouldClose) setShowEditor(false);
        fetchSources();
        refreshData && refreshData();
    };

    const checkSourceUrl = async (id) => {
        try {
            await axios.get(`${API_URL}?action=check_source_url&id=${id}`);
            fetchSources();
        } catch (error) {
            console.error('Error checking URL:', error);
        }
    };

    const checkAllUrls = async () => {
        if (checkingUrls) return;
        setCheckingUrls(true);
        try {
            await axios.post(`${API_URL}?action=check_all_source_urls`);
            fetchSources();
        } catch (error) {
            console.error('Error checking URLs:', error);
        } finally {
            setCheckingUrls(false);
        }
    };

    const handleBulkImportSave = () => {
        setShowBulkImport(false);
        fetchSources();
        refreshData && refreshData();
    };

    // Shared renderers: the desktop table and the mobile cards call the same
    // helpers so the two views cannot drift — the URL cell alone carries a
    // link, an HTTP status and two conditional check buttons.
    const renderSourceUrl = (source) => source.url ? (
        <div className="flex flex-col gap-0.5 min-w-0">
            <a
                href={source.url}
                target="_blank"
                rel="noopener noreferrer"
                title={source.url}
                className="text-xs underline hover:text-primary truncate block"
            >
                {(() => { try { return new URL(source.url, 'https://' + source.url).hostname; } catch { return source.url; } })()}
            </a>
            {source.http_status && source.http_status !== 'unknown' && (
                <div className="flex items-center gap-2">
                    <span className={`text-xs ${source.http_status === '200' ? 'text-[var(--color-success,#10b981)]' : 'text-[var(--color-danger,#ef4444)]'}`}>
                        {source.http_status === '200' ? '✓ OK' : `✗ ${source.http_status}`}
                    </span>
                    <button
                        onClick={() => checkSourceUrl(source.id)}
                        className="text-xs hover:underline"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        {t('sources.check')}
                    </button>
                </div>
            )}
            {!source.http_status || source.http_status === 'unknown' ? (
                <button
                    onClick={() => checkSourceUrl(source.id)}
                    className="text-xs hover:underline"
                    style={{ color: 'var(--color-primary)' }}
                >
                    {t('sources.check')}
                </button>
            ) : null}
        </div>
    ) : (
        <span className="text-sm" style={{ color: 'var(--color-text-muted)' }}>—</span>
    );

    const renderSourceState = (source) => (
        <span className={`status-badge ${source.state === 'active' ? 'status-active' : 'status-pending'}`}>
            {source.state === 'active' ? t('components.active') : t('components.paused')}
        </span>
    );

    const renderSourceActions = (source) => (
        <div className="action-buttons">
            <button
                onClick={() => handleEdit(source.id)}
                className="action-btn"
                title={t('components.edit')}
            >
                <Edit size={16} />
            </button>
            <button
                onClick={() => handleDelete(source.id)}
                className="action-btn text-red"
                title={t('common.delete')}
            >
                <Trash2 size={16} />
            </button>
        </div>
    );

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_traffic_sources" title={t('help.trafficSourceBannerTitle')}>
                <p>{t('help.trafficSourceBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {(search || stateFilter !== 'all') ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[search, stateFilter !== 'all' ? '1' : ''].filter(Boolean).length}
                            </span>
                        ) : null}
                    </button>
                </div>
                <div className="flex gap-2 flex-wrap justify-end">
                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="btn btn-ghost btn-icon"
                        title={t('common.refresh')}
                        disabled={refreshing}
                    >
                        <RefreshCw className={`w-5 h-5 ${refreshing ? 'animate-spin' : ''}`} />
                    </button>
                    <button
                        type="button"
                        onClick={checkAllUrls}
                        className="btn btn-ghost btn-icon"
                        title={t('sources.checkAllUrls')}
                        disabled={checkingUrls}
                    >
                        <Globe className={`w-5 h-5 ${checkingUrls ? 'animate-pulse' : ''}`} />
                    </button>
                    <button type="button" onClick={() => setSettingsOpen(true)} className="btn btn-ghost btn-icon" title={t('common.settings')}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                    {selectedIds.size > 0 && canWriteResource(user, 'sources') && (
                        <button type="button" onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 size={18} />
                            <span>{(t('common.deleteSelected') || t('common.delete'))} ({selectedIds.size})</span>
                        </button>
                    )}
                    {canWriteResource(user, 'sources') && (
                        <button type="button" onClick={handleCreate} className="btn btn-primary">
                            <Plus size={18} />
                            <span>{t('common.create')}</span>
                        </button>
                    )}
                    <button type="button" onClick={() => setShowBulkImport(true)} className="btn btn-secondary">
                        <Upload size={18} />
                        <span>{t('sources.importBtn')}</span>
                    </button>
                </div>
            </div>

            {showFilters && (
                <div className="page-card" style={{ padding: '16px' }}>
                    <div className="flex flex-wrap gap-4 items-center">
                        <div className="relative min-w-[220px] tb-release">
                            <Search 
                                className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none select-none" 
                                style={{ color: 'var(--color-text-muted)' }} 
                            />
                            <input
                                type="text"
                                placeholder={t('sources.search', 'Search sources...')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="form-input text-xs rounded-xl w-full pr-8 transition-all"
                                style={{ 
                                    paddingLeft: '36px',
                                    backgroundColor: 'var(--color-bg-card)',
                                    borderColor: 'var(--color-border)',
                                    color: 'var(--color-text-primary)'
                                }}
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => setSearch('')}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 rounded-full hover:bg-black/10 dark:hover:bg-white/10 text-[var(--color-text-muted)]"
                                    title={t('common.clear', 'Clear')}
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            )}
                        </div>
                        <select
                            value={stateFilter}
                            onChange={(e) => setStateFilter(e.target.value)}
                            className="form-select tb-release"
                            style={{ width: 'auto', minWidth: '160px' }}
                        >
                            <option value="all">{t('common.all')}</option>
                            <option value="active">{t('sources.activePlural')}</option>
                            <option value="paused">{t('sources.pausedPlural')}</option>
                        </select>
                        {(search || stateFilter !== 'all') && (
                            <button type="button" onClick={() => { setSearch(''); setStateFilter('all'); }} className="btn btn-ghost btn-sm">
                                <X className="w-4 h-4" />
                                {t('common.clear')}
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Table */}
            {loading ? (
                <div className="flex justify-center items-center h-64">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2" style={{ borderColor: 'var(--color-primary)' }}></div>
                </div>
            ) : (
                <div className="page-card p-0 overflow-hidden">
                    {/* Below lg the nine-column table is replaced by stacked
                        cards; the table keeps its scroll container for
                        tablets. */}
                    <div className="hidden lg:block overflow-x-auto">
                        <table className="page-table tracker-table sources-table" style={{ fontVariantNumeric: 'tabular-nums', ...colResize.tableStyle }}>
                            {colResize.colgroup}
                            <thead>
                                <tr>
                                    <th data-col="check" style={{ textAlign: 'center' }}>
                                        <input
                                            type="checkbox"
                                            checked={allFilteredSelected}
                                            ref={(el) => {
                                                if (el) el.indeterminate = !allFilteredSelected && someFilteredSelected;
                                            }}
                                            onChange={(e) => toggleSelectAllFiltered(e.target.checked)}
                                        />
                                    </th>
                                    <SourceSortTh colId="name" label={t('editor.name')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <SourceSortTh colId="url" label={t('sources.urlStatus')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <SourceSortTh colId="template" label={t('sources.template')} sort={sort} onSort={handleSort} colResize={colResize} align="center" firstDir="asc" />
                                    <SourceSortTh colId="campaigns_count" label={t('campaigns.campaigns')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                    <SourceSortTh colId="clicks" label={t('components.clicks')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                    <SourceSortTh colId="conversions" label={t('metrics.conversions')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                    <SourceSortTh colId="state" label={t('components.status')} sort={sort} onSort={handleSort} colResize={colResize} align="center" firstDir="asc" />
                                    <th data-col="actions" className="resizable-th" style={{ textAlign: 'center' }}>
                                        {t('common.actions')}
                                        <ColumnResizeHandle rt={colResize} colId="actions" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sortedSources.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                            {search ? t('sources.notFound') : t('sources.noSourcesAdd')}
                                        </td>
                                    </tr>
                                ) : (
                                    sortedSources.map(source => (
                                        <tr key={source.id}>
                                            <td style={{ textAlign: 'center' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.has(source.id)}
                                                    onChange={(e) => toggleSelected(source.id, e.target.checked)}
                                                />
                                            </td>
                                            <td title={source.notes ? `${source.name}\n${source.notes}` : source.name} style={{ textAlign: 'left' }}>
                                                <div className="font-medium truncate">{source.name}</div>
                                                {source.notes && (
                                                    <div className="text-[11px] truncate" style={{ color: 'var(--color-text-muted)' }}>{source.notes}</div>
                                                )}
                                            </td>
                                            <td style={{ textAlign: 'left' }}>
                                                {renderSourceUrl(source)}
                                            </td>
                                            <td title={source.template || t('sources.customTemplate')} style={{ textAlign: 'center' }}>
                                                <span
                                                    className="status-badge"
                                                    style={{
                                                        backgroundColor: 'var(--color-bg-soft)',
                                                        color: 'var(--color-text-secondary)',
                                                        maxWidth: '100%',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        display: 'inline-block',
                                                        verticalAlign: 'middle'
                                                    }}
                                                >
                                                    {source.template || t('sources.customTemplate')}
                                                </span>
                                            </td>
                                            <td style={{ textAlign: 'right' }}>{source.campaigns_count || 0}</td>
                                            <td style={{ textAlign: 'right' }}>{source.clicks || 0}</td>
                                            <td style={{ textAlign: 'right' }}>{source.conversions || 0}</td>
                                            <td style={{ textAlign: 'center' }}>
                                                {renderSourceState(source)}
                                            </td>
                                            <td style={{ textAlign: 'center' }}>
                                                {renderSourceActions(source)}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile: stacked cards (below lg). URL, state and actions
                        come from the same renderers the table uses. */}
                    <div className="lg:hidden">
                        <MobileCards
                            rows={filteredSources}
                            getId={(s) => s.id}
                            renderTitle={(s) => (
                                <>
                                    <input
                                        type="checkbox"
                                        checked={selectedIds.has(s.id)}
                                        onChange={(e) => toggleSelected(s.id, e.target.checked)}
                                        className="rounded flex-shrink-0"
                                        style={{ accentColor: 'var(--color-primary)' }}
                                        aria-label={s.name}
                                    />
                                    <span className="font-semibold text-sm flex-1 min-w-0 line-clamp-2 break-words" style={{ color: 'var(--color-text-primary)' }}>{s.name}</span>
                                </>
                            )}
                            renderSubtitle={(s) => s.notes ? s.notes.substring(0, 60) : `#${s.id}`}
                            renderHeaderRight={renderSourceActions}
                            fields={[
                                { id: 'url', label: t('sources.urlStatus'), render: renderSourceUrl },
                                { id: 'template', label: t('sources.template'), render: (s) => s.template || t('sources.customTemplate') },
                                { id: 'campaigns', label: t('campaigns.campaigns'), render: (s) => s.campaigns_count || 0 },
                                { id: 'clicks', label: t('components.clicks'), render: (s) => s.clicks || 0 },
                                { id: 'conversions', label: t('metrics.conversions'), render: (s) => s.conversions || 0 },
                                { id: 'state', label: t('components.status'), render: renderSourceState },
                            ]}
                            primaryIds={['clicks', 'conversions', 'campaigns']}
                            emptyState={<div className="text-center py-10" style={{ color: 'var(--color-text-muted)' }}>{search ? t('sources.notFound') : t('sources.noSourcesAdd')}</div>}
                        />
                    </div>
                </div>
            )}

            {/* Info panel */}
            <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--color-primary-light)', border: '1px solid var(--color-primary)' }}>
                <h3 className="font-medium mb-2" style={{ color: 'var(--color-primary)' }}>💡 {t('sources.title')}</h3>
                <p className="text-sm mb-2" style={{ color: 'var(--color-text-secondary)' }}>
                    {t('sources.infoDesc')}
                </p>
                <ul className="text-sm space-y-1" style={{ color: 'var(--color-text-secondary)' }}>
                    <li>{t('sources.infoList1')}</li>
                    <li>{t('sources.infoList2')}</li>
                    <li>{t('sources.infoList3')}</li>
                    <li>{t('sources.infoList4')}</li>
                </ul>
            </div>

            {/* Editor Modal */}
            {showEditor && (
                <TrafficSourceEditor
                    id={editId}
                    highlightSection={pendingHighlight}
                    onClose={() => { setShowEditor(false); setPendingHighlight(null); }}
                    onSave={handleEditorSave}
                />
            )}

            {/* Bulk Import Modal */}
            {showBulkImport && (
                <BulkImportSources
                    onClose={() => setShowBulkImport(false)}
                    onSave={handleBulkImportSave}
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
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSearch(''); setStateFilter('all'); }}>
                                {t('common.clear')}
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

export default TrafficSources;
