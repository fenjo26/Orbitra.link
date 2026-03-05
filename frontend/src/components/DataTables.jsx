import React from 'react';
import { useLanguage } from '../contexts/LanguageContext';

const DataTables = ({ campaigns, offers, landings, sources, preferences }) => {
    const { t } = useLanguage();
    // defaults to true if preferences is undefined for a smoother transition
    const isVisible = (block) => !preferences || !preferences.visible_blocks || preferences.visible_blocks.includes(block);

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {isVisible('campaigns') && <TableWidget title={t('dashboard.topCampaigns')} data={campaigns} t={t} />}
            {isVisible('offers') && <TableWidget title={t('dashboard.topOffers')} data={offers} t={t} />}
            {isVisible('landings') && <TableWidget title={t('dashboard.topLandings')} data={landings} t={t} />}
            {isVisible('sources') && <TableWidget title={t('dashboard.topSources')} data={sources} t={t} />}
        </div>
    );
};

const TableWidget = ({ title, data, t }) => {
    return (
        <div className="card shadow-sm overflow-hidden flex flex-col h-[350px]" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            <div className="px-5 py-3 border-b flex justify-between items-center" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{title}</h2>
                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{data?.length || 0} {t('dataTables.records')}</span>
            </div>
            <div className="overflow-y-auto flex-1 h-full">
                <table className="w-full text-left text-sm whitespace-nowrap">
                    <thead className="sticky top-0 z-10 shadow-sm" style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                        <tr>
                            <th className="px-4 py-2.5 text-xs font-semibold border-b" style={{ color: 'var(--color-text-secondary)', borderColor: 'var(--color-border)' }}>{t('dashboard.tableName')}</th>
                            <th className="px-4 py-2.5 text-xs font-semibold border-b text-right" style={{ color: 'var(--color-text-secondary)', borderColor: 'var(--color-border)' }}>{t('metrics.clicks')}</th>
                            <th className="px-4 py-2.5 text-xs font-semibold border-b text-right" style={{ color: 'var(--color-text-secondary)', borderColor: 'var(--color-border)' }}>{t('dashboard.tableUnique')}</th>
                            <th className="px-4 py-2.5 text-xs font-semibold border-b text-right" style={{ color: 'var(--color-text-secondary)', borderColor: 'var(--color-border)' }}>{t('dashboard.tableConv')}</th>
                        </tr>
                    </thead>
                    <tbody style={{ divideColor: 'var(--color-border)' }}>
                        {(!data || data.length === 0) && (
                            <tr>
                                <td colSpan="4" className="px-4 py-8 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('dashboard.noData')}</td>
                            </tr>
                        )}
                        {data && data.slice(0, 10).map((row, idx) => (
                            <tr key={row.id || idx} className="hover:bg-blue-50/10 transition duration-150 group">
                                <td className="px-4 py-2.5 font-medium group-hover:text-blue-600 cursor-pointer truncate max-w-[200px]" style={{ color: 'var(--color-text-primary)' }}>{row.name}</td>
                                <td className="px-4 py-2.5 text-right" style={{ color: 'var(--color-text-secondary)' }}>{row.clicks || 0}</td>
                                <td className="px-4 py-2.5 text-right" style={{ color: 'var(--color-text-secondary)' }}>{row.unique_clicks || 0}</td>
                                <td className="px-4 py-2.5 font-medium text-right" style={{ color: 'var(--color-success)' }}>{row.conversions || 0}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default DataTables;