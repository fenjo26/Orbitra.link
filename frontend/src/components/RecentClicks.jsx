import React, { useLayoutEffect, useRef, useState } from 'react';
import { Monitor, Smartphone, Tablet, Globe, ArrowRight, ChevronDown, ChevronUp, Settings } from 'lucide-react';
import ClickDetailsModal from './ClickDetailsModal';
import { OsIcon, BrowserIcon } from './PlatformIcons';
import ClickDirection from './ClickDirection';
import { RECENT_CLICK_COLUMNS, DEFAULT_CLICK_COLUMNS } from './recentClicksColumns';
import { SortableTh } from './common/SortableTh';
import { useIsDesktop, useResizableTableColumns } from './common/ColumnResize';
import useColumnReorder from './common/useColumnReorder';
import { useLanguage } from '../contexts/LanguageContext';

const INITIAL_LIMIT = 10;

const getDeviceIcon = (deviceType) => {
    switch (deviceType?.toLowerCase()) {
        case 'mobile':
            return <Smartphone size={16} className="text-[var(--color-text-secondary)]" />;
        case 'tablet':
            return <Tablet size={16} className="text-[var(--color-text-secondary)]" />;
        case 'desktop':
            return <Monitor size={16} className="text-[var(--color-text-secondary)]" />;
        default:
            return <Globe size={16} className="text-[var(--color-text-muted)]" />;
    }
};

// Default width (px) and alignment per column. Widths are what a fresh table
// starts with; dragging a header edge stores the user's own (recent_clicks key).
const COLUMN_GEOMETRY = {
    click_id: { width: 130, align: 'left' },
    created_at: { width: 165, align: 'left' },
    campaign_name: { width: 220, align: 'left' },
    domain: { width: 170, align: 'left' },
    country_code: { width: 70, align: 'center' },
    os: { width: 64, align: 'center' },
    browser: { width: 84, align: 'center' },
    device_type: { width: 90, align: 'center' },
    ip: { width: 150, align: 'left' },
    user_agent: { width: 260, align: 'left' },
    redirect_url: { width: 280, align: 'left' },
};
const NO_SORT = { key: null, dir: 'asc' };
const noop = () => {};

