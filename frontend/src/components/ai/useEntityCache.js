import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';

const API_URL = '/api.php';
const ACTION = { campaign: 'get_campaign', landing: 'get_landing', offer: 'get_offer' };

/**
 * Names and current values for the confirmation cards ("was 30% → will be
 * 70%"). `wants` comes from wantsFor() and must be stable between renders
 * (memoised by the caller); everything missing is loaded once. Requests go
 * with the user's own session, so a card can never show an entity the user is
 * not allowed to open.
 *
 * `version` marks which change the values predate: the chat bumps it after a
 * change ran, and entries from before it read as missing and load again.
 */
export default function useEntityCache(wants, version = 0) {
    const [cache, setCache] = useState({});
    const inFlight = useRef(new Set());

    useEffect(() => {
        wants.forEach(({ kind, id }) => {
            const key = `${kind}:${id}`;
            if (!ACTION[kind] || (cache[key] && cache[key].version === version) || inFlight.current.has(key)) return;
            inFlight.current.add(key);
            axios.get(API_URL, { params: { action: ACTION[kind], id } })
                .then((res) => setCache((c) => ({ ...c, [key]: { version, ...(res.data?.status === 'success' ? { data: res.data.data } : { missing: true }) } })))
                .catch(() => setCache((c) => ({ ...c, [key]: { version, missing: true } })))
                .finally(() => inFlight.current.delete(key));
        });
    }, [wants, cache, version]);

    return useMemo(
        () => Object.fromEntries(Object.entries(cache).filter(([, entry]) => entry.version === version)),
        [cache, version],
    );
}
