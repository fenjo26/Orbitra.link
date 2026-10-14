// Human labels for the `ua` object the API attaches to click rows
// (core/UserAgent.php). Shared by the logo tooltips and the full traffic log.

const join = (...parts) => parts.filter(Boolean).join(' ');

export const describeOs = (ua = {}, fallback = '') => {
    const name = ua.os_name || ua.os || fallback;
    return name && name !== 'Unknown' ? join(name, ua.os_version) : '';
};

export const describeBrowser = (ua = {}, fallback = '') => {
    if (ua.bot) return ua.bot;
    const name = ua.browser_name || ua.browser || fallback;
    const base = name && name !== 'Unknown' ? join(name, ua.browser_version) : '';
    return ua.in_app ? join(ua.in_app, base ? `(${base})` : '') : base;
};
