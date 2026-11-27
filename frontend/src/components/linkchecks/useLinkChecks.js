import { useEffect, useState } from 'react';
import axios from 'axios';

const API_URL = '/api.php';
const POLL_MS = 60000;

// Polls action=link_checks_summary: the numbers behind the top-bar indicator
// and the dashboard strip. null until the first answer — callers render
// nothing until then.
export function useLinkChecksSummary(enabled = true) {
    const [summary, setSummary] = useState(null);
    useEffect(() => {
        if (!enabled) return undefined;
        let alive = true;
        const load = async () => {
            try {
                const res = await axios.get(API_URL, { params: { action: 'link_checks_summary' } });
                if (alive && res.data?.status === 'success') setSummary(res.data.data);
            } catch {
                // The next poll tries again.
            }
        };
        load();
        const timer = setInterval(load, POLL_MS);
        return () => { alive = false; clearInterval(timer); };
    }, [enabled]);
    return summary;
}

// Only the links that need attention, keyed "offer:12" / "domain:3": what the
// dots next to offers and domains are made of.
export function useLinkChecksIndex(enabled = true) {
    const [index, setIndex] = useState(() => new Map());
    useEffect(() => {
        if (!enabled) return undefined;
        let alive = true;
        const load = async () => {
            try {
                const res = await axios.get(API_URL, { params: { action: 'link_checks_list', status: 'problem', limit: 500 } });
                if (alive && res.data?.status === 'success') {
                    setIndex(new Map((res.data.data.rows || []).map((row) => [`${row.object_type}:${row.object_id}`, row])));
                }
            } catch {
                // Keep the previous answer.
            }
        };
        load();
        const timer = setInterval(load, POLL_MS);
        return () => { alive = false; clearInterval(timer); };
    }, [enabled]);
    return index;
}

// The link a dot named, for the panel to pick up when it mounts. A panel that
// is already mounted hears the event instead.
let pendingFocus = null;
export const takeLinkChecksFocus = () => {
    const id = pendingFocus;
    pendingFocus = null;
    return id;
};

/** Opens Notifications → Checks, optionally with one link expanded. */
export function openLinkChecks(checkId = null) {
    if (checkId) {
        pendingFocus = checkId;
        window.dispatchEvent(new CustomEvent('orbitra:link-checks-focus', { detail: { check: checkId } }));
    }
    window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'notifications', sub: 'checks' } }));
}
