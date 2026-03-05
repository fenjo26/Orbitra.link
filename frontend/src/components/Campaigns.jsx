import React, { useState } from 'react';
import { Plus, Trash2, Edit3, Settings2, DollarSign, XCircle } from 'lucide-react';
import InfoBanner from './InfoBanner';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const Campaigns = ({ campaigns, refreshData, setActiveTab, setEditingCampaignId }) => {
    const { t } = useLanguage();
    const [actionModal, setActionModal] = useState({ type: null, campaignId: null });

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
                </div>
                <button className="btn btn-ghost btn-icon">
                    <Settings2 className="w-5 h-5" />
                </button>
            </div>

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th className="w-10">
                                <input type="checkbox" />
                            </th>
                            <th>ID</th>
                            <th>{t('campaigns.campaign')}</th>
                            <th>{t('campaigns.group')}</th>
                            <th>{t('metrics.clicks')}</th>
                            <th>{t('campaigns.unique')}</th>
                            <th>{t('metrics.conversions')}</th>
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {campaigns.length === 0 ? (
                            <tr>
                                <td colSpan="8" className="text-center py-12">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('campaigns.noCampaignsCreated')}</p>
                                        <p className="empty-state-text">{t('campaigns.createFirstCampaign')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            campaigns.map((camp) => (
                                <tr key={camp.id}>
                                    <td>
                                        <input type="checkbox" />
                                    </td>
                                    <td className="font-medium">{camp.id}</td>
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
        </div>
    );
};

export default Campaigns;