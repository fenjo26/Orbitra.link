import React, { useState, useMemo } from 'react';
import { Plus, Trash2, Edit3, Settings2, Filter, RefreshCw, X, SlidersHorizontal } from 'lucide-react';
import InfoBanner from './InfoBanner';
import LandingEditor from './LandingEditor';
import GroupsModal from './GroupsModal';
import ColumnsOrderModal from './ColumnsOrderModal';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Every column the landings table can show, backed by fields the `landings`
// endpoint actually returns. Money and status counters come from the same
// conversion aggregate as the reports (see core/ReportMetrics.php); visits
// equal clicks because one click row IS one landing visit.
export const ALL_LANDING_COLUMNS = [
    { id: 'id', label: 'ID' },
    { id: 'name', label: 'Name', required: true },
    { id: 'group_name', label: 'Group' },
    { id: 'type', label: 'Type' },
    { id: 'state', label: 'Status' },
    { id: 'visits', label: 'Visits', alignRight: true },
    { id: 'unique_visits', label: 'uVisits', alignRight: true },
    { id: 'clicks', label: 'Clicks', alignRight: true },
    { id: 'unique_clicks', label: 'Uniques', alignRight: true },
    { id: 'lp_clicks', label: 'LP Clicks', alignRight: true },
    { id: 'lp_ctr', label: 'LP CTR', alignRight: true },
    { id: 'conversions', label: 'Conversions', alignRight: true },
    { id: 'leads', label: 'Leads', alignRight: true },
    { id: 'sales', label: 'Sales', alignRight: true },
    { id: 'rejected', label: 'Rejected', alignRight: true },
    { id: 'trash', label: 'Trash', alignRight: true },
    { id: 'approve_rate', label: 'Approve %', alignRight: true },
    { id: 'cr', label: 'CR', alignRight: true },
    { id: 'cost', label: 'Cost', alignRight: true },
    { id: 'revenue', label: 'Revenue', alignRight: true },
    { id: 'revenue_confirmed', label: 'Revenue (conf)', alignRight: true },
    { id: 'profit', label: 'Profit', alignRight: true },
    { id: 'profit_confirmed', label: 'Profit (conf)', alignRight: true },
    { id: 'cpc', label: 'CPC', alignRight: true },
    { id: 'epc', label: 'EPC', alignRight: true },
    { id: 'epc_confirmed', label: 'EPC (conf)', alignRight: true },
    { id: 'epv', label: 'EPV', alignRight: true },
    { id: 'roi', label: 'ROI', alignRight: true },
    { id: 'roi_confirmed', label: 'ROI (conf)', alignRight: true },
    { id: 'last_event', label: 'Last Event' },
];

// The table as it shipped: order and composition users already know.
export const DEFAULT_LANDING_COLUMNS = [
    'id', 'name', 'group_name', 'type', 'state',
    'clicks', 'unique_clicks', 'lp_clicks', 'lp_ctr',
];

const LANDING_COLUMNS_KEY = 'orbitra_landing_columns';

const loadLandingColumns = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(LANDING_COLUMNS_KEY) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const valid = saved.filter(id => ALL_LANDING_COLUMNS.some(c => c.id === id));
            if (valid.includes('name')) return valid;
        }
    } catch (e) { /* fall through to default */ }
    return [...DEFAULT_LANDING_COLUMNS];
};

