import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { localizedCountryName } from '../utils/countryNames';
import { financeHiddenMetric, financeVisibility } from '../utils/permissions';
import { currencySymbol } from '../utils/currency';
import { DEFAULT_BLOCK_COLUMNS } from './dashboardBlockColumns';

const DataTables = ({ campaigns, offers, landings, sources, countries, preferences, onOpenEntity, user }) => {
    const { t, language } = useLanguage();
    // defaults to true if preferences is undefined for a smoother transition
    const isVisible = (block) => !preferences || !preferences.visible_blocks || preferences.visible_blocks.includes(block);

    // The countries block has no entity behind its rows: its "name" is the
    // country rendered in the UI language (the API sends the ISO code). A
    // click with no country has no name here; the cell shows a label instead.
    const countryRows = useMemo(() => (Array.isArray(countries) ? countries : []).map((row) => ({
        ...row,
        id: row.country_code || 'unknown',
        name: row.country_code ? localizedCountryName(row.country_code, language) : '',
    })), [countries, language]);

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            {isVisible('campaigns') && <TableWidget id="campaigns" title={t('dashboard.topCampaigns')} data={campaigns} t={t} preferences={preferences} user={user} onOpen={(entityId) => onOpenEntity?.({ type: 'campaign', id: entityId })} />}
            {isVisible('offers') && <TableWidget id="offers" title={t('dashboard.topOffers')} data={offers} t={t} preferences={preferences} user={user} onOpen={(entityId) => onOpenEntity?.({ type: 'offer', id: entityId })} />}
            {isVisible('landings') && <TableWidget id="landings" title={t('dashboard.topLandings')} data={landings} t={t} preferences={preferences} user={user} onOpen={(entityId) => onOpenEntity?.({ type: 'landing', id: entityId })} />}
            {isVisible('sources') && <TableWidget id="sources" title={t('dashboard.topSources')} data={sources} t={t} preferences={preferences} user={user} onOpen={(entityId) => onOpenEntity?.({ type: 'source', id: entityId })} />}
            {isVisible('countries') && (
                <div className="md:col-span-2">
                    <TableWidget id="countries" title={t('dashboard.topCountries')} data={countryRows} t={t} preferences={preferences} user={user} />
                </div>
            )}
        </div>
    );
};

const TOP_N = 10;
const SORT_STORAGE_KEY = 'orbitra_dashboard_block_sort';
const DEFAULT_SORT = { key: 'clicks', dir: 'desc' };

// Every column the user may pick, in the order they are shown. width: what the
// column needs for its header and a formatted value; the table is never
// narrower than their sum, so on a narrow screen it scrolls inside its card
// instead of squeezing the columns into each other.
const COLUMNS = [
    { key: 'name', labelKey: 'dashboard.tableName', align: 'left', width: 150 },
    { key: 'clicks', labelKey: 'metrics.clicks', align: 'right', width: 84 },
    { key: 'unique_clicks', labelKey: 'dashboard.tableUnique', align: 'right', width: 72 },
    { key: 'conversions', labelKey: 'dashboard.tableConv', align: 'right', width: 76 },
    { key: 'cost', labelKey: 'metrics.cost', align: 'right', width: 88, money: true },
    { key: 'revenue', labelKey: 'metrics.revenue', align: 'right', width: 96, money: true },
    { key: 'profit', labelKey: 'metrics.profit', align: 'right', width: 100, money: true },
];
const NAME_COLUMN = COLUMNS[0];
// Equal values: keep the busier row first so ties stay meaningful.
const NUMERIC_TIEBREAK = ['conversions', 'clicks', 'unique_clicks'];

// The panel's own `th` / `td` rules (index.css) sit outside the cascade layers,
// so they win over any Tailwind utility: they give every cell 16px of padding
// all round and a 14px font — fine for four columns, too wide for seven. The
// paddings and sizes of these tables are therefore set inline, which does win.
const NAME_CELL = { padding: '9px 12px', fontSize: '13px' };
const NUM_CELL = { padding: '9px 8px', fontSize: '13px' };
const TH_CELL = { padding: '10px 8px', fontSize: '11px' };

