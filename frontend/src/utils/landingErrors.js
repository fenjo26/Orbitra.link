/**
 * Turning a failed landing save into something an operator can act on.
 *
 * Two things went wrong before this existed. The API answers slug problems with
 * stable codes ('landing_slug_taken') so each locale can phrase them, but the
 * forms alerted the code verbatim. And when the request itself failed, axios
 * threw and the catch showed a flat "network error" — so a 500 from the server,
 * a rejected slug and an actually-unreachable host all looked identical.
 *
 * These helpers keep the codes translatable and make sure the server's own
 * message wins over axios's generic "Request failed with status code 500".
 */

const SLUG_ERROR_KEYS = {
    landing_slug_invalid: 'landingEditor.slugInvalid',
    landing_slug_reserved: 'landingEditor.slugReserved',
    landing_slug_taken: 'landingEditor.slugTaken',
    landing_slug_check_failed: 'landingEditor.slugCheckFailed',
};

/**
 * Translate a message the API returned in a `status: 'error'` body.
 * Unknown text passes through — the server may have more to say than we mapped.
 */
export function translateLandingError(t, message) {
    if (!message) return '';
    const key = SLUG_ERROR_KEYS[message];
    return key ? t(key) : String(message);
}

/**
 * Translate a thrown failure, whether axios threw it or we did.
 *
 * Order matters: the JSON body the server sent is the most specific thing we
 * have, then the HTTP status, then a message we threw ourselves after reading
 * an error body. Only a request that never got a response at all is a genuine
 * network error.
 */
export function translateLandingRequestError(t, error) {
    const body = error?.response?.data;
    const serverMessage = body && typeof body === 'object' ? body.message : null;
    if (serverMessage) {
        return translateLandingError(t, serverMessage);
    }
    if (error?.response?.status) {
        return `${t('landingEditor.serverError')} (HTTP ${error.response.status})`;
    }
    // Errors we threw ourselves carry the API's message; axios's own throw for a
    // dead connection carries only "Network Error", which is what it says.
    if (error?.message && !error.isAxiosError) {
        return translateLandingError(t, error.message);
    }
    return t('landingEditor.networkError');
}
