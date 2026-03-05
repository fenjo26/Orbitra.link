import React, { useState } from 'react';
import { Plus, Trash2, Edit3, Settings2, RefreshCw, Filter, X } from 'lucide-react';
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

    // Get unique values for filters
    const groups = [...new Set(offers.map(o => o.group_name).filter(Boolean))];
    const networks = [...new Set(offers.map(o => o.affiliate_network_name).filter(Boolean))];

    const filteredOffers = offers.filter(o => {
        if (filterGroup && o.group_name !== filterGroup) return false;
        if (filterNetwork && o.affiliate_network_name !== filterNetwork) return false;
        if (filterState && o.state !== filterState) return false;
        return true;
    });

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
                                <input type="checkbox" />
                            </th>
                            <th>ID</th>
                            <th>{t('editor.name')}</th>
                            <th>{t('components.group')}</th>
                            <th>{t('offers.network')}</th>
                            <th>{t('components.type')}</th>
                            <th>{t('components.status')}</th>
                            <th className="text-right">{t('components.clicks')}</th>
                            <th className="text-right">{t('components.uniques')}</th>
                            <th className="text-right">{t('metrics.conversions')}</th>
                            <th className="text-right">{t('metrics.revenue')}</th>
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredOffers.length === 0 ? (
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
                            filteredOffers.map((offer) => (
                                <tr key={offer.id}>
                                    <td>
                                        <input type="checkbox" />
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