const RecentClicks = ({ logs, preferences, setPreferences, onShowAll, onOpenSettings }) => {
    const { t } = useLanguage();
    const chosen = Array.isArray(preferences?.click_columns) ? preferences.click_columns : DEFAULT_CLICK_COLUMNS;
    // The user's order, not the registry's: headers drag to reorder.
    const columns = chosen
        .map((id) => RECENT_CLICK_COLUMNS.find((col) => col.id === id))
        .filter(Boolean);
    const columnIds = columns.map((col) => col.id);
    const isDesktop = useIsDesktop();
    const colResize = useResizableTableColumns({
        tableId: 'recent_clicks',
        enabled: isDesktop,
        columns: columns.map((col) => ({ id: col.id, width: COLUMN_GEOMETRY[col.id]?.width ?? 120 })),
    });
    const reorder = useColumnReorder(columnIds, (next) => {
        if (setPreferences) setPreferences((prev) => ({ ...prev, click_columns: next }));
    });
    const cellProps = (colId, extra = '') => ({
        'data-col': colId,
        className: [`align-${COLUMN_GEOMETRY[colId]?.align || 'left'}`, extra].filter(Boolean).join(' '),
    });
    const [selectedClickId, setSelectedClickId] = useState(null);
    const [isExpanded, setIsExpanded] = useState(false);
    const [tableHeight, setTableHeight] = useState(null);
    const tableContainerRef = useRef(null);
    const loadedLogs = Array.isArray(logs) ? logs : [];
    const displayedLogs = isExpanded ? loadedLogs : loadedLogs.slice(0, INITIAL_LIMIT);
    const hiddenLogCount = Math.max(loadedLogs.length - INITIAL_LIMIT, 0);
    const showMoreLabel = t('dashboard.showMoreLogs').replace('10', String(hiddenLogCount));
    const unknownLabel = t('clickDetails.noData');

    useLayoutEffect(() => {
        const tableContainer = tableContainerRef.current;
        if (!tableContainer) return undefined;

        const updateHeight = () => {
            setTableHeight(tableContainer.getBoundingClientRect().height);
        };

        updateHeight();

        if (typeof ResizeObserver === 'undefined') return undefined;

        const resizeObserver = new ResizeObserver(updateHeight);
        resizeObserver.observe(tableContainer);
        return () => resizeObserver.disconnect();
    }, [displayedLogs.length, preferences, colResize.widthOf]);

    const openDetails = (log) => setSelectedClickId(log.click_id || log.id);

    const renderCell = (colId, log) => {
        switch (colId) {
            case 'click_id': {
                const id = String(log.click_id || log.id || '');
                return (
                    <td key={colId} {...cellProps(colId, "font-mono text-xs")}>
                        <button
                            type="button"
                            onClick={() => openDetails(log)}
                            title={id}
                            className="block max-w-full truncate text-left hover:underline"
                            style={{ color: 'var(--color-text-secondary)' }}
                        >
                            {id || '-'}
                        </button>
                    </td>
                );
            }
            case 'created_at':
                return (
                    <td key={colId} {...cellProps(colId, "font-mono text-xs whitespace-nowrap")}>
                        <button type="button" onClick={() => openDetails(log)} title={log.created_at_utc ? `UTC: ${log.created_at_utc}` : undefined} className="text-[var(--color-primary)] hover:opacity-80 hover:underline font-medium text-left">
                            {log.created_at}
                        </button>
                    </td>
                );
            case 'campaign_name':
                return <td key={colId} {...cellProps(colId, "font-medium")} style={{ color: 'var(--color-text-primary)' }}>{log.campaign_name}</td>;
            case 'domain':
                return <td key={colId} {...cellProps(colId, "text-xs")} title={log.domain || ''} style={{ color: 'var(--color-text-secondary)' }}>{log.domain || '-'}</td>;
            case 'country_code':
                return (
                    <td
                        key={colId}
                        {...cellProps(colId, "text-center text-xs font-mono align-middle")}
                        title={[log.country_code, log.region, log.city, log.geo_timezone].filter(Boolean).join(' / ')}
                    >
                        {log.country_code || '-'}
                    </td>
                );
            case 'os':
                return (
                    <td key={colId} {...cellProps(colId, "text-center align-middle")}>
                        <OsIcon ua={log.ua} fallbackOs={log.os} unknownLabel={unknownLabel} />
                    </td>
                );
            case 'browser':
                return (
                    <td key={colId} {...cellProps(colId, "text-center align-middle")}>
                        <BrowserIcon ua={log.ua} fallbackBrowser={log.browser} unknownLabel={unknownLabel} />
                    </td>
                );
            case 'device_type':
                return (
                    <td key={colId} {...cellProps(colId, "text-center align-middle")} title={log.device_type || ''}>
                        <div className="flex justify-center">{getDeviceIcon(log.device_type)}</div>
                    </td>
                );
            case 'ip':
                return <td key={colId} {...cellProps(colId, "font-mono text-xs align-middle whitespace-nowrap")} style={{ color: 'var(--color-text-secondary)' }}>{log.ip}</td>;
            case 'user_agent':
                return (
                    <td key={colId} {...cellProps(colId, "text-xs")} title={log.user_agent} style={{ color: 'var(--color-text-muted)' }}>
                        {log.user_agent || '-'}
                    </td>
                );
            case 'redirect_url':
                return (
                    <td key={colId} {...cellProps(colId, "")}>
                        <div className="flex items-center gap-2 text-xs min-w-0">
                            <ArrowRight size={14} className="text-[var(--color-text-muted)] shrink-0" />
                            <div className="min-w-0">
                                <ClickDirection destination={log.destination} fallbackUrl={log.redirect_url} />
                            </div>
                        </div>
                    </td>
                );
            default:
                return null;
        }
    };

    return (
        <div className="card shadow-sm overflow-hidden mb-8" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
            <div className="px-5 py-4 border-b flex justify-between items-center" style={{ borderColor: 'var(--color-border)' }}>
                <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-primary)' }}>{t('dashboard.recentClicksLog')}</h2>
                <div className="flex items-center gap-3">
                    <div className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-[var(--color-success,#10b981)] rounded-full animate-pulse"></span>
                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>Live</span>
                    </div>
                    {onOpenSettings && (
                        <button
                            type="button"
                            onClick={onOpenSettings}
                            title={t('dashboard.configureColumns')}
                            aria-label={t('dashboard.configureColumns')}
                            data-testid="recent-clicks-settings"
                            className="p-1 rounded-md transition-colors hover:bg-[var(--color-bg-hover)]"
                            style={{ color: 'var(--color-text-muted)' }}
                        >
                            <Settings size={15} />
                        </button>
                    )}
                </div>
            </div>
            <div
                id="recent-clicks-table"
                className="overflow-hidden transition-[height] duration-300 ease-in-out motion-reduce:transition-none"
                style={{ height: tableHeight === null ? 'auto' : `${tableHeight}px` }}
            >
                <div ref={tableContainerRef} className="overflow-x-auto">
                    <table className="page-table tracker-table w-full" style={colResize.tableStyle || undefined}>
                        {colResize.colgroup}
                        <thead>
                            <tr>
                                {columns.map((col, idx) => (
                                    <SortableTh
                                        key={col.id}
                                        colKey={col.id}
                                        label={t(col.labelKey)}
                                        sortable={false}
                                        sortBy={NO_SORT}
                                        requestSort={noop}
                                        draggable={Boolean(setPreferences)}
                                        resize={colResize.enabled ? colResize : null}
                                        data-col={col.id}
                                        className={`align-${COLUMN_GEOMETRY[col.id]?.align || 'left'}`}
                                        {...reorder.thProps(idx)}
                                    />
                                ))}
                            </tr>
                        </thead>
                        <tbody style={{ divideColor: 'var(--color-border)' }}>
                            {loadedLogs.length === 0 && (
                                <tr>
                                    <td colSpan={Math.max(columns.length, 1)} className="py-12 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('dashboard.noLogs')}</td>
                                </tr>
                            )}
                            {displayedLogs.map((log) => (
                                <tr key={log.id}>
                                    {columns.map((col) => renderCell(col.id, log))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {hiddenLogCount > 0 && (
                <div className="px-5 py-2.5 border-t flex justify-center" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    <button
                        type="button"
                        onClick={() => setIsExpanded((expanded) => !expanded)}
                        aria-controls="recent-clicks-table"
                        aria-expanded={isExpanded}
                        title={isExpanded ? t('dashboard.showLess') : t('dashboard.last10Logs')}
                        className="text-xs font-medium flex items-center gap-1.5 transition-colors hover:underline"
                        style={{ color: 'var(--color-primary)' }}
                    >
                        {isExpanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
                        <span>{isExpanded ? t('dashboard.showLess') : showMoreLabel}</span>
                    </button>
                </div>
            )}

            <div className="px-5 py-3 border-t flex justify-center" style={{ backgroundColor: 'var(--color-bg-hover)', borderColor: 'var(--color-border)' }}>
                <button
                    onClick={onShowAll}
                    className="text-[var(--color-primary)] hover:opacity-80 text-sm font-medium flex items-center gap-1 transition-colors"
                >
                    {t('dashboard.viewFullLogs')} <ArrowRight size={14} />
                </button>
            </div>

            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </div>
    );
};

export default RecentClicks;
