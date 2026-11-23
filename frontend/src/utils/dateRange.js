// Date helpers and the useDateRange hook; DateRangePicker.jsx renders the control.
import { useState, useEffect, useCallback } from 'react';
import { getFirstDayOfWeek } from './weekStart';

// tz-database identifiers use underscores for spaces: the chip read "New_York",
// "Sao_Paulo", "Ho_Chi_Minh". Show the city the way it is spelled.
export const formatTimezoneChip = (tz) => {
    const parts = String(tz || '').split('/');
    const city = parts.length > 1 ? parts[parts.length - 1] : parts[0];
    return city.replace(/_/g, ' ');
};

export const TIMEZONES = [
    { value: 'UTC', label: 'UTC (UTC+00:00)' },
    { value: 'Europe/London', label: 'London (UTC+01:00 / +00:00)' },
    { value: 'Europe/Berlin', label: 'Berlin / Paris / Rome (CET, UTC+01:00 / +02:00)' },
    { value: 'Europe/Kyiv', label: 'Kyiv (UTC+03:00 / +02:00)' },
    { value: 'Europe/Moscow', label: 'Moscow (UTC+03:00)' },
    { value: 'Asia/Dubai', label: 'Dubai (UTC+04:00)' },
    { value: 'Asia/Kolkata', label: 'India / Kolkata (IST, UTC+05:30)' },
    { value: 'Asia/Bangkok', label: 'Bangkok (UTC+07:00)' },
    { value: 'Asia/Singapore', label: 'Singapore (UTC+08:00)' },
    { value: 'Asia/Tokyo', label: 'Tokyo (UTC+09:00)' },
    { value: 'America/New_York', label: 'New York (UTC-04:00 / -05:00)' },
    { value: 'America/Chicago', label: 'Chicago (UTC-05:00 / -06:00)' },
    { value: 'America/Los_Angeles', label: 'Los Angeles (UTC-07:00 / -08:00)' },
    { value: 'America/Sao_Paulo', label: 'São Paulo (UTC-03:00)' }
];

export const formatDate = (d) => {
    if (!d) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

export const parseDate = (str) => {
    if (!str) return null;
    const parts = str.split('-');
    if (parts.length !== 3) return null;
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
};

export const getPresetDates = (presetKey) => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    switch (presetKey) {
        case 'today':
            return { from: formatDate(today), to: formatDate(today) };
        case 'yesterday': {
            const y = new Date(today);
            y.setDate(y.getDate() - 1);
            return { from: formatDate(y), to: formatDate(y) };
        }
        case 'thisWeek': {
            // Week starts on the profile's first day (0 = Sun, 1 = Mon).
            const weekStart = getFirstDayOfWeek();
            const startOffset = -((today.getDay() - weekStart + 7) % 7);
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() + startOffset);
            return { from: formatDate(startOfWeek), to: formatDate(today) };
        }
        case 'last7Days': {
            const start = new Date(today);
            start.setDate(today.getDate() - 6);
            return { from: formatDate(start), to: formatDate(today) };
        }
        case 'thisMonth': {
            const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            return { from: formatDate(startOfMonth), to: formatDate(today) };
        }
        case 'last30Days': {
            const start = new Date(today);
            start.setDate(today.getDate() - 29);
            return { from: formatDate(start), to: formatDate(today) };
        }
        case 'previousMonth': {
            const startOfPrevMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const endOfPrevMonth = new Date(today.getFullYear(), today.getMonth(), 0);
            return { from: formatDate(startOfPrevMonth), to: formatDate(endOfPrevMonth) };
        }
        default:
            return null;
    }
};

// Which fixed preset a committed range equals. Used only as a fallback to
// classify a range the picker reported without a preset id; display stays raw
// dates by the operator's preference (Keitaro shows the period's dates too).
const presetForRange = (from, to) => {
    if (!from || !to) return null;
    const ids = ['today', 'yesterday', 'thisWeek', 'last7Days', 'thisMonth', 'last30Days', 'previousMonth'];
    for (const id of ids) {
        const range = getPresetDates(id);
        if (range && range.from === from && range.to === to) return id;
    }
    return null;
};

/**
 * Date range for a list page, Keitaro-style: the "today" the page opens on is
 * ALWAYS the actual today, never the day the tab happened to load.
 *
 * What this replaces: pages computed getPresetDates('today') once at mount, so
 * a panel tab kept open for days quietly served day-old numbers on Campaigns,
 * Offers and Landings alike — and Campaigns additionally replayed a saved
 * custom range forever after its end date passed. Here:
 *   - a non-custom preset is re-derived on every mount AND rolled forward by a
 *     minute timer, so an overnight tab switches to the new day on its own;
 *   - a stored custom range survives only while it still touches today (a
 *     research window ending in the past would otherwise silently become the
 *     default view, which is exactly the three-day-old-numbers report);
 *   - with storageKey the {preset, from, to} entry persists across reloads.
 *
 * Returns [{from, to, preset}, setRange]; setRange matches the picker's
 * onChange signature (from, to, preset).
 */
export function useDateRange({ storageKey = null, defaultPreset = 'today' } = {}) {
    const [state, setState] = useState(() => {
        const fallback = getPresetDates(defaultPreset) || getPresetDates('today');
        let saved = null;
        if (storageKey) {
            try { saved = JSON.parse(localStorage.getItem(storageKey) || 'null'); } catch { saved = null; }
        }
        if (saved && saved.preset && saved.preset !== 'custom') {
            const derived = getPresetDates(saved.preset);
            if (derived) return { from: derived.from, to: derived.to, preset: saved.preset };
        }
        // Legacy entries saved before presets existed (bare {from, to}) are
        // treated as custom — and custom is kept only while still current.
        const savedIsCurrentCustom = saved
            && (!saved.preset || saved.preset === 'custom')
            && saved.from && saved.to
            && saved.to >= (fallback?.to || '');
        if (savedIsCurrentCustom) return { from: saved.from, to: saved.to, preset: 'custom' };
        return { from: fallback?.from || formatDate(new Date()), to: fallback?.to || formatDate(new Date()), preset: defaultPreset };
    });

    useEffect(() => {
        if (state.preset === 'custom') return;
        const timer = setInterval(() => {
            const derived = getPresetDates(state.preset);
            if (derived && (derived.from !== state.from || derived.to !== state.to)) {
                setState({ from: derived.from, to: derived.to, preset: state.preset });
            }
        }, 60000);
        return () => clearInterval(timer);
    }, [state.preset, state.from, state.to]);

    const setRange = useCallback((from, to, preset) => {
        const next = { from, to, preset: preset || presetForRange(from, to) || 'custom' };
        setState(next);
        if (storageKey) {
            try { localStorage.setItem(storageKey, JSON.stringify(next)); } catch { /* storage unavailable */ }
        }
    }, [storageKey]);

    return [state, setRange];
};
