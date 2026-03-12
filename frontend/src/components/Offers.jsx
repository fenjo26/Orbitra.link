import React, { useState, useMemo } from 'react';
import { Plus, Trash2, Edit3, Settings2, RefreshCw, Filter, X, ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import InfoBanner from './InfoBanner';
import OfferEditor from './OfferEditor';
import GroupsModal from './GroupsModal';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Offers = ({ offers, refreshData }) => {
    const { t } = useLanguage();
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [editingOfferId, setEditingOfferId] = useState(null);
    const [isGroupsModalOpen, setIsGroupsModalOpen] = useState(false);
    const [filterGroup, setFilterGroup] = useState('');
    const [filterNetwork, setFilterNetwork] = useState('');
    const [filterState, setFilterState] = useState('');
    const [showFilters, setShowFilters] = useState(false);
    const [selectedOfferIds, setSelectedOfferIds] = useState(() => new Set());
    const [sortBy, setSortBy] = useState({ key: null, dir: 'desc' }); // key=null keeps API order

    // Get unique values for filters
    const groups = [...new Set(offers.map(o => o.group_name).filter(Boolean))];
    const networks = [...new Set(offers.map(o => o.affiliate_network_name).filter(Boolean))];

    const filteredOffers = offers.filter(o => {
        if (filterGroup && o.group_name !== filterGroup) return false;
        if (filterNetwork && o.affiliate_network_name !== filterNetwork) return false;
        if (filterState && o.state !== filterState) return false;
        return true;
    });

    const requestSort = (key, defaultDir = 'asc') => {
        setSortBy(prev => {
            if (prev.key === key) {
                return { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' };
            }
            return { key, dir: defaultDir };
        });
    };

    const visibleOffers = useMemo(() => {
        if (!sortBy.key) return filteredOffers;
        const dirMul = sortBy.dir === 'asc' ? 1 : -1;

        const getVal = (o) => {
            switch (sortBy.key) {
                case 'id': return Number(o.id) || 0;
                case 'name': return String(o.name || '');
                case 'group_name': return String(o.group_name || '');
                case 'affiliate_network_name': return String(o.affiliate_network_name || '');
                case 'redirect_type': return String(o.redirect_type || '');
                case 'state': return String(o.state || '');
                case 'clicks': return Number(o.clicks) || 0;
                case 'unique_clicks': return Number(o.unique_clicks) || 0;
                case 'conversions': return Number(o.conversions) || 0;
                case 'revenue': return Number(o.revenue) || 0;
                default: return '';
            }
        };

        const isNumeric = ['id', 'clicks', 'unique_clicks', 'conversions', 'revenue'].includes(sortBy.key);

        return filteredOffers
            .map((offer, idx) => ({ offer, idx }))
            .sort((a, b) => {
                const av = getVal(a.offer);
                const bv = getVal(b.offer);
                let cmp = 0;
                if (isNumeric) {
                    cmp = (Number(av) || 0) - (Number(bv) || 0);
                } else {
                    cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
                }
                if (cmp !== 0) return cmp * dirMul;
                return a.idx - b.idx; // stable
            })
            .map(x => x.offer);
    }, [filteredOffers, sortBy]);

    const handleCreate = () => {
        setEditingOfferId(null);
        setIsEditorOpen(true);
    };

    const handleEdit = (id) => {
        setEditingOfferId(id);
        setIsEditorOpen(true);
    };

    const handleDelete = async (id) => {
        if (window.confirm(t('common.deleteConfirm'))) {
            try {
                await axios.post(`${API_URL}?action=delete_offer`, { id });
                refreshData();
            } catch (err) {
                alert(t('common.error'));
            }
        }
    };

    const toggleSelected = (id, checked) => {
        setSelectedOfferIds(prev => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    };

    const toggleSelectAllFiltered = (checked) => {
        setSelectedOfferIds(prev => {
            const next = new Set(prev);
            if (checked) {
                visibleOffers.forEach(o => next.add(o.id));
            } else {
                visibleOffers.forEach(o => next.delete(o.id));
            }
            return next;
        });
    };

    const allFilteredSelected = visibleOffers.length > 0 && visibleOffers.every(o => selectedOfferIds.has(o.id));
    const someFilteredSelected = visibleOffers.some(o => selectedOfferIds.has(o.id));

    const handleBulkDeleteSelected = async () => {
        const ids = Array.from(selectedOfferIds);
        if (ids.length === 0) return;
        const msg = (t('common.deleteSelectedConfirm') || t('common.deleteConfirm')).replace('{count}', String(ids.length));
        if (!window.confirm(msg)) return;
        try {
            await axios.post(`${API_URL}?action=bulk_delete_offers`, { ids });
            setSelectedOfferIds(new Set());
            refreshData();
        } catch (err) {
            alert(t('common.error'));
        }
    };

    const handleEditorClose = (wasSaved) => {
        setIsEditorOpen(false);
        if (wasSaved) {
            refreshData();
        }
    };

    const clearFilters = () => {
        setFilterGroup('');
        setFilterNetwork('');
        setFilterState('');
    };

    const hasActiveFilters = filterGroup || filterNetwork || filterState;

    // Calculate totals for filtered offers
    const totals = filteredOffers.reduce((acc, o) => {
        acc.clicks += parseInt(o.clicks || 0);
        acc.unique_clicks += parseInt(o.unique_clicks || 0);
        acc.conversions += parseInt(o.conversions || 0);
        acc.revenue += parseFloat(o.revenue || 0);
        return acc;
    }, { clicks: 0, unique_clicks: 0, conversions: 0, revenue: 0 });

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

    return (
        <div className="page-card">
            <InfoBanner storageKey="help_offers" title={t('help.offerBannerTitle')}>
                <p>{t('help.offerBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="page-header">
                <div className="flex flex-wrap gap-3">
                    <button onClick={handleCreate} className="btn btn-primary">
                        <Plus className="w-4 h-4" />
                        {t('common.create')}
                    </button>
                    <button onClick={() => setIsGroupsModalOpen(true)} className="btn btn-secondary">
                        {t('campaigns.groups')}
                    </button>
                    {selectedOfferIds.size > 0 && (
                        <button onClick={handleBulkDeleteSelected} className="btn btn-danger" title={t('common.deleteSelected')}>
                            <Trash2 className="w-4 h-4" />
                            {(t('common.deleteSelected') || t('common.delete'))} ({selectedOfferIds.size})
                        </button>
                    )}
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={() => setShowFilters(!showFilters)}
                        className={`btn btn-ghost ${showFilters ? 'bg-[var(--color-primary-light)]' : ''}`}
                        style={showFilters ? { color: 'var(--color-primary)' } : {}}
                    >
                        <Filter className="w-4 h-4" />
                        {t('editor.filters')}
                        {hasActiveFilters && (
                            <span className="ml-1 px-1.5 py-0.5 bg-[var(--color-primary)] text-white text-xs rounded-full">
                                {[filterGroup, filterNetwork, filterState].filter(Boolean).length}
                            </span>
                        )}
                    </button>
                    <button onClick={refreshData} className="btn btn-ghost btn-icon" title={t('common.refresh')}>
                        <RefreshCw className="w-5 h-5" />
                    </button>
                    <button className="btn btn-ghost btn-icon">
                        <Settings2 className="w-5 h-5" />
                    </button>
                </div>
            </div>

            {/* Filters Panel */}
            {showFilters && (
                <div className="flex flex-wrap gap-4 items-center py-4 mb-4 border-b" style={{ borderColor: 'var(--color-border)' }}>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.group')}:</label>
                        <select
                            value={filterGroup}
                            onChange={(e) => setFilterGroup(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '140px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            {groups.map(g => <option key={g} value={g}>{g}</option>)}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('offers.network')}:</label>
                        <select
                            value={filterNetwork}
                            onChange={(e) => setFilterNetwork(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '160px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            {networks.map(n => <option key={n} value={n}>{n}</option>)}
                        </select>
                    </div>
                    <div className="flex items-center gap-2">
                        <label className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>{t('components.status')}:</label>
                        <select
                            value={filterState}
                            onChange={(e) => setFilterState(e.target.value)}
                            className="form-select"
                            style={{ width: 'auto', minWidth: '120px' }}
                        >
                            <option value="">{t('common.all')}</option>
                            <option value="active">{t('components.active')}</option>
                            <option value="archived">{t('components.archive')}</option>
                        </select>
                    </div>
                    {hasActiveFilters && (
                        <button onClick={clearFilters} className="btn btn-ghost btn-sm">
                            <X className="w-4 h-4" />
                            {t('common.clear')}
                        </button>
                    )}
                </div>
            )}

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input
                                    type="checkbox"
                                    checked={allFilteredSelected}
                                    ref={(el) => {
                                        if (el) el.indeterminate = !allFilteredSelected && someFilteredSelected;
                                    }}
                                    onChange={(e) => toggleSelectAllFiltered(e.target.checked)}
                                />
                            </th>
                            <SortableTh colKey="id" label="ID" defaultDir="desc" />
                            <SortableTh colKey="name" label={t('editor.name')} defaultDir="asc" />
                            <SortableTh colKey="group_name" label={t('components.group')} defaultDir="asc" />
                            <SortableTh colKey="affiliate_network_name" label={t('offers.network')} defaultDir="asc" />
                            <SortableTh colKey="redirect_type" label={t('components.type')} defaultDir="asc" />
                            <SortableTh colKey="state" label={t('components.status')} defaultDir="asc" />
                            <SortableTh colKey="clicks" label={t('components.clicks')} defaultDir="desc" alignRight />
                            <SortableTh colKey="unique_clicks" label={t('components.uniques')} defaultDir="desc" alignRight />
                            <SortableTh colKey="conversions" label={t('metrics.conversions')} defaultDir="desc" alignRight />
                            <SortableTh colKey="revenue" label={t('metrics.revenue')} defaultDir="desc" alignRight />
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleOffers.length === 0 ? (
                            <tr>
                                <td colSpan="12" className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">
                                            {offers.length === 0 ? t('offers.noOffers') : t('offers.noOffersFiltered')}
                                        </p>
                                        <p className="empty-state-text">
                                            {offers.length === 0 ? t('offers.noOffersDesc') : t('offers.changeFilters')}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            visibleOffers.map((offer) => (
                                <tr key={offer.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={selectedOfferIds.has(offer.id)}
                                            onChange={(e) => toggleSelected(offer.id, e.target.checked)}
                                        />
                                    </td>
                                    <td className="font-medium">{offer.id}</td>
                                    <td>
                                        <div className="flex flex-col">
                                            <span
                                                className="font-semibold cursor-pointer hover:underline"
                                                style={{ color: 'var(--color-primary)' }}
                                                onClick={() => handleEdit(offer.id)}
                                            >
                                                {offer.name}
                                            </span>
                                            {!offer.is_local && offer.url && (
                                                <span style={{ color: 'var(--color-text-muted)', fontSize: '12px' }} className="truncate max-w-[200px]" title={offer.url}>
                                                    {offer.url}
                                                </span>
                                            )}
                                            {offer.is_local && (
                                                <span style={{ color: 'var(--color-accent-purple)', fontSize: '12px' }}>{t('offers.localOffer')}</span>
                                            )}
                                        </div>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{offer.group_name || '-'}</td>
                                    <td style={{ color: 'var(--color-text-secondary)' }}>{offer.affiliate_network_name || '-'}</td>
                                    <td>
                                        <span className={`px-2 py-1 rounded text-xs font-semibold ${offer.redirect_type === 'redirect' ? 'bg-blue-100 text-blue-800' :
                                            offer.redirect_type === 'frame' ? 'bg-purple-100 text-purple-800' :
                                                offer.redirect_type === 'local' ? 'bg-indigo-100 text-indigo-800' :
                                                    'bg-gray-100 text-gray-800'
                                            }`}>
                                            {offer.redirect_type === 'redirect' ? t('offers.redirect') :
                                                offer.redirect_type === 'frame' ? t('offers.iframe') :
                                                    offer.redirect_type === 'local' ? t('offers.local') :
                                                        offer.redirect_type}
                                        </span>
                                    </td>
                                    <td>
                                        <span className="flex items-center text-xs font-medium" style={{ color: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}>
                                            <span className={`w-2 h-2 rounded-full mr-1.5`} style={{ backgroundColor: offer.state === 'active' ? 'var(--color-success)' : 'var(--color-text-muted)' }}></span>
                                            {offer.state === 'active' ? t('components.active') : t('components.archive')}
                                        </span>
                                    </td>
                                    <td className="text-right font-medium">{offer.clicks || 0}</td>
                                    <td className="text-right">{offer.unique_clicks || 0}</td>
                                    <td className="text-right font-medium" style={{ color: 'var(--color-success)' }}>{offer.conversions || 0}</td>
                                    <td className="text-right font-medium" style={{ color: 'var(--color-success)' }}>
                                        ${(parseFloat(offer.revenue || 0)).toFixed(2)}
                                    </td>
                                    <td>
                                        <div className="action-buttons">
                                            <button onClick={() => handleEdit(offer.id)} className="action-btn text-blue" title={t('common.edit') || t('components.edit')}>
                                                <Edit3 className="w-4 h-4" />
                                            </button>
                                            <button onClick={() => handleDelete(offer.id)} className="action-btn text-red" title={t('common.delete')}>
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {/* Totals Footer */}
                    {filteredOffers.length > 0 && (
                        <tfoot style={{ background: 'var(--color-bg-soft)' }}>
                            <tr className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                <td className="px-4 py-3" colSpan="7">{t('offers.total').replace('{count}', filteredOffers.length)}</td>
                                <td className="px-4 py-3 text-right">{totals.clicks}</td>
                                <td className="px-4 py-3 text-right">{totals.unique_clicks}</td>
                                <td className="px-4 py-3 text-right" style={{ color: 'var(--color-success)' }}>{totals.conversions}</td>
                                <td className="px-4 py-3 text-right" style={{ color: 'var(--color-success)' }}>${totals.revenue.toFixed(2)}</td>
                                <td className="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            {/* Editor Modal */}
            {isEditorOpen && (
                <OfferEditor
                    offerId={editingOfferId}
                    onClose={handleEditorClose}
                />
            )}

            {/* Groups Modal */}
            {isGroupsModalOpen && (
                <GroupsModal
                    type="offer"
                    onClose={() => setIsGroupsModalOpen(false)}
                />
            )}
        </div>
    );
};

export default Offers;
