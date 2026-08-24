import { useEffect, useState } from 'react';

// Shared timezone selection.
//
// Every list view used to keep its own `useState(() => localStorage.getItem('orbitra_tz'))`.
// DateRangePicker writes the key on Apply, but an already-mounted component never
// re-reads it, so two views could sit on screen showing two different periods —
// and CampaignReports read localStorage inside its fetch while rendering its own
// state, so it could disagree with itself.
//
// One module-level value, one set of subscribers. useTimezone() is a drop-in for the
// old useState line: it returns [timezone, setTimezone] with the same shape, so
// existing dependency arrays keep working unchanged.

const STORAGE_KEY = 'orbitra_tz';
const DEFAULT_TIMEZONE = 'UTC';

const readStored = () => {
    try {
        return localStorage.getItem(STORAGE_KEY) || DEFAULT_TIMEZONE;
    } catch {
        // Private mode / storage disabled: fall back rather than break the page.
        return DEFAULT_TIMEZONE;
    }
};

let currentTimezone = readStored();
const subscribers = new Set();

/** The current selection, readable outside React (fetch helpers, non-component code). */
export const getTimezone = () => currentTimezone;

/** Set the selection, persist it, and wake every mounted component. */
export const setTimezone = (next) => {
    const value = next || DEFAULT_TIMEZONE;
    if (value === currentTimezone) return;
    currentTimezone = value;
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Persistence is a convenience; the in-memory value is the source of truth.
    }
    subscribers.forEach((fn) => fn(value));
};

export const subscribeTimezone = (fn) => {
    subscribers.add(fn);
    return () => subscribers.delete(fn);
};

export { STORAGE_KEY as TIMEZONE_STORAGE_KEY, DEFAULT_TIMEZONE };

// Another tab applying a different timezone should not leave this one stale.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY || !e.newValue || e.newValue === currentTimezone) return;
        currentTimezone = e.newValue;
        subscribers.forEach((fn) => fn(currentTimezone));
    });
}

/**
 * Drop-in replacement for `useState(() => localStorage.getItem('orbitra_tz') || 'UTC')`.
 * Returns [timezone, setTimezone] against the shared value.
 */
export function useTimezone() {
    const [value, setValue] = useState(currentTimezone);

    useEffect(() => {
        // A change between first render and subscribe would otherwise be missed.
        if (value !== currentTimezone) setValue(currentTimezone);
        return subscribeTimezone(setValue);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return [value, setTimezone];
}
