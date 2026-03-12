import React from 'react';
import { useLanguage } from '../contexts/LanguageContext';

const formatNum = (num) => {
    if (num === null || num === undefined) return '0';
    return new Intl.NumberFormat('ru-RU').format(num);
}

const formatCurrency = (num) => {
    if (num === null || num === undefined) return '$0.00';
    return '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num);
}

const StatCards = ({ metrics, preferences, activeMetrics = [], setActiveMetrics }) => {
    const { t } = useLanguage();
    const isVisible = (metric) => !preferences || !preferences.visible_metrics || preferences.visible_metrics.includes(metric);

    const toggleMetric = (metricName) => {
        if (!setActiveMetrics) return;
        setActiveMetrics(prev =>
            prev.includes(metricName)
                ? prev.filter(m => m !== metricName)
                : [...prev, metricName]
        );
    };

    return (
        <div className="flex overflow-x-auto no-scrollbar gap-4 mb-6 mt-6 pb-4 w-full">
            {isVisible('clicks') && <Card title={t('metrics.clicks')} value={formatNum(metrics?.clicks)} isActive={activeMetrics.includes('clicks')} onClick={() => toggleMetric('clicks')} colorVar="--color-primary" />}
            {isVisible('unique_clicks') && <Card title={t('metrics.uniqueClicks')} value={formatNum(metrics?.unique_clicks)} isActive={activeMetrics.includes('unique_clicks')} onClick={() => toggleMetric('unique_clicks')} colorVar="--color-accent-turquoise" />}
            {isVisible('conversions') && <Card title={t('metrics.conversions')} value={formatNum(metrics?.conversions)} isActive={activeMetrics.includes('conversions')} onClick={() => toggleMetric('conversions')} colorVar="--color-success" />}
            {isVisible('cost') && <Card title={t('metrics.cost')} value={formatCurrency(metrics?.cost)} isActive={activeMetrics.includes('cost')} onClick={() => toggleMetric('cost')} colorVar="--color-danger" />}
            {isVisible('revenue') && <Card title={t('metrics.revenue')} value={formatCurrency(metrics?.revenue)} isActive={activeMetrics.includes('revenue')} onClick={() => toggleMetric('revenue')} colorVar="--color-warning" />}
            {isVisible('profit') && <Card title={t('metrics.profit')} value={formatCurrency(metrics?.profit)} isActive={activeMetrics.includes('profit')} onClick={() => toggleMetric('profit')} colorVar="--color-info" />}
            {isVisible('roi') && <Card title={t('metrics.roi')} value={`${formatNum(metrics?.roi ?? 0)}%`} isActive={activeMetrics.includes('roi')} onClick={() => toggleMetric('roi')} colorVar="--color-accent-purple" />}
            {isVisible('real_revenue') && <Card title={t('metrics.realRevenue') || 'Real Rev'} value={formatCurrency(metrics?.real_revenue)} isActive={activeMetrics.includes('real_revenue')} onClick={() => toggleMetric('real_revenue')} colorVar="--color-real-rev" />}
            {isVisible('real_roi') && <Card title={t('metrics.realRoi') || 'Real ROI'} value={`${formatNum(metrics?.real_roi ?? 0)}%`} isActive={activeMetrics.includes('real_roi')} onClick={() => toggleMetric('real_roi')} colorVar="--color-real-roi" />}
            {isVisible('ctr') && <Card title={t('metrics.ctr') || 'CTR'} value={`${formatNum(metrics?.ctr ?? 0)}%`} isActive={activeMetrics.includes('ctr')} onClick={() => toggleMetric('ctr')} colorVar="--color-ctr" />}
        </div>
    );
};

const Card = ({ title, value, isActive, onClick, colorVar }) => {
    return (
        <div
            onClick={onClick}
            className={`card cursor-pointer select-none min-w-[140px] flex-1 flex flex-col justify-center transition-all`}
            style={{
                padding: '20px',
                border: isActive ? `2px solid var(${colorVar})` : `2px solid transparent`,
                boxShadow: isActive ? `0 8px 25px var(${colorVar}, rgba(0,0,0,0.1))` : 'var(--shadow-main)'
            }}
        >
            <h3 className="text-xs uppercase font-semibold mb-2 tracking-wide text-[var(--color-text-muted)]">{title}</h3>
            <div className="text-2xl font-bold" style={{ color: `var(${colorVar})` }}>{value}</div>
        </div>
    )
}

export default StatCards;
