import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { RefreshCw, Search, Download, ChevronLeft, ChevronRight, BarChart3 } from 'lucide-react';
import InfoBanner from './InfoBanner';
import { useLanguage } from '../contexts/LanguageContext';
import ClickDetailsModal from './ClickDetailsModal';

const API_URL = '/api.php';

const ConversionsLog = ({ campaignId: propCampaignId, onClose }) => {
    const { t } = useLanguage();
    const [conversions, setConversions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pagination, setPagination] = useState({ total: 0, page: 1, per_page: 50, total_pages: 0 });
    const [selectedClickId, setSelectedClickId] = useState(null);

    // Filters
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [internalCampaignId, setInternalCampaignId] = useState('');

    // Use prop campaignId if provided, otherwise use internal state
    const effectiveCampaignId = propCampaignId !== undefined ? propCampaignId : internalCampaignId;

    const fetchConversions = async (page = 1) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ action: 'conversions', page, per_page: pagination.per_page });
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (effectiveCampaignId) params.append('campaign_id', effectiveCampaignId);

            const res = await axios.get(`${API_URL}?${params.toString()}`);
            if (res.data.status === 'success') {
                setConversions(res.data.data);
                setPagination(res.data.pagination);
            }
        } catch (error) {
            console.error('Error fetching conversions:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchConversions(1);
    }, [statusFilter, dateFrom, dateTo, effectiveCampaignId]);

    const handleSearch = () => {
        fetchConversions(1);
    };

    const handlePageChange = (newPage) => {
        if (newPage >= 1 && newPage <= pagination.total_pages) {
            fetchConversions(newPage);
        }
    };

    const exportCSV = () => {
        if (conversions.length === 0) return;
        const headers = ['ID', 'Click ID', 'TID', 'Status', 'Payout', 'Currency', 'Campaign', 'Offer', 'IP', 'Created'];
        const rows = conversions.map(c => [
            c.id,
            c.click_id,
            c.tid || '',
            c.status,
            c.payout,
            c.currency,
            c.campaign_name || '',
            c.offer_name || '',
            c.ip || '',
            c.created_at
        ]);

        const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `conversions_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
    };

    const getStatusBadge = (status) => {
        const baseStyle = {
            display: 'inline-flex',
            padding: '4px 10px',
            fontSize: '12px',
            fontWeight: 500,
            borderRadius: '12px',
        };

        const styles = {
            lead: { background: 'var(--color-info-bg)', color: 'var(--color-info)' },
            sale: { background: 'var(--color-success-bg)', color: 'var(--color-success)' },
            rejected: { background: 'var(--color-danger-bg)', color: 'var(--color-danger)' },
            registration: { background: 'var(--color-primary-light)', color: 'var(--color-primary)' },
            deposit: { background: 'var(--color-warning-bg)', color: 'var(--color-warning)' },
            trash: { background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }
        };

        const statusLabels = {
            lead: t('conversions.lead'),
            sale: t('conversions.sale'),
            rejected: t('conversions.rejected'),
            registration: t('conversions.registration'),
            deposit: t('conversions.deposit'),
            trash: t('conversions.trash')
        };

        return (
            <span style={{ ...baseStyle, ...(styles[status] || styles.trash) }}>
                {statusLabels[status] || status}
            </span>
        );
    };

    // When used in modal (onClose provided), hide banner and adjust spacing
    const isModalMode = onClose !== undefined;

    return (
        <div className={isModalMode ? '' : 'space-y-4'}>
            {!isModalMode && (
                <InfoBanner storageKey="help_conversions" title={t('help.conversionsBannerTitle')}>
                    <p>{t('help.conversionsBanner')}</p>
                </InfoBanner>
            )}
            {/* Filters */}
            <div className="page-card">
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '12px', alignItems: 'end' }}>
                    <div style={{ gridColumn: 'span 2' }}>
                        <label className="form-label">{t('conversions.search')}</label>
                        <div className="relative">
                            <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="text"
                                placeholder={t('conversions.searchPlaceholder')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                className="form-input pl-12"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.status')}</label>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="form-select"
                        >
                            <option value="">{t('conversions.allStatuses')}</option>
                            <option value="lead">{t('conversions.lead')}</option>
                            <option value="sale">{t('conversions.sale')}</option>
                            <option value="rejected">{t('conversions.rejected')}</option>
                            <option value="registration">{t('conversions.registration')}</option>
                            <option value="deposit">{t('conversions.deposit')}</option>
                            <option value="trash">{t('conversions.trash')}</option>
                        </select>
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.dateFrom')}</label>
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            className="form-input"
                        />
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.dateTo')}</label>
                        <input
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            className="form-input"
                        />
                    </div>
                    <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                            onClick={() => fetchConversions(pagination.page)}
                            className="btn btn-secondary btn-icon"
                            title={t('common.refresh')}
                        >
                            <RefreshCw size={18} />
                        </button>
                        <button
                            onClick={exportCSV}
                            className="btn btn-secondary btn-icon"
                            title="CSV"
                        >
                            <Download size={18} />
                        </button>
                    </div>
                </div>
            </div>

            {/* Table */}
            {loading ? (
                <div className="page-card">
                    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '200px' }}>
                        <div style={{ width: '32px', height: '32px', border: '3px solid var(--color-border)', borderTopColor: 'var(--color-primary)', borderRadius: '50%', animation: 'spin 1s linear infinite' }}></div>
                    </div>
                </div>
            ) : (
                <div className="page-card" style={{ padding: 0 }}>
                    <div className="overflow-x-auto">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Click ID</th>
                                    <th>TID</th>
                                    <th>{t('conversions.status')}</th>
                                    <th>{t('conversions.payout')}</th>
                                    <th>{t('conversions.campaign')}</th>
                                    <th>{t('conversions.offer')}</th>
                                    <th>IP</th>
                                    <th>{t('conversions.date')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {conversions.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="text-center" style={{ padding: '48px' }}>
                                            <div className="empty-state">
                                                <p className="empty-state-title">{t('conversions.noConversions')}</p>
                                                <p className="empty-state-text">{t('conversions.noConversionsText')}</p>
                                            </div>
                                        </td>
                                    </tr>
                                ) : (
                                    conversions.map((conv) => (
                                        <tr key={conv.id}>
                                            <td style={{ color: 'var(--color-text-secondary)' }}>{conv.id}</td>
                                            <td>
                                                <button
                                                    onClick={() => setSelectedClickId(conv.click_id)}
                                                    style={{
                                                        fontFamily: 'monospace',
                                                        fontSize: '12px',
                                                        color: 'var(--color-primary)',
                                                        background: 'var(--color-bg-soft)',
                                                        padding: '4px 8px',
                                                        borderRadius: '6px',
                                                        border: 'none',
                                                        cursor: 'pointer',
                                                        transition: 'all 0.2s'
                                                    }}
                                                >
                                                    {conv.click_id}
                                                </button>
                                            </td>
                                            <td style={{ color: 'var(--color-text-secondary)' }}>{conv.tid || '-'}</td>
                                            <td>{getStatusBadge(conv.status)}</td>
                                            <td style={{ color: 'var(--color-success)', fontWeight: 500 }}>
                                                ${Number(conv.payout || 0).toFixed(2)}
                                            </td>
                                            <td style={{ color: 'var(--color-text-primary)' }}>{conv.campaign_name || '-'}</td>
                                            <td style={{ color: 'var(--color-text-primary)' }}>{conv.offer_name || '-'}</td>
                                            <td style={{ color: 'var(--color-text-muted)', fontSize: '14px' }}>{conv.ip || '-'}</td>
                                            <td>
                                                <button
                                                    onClick={() => setSelectedClickId(conv.click_id)}
                                                    style={{
                                                        color: 'var(--color-text-secondary)',
                                                        fontSize: '14px',
                                                        background: 'none',
                                                        border: 'none',
                                                        cursor: 'pointer',
                                                        padding: 0,
                                                        transition: 'color 0.2s'
                                                    }}
                                                >
                                                    {new Date(conv.created_at).toLocaleString('ru-RU')}
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination.total_pages > 1 && (
                        <div style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            padding: '16px',
                            borderTop: '1px solid var(--color-border)'
                        }}>
                            <div style={{ fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                                {t('conversions.shown')} {((pagination.page - 1) * pagination.per_page) + 1} - {Math.min(pagination.page * pagination.per_page, pagination.total)} {t('conversions.of')} {pagination.total}
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                <button
                                    onClick={() => handlePageChange(pagination.page - 1)}
                                    disabled={pagination.page <= 1}
                                    className="btn btn-secondary btn-sm"
                                    style={{ opacity: pagination.page <= 1 ? 0.5 : 1 }}
                                >
                                    <ChevronLeft size={16} />
                                </button>
                                <span style={{ color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                    {pagination.page} / {pagination.total_pages}
                                </span>
                                <button
                                    onClick={() => handlePageChange(pagination.page + 1)}
                                    disabled={pagination.page >= pagination.total_pages}
                                    className="btn btn-secondary btn-sm"
                                    style={{ opacity: pagination.page >= pagination.total_pages ? 0.5 : 1 }}
                                >
                                    <ChevronRight size={16} />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Info */}
            {!isModalMode && (
                <div className="page-card" style={{ background: 'var(--color-info-bg)', borderColor: 'var(--color-info)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                    <BarChart3 size={20} style={{ color: 'var(--color-info)', flexShrink: 0, marginTop: '2px' }} />
                    <div>
                        <h3 style={{ fontWeight: 500, marginBottom: '4px', color: 'var(--color-text-primary)' }}>
                            {t('conversions.title')}
                        </h3>
                        <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', margin: 0 }}>
                            {t('conversions.logInfo')}
                        </p>
                    </div>
                </div>
            </div>
            )}

            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </div>
    );
};

export default ConversionsLog;