import React from 'react';
import { useLanguage } from '../../contexts/LanguageContext';

// Shared table pagination: "Showing X-Y of N rows" + page size selector +
// First/Prev/pages/Next/Last. The chosen size is persisted to a single
// localStorage key so it applies to every table that uses this toolbar.
// Totals are not handled here — pages compute them over the whole filtered
// list, paging must not change the TOTAL row.
const PaginationToolbar = ({
    totalRows,
    currentPage,       // 0-indexed (0, 1, 2…)
    pageSize,          // 25 | 50 | 100 | 250 | 'All'
    onPageChange,
    onPageSizeChange
}) => {
    const { t } = useLanguage();

    if (totalRows === 0) return null;

    const isAll = pageSize === 'All';
    const limit = isAll ? totalRows : Number(pageSize);
    const totalPages = isAll ? 1 : Math.max(1, Math.ceil(totalRows / limit));

    const startRow = isAll ? 1 : currentPage * limit + 1;
    const endRow = isAll ? totalRows : Math.min(totalRows, (currentPage + 1) * limit);

    // Visible page numbers: a window of 5 around the current page.
    const getPageNumbers = () => {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, (_, i) => i);
        }
        const start = Math.max(0, Math.min(currentPage - 2, totalPages - 5));
        const end = Math.min(totalPages - 1, start + 4);
        const pages = [];
        for (let i = start; i <= end; i++) pages.push(i);
        return pages;
    };

    const navBtnStyle = {
        backgroundColor: 'var(--color-bg-card)',
        borderColor: 'var(--color-border)',
        color: 'var(--color-text-primary)'
    };

    const navBtn = (label, disabled, onClick) => (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="touch-min-44 px-2.5 py-1 rounded-lg border text-xs font-medium transition disabled:opacity-40"
            style={navBtnStyle}
        >
            {label}
        </button>
    );

    return (
        <div
            className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 mt-3 rounded-xl text-xs select-none"
            style={{
                backgroundColor: 'var(--color-bg-soft)',
                border: '1px solid var(--color-border)',
                color: 'var(--color-text-secondary)'
            }}
        >
            {/* Left: row counter */}
            <div className="font-medium">
                {t('table.showingRows', 'Showing {start}-{end} of {total} rows')
                    .replace('{start}', startRow)
                    .replace('{end}', endRow)
                    .replace('{total}', totalRows)}
            </div>

            {/* Right: page size + navigation */}
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex items-center gap-1.5 mr-2">
                    <span className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>{t('table.pageSize', 'Page Size')}</span>
                    <select
                        value={String(pageSize)}
                        onChange={(e) => {
                            const val = e.target.value === 'All' ? 'All' : Number(e.target.value);
                            localStorage.setItem('orbitra_table_page_size', String(val));
                            onPageSizeChange(val);
                        }}
                        className="form-select font-semibold"
                        style={{ width: 'auto', padding: '2px 8px', fontSize: '12px' }}
                    >
                        {[25, 50, 100, 250].map((n) => (
                            <option key={n} value={n}>{n}</option>
                        ))}
                        <option value="All">{t('table.all', 'All')}</option>
                    </select>
                </div>

                {!isAll && totalPages > 1 && (
                    /* flex-wrap: without it the 9-button cluster keeps its
                       ~480px min-content width and drags the whole page past
                       a phone viewport. */
                    <div className="flex flex-wrap items-center gap-1">
                        {navBtn(t('table.first', 'First'), currentPage === 0, () => onPageChange(0))}
                        {navBtn(t('table.prev', 'Prev'), currentPage === 0, () => onPageChange(currentPage - 1))}

                        {getPageNumbers().map((num) => {
                            const isActive = num === currentPage;
                            return (
                                <button
                                    key={num}
                                    type="button"
                                    onClick={() => onPageChange(num)}
                                    className="min-w-[28px] h-7 px-1.5 rounded-lg text-xs font-bold transition flex items-center justify-center"
                                    style={{
                                        backgroundColor: isActive ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                        color: isActive ? 'var(--color-text-inverse)' : 'var(--color-text-primary)',
                                        border: `1px solid ${isActive ? 'var(--color-primary)' : 'var(--color-border)'}`
                                    }}
                                >
                                    {num + 1}
                                </button>
                            );
                        })}

                        {navBtn(t('table.next', 'Next'), currentPage >= totalPages - 1, () => onPageChange(currentPage + 1))}
                        {navBtn(t('table.last', 'Last'), currentPage >= totalPages - 1, () => onPageChange(totalPages - 1))}
                    </div>
                )}
            </div>
        </div>
    );
};

export default PaginationToolbar;
