import React from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import ConversionsLog from './ConversionsLog';

/**
 * Conversion Log overlay for a single campaign.
 *
 * The overlay chrome used to live inline in CampaignEditor; it moved here so
 * the Campaigns list opens the identical screen from its row actions. The
 * filtering itself is ConversionsLog's own — it forwards campaignId to the
 * conversions endpoint, which constrains on cv.campaign_id — this component
 * only supplies the frame and names the campaign in the header.
 */
const ConversionsLogModal = ({ campaignId, campaignName, onClose }) => {
    const { t } = useLanguage();
    if (!campaignId) return null;

    return (
        <div className="modal-overlay" style={{ zIndex: 1100, top: '88px', height: 'calc(100vh - 88px)' }}>
            <div className="modal-content" style={{ maxWidth: '1200px', maxHeight: '100%', overflow: 'auto' }}>
                <div className="flex items-center justify-between mb-4">
                    <div className="min-w-0">
                        <h3 className="modal-title">{t('editor.conversionsLog')}</h3>
                        {campaignName && (
                            <div className="text-xs truncate mt-0.5" style={{ color: 'var(--color-text-muted)' }} title={campaignName}>
                                {campaignName}
                            </div>
                        )}
                    </div>
                    <button onClick={onClose} className="btn btn-ghost btn-icon">
                        <X className="w-5 h-5" />
                    </button>
                </div>
                <ConversionsLog
                    campaignId={campaignId}
                    onClose={onClose}
                />
            </div>
        </div>
    );
};

export default ConversionsLogModal;
