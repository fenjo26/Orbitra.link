import { useEffect, useSyncExternalStore } from 'react';

// What the user has open: the campaign, landing or offer editor. Editors
// announce themselves with useAiContext(); the chat reads it with
// useAiPage() to tell the model which entity "this" means and to offer
// shortcuts for it.
//
// A module-level store rather than window events: the last editor to
// announce wins, and the one that closes clears only its own announcement.

let current = null;
const listeners = new Set();

export function setAiContext(next) {
    const value = typeof next === 'function' ? next(current) : next;
    current = value && value.kind ? { kind: value.kind, id: value.id ?? null } : null;
    listeners.forEach((fn) => fn(current));
}

export const getAiContext = () => current;

export function subscribeAiContext(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/** The open page in a component: { kind, id } or null; re-renders on change. */
export function useAiPage() {
    return useSyncExternalStore(subscribeAiContext, getAiContext, getAiContext);
}

/** Call from an editor or section page: useAiContext('campaign', campaignId) or useAiContext('crm'). */
export function useAiContext(kind, id = null) {
    useEffect(() => {
        if (!kind) return undefined;
        const normalizedId = id ?? null;
        setAiContext({ kind, id: normalizedId });
        return () => setAiContext((prev) => (prev && prev.kind === kind && (prev.id ?? null) === normalizedId ? null : prev));
    }, [kind, id]);
}
