import React from 'react';
import { Layers, Package, LayoutTemplate } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { useEntityList } from '../../utils/useEntityList';
import MultiSelect from './MultiSelect';

/**
 * Campaign, offer and landing filters for the Analytics page.
 *
 * @param {Object} props
 * @param {number[]} props.campaignIds - Selected campaign IDs
 * @param {function(number[]): void} props.onCampaignChange - Callback when campaign selection changes
 * @param {number[]} props.offerIds - Selected offer IDs
 * @param {function(number[]): void} props.onOfferChange - Callback when offer selection changes
 * @param {number[]} props.landingIds - Selected landing IDs
 * @param {function(number[]): void} props.onLandingChange - Callback when landing selection changes
 */
const AnalyticsEntityFilters = ({
    campaignIds = [],
    onCampaignChange,
    offerIds = [],
    onOfferChange,
    landingIds = [],
    onLandingChange,
}) => {
    const { t } = useLanguage();

    // Fetch campaigns, offers and landings with their groups.
    // The *_simple endpoints are the lightweight lists built for dropdowns.
    const { items: campaigns } = useEntityList('campaigns_simple');
    const { items: offers } = useEntityList('offers_simple');
    const { items: landings } = useEntityList('landings');
    const { items: campaignGroups } = useEntityList('campaign_groups');
    const { items: offerGroups } = useEntityList('offer_groups');
    const { items: landingGroups } = useEntityList('landing_groups');

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

            {/* Landing filter */}
            <MultiSelect
                icon={<LayoutTemplate className="w-4 h-4" />}
                label={t('analytics.landings')}
                placeholder={t('analytics.allLandings')}
                items={landings}
                groups={landingGroups}
                value={landingIds}
                onChange={onLandingChange}
            />
        </div>
    );
};

export default AnalyticsEntityFilters;
