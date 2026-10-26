// Shared helpers for the session-recording screens (Logs → Session
// recordings, the landing editor tab, the player and the heatmap).

export const API_URL = '/api.php';

export const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
    if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(n < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    return `${(n / (1024 * 1024 * 1024)).toFixed(1)} GB`;
};

export const formatDuration = (ms) => {
    const total = Math.max(0, Math.round((Number(ms) || 0) / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (v) => String(v).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
};

// created_at comes from the server in UTC ("YYYY-MM-DD HH:MM:SS").
export const formatUtcDateTime = (value, language, timeZone) => {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(d.getTime())) return String(value);
    try {
        return d.toLocaleString(language || undefined, {
            timeZone: timeZone || undefined,
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
        });
    } catch {
        return d.toLocaleString();
    }
};

export const flagEmoji = (code) => {
    const cc = String(code || '').trim().toUpperCase();
    if (!/^[A-Z]{2}$/.test(cc)) return '';
    return String.fromCodePoint(...[...cc].map(c => 127397 + c.charCodeAt(0)));
};

// The rrweb player and the heatmap background share one lazy chunk: the
// admin bundle does not pay for rrweb until someone opens a recording.
let rrwebPromise = null;
export const loadRrweb = () => {
    if (!rrwebPromise) {
        rrwebPromise = Promise.all([
            import('rrweb'),
            import('rrweb/dist/style.css'),
        ]).then(([mod]) => mod);
    }
    return rrwebPromise;
};

// rrweb needs events in timestamp order; batches can arrive out of order.
export const sortEvents = (events) => {
    const list = Array.isArray(events) ? events.slice() : [];
    list.sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0));
    return list;
};

/** Open Logs → Session recordings, optionally filtered to one landing. */
export const openReplaysInLogs = (landingId) => {
    try {
        sessionStorage.setItem('orbitra_logs_tab', 'replays');
        if (landingId) sessionStorage.setItem('orbitra_replays_landing', String(landingId));
    } catch { /* private mode: the tab still opens */ }
    window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_logs' } }));
    window.dispatchEvent(new CustomEvent('orbitra:logs-tab', { detail: { tab: 'replays', landingId } }));
};
