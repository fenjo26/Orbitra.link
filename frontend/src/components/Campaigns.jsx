import React, { useState, useMemo } from 'react';
import { Plus, Trash2, Edit3, Settings2, DollarSign, XCircle, ChevronUp, ChevronDown, ChevronsUpDown, Filter, RefreshCw, X } from 'lucide-react';
import InfoBanner from './InfoBanner';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Campaigns = ({ campaigns, refreshData, setActiveTab, setEditingCampaignId }) => {
    const { t } = useLanguage();
    const [actionModal, setActionModal] = useState({ type: null, campaignId: null });
    const [selectedCampaignIds, setSelectedCampaignIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState('');
    const [settingsOpen, setSettingsOpen] = useState(false);

    const handleCreate = () => {
        setEditingCampaignId(null);
        setActiveTab('campaign_editor');
    };

    const handleEdit = (id) => {
        setEditingCampaignId(id);
        setActiveTab('campaign_editor');
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('campaigns.deleteConfirm'))) {
            try {
                await axios.post(`${API_URL}?action=delete_campaign`, { id });
                refreshData();
            } catch (err) {
                alert(t('common.deleteError'));
            }
        }
    };

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => {
            if (prev.key === key) {
                return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
            }
            return { key, dir: defaultDir };
        });
    };

    const filteredCampaigns = useMemo(() => {
        const q = String(search || '').trim().toLowerCase();
        if (!q) return campaigns;
        return campaigns.filter(c => {
            const n = String(c.name || '').toLowerCase();
            const a = String(c.alias || '').toLowerCase();
            return n.includes(q) || a.includes(q);
        });
    }, [campaigns, search]);

    const visibleCampaigns = useMemo(() => {
        if (!sortBy.key) return filteredCampaigns;
        const dirMul = sortBy.dir === 'asc' ? 1 : -1;

        const getVal = (c) => {
            switch (sortBy.key) {
                case 'id': return Number(c.id) || 0;
                case 'name': return String(c.name || '');
                case 'group_name': return String(c.group_name || '');
                case 'clicks': return Number(c.clicks) || 0;
                case 'unique_clicks': return Number(c.unique_clicks) || 0;
                case 'conversions': return Number(c.conversions) || 0;
                default: return '';
            }
        };

        const isNumeric = ['id', 'clicks', 'unique_clicks', 'conversions'].includes(sortBy.key);

        return filteredCampaigns
            .map((camp, idx) => ({ camp, idx }))
            .sort((a, b) => {
                const av = getVal(a.camp);
                const bv = getVal(b.camp);
                let cmp = 0;
                if (isNumeric) {
                    cmp = (Number(av) || 0) - (Number(bv) || 0);
                } else {
                    cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
                }
                if (cmp !== 0) return cmp * dirMul;
                return a.idx - b.idx; // stable
            })
            .map(x => x.camp);
    }, [filteredCampaigns, sortBy]);

    const toggleSelected = (id, checked) => {
        setSelectedCampaignIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAll = (checked) => {
        setSelectedCampaignIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleCampaigns.forEach(c => next.add(c.id));
            } else {
                visibleCampaigns.forEach(c => next.delete(c.id));
            }
            return next;
        });
    };

    const allSelected = visibleCampaigns.length > 0 && visibleCampaigns.every(c => selectedCampaignIds.has(c.id));
    const someSelected = visibleCampaigns.some(c => selectedCampaignIds.has(c.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedCampaignIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('campaigns.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_campaigns`, { ids });
            setSelectedCampaignIds(new Set());
            refreshData();
        } catch (err) {
            alert(t('common.deleteError'));
        }
    };

    const SortIcon = ({ colKey }) => {
        if (sortBy.key !== colKey) return <ChevronsUpDown className="w-3.5 h-3.5 opacity-60" />;
        return sortBy.dir === 'asc'
            ? <ChevronUp className="w-3.5 h-3.5" />
            : <ChevronDown className="w-3.5 h-3.5" />;
    };

    const SortableTh = ({ colKey, label, defaultDir = 'asc', alignRight = false }) => {
        const isActive = sortBy.key === colKey;
        return (
            <th className={alignRight ? 'text-right' : ''} aria-sort={isActive ? (sortBy.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
                <button
                    type="button"
                    onClick={() => requestSort(colKey, defaultDir)}
                    className={`inline-flex items-center gap-1 select-none ${alignRight ? 'justify-end w-full' : ''}`}
                    style={{ color: isActive ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}
                    title={t('common.sort', 'Sort')}
                >
                    <span>{label}</span>
                    <SortIcon colKey={colKey} />
                </button>
            </th>
        );
    };

    const exportVisibleCsv = () => {
        const cols = [
            { key: 'id', label: 'id' },
            { key: 'name', label: 'name' },
            { key: 'alias', label: 'alias' },
            { key: 'group_name', label: 'group' },
            { key: 'source_name', label: 'source' },
            { key: 'clicks', label: 'clicks' },
            { key: 'unique_clicks', label: 'unique_clicks' },
            { key: 'conversions', label: 'conversions' },
        ];

        const escape = (v) => {
            const s = v === null || v === undefined ? '' : String(v);
            if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
            return s;
        };

        const header = cols.map(c => escape(c.label)).join(',');
        const lines = visibleCampaigns.map(c => cols.map(col => escape(c[col.key])).join(','));
        const csv = [header, ...lines].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `campaigns_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const handleClearStats = async () => {
        try {
            await axios.post(`${API_URL}?action=clear_stats`, { campaign_id: actionModal.campaignId });
            refreshData();
            setActionModal({ type: null, campaignId: null });
        } catch (err) {
            alert(t('common.clearError'));
        }
    };

    const handleUpdateCosts = async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const data = {
            campaign_id: actionModal.campaignId,
            cost: parseFloat(fd.get('cost')),
            start_date: fd.get('start_date'),
            end_date: fd.get('end_date'),
            unique_only: fd.get('unique_only') === 'on'
        };
        try {
            const res = await axios.post(`${API_URL}?action=update_costs`, data);
            if (res.data.status === 'success') {
                alert(t('campaigns.updatedClicks').replace('{count}', res.data.updated_clicks));
                refreshData();
                setActionModal({ type: null, campaignId: null });
            } else {
                alert(res.data.message);
            }
        } catch (err) {
            alert(t('common.networkError'));
        }
    };

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_campaigns" title={t('help.campaignBannerTitle')}>
                <p>{t('help.campaignBanner')}</p>
            </InfoBanner>
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    <button className="btn btn-secondary">
                        {t('campaigns.sources')}
                    </button>
                    {selectedCampaignIds.size > 0 && (
                        <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedCampaignIds.size})
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {search ? (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">1</span>
                        ) : null}
                    </button>
                    <button type="button" onClick={refreshData} className="btn btn-ghost btn-icon" title={t('common.refresh')}>
                        <RefreshCw className="w-5 h-5" />
                    </button>
                    <button type="button" className="btn btn-ghost btn-icon" title={t('common.settings', 'Settings')} onClick={() => setSettingsOpen(true)}>
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('common.search', 'Search')}:</label>
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="form-input"
                            style={{ width: 'auto', minWidth: '260px' }}
                            placeholder={t('common.searchPlaceholder', 'Name or alias')}
                        />
                    </div>
                    {search && (
                        <button type="button" onClick={() => setSearch('')} className="btn btn-ghost btn-sm">
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
                            <SortableTh colKey="id" label="ID" defaultDir="desc" />
                            <SortableTh colKey="name" label={t('campaigns.campaign')} defaultDir="asc" />
                            <SortableTh colKey="group_name" label={t('campaigns.group')} defaultDir="asc" />
                            <SortableTh colKey="clicks" label={t('metrics.clicks')} defaultDir="desc" />
                            <SortableTh colKey="unique_clicks" label={t('campaigns.unique')} defaultDir="desc" />
                            <SortableTh colKey="conversions" label={t('metrics.conversions')} defaultDir="desc" />
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleCampaigns.length === 0 ? (
                            <tr>
                                <td colSpan="8" className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('campaigns.noCampaignsCreated')}</p>
                                        <p className="empty-state-text">{t('campaigns.createFirstCampaign')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleCampaigns.map((camp) => (
                                <tr key={camp.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedCampaignIds.has(camp.id)}
                                            onChange={(e) => toggleSelected(camp.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="font-medium">
                                        <div className="flex flex-col">
                                            <span>{camp.id}</span>
                                            {camp.keitaro_id ? (
                                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }}>
                                                    K:{camp.keitaro_id}
                                                </span>
                                            ) : null}
                                        </div>
                                    </td>
                                    <td>
                                        <div className="flex flex-col">
                                            <span
                                                className="font-semibold cursor-pointer hover:underline"
                                                style={{ color: 'var(--color-primary)' }}
                                                onClick={() => handleEdit(camp.id)}
                                            >
                                                {camp.name}
                                            </span>
                                            <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }}>{camp.alias}</span>
                                        </div>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{camp.group_name || '-'}</td>
                                    <td>{camp.clicks}</td>
                                    <td>{camp.unique_clicks}</td>
                                    <td>{camp.conversions}</td>
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(camp.id)} className="action-btn text-blue">
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => setActionModal({ type: 'update_costs', campaignId: camp.id })} className="action-btn text-green" title={t('campaigns.updateCosts')}>
                                                <DollarSign className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => setActionModal({ type: 'clear_stats', campaignId: camp.id })} className="action-btn text-orange" title={t('common.clearStats')}>
                                                <XCircle className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(camp.id)} className="action-btn text-red" title={t('common.delete')}>
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

            {/* Clear Stats Modal */}
            {actionModal.type === 'clear_stats' && (
                <div className="modal-overlay">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.clearStats')}?</h3>
                        </div>
                        <p style={{ color: 'var(--color-text-secondary)', fontSize: '14px', marginBottom: '24px' }}>
                            {t('campaigns.clearStatsWarning')}
                        </p>
                        <div className="modal-footer">
                            <button onClick={() => setActionModal({ type: null, campaignId: null })} className="btn btn-secondary">{t('common.cancel')}</button>
                            <button onClick={handleClearStats} className="btn btn-danger">{t('common.clear')}</button>
                        </div>
                    </div>
                </div>
            )}

            {/* Update Costs Modal */}
            {actionModal.type === 'update_costs' && (
                <div className="modal-overlay">
                    <div className="modal-content" style={{ maxWidth: '520px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('campaigns.updateCosts')}</h3>
                        </div>
                        <form onSubmit={handleUpdateCosts} className="space-y-4">
                            <div>
                                <label className="form-label">{t('campaigns.costAmount')}</label>
                                <input type="number" step="0.01" name="cost" required className="form-input" placeholder="0.00" />
                            </div>
                            <div className="flex gap-4">
                                <div className="flex-1">
                                    <label className="form-label">{t('campaigns.startDate')}</label>
                                    <input type="date" name="start_date" required className="form-input" />
                                </div>
                                <div className="flex-1">
                                    <label className="form-label">{t('campaigns.endDate')}</label>
                                    <input type="date" name="end_date" required className="form-input" />
                                </div>
                            </div>
                            <div>
                                <label className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                    <input type="checkbox" name="unique_only" />
                                    <span>{t('campaigns.distributeUniqueOnly')}</span>
                                </label>
                            </div>
                            <div className="modal-footer">
                                <button type="button" onClick={() => setActionModal({ type: null, campaignId: null })} className="btn btn-secondary">{t('common.cancel')}</button>
                                <button type="submit" className="btn btn-primary">
                                    <DollarSign className="w-4 h-4" />
                                    {t('common.apply')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {settingsOpen && (
                <div className="modal-overlay" onClick={() => setSettingsOpen(false)}>
                    <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '560px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('common.settings', 'Settings')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setSettingsOpen(false)} title={t('common.close')}>
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="space-y-3">
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSortBy({ key: null, dir: 'desc' }); }}>
                                {t('common.resetSort', 'Reset sorting')}
                            </button>
                            <button type="button" className="btn btn-secondary w-full" onClick={() => { setSelectedCampaignIds(new Set()); }}>
                                {t('common.clearSelection', 'Clear selection')}
                            </button>
                            <button type="button" className="btn btn-primary w-full" onClick={exportVisibleCsv}>
                                {t('common.exportCsv', 'Export CSV')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Campaigns;
