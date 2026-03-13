import axios from 'axios';

const API_URL = '/api.php';

// Simple in-memory cache for API responses
const cache = new Map();
const CACHE_TTL = 30000; // 30 seconds default cache

/**
 * Generate cache key from URL and params
 */
function getCacheKey(action, params = {}) {
    const paramStr = Object.keys(params)
        .sort()
        .map(k => `${k}=${JSON.stringify(params[k])}`)
        .join('&');
    return `${action}${paramStr ? '?' + paramStr : ''}`;
}

/**
 * Check if cached entry is still valid
 */
function isCacheValid(entry) {
    if (!entry) return false;
    return Date.now() - entry.timestamp < entry.ttl;
}

/**
 * Make a cached GET request
 * @param {string} action - API action parameter
 * @param {object} params - Additional query parameters
 * @param {number} ttl - Cache time-to-live in ms (default: 30000)
 * @returns {Promise} Axios response
 */
export async function cachedGet(action, params = {}, ttl = CACHE_TTL) {
    const cacheKey = getCacheKey(action, params);
    const cached = cache.get(cacheKey);

    // Return cached data if still valid
    if (cached && isCacheValid(cached)) {
        return { data: cached.data, fromCache: true };
    }

    // Make actual request
    const response = await axios.get(API_URL, { params: { action, ...params } });

    // Cache successful responses
    if (response.data?.status === 'success') {
        cache.set(cacheKey, {
            data: response.data,
            timestamp: Date.now(),
            ttl: ttl
        });
    }

    return { data: response.data, fromCache: false };
}

/**
 * Invalidate cache entries matching a pattern
 * @param {string} pattern - Cache key pattern to invalidate
 */
export function invalidateCache(pattern) {
    if (!pattern) {
        cache.clear();
        return;
    }

    for (const key of cache.keys()) {
        if (key.includes(pattern)) {
            cache.delete(key);
        }
    }
}

/**
 * Make a POST request (invalidates related cache entries)
 * @param {string} action - API action parameter
 * @param {object} data - POST data
 * @param {object} params - Additional query parameters
 * @returns {Promise} Axios response
 */
export async function cachedPost(action, data = {}, params = {}) {
    const response = await axios.post(API_URL, data, { params: { action, ...params } });

    // Invalidate related cache on successful POST
    if (response.data?.status === 'success') {
        // Invalidate all cache for safety (could be optimized)
        cache.clear();
    }

    return response;
}

// Export regular axios instance for non-cached requests
export { axios };
export default { cachedGet, cachedPost, invalidateCache };
