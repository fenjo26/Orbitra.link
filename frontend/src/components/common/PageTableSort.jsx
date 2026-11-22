import React from 'react';
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';

// Click-to-sort for the plain .page-table report tables (Analytics → Trends,
// Cohorts). The tracker tables (Campaigns / Offers / Landings) have their own
// SortableTh with drag-reorder and resize; these tables need neither, and its
// 10px tracker typography does not belong in a .page-table header.
//
// Rules shared by every table that uses this:
//   - first click on a column sorts by it in `firstDir` (numbers: biggest
//     first), the next click flips the direction;
//   - empty values (null / undefined / '') always go last, in both directions
//     — "no LP CTR" is not "the lowest LP CTR";
//   - ties keep the server order, so equal rows don't shuffle between clicks;
//   - the totals row is NOT part of `rows`: it stays in <tfoot> and never moves.
//
// The state lives in usePageTableSort.js (hooks and components in separate
// modules keep React fast refresh working).

export const PageSortTh = ({ colKey, label, sort, onSort, align = 'left', firstDir = 'desc', title, style, ...rest }) => {
    const active = sort.key === colKey;
    const Icon = !active ? ChevronsUpDown : (sort.dir === 'asc' ? ChevronUp : ChevronDown);
    const icon = <Icon className="page-sort-icon" aria-hidden="true" />;
    return (
        <th
            {...rest}
            aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
            style={{ textAlign: align, ...style }}
        >
            <button
                type="button"
                className="page-sort-btn"
                data-active={active ? 'true' : 'false'}
                title={title}
                onClick={() => onSort(colKey, firstDir)}
            >
                {/* Icon on the inner side so right-aligned numeric headers
                    keep their label flush with the numbers below. */}
                {align === 'right' && icon}
                <span>{label}</span>
                {align !== 'right' && icon}
            </button>
        </th>
    );
};
