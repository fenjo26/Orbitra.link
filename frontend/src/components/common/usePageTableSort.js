import { useMemo, useState, useCallback } from 'react';

// Sorting state for PageSortTh tables — see PageTableSort.jsx for the rules.

const readSaved = (storageKey) => {
    if (!storageKey) return null;
    try {
        const v = JSON.parse(localStorage.getItem(storageKey) || 'null');
        return v && typeof v.key === 'string' && (v.dir === 'asc' || v.dir === 'desc') ? v : null;
    } catch { return null; }
};

const isEmpty = (v) => v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));

const compareValues = (a, b) => {
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    const na = Number(a), nb = Number(b);
    if (a !== '' && b !== '' && Number.isFinite(na) && Number.isFinite(nb)) return na - nb;
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
};

export const usePageTableSort = (rows, { defaultKey, defaultDir = 'asc', getValue, storageKey } = {}) => {
    const [sort, setSort] = useState(() => readSaved(storageKey) || { key: defaultKey, dir: defaultDir });

    const onSort = useCallback((key, firstDir = 'desc') => {
        setSort(prev => {
            const next = prev.key === key
                ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: firstDir };
            if (storageKey) {
                try { localStorage.setItem(storageKey, JSON.stringify(next)); } catch { /* storage blocked */ }
            }
            return next;
        });
    }, [storageKey]);

    const sorted = useMemo(() => {
        if (!Array.isArray(rows)) return [];
        const value = getValue || ((row, key) => row[key]);
        const mul = sort.dir === 'asc' ? 1 : -1;
        return rows
            .map((row, idx) => ({ row, idx }))
            .sort((x, y) => {
                const a = value(x.row, sort.key);
                const b = value(y.row, sort.key);
                const ea = isEmpty(a), eb = isEmpty(b);
                if (ea || eb) return ea === eb ? x.idx - y.idx : (ea ? 1 : -1);
                return mul * compareValues(a, b) || x.idx - y.idx;
            })
            .map(x => x.row);
    }, [rows, sort, getValue]);

    return { sorted, sort, onSort };
};
