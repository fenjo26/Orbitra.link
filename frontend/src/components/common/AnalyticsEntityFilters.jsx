import React from 'react';
import { Layers, Package } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { useEntityList } from '../../utils/useEntityList';
import MultiSelect from './MultiSelect';

/**
 * Campaign and offer filters for the Analytics page.
 *
 * @param {Object} props
 * @param {number[]} props.campaignIds - Selected campaign IDs
 * @param {function(number[]): void} props.onCampaignChange - Callback when campaign selection changes
 * @param {number[]} props.offerIds - Selected offer IDs
 * @param {function(number[]): void} props.onOfferChange - Callback when offer selection changes
 */
const AnalyticsEntityFilters = ({
    campaignIds = [],
    onCampaignChange,
    offerIds = [],
    onOfferChange,
}) => {
    const { t } = useLanguage();

    // Fetch campaigns and offers with groups
    const { items: campaigns, loading: campaignsLoading } = useEntityList('campaigns');
    const { items: offers, loading: offersLoading } = useEntityList('offers');
    const { items: campaignGroups } = useEntityList('campaign_groups');
    const { items: offerGroups } = useEntityList('offer_groups');

    return (
        <div className="flex flex-wrap items-center gap-3">
            {/* Campaign filter */}
            <MultiSelect
                icon={<Layers className="w-4 h-4" />}
                label={t('analytics.campaigns')}
                placeholder={t('analytics.allCampaigns')}
                items={campaigns}
                groups={campaignGroups}
                value={campaignIds}
                onChange={onCampaignChange}
            />

            {/* Offer filter */}
            <MultiSelect
                icon={<Package className="w-4 h-4" />}
                label={t('analytics.offers')}
                placeholder={t('analytics.allOffers')}
                items={offers}
                groups={offerGroups}
                value={offerIds}
                onChange={onOfferChange}
            />
        </div>
    );
};

export default AnalyticsEntityFilters;
