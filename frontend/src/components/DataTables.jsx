import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const DataTables = ({ campaigns, offers, landings, sources, preferences }) => {
    const { t } = useLanguage();
    // defaults to true if preferences is undefined for a smoother transition
    const isVisible = (block) => !preferences || !preferences.visible_blocks || preferences.visible_blocks.includes(block);

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {isVisible('campaigns') && <TableWidget id="campaigns" title={t('dashboard.topCampaigns')} data={campaigns} t={t} />}
            {isVisible('offers') && <TableWidget id="offers" title={t('dashboard.topOffers')} data={offers} t={t} />}
            {isVisible('landings') && <TableWidget id="landings" title={t('dashboard.topLandings')} data={landings} t={t} />}
            {isVisible('sources') && <TableWidget id="sources" title={t('dashboard.topSources')} data={sources} t={t} />}
        </div>
    );
};

const TOP_N = 10;
const SORT_STORAGE_KEY = 'orbitra_dashboard_block_sort';

// Per-block sort, remembered per browser. The block receives the whole list
// and picks its top 10 after sorting, so sorting by conversions surfaces a
// campaign with 2 clicks and a sale that the clicks top-10 would never show.
const readSorts = () => {
    try { return JSON.parse(localStorage.getItem(SORT_STORAGE_KEY) || '{}') || {}; } catch { return {}; }
};
const writeSort = (id, sort) => {
    try { localStorage.setItem(SORT_STORAGE_KEY, JSON.stringify({ ...readSorts(), [id]: sort })); } catch { /* storage blocked */ }
};

const COLUMNS = [
    { key: 'name', labelKey: 'dashboard.tableName', width: '46%', align: 'left' },
    { key: 'clicks', labelKey: 'metrics.clicks', width: '18%', align: 'right' },
    { key: 'unique_clicks', labelKey: 'dashboard.tableUnique', width: '18%', align: 'right' },
    { key: 'conversions', labelKey: 'dashboard.tableConv', width: '18%', align: 'right' },
];
const NUMERIC_TIEBREAK = ['conversions', 'clicks', 'unique_clicks'];

const compareRows = (a, b, key, dir) => {
    const mul = dir === 'asc' ? 1 : -1;
    if (key === 'name') {
        return mul * String(a.name || '').localeCompare(String(b.name || ''), undefined, { numeric: true, sensitivity: 'base' });
    }
    const diff = (Number(a[key]) || 0) - (Number(b[key]) || 0);
    if (diff !== 0) return mul * diff;
    // Equal values: keep the busier row first so ties stay meaningful.
    for (const k of NUMERIC_TIEBREAK) {
        if (k === key) continue;
        const d = (Number(b[k]) || 0) - (Number(a[k]) || 0);
        if (d !== 0) return d;
    }
    return 0;
};

const TableWidget = ({ id, title, data, t }) => {
    const [sort, setSort] = useState(() => {
        const saved = readSorts()[id];
        return saved && COLUMNS.some(c => c.key === saved.key) ? saved : { key: 'clicks', dir: 'desc' };
    });

    const rows = useMemo(() => {
        if (!Array.isArray(data)) return [];
        return [...data].sort((a, b) => compareRows(a, b, sort.key, sort.dir)).slice(0, TOP_N);
    }, [data, sort]);

    const toggleSort = (key) => {
        const next = sort.key === key
            ? { key, dir: sort.dir === 'desc' ? 'asc' : 'desc' }
            : { key, dir: key === 'name' ? 'asc' : 'desc' };
        setSort(next);
        writeSort(id, next);
    };

    return (
        <div className="card shadow-sm overflow-hidden flex flex-col h-[350px]" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            <div className="px-5 py-3 border-b flex justify-between items-center" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{title}</h2>
                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{rows.length} {t('dataTables.records')}</span>
            </div>
            <div className="overflow-y-auto flex-1 h-full">
                <table className="w-full text-sm whitespace-nowrap" style={{ tableLayout: 'fixed' }}>
                    <thead className="sticky top-0 z-10 shadow-sm" style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                        <tr>
                            {COLUMNS.map(col => {
                                const active = sort.key === col.key;
                                const Arrow = sort.dir === 'asc' ? ArrowUp : ArrowDown;
                                return (
                                    <th key={col.key}
                                        className={`px-4 py-2.5 text-xs font-semibold border-b text-${col.align} cursor-pointer select-none`}
                                        style={{ width: col.width, color: active ? 'var(--color-text-primary)' : 'var(--color-text-secondary)', borderColor: 'var(--color-border)', textAlign: col.align }}
                                        aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                                        onClick={() => toggleSort(col.key)}>
                                        <span className={`inline-flex items-center gap-1 ${col.align === 'right' ? 'flex-row-reverse' : ''}`}>
                                            <span>{t(col.labelKey)}</span>
                                            <Arrow size={12} style={{ visibility: active ? 'visible' : 'hidden', flexShrink: 0 }} />
                                        </span>
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody style={{ divideColor: 'var(--color-border)' }}>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan="4" className="px-4 py-8 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('dashboard.noData')}</td>
                            </tr>
                        )}
                        {rows.map((row, idx) => (
                            <tr key={row.id || idx} className="hover:bg-[var(--color-bg-hover)] transition duration-150 group">
                                <td className="px-4 py-2.5 font-medium cursor-pointer truncate text-left" style={{ width: '46%', color: 'var(--color-text-primary)', textAlign: 'left' }}>{row.name}</td>
                                <td className="px-4 py-2.5 text-right" style={{ width: '18%', color: 'var(--color-text-secondary)', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{row.clicks || 0}</td>
                                <td className="px-4 py-2.5 text-right" style={{ width: '18%', color: 'var(--color-text-secondary)', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{row.unique_clicks || 0}</td>
                                <td className="px-4 py-2.5 font-medium text-right" style={{ width: '18%', color: 'var(--color-success)', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{row.conversions || 0}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default DataTables;