import React from 'react';
import { useLanguage } from '../contexts/LanguageContext';
import { financeVisibility, financeHiddenMetric } from '../utils/permissions';

const formatInteger = (num) => new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0
}).format(Number(num) || 0);

const formatDecimal = (num) => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
}).format(Number(num) || 0);

const formatCurrency = (num) => `$${formatDecimal(num)}`;
const formatPercent = (num) => `${formatDecimal(num)}%`;

const StatCards = ({ metrics, preferences, activeMetrics = [], setActiveMetrics, user }) => {
    const { t } = useLanguage();
    const financeVis = financeVisibility(user);
    const isVisible = (metric) =>
        !preferences || !preferences.visible_metrics || preferences.visible_metrics.includes(metric);

    // Card-level gate for finance-restricted users — the backend also nulls
    // these values, while this keeps restricted cards out of the layout.
    const showCard = (metric) => isVisible(metric) && !financeHiddenMetric(metric, financeVis);

    const toggleMetric = (metricName) => {
        if (!setActiveMetrics) return;
        setActiveMetrics(prev =>
            prev.includes(metricName)
                ? prev.filter(metric => metric !== metricName)
                : [...prev, metricName]
        );
    };

    const cards = [
        // Traffic & volume
        { id: 'clicks', title: t('metrics.clicks'), value: formatInteger(metrics?.clicks), color: '--color-primary' },
        { id: 'unique_clicks', title: t('metrics.uniqueClicks'), value: formatInteger(metrics?.unique_clicks), color: '--color-accent-turquoise' },
        { id: 'conversions', title: t('metrics.conversions'), value: formatInteger(metrics?.conversions), color: '--color-success' },
        { id: 'leads', title: t('metrics.leads', 'Leads'), value: formatInteger(metrics?.leads), color: '--color-accent-turquoise' },
        { id: 'sales', title: t('metrics.sales', 'Sales'), value: formatInteger(metrics?.sales), color: '--color-success' },
        { id: 'bots', title: t('metrics.bots', 'Bots'), value: formatInteger(metrics?.bots), color: '--color-danger' },

        // Financials & profit
        { id: 'cost', title: t('metrics.cost'), value: formatCurrency(metrics?.cost), color: '--color-danger' },
        { id: 'revenue', title: t('metrics.revenue'), value: formatCurrency(metrics?.revenue), color: '--color-warning' },
        { id: 'profit', title: t('metrics.profit'), value: formatCurrency(metrics?.profit), color: '--color-info' },
        { id: 'roi', title: t('metrics.roi'), value: formatPercent(metrics?.roi), color: '--color-accent-purple' },
        { id: 'revenue_confirmed', title: t('metrics.revenueConfirmed', 'Confirmed Revenue'), value: formatCurrency(metrics?.revenue_confirmed), color: '--color-success' },
        { id: 'profit_confirmed', title: t('metrics.profitConfirmed', 'Confirmed Profit'), value: formatCurrency(metrics?.profit_confirmed), color: '--color-success' },
        { id: 'roi_confirmed', title: t('metrics.roiConfirmed', 'Confirmed ROI'), value: formatPercent(metrics?.roi_confirmed), color: '--color-accent-purple' },
        { id: 'real_revenue', title: t('metrics.realRevenue'), value: formatCurrency(metrics?.real_revenue), color: '--color-real-rev' },
        { id: 'real_roi', title: t('metrics.realRoi'), value: formatPercent(metrics?.real_roi), color: '--color-real-roi' },

        // Unit economics
        { id: 'cpa', title: t('metrics.cpa'), value: formatCurrency(metrics?.cpa), color: '--color-warning' },
        { id: 'cpc', title: t('metrics.cpc'), value: formatCurrency(metrics?.cpc), color: '--color-info' },
        { id: 'cpv', title: t('metrics.cpv', 'CPV'), value: formatCurrency(metrics?.cpv), color: '--color-accent-turquoise' },
        { id: 'cpl', title: t('metrics.cpl', 'CPL'), value: formatCurrency(metrics?.cpl), color: '--color-warning' },
        { id: 'cps', title: t('metrics.cps', 'CPS'), value: formatCurrency(metrics?.cps), color: '--color-primary' },
        { id: 'epc', title: t('metrics.epc'), value: formatCurrency(metrics?.epc), color: '--color-success' },
        { id: 'epv', title: t('metrics.epv', 'EPV'), value: formatCurrency(metrics?.epv), color: '--color-success' },

        // Rates
        { id: 'cr', title: t('metrics.cr', 'CR %'), value: formatPercent(metrics?.cr), color: '--color-ctr' },
        { id: 'approve_rate', title: t('metrics.approveRate', 'Approve %'), value: formatPercent(metrics?.approve_rate), color: '--color-success' },
        { id: 'lp_ctr', title: t('metrics.lpCtr', 'LP CTR %'), value: formatPercent(metrics?.lp_ctr), color: '--color-accent-purple' },
        { id: 'bot_rate', title: t('metrics.botRate', 'Bot %'), value: formatPercent(metrics?.bot_rate), color: '--color-danger' },
        { id: 'ctr', title: t('metrics.ctr'), value: formatPercent(metrics?.ctr), color: '--color-ctr' },
    ];

    return (
        <div
            className="flex overflow-x-auto no-scrollbar gap-4 mt-6 mb-2 pt-5 pb-7 px-4 md:px-6 -mx-4 md:-mx-6 w-[calc(100%+2rem)] md:w-[calc(100%+3rem)]"
            style={{ WebkitOverflowScrolling: 'touch' }}
        >
            {cards.filter(card => showCard(card.id)).map(card => (
                <Card
                    key={card.id}
                    title={card.title}
                    value={card.value}
                    isActive={activeMetrics.includes(card.id)}
                    onClick={() => toggleMetric(card.id)}
                    colorVar={card.color}
                />
            ))}
        </div>
    );
};

const Card = ({ title, value, isActive, onClick, colorVar }) => (
    <div
        onClick={onClick}
        className={`card cursor-pointer select-none min-w-[140px] flex-1 flex flex-col justify-center transition-all ${isActive ? 'relative z-10' : ''}`}
        style={{
            padding: '20px',
            border: isActive ? `2px solid var(${colorVar})` : '2px solid transparent',
            boxShadow: isActive ? `0 8px 25px var(${colorVar}, rgba(0,0,0,0.1))` : 'var(--shadow-main)'
        }}
    >
        <h3 className="text-xs uppercase font-semibold mb-2 tracking-wide text-[var(--color-text-muted)]">{title}</h3>
        <div className="text-2xl font-bold" style={{ color: `var(${colorVar})` }}>{value}</div>
    </div>
);

export default StatCards;