// Per-block sort, remembered per browser. The block receives the whole list
// and picks its top 10 after sorting, so sorting by conversions surfaces a
// campaign with 2 clicks and a sale that the clicks top-10 would never show.
const readSorts = () => {
    try { return JSON.parse(localStorage.getItem(SORT_STORAGE_KEY) || '{}') || {}; } catch { return {}; }
};
const writeSort = (id, sort) => {
    try { localStorage.setItem(SORT_STORAGE_KEY, JSON.stringify({ ...readSorts(), [id]: sort })); } catch { /* storage blocked */ }
};

const compareRows = (a, b, key, dir) => {
    const mul = dir === 'asc' ? 1 : -1;
    if (key === 'name') {
        return mul * String(a.name || '').localeCompare(String(b.name || ''), undefined, { numeric: true, sensitivity: 'base' });
    }
    const diff = (Number(a[key]) || 0) - (Number(b[key]) || 0);
    if (diff !== 0) return mul * diff;
    for (const k of NUMERIC_TIEBREAK) {
        if (k === key) continue;
        const d = (Number(b[k]) || 0) - (Number(a[k]) || 0);
        if (d !== 0) return d;
    }
    return 0;
};

// Everything the user picked — not only the visible columns: a row with one
// conversion and no clicks is not "empty" whichever columns are on screen.
const isEmptyRow = (row, keys) => keys.every((key) => !(Number(row[key]) || 0));

// From 100 000 on, a number no longer fits its column ("1,234,567" is wider
// than the clicks column of a half-width card): "123K", "1.2M".
const COMPACT_FROM = 100000;
const compactFormat = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });
const moneyFormat = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatCount = (value, language) => {
    const num = Number(value) || 0;
    return Math.abs(num) >= COMPACT_FROM ? compactFormat.format(num) : num.toLocaleString(language || undefined);
};
const formatMoney = (value) => {
    const num = Number(value) || 0;
    const body = Math.abs(num) >= COMPACT_FROM ? compactFormat.format(Math.abs(num)) : moneyFormat.format(Math.abs(num));
    // The panel's money convention: the sign stands before the currency symbol.
    return `${num < 0 ? '-' : ''}${currencySymbol()}${body}`;
};

