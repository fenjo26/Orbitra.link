/**
 * A local landing's or local offer's files back as one ZIP archive.
 *
 * The API streams the archive (landing_download / offer_download). It is asked
 * for through axios rather than a plain link so that a refusal — the landing is
 * not local, nothing was uploaded yet, the files are too large for one archive
 * — arrives as a message in the panel instead of a page of JSON.
 *
 * The server answers errors with codes; archiveDownloadErrorKey maps a code to
 * its locale key, or null for anything the panel has no wording for.
 */

import axios from 'axios';

const API_URL = '/api.php';

const ERROR_KEYS = {
    landing_not_found: 'archiveDownload.notFound',
    offer_not_found: 'archiveDownload.notFound',
    landing_not_local: 'archiveDownload.notLocal',
    offer_not_local: 'archiveDownload.notLocal',
    archive_no_files: 'archiveDownload.noFiles',
    archive_too_large: 'archiveDownload.tooLarge',
};

export function archiveDownloadErrorKey(code) {
    return ERROR_KEYS[code] || null;
}

/** The file name the server put into Content-Disposition: UTF-8 spelling first, ASCII second. */
function fileNameFrom(disposition, fallbackName) {
    if (!disposition) return fallbackName;
    const utf8 = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(disposition);
    if (utf8) {
        try { return decodeURIComponent(utf8[1].trim()); } catch { /* fall through */ }
    }
    const plain = /filename\s*=\s*"?([^";]+)"?/i.exec(disposition);
    return plain ? plain[1].trim() : fallbackName;
}

/**
 * @param {'landing'|'offer'} kind
 * @param {number|string} id
 * @param {string} fallbackName  used when the server names no file
 * @returns {Promise<{ok: true}|{ok: false, code: string}>}
 */
export async function downloadLocalArchive(kind, id, fallbackName) {
    const action = kind === 'offer' ? 'offer_download' : 'landing_download';
    try {
        const res = await axios.get(`${API_URL}?action=${action}`, { params: { id }, responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileNameFrom(res.headers?.['content-disposition'], fallbackName);
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        return { ok: true };
    } catch (err) {
        // With responseType 'blob' the error body arrives as a Blob; it is the API's JSON.
        const body = err?.response?.data;
        if (body instanceof Blob) {
            try {
                const json = JSON.parse(await body.text());
                return { ok: false, code: String(json?.message || 'archive_failed') };
            } catch { /* not JSON: fall through */ }
        }
        return { ok: false, code: String(err?.message || 'archive_failed') };
    }
}
