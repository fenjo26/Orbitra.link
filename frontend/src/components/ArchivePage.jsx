import React, { useState, useEffect } from 'react';
import { Trash2, RotateCcw, ShieldAlert, Archive, Trash } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const ArchivePage = () => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('campaigns');
    const [items, setItems] = useState({
        campaigns: [],
        offers: [],
        landings: [],
        traffic_sources: [],
        affiliate_networks: []
    });
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(false);

    const tabs = [
        { id: 'campaigns', label: t('archive.tabs.campaigns') },
        { id: 'offers', label: t('archive.tabs.offers') },
        { id: 'landings', label: t('archive.tabs.landings') },
        { id: 'traffic_sources', label: t('archive.tabs.sources') },
        { id: 'affiliate_networks', label: t('archive.tabs.networks') }
    ];

    const fetchArchive = () => {
        setLoading(true);
        fetch(`${API_URL}?action=archive_items`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setItems(data.data);
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => {
        fetchArchive();
    }, []);

    const handleAction = async (endpointAction, payload) => {
        if (!window.confirm(t('archive.confirmAction'))) return;

        setActionLoading(true);
        try {
            const res = await fetch(`${API_URL}?action=${endpointAction}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.status === 'success') {
                fetchArchive();
            } else {
                alert(data.message || t('archive.actionError'));
            }
        } catch (e) {
            alert(t('archive.networkError') + ': ' + e.message);
        } finally {
            setActionLoading(false);
        }
    };

    const handleRestoreItem = (id) => handleAction('archive_restore', { type: activeTab, id });
    const handlePurgeItem = (id) => handleAction('archive_purge', { type: activeTab, action: 'purge_item', id });
    const handleRestoreAllSection = () => handleAction('archive_restore', { type: activeTab, action: 'restore_all' });
    const handlePurgeSection = () => handleAction('archive_purge', { type: activeTab, action: 'purge_section' });
    const handlePurgeEverything = () => handleAction('archive_purge', { action: 'purge_all' });

    if (loading && !items[activeTab]) {
        return (
            <div className="page-card">
                <div className="empty-state">
                    <p className="empty-state-title">{t('archive.loading')}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="page-card">
            {/* Header */}
            <div className="page-header">
                <div className="flex items-center gap-2">
                    <Archive className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                    <h2 className="page-title">{t('archive.title')}</h2>
                </div>
                <button
                    onClick={handlePurgeEverything}
                    disabled={actionLoading}
                    className="btn btn-danger"
                >
                    <ShieldAlert className="w-4 h-4" />
                    {t('archive.deleteAll')}
                </button>
            </div>

            {/* Tabs */}
            <div style={{ borderBottom: '1px solid var(--color-border)', marginBottom: '16px' }}>
                <nav style={{ display: 'flex', gap: '4px' }}>
                    {tabs.map(tab => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            style={{
                                padding: '12px 16px',
                                fontSize: '14px',
                                fontWeight: 500,
                                background: activeTab === tab.id ? 'var(--color-bg-soft)' : 'transparent',
                                color: activeTab === tab.id ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                border: 'none',
                                borderBottom: activeTab === tab.id ? '2px solid var(--color-primary)' : '2px solid transparent',
                                cursor: 'pointer',
                                transition: 'all 0.2s',
                                borderRadius: '8px 8px 0 0'
                            }}
                        >
                            {tab.label}
                            {items[tab.id]?.length > 0 && (
                                <span
                                    style={{
                                        marginLeft: '8px',
                                        padding: '2px 8px',
                                        fontSize: '12px',
                                        borderRadius: '10px',
                                        background: activeTab === tab.id ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                                        color: activeTab === tab.id ? 'var(--color-primary)' : 'var(--color-text-muted)'
                                    }}
                                >
                                    {items[tab.id].length}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>
            </div>

            {/* Section Actions */}
            <div style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: '12px 16px',
                background: 'var(--color-bg-soft)',
                borderRadius: '16px',
                marginBottom: '16px'
            }}>
                <span style={{ fontSize: '14px', color: 'var(--color-text-secondary)', fontWeight: 500 }}>
                    {t('archive.sectionManagement')} {tabs.find(t => t.id === activeTab)?.label}
                </span>
                <div style={{ display: 'flex', gap: '8px' }}>
                    <button
                        onClick={handleRestoreAllSection}
                        disabled={actionLoading || items[activeTab]?.length === 0}
                        className="btn btn-secondary"
                    >
                        <RotateCcw className="w-4 h-4" style={{ color: 'var(--color-success)' }} />
                        {t('archive.restoreAll')}
                    </button>
                    <button
                        onClick={handlePurgeSection}
                        disabled={actionLoading || items[activeTab]?.length === 0}
                        className="btn btn-danger"
                    >
                        <Trash className="w-4 h-4" />
                        {t('archive.clearSection')}
                    </button>
                </div>
            </div>

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th style={{ width: '80px' }}>{t('archive.id')}</th>
                            <th>{t('archive.name')}</th>
                            <th style={{ width: '180px' }}>{t('archive.deletedAt')}</th>
                            <th style={{ width: '120px', textAlign: 'right' }}>{t('archive.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan="4" className="text-center" style={{ padding: '32px' }}>
                                    <p style={{ color: 'var(--color-text-secondary)', fontSize: '14px' }}>{t('archive.loadingData')}</p>
                                </td>
                            </tr>
                        ) : !items[activeTab] || items[activeTab].length === 0 ? (
                            <tr>
                                <td colSpan="4" className="text-center" style={{ padding: '48px' }}>
                                    <div className="empty-state">
                                        <Archive className="w-10 h-10 mx-auto mb-3" style={{ color: 'var(--color-text-muted)' }} />
                                        <p className="empty-state-title">{t('archive.emptySection')}</p>
                                    </div>
                                </td>
                            </tr>
                        ) : (
                            items[activeTab].map(item => (
                                <tr key={item.id}>
                                    <td style={{ color: 'var(--color-text-muted)', fontSize: '14px' }}>#{item.id}</td>
                                    <td style={{ fontWeight: 500, color: 'var(--color-text-primary)' }}>{item.name}</td>
                                    <td>
                                        <span style={{
                                            padding: '4px 10px',
                                            background: 'var(--color-bg-soft)',
                                            borderRadius: '8px',
                                            fontSize: '12px',
                                            color: 'var(--color-text-secondary)'
                                        }}>
                                            {item.archived_at}
                                        </span>
                                    </td>
                                    <td>
                                        <div className="action-buttons">
                                            <button
                                                onClick={() => handleRestoreItem(item.id)}
                                                disabled={actionLoading}
                                                className="action-btn"
                                                title={t('archive.restore')}
                                                style={{ color: 'var(--color-success)' }}
                                            >
                                                <RotateCcw className="w-4 h-4" />
                                            </button>
                                            <button
                                                onClick={() => handlePurgeItem(item.id)}
                                                disabled={actionLoading}
                                                className="action-btn"
                                                title={t('archive.deleteForever')}
                                                style={{ color: 'var(--color-danger)' }}
                                            >
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
        </div>
    );
};

export default ArchivePage;