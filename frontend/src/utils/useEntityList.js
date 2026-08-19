import { useState, useEffect } from 'react';

// Module-level cache per action key — one request per session
const _cache = {};

/**
 * Hook for fetching entity lists with caching.
 * @param {string} action - API action name (e.g., 'campaigns', 'offers', 'campaign_groups')
 * @returns {{ items: Array, loading: boolean }}
 */
export const useEntityList = (action) => {
    const [items, setItems] = useState(_cache[action] || []);
    const [loading, setLoading] = useState(!_cache[action]);

    useEffect(() => {
        if (_cache[action]) return;

        const fetchItems = async () => {
            try {
                const res = await fetch(`/api.php?action=${action}`);
                const d = await res.json();

                // Normalize response: *_simple and *_groups return a plain
                // array in d.data; the full 'campaigns' action wraps it in
                // d.data.campaigns.
                const list = Array.isArray(d.data)
                    ? d.data
                    : (d.data?.campaigns || d.data?.offers || []);

                // Filter out archived items (full lists carry is_archived;
                // *_simple endpoints already exclude them in SQL)
                _cache[action] = list.filter(i => !i.is_archived);
                setItems(_cache[action]);
            } catch (err) {
                console.error(`Failed to fetch ${action}:`, err);
                _cache[action] = [];
                setItems([]);
            } finally {
                setLoading(false);
            }
        };

        fetchItems();
    }, [action]);

    return { items, loading };
};
