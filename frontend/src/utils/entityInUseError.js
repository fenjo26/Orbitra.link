/**
 * The delete guard's answer, phrased in the operator's language.
 *
 * When a landing or offer is still referenced by a serving campaign, the API
 * refuses the delete (an archive would break the stream mid-flight) and
 * answers HTTP 200 with { status:'error', code:'entity_in_use', campaigns:[..] }.
 * That 200 is the trap: axios does not throw, so a handler that only catches
 * network errors treats the refusal as a success and silently refreshes the
 * list. These helpers read the body, localize the refusal and keep the
 * server's own message as the fallback for every other error shape.
 */

/**
 * @param {Function} t locale translator
 * @param {object|null} data response body (may be missing on a hard network fail)
 * @param {Error|null} err the axios error, when there was one
 * @param {string} fallbackKey locale key for the generic failure case
 * @returns {string} text ready for alert()
 */
export const entityDeleteErrorText = (t, data, err = null, fallbackKey = 'common.error') => {
    if (data?.code === 'entity_in_use') {
        const campaigns = Array.isArray(data.campaigns) ? data.campaigns.filter(Boolean) : [];
        // The server always names the campaigns; its English message is still
        // informative if a future shape ever omits them.
        return campaigns.length > 0
            ? t('common.entityInUse').replace('{campaigns}', campaigns.join(', '))
            : (data.message || t('common.entityInUse').replace('{campaigns}', ''));
    }
    return data?.message || err?.response?.data?.message || err?.message || t(fallbackKey);
};

/** True when the API body is a refusal from the delete guard (vs any other error). */
export const isEntityInUse = (data) => data?.code === 'entity_in_use';