const TableWidget = ({ id, title, data, t, preferences, user, onOpen }) => {
    const { language } = useLanguage();
    const financeVis = useMemo(() => financeVisibility(user), [user]);
    // What the user picked in the dashboard settings, without the columns
    // their finance rights hide; with nothing picked (yet), the default three.
    const allowed = COLUMNS.filter(col => !financeHiddenMetric(col.key, financeVis));
    const wanted = Array.isArray(preferences?.block_columns) ? preferences.block_columns : [];
    const picked = allowed.filter(col => wanted.includes(col.key));
    const shown = picked.length ? picked : allowed.filter(col => DEFAULT_BLOCK_COLUMNS.includes(col.key));
    const columns = [NAME_COLUMN, ...shown];
    // "Hide rows without data" is on unless the user switched it off.
    const hideEmpty = preferences?.hide_empty_rows !== false;

    const [sort, setSort] = useState(() => {
        const saved = readSorts()[id];
        return saved && COLUMNS.some(c => c.key === saved.key) ? saved : DEFAULT_SORT;
    });
    // A sort remembered on a column that is not on screen now falls back to clicks.
    const activeSort = columns.some(c => c.key === sort.key) ? sort : DEFAULT_SORT;

    // Empty rows go before the cut, so they never push a row with traffic
    // out of the top 10 — and out entirely while the filter is on.
    const { rows, hidden } = useMemo(() => {
        if (!Array.isArray(data)) return { rows: [], hidden: 0 };
        const sorted = [...data].sort((a, b) => compareRows(a, b, activeSort.key, activeSort.dir));
        const kept = hideEmpty ? sorted.filter(row => !isEmptyRow(row, COLUMNS.map(col => col.key))) : sorted;
        return { rows: kept.slice(0, TOP_N), hidden: sorted.length - kept.length };
    }, [data, activeSort, hideEmpty]);

    const toggleSort = (key) => {
        const next = activeSort.key === key
            ? { key, dir: activeSort.dir === 'desc' ? 'asc' : 'desc' }
            : { key, dir: key === 'name' ? 'asc' : 'desc' };
        setSort(next);
        writeSort(id, next);
    };

    const renderCell = (col, row) => {
        if (col.key === 'name') {
            return (
                <td key={col.key} className="font-medium truncate text-left" style={{ ...NAME_CELL, paddingLeft: 16, color: 'var(--color-text-primary)', textAlign: 'left' }} title={row.name}>
                    {row.name || t('dashboard.unknownCountry')}
                </td>
            );
        }
        let text;
        let color = 'var(--color-text-secondary)';
        let weight;
        if (col.money) {
            const value = Number(row[col.key]) || 0;
            text = col.key === 'profit' && value > 0 ? `+${formatMoney(value)}` : formatMoney(value);
            // A zero profit is neutral, not "in the black".
            if (col.key === 'profit' && value !== 0) {
                color = value > 0 ? 'var(--color-success)' : 'var(--color-danger)';
                weight = 500;
            }
        } else {
            text = formatCount(row[col.key], language);
            if (col.key === 'conversions') {
                color = 'var(--color-success)';
                weight = 500;
            }
        }
        return (
            <td key={col.key} className="text-right" style={{ ...NUM_CELL, color, textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: weight }}>
                {text}
            </td>
        );
    };

    return (
        <div className="card shadow-sm overflow-hidden flex flex-col h-[350px]" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            {/* No border-b here: right below sits the table's grey header strip,
                so the border drew a second line that also ran past the table's
                edge (the scroll container's scrollbar crops the table). The
                background change alone separates title from header. */}
            <div className="px-5 py-3 flex justify-between items-center gap-3" style={{ backgroundColor: 'var(--color-bg-card)' }}>
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{title}</h2>
                <span className="text-xs whitespace-nowrap" style={{ color: 'var(--color-text-muted)' }}>
                    {rows.length} {t('dataTables.records')}
                    {hidden > 0 && (
                        <span className="ml-2" title={t('dataTables.hiddenEmptyHint')}>· {t('dataTables.hiddenEmpty', { count: hidden })}</span>
                    )}
                </span>
            </div>
            {/* Scrolls both ways: down through the rows, and sideways when the
                picked columns are wider than the card on a narrow screen. */}
            <div className="overflow-auto flex-1 h-full">
                <table className="w-full text-sm whitespace-nowrap" style={{ tableLayout: 'fixed', minWidth: columns.reduce((sum, col) => sum + col.width, 0) }}>
                    <thead className="sticky top-0 z-10 shadow-sm" style={{ backgroundColor: 'var(--color-bg-hover)' }}>
                        <tr>
                            {columns.map(col => {
                                const active = activeSort.key === col.key;
                                const Arrow = activeSort.dir === 'asc' ? ArrowUp : ArrowDown;
                                const label = t(col.labelKey);
                                return (
                                    <th key={col.key}
                                        data-col={col.key}
                                        className="cursor-pointer select-none"
                                        style={{ ...TH_CELL, width: col.key === 'name' ? undefined : col.width, paddingLeft: col.align === 'left' ? 16 : 8, color: active ? 'var(--color-text-primary)' : 'var(--color-text-secondary)', borderColor: 'var(--color-border)', textAlign: col.align }}
                                        title={label}
                                        aria-sort={active ? (activeSort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                                        onClick={() => toggleSort(col.key)}>
                                        {/* A block-level flex box: the label may shrink (a long one
                                            ends in an ellipsis, the title above has it in full) while
                                            the sort arrow keeps its place. */}
                                        <span className={`flex items-center gap-1 ${col.align === 'right' ? 'flex-row-reverse' : ''}`}>
                                            <span className="truncate">{label}</span>
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
                                <td colSpan={columns.length} className="px-4 py-8 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('dashboard.noData')}</td>
                            </tr>
                        )}
                        {rows.map((row, idx) => (
                            <tr
                                key={row.id || idx}
                                // The hover + pointer styling promised a jump the rows never
                                // delivered — a click now opens that entity's settings.
                                className={`hover:bg-[var(--color-bg-hover)] transition duration-150 group ${row.id && onOpen ? 'cursor-pointer' : ''}`}
                                title={row.id && onOpen ? t('dashboard.openEntityHint') : undefined}
                                onClick={row.id && onOpen ? () => onOpen(row.id) : undefined}
                            >
                                {columns.map(col => renderCell(col, row))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default DataTables;
