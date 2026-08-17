/**
 * Copy text in both secure browser contexts and HTTP/IP deployments.
 *
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export async function copyToClipboard(text) {
    if (!text && text !== '0') return false;

    const value = String(text);

    // The Clipboard API is restricted to secure contexts (HTTPS/localhost).
    if (typeof window !== 'undefined'
        && window.isSecureContext
        && navigator?.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch (error) {
            console.warn('navigator.clipboard failed, falling back to execCommand:', error);
        }
    }

    // execCommand remains available in browsers that block Clipboard API on
    // plain HTTP. The textarea must be selectable, so it cannot use display:none.
    let textarea;
    try {
        textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        textarea.style.left = '-9999px';
        textarea.style.width = '2em';
        textarea.style.height = '2em';
        textarea.style.padding = '0';
        textarea.style.border = 'none';
        textarea.style.outline = 'none';
        textarea.style.boxShadow = 'none';
        textarea.style.background = 'transparent';
        textarea.style.opacity = '0';
        textarea.style.zIndex = '-1';

        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);

        return document.execCommand('copy');
    } catch (error) {
        console.error('execCommand copy failed:', error);
        return false;
    } finally {
        textarea?.remove();
    }
}