const Landings = ({ landings, refreshData }) => {
    const { t } = useLanguage();
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [editingLandingId, setEditingLandingId] = useState(null);
    const [selectedLandingIds, setSelectedLandingIds] = useState(() => new Set());
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [stateFilter, setStateFilter] = useState('');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [columnsModalOpen, setColumnsModalOpen] = useState(false);
    const [chosenColumns, setChosenColumns] = useState(() => loadLandingColumns());

    const handleCreate = () => {
        setEditingLandingId(null);
        setIsEditorOpen(true);
    };

    const handleEdit = (id) => {
        setEditingLandingId(id);
        setIsEditorOpen(true);
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('common.deleteConfirm'))) {
            try {
                const res = await axios.post(`${API_URL}?action=delete_landing`, { id });
                if (res?.data?.status !== 'success') {
                    alert(res?.data?.message || t('common.error'));
                    return;
                }
                refreshData();
            } catch (err) {
                alert(err?.response?.data?.message || err?.message || t('common.error'));
            }
        }
    };

    const filteredLandings = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        return landings.filter(l => {
            if (q) {
                const n = String(l.name || '').toLowerCase();
                const u = String(l.url || '').toLowerCase();
                if (!n.includes(q) && !u.includes(q)) return false;
            }
            if (typeFilter && String(l.type || '') !== typeFilter) return false;
            if (stateFilter && String(l.state || '') !== stateFilter) return false;
            return true;
        });
    }, [landings, search, typeFilter, stateFilter]);

    const visibleLandings = filteredLandings;

    const toggleSelected = (id, checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedLandingIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleLandings.forEach(l => next.add(l.id));
            } else {
                visibleLandings.forEach(l => next.delete(l.id));
            }
            return next;
        });
    };

    const allSelected = visibleLandings.length > 0 && visibleLandings.every(l => selectedLandingIds.has(l.id));
    const someSelected = visibleLandings.some(l => selectedLandingIds.has(l.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedLandingIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_landings`, { ids });
            setSelectedLandingIds(new Set());
            refreshData();
        } catch (err) {
            alert(t('common.error'));
        }
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'group_name', label: 'group' },
            { key: 'type', label: 'type' },
            { key: 'state', label: 'state' },
            { key: 'clicks', label: 'clicks' },
            { key: 'unique_clicks', label: 'unique_clicks' },
            { key: 'lp_clicks', label: 'lp_clicks' },
            { key: 'lp_ctr', label: 'lp_ctr' },
            { key: 'url', label: 'url' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = visibleLandings.map(l => cols.map(c => escape(l[c.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `landings_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleRefresh = async () => {
        if (refreshing) return;
        setRefreshing(true);
        try {
            await Promise.resolve(refreshData?.());
        } finally {
            setRefreshing(false);
        }
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            refreshData();
        }
    };

    const columnLabel = (colId) => ({
        id: 'ID',
        name: t('components.aliasName'),
        type: t('components.type'),
        state: t('components.status'),
        visits: t('metrics.visits'),
        unique_visits: t('metrics.uniqueVisits'),
        clicks: t('components.clicks'),
        unique_clicks: t('components.uniques'),
        lp_clicks: t('components.lpClicks'),
        lp_ctr: t('components.lpCtr'),
        conversions: t('landingColumns.conversions'),
        leads: t('offerColumns.leads'),
        sales: t('offerColumns.sales'),
        rejected: t('offerColumns.rejected'),
        trash: t('metrics.trash'),
        approve_rate: t('metrics.approve'),
        cr: t('landingColumns.cr'),
        cost: t('metrics.cost'),
        revenue: t('metrics.revenue'),
        revenue_confirmed: t('offerColumns.revenueConfirmed'),
        profit: t('metrics.profit'),
        profit_confirmed: t('offerColumns.profitConfirmed'),
        cpc: t('metrics.cpc'),
        epc: t('metrics.epc'),
        epc_confirmed: t('offerColumns.epcConfirmed'),
        epv: t('metrics.epv'),
        roi: t('metrics.roi'),
        roi_confirmed: t('offerColumns.roiConfirmed'),
        group_name: t('components.group'),
        last_event: t('landingColumns.lastEvent'),
    }[colId] || colId);

    const localizedColumns = ALL_LANDING_COLUMNS.map(c => ({ ...c, label: columnLabel(c.id) }));

    // SQLite hands back "YYYY-MM-DD HH:MM:SS"; the space separator chokes
    // Safari's Date parser, so normalize to ISO before formatting.
    const formatLastEvent = (v) => {
        if (!v) return t('landingColumns.never');
        const d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) return t('landingColumns.never');
        const p = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
    };

    const money = (v) => `$${(parseFloat(v) || 0).toFixed(2)}`;

    const renderLandingCell = (landing, colId) => {
        const tdCls = ALL_LANDING_COLUMNS.find(c => c.id === colId)?.alignRight ? 'text-right' : '';
        switch (colId) {
            case 'id':
                return <td key={colId} className="font-medium">{landing.id}</td>;
            case 'name':
                return (
                    <td key={colId}>
                        <div className="flex flex-col">
                            <span
                                className="font-semibold cursor-pointer hover:underline"
                                style={{ color: 'var(--color-primary)' }}
                                onClick={() => handleEdit(landing.id)}
                            >
                                {landing.name}
                            </span>
                            {landing.type !== 'local' && landing.type !== 'action' && (
                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={landing.url}>
                                    {landing.url}
                                </span>
                            )}
                        </div>
                    </td>
                );
            case 'type':
                return (
                    <td key={colId}>
                        <span className="px-2 py-1 rounded text-xs font-semibold" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {landing.type}
                        </span>
                    </td>
                );
            case 'state':
                return (
                    <td key={colId}>
                        <span className="flex items-center text-xs font-medium" style={{ color: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                            <span className="w-2 h-2 rounded-full mr-1.5" style={{ backgroundColor: landing.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                            {landing.state === 'active' ? t('components.active') : t('components.archive')}
                        </span>
                    </td>
                );
            case 'group_name':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{landing.group_name || '-'}</td>;
            case 'cost':
                return <td key={colId} className={tdCls}>{money(landing.cost)}</td>;
            case 'revenue':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(landing.revenue)}</td>;
            case 'revenue_confirmed':
                return <td key={colId} className={`${tdCls} font-medium`} style={{ color: 'var(--color-success)' }}>{money(landing.revenue_confirmed)}</td>;
            case 'profit':
            case 'profit_confirmed': {
                const v = parseFloat(landing[colId]) || 0;
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: v > 0 ? 'var(--color-success)' : v < 0 ? 'var(--color-danger)' : 'var(--color-text-secondary)' }}>
                        {money(v)}
                    </td>
                );
            }
            case 'cpc':
            case 'epc':
            case 'epc_confirmed':
            case 'epv':
                return <td key={colId} className={tdCls}>{money(landing[colId])}</td>;
            case 'approve_rate':
                return <td key={colId} className={tdCls}>{`${parseFloat(landing.approve_rate) || 0}%`}</td>;
            case 'roi':
            case 'roi_confirmed': {
                const v = landing[colId];
                return (
                    <td key={colId} className={`${tdCls} font-medium`} style={{ color: (parseFloat(v) || 0) > 0 ? 'var(--color-success)' : 'var(--color-text-secondary)' }}>
                        {v !== null && v !== undefined ? `${v}%` : '—'}
                    </td>
                );
            }
            case 'lp_ctr':
                return <td key={colId} className={tdCls}>{landing.lp_ctr !== undefined ? `${landing.lp_ctr}%` : '0%'}</td>;
            case 'cr':
                return <td key={colId} className={tdCls}>{landing.cr !== undefined ? `${landing.cr}%` : '0%'}</td>;
            case 'last_event':
                return <td key={colId} style={{ color: 'var(--color-text-secondary)' }}>{formatLastEvent(landing.last_event)}</td>;
            default:
                return <td key={colId} className={tdCls}>{Number(landing[colId] || 0).toLocaleString()}</td>;
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_landings" title={t('help.landingBannerTitle')}>
                <p>{t('help.landingBanner')}</p>
            </InfoBanner>
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button onClick={() => setShowGroupsModal(true)} className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedLandingIds.size > 0 && (
                        <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedLandingIds.size})
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    {/* Columns Customizer Button [ ☵ ] */}
                    <button
                        type="button"
                        onClick={() => setColumnsModalOpen(true)}
                        className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl flex items-center gap-1.5 font-medium"
                        title={t('landingColumns.title')}
                        style={{
                            backgroundColor: 'var(--color-bg-card)',
                            border: '1px solid var(--color-border)',
                            color: 'var(--color-text-primary)'
                        }}
                    >
                        <SlidersHorizontal className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
                        <span>{t('reportCustomizer.columns')}</span>
                        <span className="text-[10px] px-1.5 py-0.2 rounded-full" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            {chosenColumns.length}
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {(search || typeFilter || stateFilter) ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[search, typeFilter, stateFilter].filter(Boolean).length}
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
                    <button type="button" className="btn btn-ghost btn-icon" title={t('common.settings')} onClick={() => setSettingsOpen(true)}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
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
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.type')}:</label>
                        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '140px' }}>
                            <option value="">{t('common.all')}</option>
                            <option value="local">local</option>
                            <option value="redirect">redirect</option>
                            <option value="action">action</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                        <select value={stateFilter} onChange={(e) => setStateFilter(e.target.value)} className="form-select" style={{ width: 'auto', minWidth: '140px' }}>
                            <option value="">{t('common.all')}</option>
                            <option value="active">{t('components.active')}</option>
                            <option value="archived">{t('components.archive')}</option>
                        </select>
                    </div>
                    {(search || typeFilter || stateFilter) && (
                        <button type="button" onClick={() => { setSearch(''); setTypeFilter(''); setStateFilter(''); }} className="btn btn-ghost btn-sm">
                            <X className="w-4 h-4" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allSelected && someSelected;
                                    }}
                                    onChange={(e) => toggleSelectAll(e.target.checked)}
                                />
                            </th>
                            {chosenColumns.map((colId) => (
                                <th key={colId} className={ALL_LANDING_COLUMNS.find(c => c.id === colId)?.alignRight ? 'text-right' : ''}>{columnLabel(colId)}</th>
                            ))}
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleLandings.length === 0 ? (
                            <tr>
                                <td colSpan={chosenColumns.length + 2} className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('landings.noLandings')}</p>
                                        <p className="empty-state-text">{t('landings.noLandingsDesc')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleLandings.map((landing) => (
                                <tr key={landing.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedLandingIds.has(landing.id)}
                                            onChange={(e) => toggleSelected(landing.id, e.target.checked)}
                                        />
                                    </td>
                                    {chosenColumns.map((colId) => renderLandingCell(landing, colId))}
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(landing.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(landing.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {isEditorOpen && (
                <LandingEditor
                    landingId={editingLandingId}
                    onClose={handleEditorClose}
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
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedLandingIds(new Set()); }}>
                                {t('common.clearSelection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {showGroupsModal && (
                <GroupsModal
                    type="landing"
                    onClose={() => setShowGroupsModal(false)}
                />
            )}

            {columnsModalOpen && (
                <ColumnsOrderModal
                    columns={localizedColumns}
                    selectedIds={chosenColumns}
                    defaultIds={DEFAULT_LANDING_COLUMNS}
                    onClose={() => setColumnsModalOpen(false)}
                    onSave={(ids) => {
                        setChosenColumns(ids);
                        localStorage.setItem(LANDING_COLUMNS_KEY, JSON.stringify(ids));
                        setColumnsModalOpen(false);
                    }}
                />
            )}
        </div>
    );
};

export default Landings;
