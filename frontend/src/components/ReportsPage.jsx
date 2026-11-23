import React, { useState } from 'react';
import { BarChart3, FileText } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { useEntityList } from '../utils/useEntityList';
import SegmentedControl from './common/SegmentedControl';
import CampaignReports from './CampaignReports';
import ConversionsLog from './ConversionsLog';

// The "Reports" item of the main menu: the campaign report (over every
// campaign, or the one picked next to the tabs) and the conversions log —
// the two reports used to live behind a button on the Campaigns tab and in
// the gear menu.
const ReportsPage = () => {
    const { t } = useLanguage();
    const { items: campaigns } = useEntityList('campaigns_simple');
    const [section, setSection] = useState('campaigns');
    const [campaignId, setCampaignId] = useState('');

    const picked = campaigns.find((c) => String(c.id) === campaignId) || null;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex-1 min-w-[240px] max-w-[440px]">
                    <SegmentedControl
                        ariaLabel={t('app.reports')}
                        value={section}
                        onChange={setSection}
                        options={[
                            { value: 'campaigns', label: t('reports.byCampaign'), icon: BarChart3 },
                            { value: 'conversions', label: t('adminMenu.conversions'), icon: FileText },
                        ]}
                    />
                </div>
                {section === 'campaigns' && (
                    <label className="flex items-center gap-2 text-sm whitespace-nowrap" style={{ color: 'var(--color-text-secondary)' }}>
                        <span>{t('reports.campaign')}</span>
                        <select
                            value={campaignId}
                            onChange={(e) => setCampaignId(e.target.value)}
                            className="form-select"
                            style={{ minWidth: 220 }}
                        >
                            <option value="">{t('campaignReports.allCampaigns', 'All Campaigns')}</option>
                            {campaigns.map((c) => (
                                <option key={c.id} value={String(c.id)}>{c.name}</option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {section === 'campaigns' ? (
                // key: another campaign is another report — the grouping, the
                // filters and the rows start over instead of refetching in place.
                <CampaignReports
                    key={campaignId}
                    embedded
                    campaignId={picked ? picked.id : null}
                    campaignName={picked ? picked.name : null}
                />
            ) : (
                <ConversionsLog embedded />
            )}
        </div>
    );
};

export default ReportsPage;
