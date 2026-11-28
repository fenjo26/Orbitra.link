import React, { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Sparkles, X, Send, Plus, History, Trash2, Wrench, CheckCircle2, AlertTriangle, Loader2, ShieldAlert, ChevronRight, ChevronLeft } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { currencySymbol } from '../../utils/currency';
import AiSetupForm from './AiSetupForm';
import ActionCard from './ActionCard';
import useEntityCache from './useEntityCache';
import { useAiPage } from './aiContext';
import { describeCall, pickedVariants, wantsFor } from './actionCards';

const API_URL = '/api.php';
const STORE_KEY = 'orbitra_ai_chat_id';
const MAX_CLIENT_STEPS = 16;
const DOCK_KEY = 'orbitra_ai_dock';
const BTN = 56;
const PEEK = 12; // what stays on screen of a hidden button
const EMPTY = [];

// The first shortcut follows the page the user has open.
const PAGE_CHIPS = {
    campaign: { label: 'ai.chip.hereCampaign', prompt: 'ai.prompt.hereCampaign' },
    landing: { label: 'ai.chip.hereTextTest', prompt: 'ai.prompt.hereTextTest' },
    offer: { label: 'ai.chip.hereOffer', prompt: 'ai.prompt.hereOffer' },
    crm: { label: 'ai.chip.crmShave', prompt: 'ai.prompt.crmShave' },
    leadforge: { label: 'ai.chip.leadforgeHelp', prompt: 'ai.prompt.leadforgeHelp' },
};

// Where the button lives: which edge, how far from the bottom, hidden or not.
function loadDock() {
    try {
        const d = JSON.parse(localStorage.getItem(DOCK_KEY) || 'null');
        if (d && (d.side === 'left' || d.side === 'right') && Number.isFinite(d.bottom)) return { side: d.side, bottom: d.bottom, hidden: !!d.hidden };
    } catch { /* default */ }
    return { side: 'right', bottom: 16, hidden: false };
}
const clampBottom = (b) => Math.max(8, Math.min(b, (typeof window !== 'undefined' ? window.innerHeight : 800) - BTN - 8));

// Minimal, safe markdown for model replies: paragraphs, "- " / "1. " lists,
// **bold**, `code`. Everything is rendered as React text — never as HTML.
function renderInline(text, keyBase) {
    const parts = [];
    const re = /(\*\*[^*]+\*\*|`[^`]+`)/g;
    let last = 0;
    let m;
    let i = 0;
    while ((m = re.exec(text)) !== null) {
        if (m.index > last) parts.push(text.slice(last, m.index));
        const tok = m[0];
        parts.push(tok.startsWith('**')
            ? <strong key={`${keyBase}-${i++}`}>{tok.slice(2, -2)}</strong>
            : <code key={`${keyBase}-${i++}`} style={{ fontSize: '0.85em', padding: '0 3px', borderRadius: 4, background: 'var(--color-bg-soft)' }}>{tok.slice(1, -1)}</code>);
        last = m.index + tok.length;
    }
    if (last < text.length) parts.push(text.slice(last));
    return parts;
}

function Markdown({ text }) {
    const blocks = [];
    let list = null;
    String(text || '').split('\n').forEach((raw, idx) => {
        const line = raw.replace(/\s+$/, '');
        const bullet = /^\s*(?:[-*•]|\d+[.)])\s+(.*)$/.exec(line);
        if (bullet) {
            if (!list) { list = []; blocks.push({ list }); }
            list.push(bullet[1]);
            return;
        }
        list = null;
        const heading = /^#{1,4}\s+(.*)$/.exec(line);
        blocks.push({ p: heading ? `**${heading[1]}**` : line, idx });
    });
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            {blocks.map((b, i) => b.list
                ? <ul key={i} style={{ margin: 0, paddingLeft: 18, listStyle: 'disc' }}>{b.list.map((li, j) => <li key={j}>{renderInline(li, `${i}-${j}`)}</li>)}</ul>
                : (b.p === '' ? <div key={i} style={{ height: 4 }} /> : <div key={i}>{renderInline(b.p, `p${i}`)}</div>))}
        </div>
    );
}

const money = (v) => {
    const n = Number(v) || 0;
    return `${n < 0 ? '−' : ''}${currencySymbol()}${Math.abs(n).toFixed(2)}`;
};

export default function AiAssistant({ user }) {
    const { t, language } = useLanguage();
    const page = useAiPage();
    // Card choices belong to the calls they were made for.
    const [picks, setPicks] = useState({});
    const [skips, setSkips] = useState({});
    // Bumped after a change ran: the names and "was" values the cards cached
    // are from before it.
    const [entityVersion, setEntityVersion] = useState(0);
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState(null);
    const [greeting, setGreeting] = useState(null);
    const [chatId, setChatId] = useState(() => {
        try { return Number(localStorage.getItem(STORE_KEY)) || null; } catch { return null; }
    });
    const [state, setState] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [input, setInput] = useState('');
    const [history, setHistory] = useState(null);
    const scrollRef = useRef(null);
    const inputRef = useRef(null);
    const [dock, setDock] = useState(loadDock);
    const [drag, setDrag] = useState(null); // {x, y} while dragging
    const [hover, setHover] = useState(false);
    const dragRef = useRef(null);
    const canHover = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(hover: hover)').matches : true;

    const saveDock = (d) => {
        setDock(d);
        try { localStorage.setItem(DOCK_KEY, JSON.stringify(d)); } catch { /* private mode */ }
    };
    useEffect(() => {
        const onResize = () => setDock(d => ({ ...d, bottom: clampBottom(d.bottom) }));
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    // Drag along the screen; on release snap to the nearer side edge. A press
    // that never moved more than a few pixels is a click.
    const onPointerDown = (e) => {
        if (e.button !== undefined && e.button !== 0) return;
        dragRef.current = { sx: e.clientX, sy: e.clientY, moved: false };
        e.currentTarget.setPointerCapture?.(e.pointerId);
    };
    const onPointerMove = (e) => {
        const d = dragRef.current;
        if (!d) return;
        if (!d.moved && Math.hypot(e.clientX - d.sx, e.clientY - d.sy) < 6) return;
        d.moved = true;
        setDrag({ x: e.clientX, y: e.clientY });
    };
    const onPointerUp = (e) => {
        const d = dragRef.current;
        dragRef.current = null;
        if (!d) return;
        if (!d.moved) {
            setOpen(o => !o);
            return;
        }
        setDrag(null);
        const side = e.clientX < window.innerWidth / 2 ? 'left' : 'right';
        saveDock({ side, bottom: clampBottom(window.innerHeight - e.clientY - BTN / 2), hidden: false });
    };

    const loadStatus = async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'ai_status' } });
            if (res.data.status === 'success') setStatus(res.data.data);
        } catch { /* the button still opens; the panel explains */ }
    };
    const loadGreeting = async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'ai_greeting' } });
            if (res.data.status === 'success') setGreeting(res.data.data);
        } catch { setGreeting({}); }
    };

    useEffect(() => { loadStatus(); }, []);

    useEffect(() => {
        if (!open) return;
        loadStatus();
        loadGreeting();
        if (chatId && !state) {
            axios.get(API_URL, { params: { action: 'ai_chat', chat_id: chatId } })
                .then(res => {
                    if (res.data.status === 'success') setState(res.data.data);
                    else { setChatId(null); try { localStorage.removeItem(STORE_KEY); } catch { /* ignore */ } }
                })
                .catch(() => {});
        }
        setTimeout(() => inputRef.current?.focus(), 50);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }, [state, busy, greeting]);

    // Let any page open the chat with a prepared question:
    // window.dispatchEvent(new CustomEvent('orbitra:ai-ask', { detail: { prompt } }))
    useEffect(() => {
        const onAsk = (e) => {
            setOpen(true);
            if (e.detail?.prompt) setTimeout(() => send(e.detail.prompt), 100);
        };
        window.addEventListener('orbitra:ai-ask', onAsk);
        return () => window.removeEventListener('orbitra:ai-ask', onAsk);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [chatId, page]);

    const remember = (id) => {
        setChatId(id);
        try { if (id) localStorage.setItem(STORE_KEY, String(id)); else localStorage.removeItem(STORE_KEY); } catch { /* ignore */ }
    };

    // Held calls, stable between renders so the lookups the cards need run once.
    const pending = useMemo(() => (state?.status === 'confirm' ? state.pending || EMPTY : EMPTY), [state]);
    const wants = useMemo(() => pending.flatMap((p) => wantsFor(p.name, p.args)), [pending]);
    const cache = useEntityCache(wants, entityVersion);
    // What the card lets the user narrow: an action left out, or a text test
    // with every variant unticked, is not run.
    const isKept = (p) => {
        if (skips[p.id]) return false;
        const card = describeCall(p.name, p.args, t, cache);
        return !card?.variants || pickedVariants(card, picks[p.id]).length > 0;
    };
    const nothingToRun = pending.length > 0 && !pending.some(isKept);

    // Drive the server loop: one model turn per request until the chat rests.
    // Resolves to the last state, or null when the request itself failed.
    const run = async (first) => {
        setBusy(true);
        setError(null);
        let last = null;
        try {
            let res = await first();
            for (let i = 0; i < MAX_CLIENT_STEPS; i++) {
                if (res.data.status !== 'success') {
                    setError(res.data.code ? t(`ai.error.${res.data.code}`, res.data.message || res.data.code) : (res.data.message || t('common.networkError')));
                    break;
                }
                const s = res.data.data;
                last = s;
                setState(s);
                // Card choices belong to the calls they were made for.
                if (s.status !== 'confirm') { setPicks({}); setSkips({}); }
                remember(s.chat_id);
                if (s.status === 'error') { setError(s.error); break; }
                if (s.status !== 'continue') break;
                res = await axios.post(`${API_URL}?action=ai_chat_step`, { chat_id: s.chat_id, lang: language });
            }
        } catch (e) {
            // The server's own words when it sent any ("CSRF token mismatch"
            // says more than "network error").
            setError(e?.response?.data?.message || t('common.networkError'));
        } finally {
            setBusy(false);
        }
        return last;
    };

    const send = (text) => {
        const msg = (text ?? input).trim();
        if (!msg || busy) return;
        setInput('');
        setState(s => s ? { ...s, messages: [...s.messages, { type: 'user', text: msg }] } : { messages: [{ type: 'user', text: msg }], pending: [] });
        return run(() => axios.post(`${API_URL}?action=ai_chat_send`, {
            chat_id: chatId, message: msg, lang: language,
            page: page ? { kind: page.kind, id: page.id } : undefined,
        }));
    };

    const confirm = async (approve) => {
        const body = { chat_id: chatId, approve };
        if (approve) {
            const edits = {};
            const skip = [];
            pending.forEach((p) => {
                if (!isKept(p)) {
                    skip.push(p.id);
                    return;
                }
                const card = describeCall(p.name, p.args, t, cache);
                const kept = pickedVariants(card, picks[p.id]);
                if (card?.variants && kept.length !== card.variants.items.length) {
                    edits[p.id] = { ...p.args, variants: kept };
                }
            });
            if (Object.keys(edits).length) body.edits = edits;
            if (skip.length) body.skip = skip;
        }
        const s = await run(() => axios.post(`${API_URL}?action=ai_chat_confirm`, body));
        if (approve && s) {
            // The editors of what changed are still showing the old values:
            // tell them (each decides whether it can reload), and forget the
            // pre-change values the cards cached.
            const touched = new Map();
            pending.forEach((p) => {
                if (isKept(p)) wantsFor(p.name, p.args).forEach((w) => touched.set(`${w.kind}:${w.id}`, w));
            });
            if (touched.size) {
                touched.forEach((w) => window.dispatchEvent(new CustomEvent('orbitra:ai-applied', { detail: w })));
                setEntityVersion((v) => v + 1);
            }
        }
    };

    const retry = () => chatId && run(() => axios.post(`${API_URL}?action=ai_chat_step`, { chat_id: chatId, lang: language }));

    const newChat = () => {
        remember(null);
        setState(null);
        setError(null);
        setHistory(null);
        loadGreeting();
        setTimeout(() => inputRef.current?.focus(), 50);
    };

    const toggleHistory = async () => {
        if (history) { setHistory(null); return; }
        try {
            const res = await axios.get(API_URL, { params: { action: 'ai_chats' } });
            setHistory(res.data.status === 'success' ? res.data.data : []);
        } catch { setHistory([]); }
    };
    const openChat = async (id) => {
        setHistory(null);
        try {
            const res = await axios.get(API_URL, { params: { action: 'ai_chat', chat_id: id } });
            if (res.data.status === 'success') { setState(res.data.data); remember(id); setError(null); }
        } catch { /* stays where it was */ }
    };
    const deleteChat = async (id) => {
        try {
            await axios.post(`${API_URL}?action=ai_chat_delete`, { chat_id: id });
            setHistory(h => (h || []).filter(c => c.id !== id));
            if (id === chatId) newChat();
        } catch { /* ignore */ }
    };

    const configured = !!status?.configured;
    const messages = state?.messages || [];
    const firstName = user?.username || '';

    // Suggestions: the open page first, then the tracker's own numbers.
    const suggestions = [];
    const isAdmin = user?.role === 'admin';
    if (page?.kind && PAGE_CHIPS[page.kind] && (page.id || page.kind === 'crm' || page.kind === 'leadforge')) {
        const chip = PAGE_CHIPS[page.kind];
        suggestions.push({ label: t(chip.label), prompt: t(chip.prompt, { id: page.id || '' }) });
    }
    suggestions.push({ label: t('ai.chip.todayReport'), prompt: t('ai.prompt.todayReport') });
    if (greeting?.worst) {
        suggestions.push({ label: t('ai.chip.optimize', { name: greeting.worst.name }), prompt: t('ai.prompt.optimize', { name: greeting.worst.name, id: greeting.worst.id }) });
    }
    suggestions.push({ label: t('ai.chip.burners'), prompt: t('ai.prompt.burners') });
    if (greeting?.best) {
        suggestions.push({ label: t('ai.chip.best', { name: greeting.best.name }), prompt: t('ai.prompt.best', { name: greeting.best.name, id: greeting.best.id }) });
    }
    suggestions.push({ label: t('ai.chip.stopLoss'), prompt: t('ai.prompt.stopLoss') });
    suggestions.push({ label: t('ai.chip.textTest'), prompt: t('ai.prompt.textTest') });
    if (isAdmin && page?.kind !== 'crm') {
        suggestions.push({ label: t('ai.chip.crmShave'), prompt: t('ai.prompt.crmShave') });
    }

    const toolLabel = (m) => {
        const human = String(m.name || '').replace(/^orbitra_/, '').replace(/_/g, ' ');
        return t(`ai.tools.${m.name}`, human);
    };

    const sideKey = dock.side === 'left' ? 'left' : 'right';
    // Above the button when there is room for a usable chat; otherwise the
    // panel takes the full height of its side (and covers the button).
    const viewH = typeof window !== 'undefined' ? window.innerHeight : 800;
    const panelBottom = viewH - (dock.bottom + BTN + 12) >= 480 ? dock.bottom + BTN + 12 : 16;
    const panelStyle = {
        position: 'fixed', [sideKey]: 16, bottom: Math.max(16, panelBottom), zIndex: 2450,
        width: 'min(420px, calc(100vw - 32px))', height: `min(660px, calc(100dvh - ${Math.max(16, panelBottom) + 24}px))`,
        display: 'flex', flexDirection: 'column', borderRadius: 18, overflow: 'hidden',
        background: 'var(--color-bg-card)', border: '1px solid var(--color-border)',
        boxShadow: '0 20px 60px rgba(0,0,0,0.25)'
    };

    // Hidden: tucked behind the edge, a thin tab stays; hover peeks it out.
    const tucked = dock.hidden && !open && !hover && !drag;
    const offset = tucked ? -(BTN - PEEK) : 16;
    const btnStyle = drag
        ? { left: drag.x - BTN / 2, top: drag.y - BTN / 2, transition: 'none', cursor: 'grabbing' }
        : { [sideKey]: offset, bottom: dock.bottom, transition: 'left .2s ease, right .2s ease, opacity .2s ease', cursor: 'pointer' };
    const HideIcon = dock.side === 'right' ? (dock.hidden ? ChevronLeft : ChevronRight) : (dock.hidden ? ChevronRight : ChevronLeft);

    return (
        <>
            <div
                className="fixed"
                style={{ zIndex: 2450, width: BTN, height: BTN, opacity: tucked ? 0.55 : 1, touchAction: 'none', ...btnStyle }}
                onMouseEnter={() => setHover(true)}
                onMouseLeave={() => setHover(false)}
            >
                <button
                    type="button"
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={onPointerUp}
                    onPointerCancel={() => { dragRef.current = null; setDrag(null); }}
                    className="w-full h-full flex items-center justify-center rounded-full shadow-2xl select-none"
                    style={{ background: 'var(--color-primary)', color: '#fff', cursor: 'inherit' }}
                    title={t('ai.buttonHint')}
                    aria-label={t('ai.button')}
                    data-testid="ai-chat-button"
                >
                    {open ? <X className="w-6 h-6" /> : <Sparkles className="w-6 h-6" />}
                    {!open && status && !configured && (
                        <span className="absolute top-1 right-1 w-3 h-3 rounded-full" style={{ background: 'var(--color-warning, #f59e0b)', border: '2px solid #fff' }} />
                    )}
                </button>
                {!open && !drag && (hover || !canHover) && (
                    <button
                        type="button"
                        onClick={(e) => { e.stopPropagation(); saveDock({ ...dock, hidden: !dock.hidden }); setHover(false); }}
                        className="absolute flex items-center justify-center rounded-full shadow"
                        style={{
                            top: -6, [dock.side === 'right' ? 'left' : 'right']: -6, width: 22, height: 22,
                            background: 'var(--color-bg-card)', color: 'var(--color-text-secondary)', border: '1px solid var(--color-border)',
                            opacity: canHover ? 1 : 0.8
                        }}
                        title={dock.hidden ? t('ai.unhide') : t('ai.hide')}
                        aria-label={dock.hidden ? t('ai.unhide') : t('ai.hide')}
                        data-testid="ai-hide-toggle"
                    >
                        <HideIcon className="w-3.5 h-3.5" />
                    </button>
                )}
            </div>

            {open && (
                <div style={panelStyle} data-testid="ai-chat-panel" role="dialog" aria-label={t('ai.title')}>
                    {/* header */}
                    <div className="flex items-center gap-2 px-4 py-3" style={{ borderBottom: '1px solid var(--color-border)' }}>
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            <Sparkles className="w-4 h-4" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('ai.title')}</div>
                            <div className="text-[11px] truncate" style={{ color: 'var(--color-text-muted)' }}>
                                {configured ? `${t(`ai.provider.${status.provider}`)} · ${status.model}` : t('ai.notConnected')}
                            </div>
                        </div>
                        {configured && (
                            <>
                                <button type="button" className="btn btn-ghost btn-icon p-1.5" onClick={toggleHistory} title={t('ai.history')} data-testid="ai-history"><History className="w-4 h-4" /></button>
                                <button type="button" className="btn btn-ghost btn-icon p-1.5" onClick={newChat} title={t('ai.newChat')} data-testid="ai-new-chat"><Plus className="w-4 h-4" /></button>
                            </>
                        )}
                        <button type="button" className="btn btn-ghost btn-icon p-1.5" onClick={() => setOpen(false)} title={t('common.close', 'Close')}><X className="w-4 h-4" /></button>
                    </div>

                    {history && (
                        <div className="px-3 py-2 space-y-1 overflow-y-auto" style={{ maxHeight: 220, borderBottom: '1px solid var(--color-border)', background: 'var(--color-bg-soft)' }}>
                            {history.length === 0 && <div className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('ai.noHistory')}</div>}
                            {history.map(c => (
                                <div key={c.id} className="flex items-center gap-2 text-xs">
                                    <button type="button" className="flex-1 text-left truncate hover:underline" style={{ color: c.id === chatId ? 'var(--color-primary)' : 'var(--color-text-primary)' }} onClick={() => openChat(c.id)}>{c.title || `#${c.id}`}</button>
                                    <button type="button" className="p-1" onClick={() => deleteChat(c.id)} title={t('common.delete')} style={{ color: 'var(--color-text-muted)' }}><Trash2 className="w-3.5 h-3.5" /></button>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* body */}
                    <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-3 space-y-3 text-[13px]" style={{ color: 'var(--color-text-primary)' }}>
                        {status && !configured && (
                            <div className="space-y-3" data-testid="ai-onboarding">
                                <div className="font-semibold">{t('ai.onboarding.title', { name: firstName })}</div>
                                <p style={{ color: 'var(--color-text-secondary)' }}>{t('ai.onboarding.text')}</p>
                                <ul className="space-y-1" style={{ color: 'var(--color-text-secondary)', paddingLeft: 18, listStyle: 'disc' }}>
                                    <li>{t('ai.onboarding.can1')}</li>
                                    <li>{t('ai.onboarding.can2')}</li>
                                    <li>{t('ai.onboarding.can3')}</li>
                                </ul>
                                {status.can_configure ? (
                                    <div className="rounded-xl p-3" style={{ border: '1px solid var(--color-border)', background: 'var(--color-bg-card)' }}>
                                        <div className="text-xs font-semibold mb-2">{t('ai.onboarding.connect')}</div>
                                        <AiSetupForm t={t} compact onSaved={() => { loadStatus(); loadGreeting(); }} />
                                    </div>
                                ) : (
                                    <div className="rounded-xl p-3 text-xs" style={{ border: '1px solid var(--color-border)', color: 'var(--color-text-secondary)' }}>{t('ai.onboarding.askAdmin')}</div>
                                )}
                            </div>
                        )}

                        {configured && messages.length === 0 && (
                            <div className="space-y-3" data-testid="ai-greeting">
                                <div className="rounded-2xl px-3 py-2.5" style={{ background: 'var(--color-bg-soft)' }}>
                                    <div className="font-semibold mb-1">{t('ai.greeting.hello', { name: firstName })}</div>
                                    {greeting ? (
                                        <div className="space-y-1" style={{ color: 'var(--color-text-secondary)' }}>
                                            {greeting.today && (greeting.today.clicks > 0
                                                ? <div>{greeting.finance_hidden
                                                    ? t('ai.greeting.todayNoMoney', { clicks: greeting.today.clicks.toLocaleString(), conversions: greeting.today.conversions })
                                                    : t('ai.greeting.today', { clicks: greeting.today.clicks.toLocaleString(), conversions: greeting.today.conversions, profit: money(greeting.today.profit) })}</div>
                                                : <div>{t('ai.greeting.noTrafficToday')}</div>)}
                                            {greeting.worst && <div>{t('ai.greeting.worst', { name: greeting.worst.name, roi: greeting.worst.roi ?? '—', profit: money(greeting.worst.profit) })}</div>}
                                            {greeting.best && !greeting.finance_hidden && <div>{t('ai.greeting.best', { name: greeting.best.name, revenue: money(greeting.best.revenue) })}</div>}
                                            {greeting.campaigns_7d === 0 && <div>{t('ai.greeting.noTraffic7d')}</div>}
                                            <div className="pt-1">{t('ai.greeting.offer')}</div>
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-2" style={{ color: 'var(--color-text-muted)' }}><Loader2 className="w-3.5 h-3.5 animate-spin" />{t('ai.greeting.loading')}</div>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {suggestions.map((s, i) => (
                                        <button key={i} type="button" onClick={() => send(s.prompt)} disabled={busy}
                                            className="text-xs px-2.5 py-1.5 rounded-full border transition-colors hover:bg-black/5 dark:hover:bg-white/5 text-left"
                                            style={{ borderColor: 'var(--color-border)', color: 'var(--color-primary)' }} data-testid="ai-chip">
                                            {s.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {messages.map((m, i) => {
                            if (m.type === 'user') {
                                return (
                                    <div key={i} className="flex justify-end">
                                        <div className="rounded-2xl px-3 py-2 max-w-[85%] whitespace-pre-wrap" style={{ background: 'var(--color-primary)', color: '#fff' }}>{m.text}</div>
                                    </div>
                                );
                            }
                            if (m.type === 'assistant') {
                                return (
                                    <div key={i} className="rounded-2xl px-3 py-2 max-w-[92%]" style={{ background: 'var(--color-bg-soft)' }} data-testid="ai-assistant-msg">
                                        <Markdown text={m.text} />
                                    </div>
                                );
                            }
                            if (m.type === 'notice') {
                                return <div key={i} className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t(`ai.notice.${m.code}`)}</div>;
                            }
                            // tool line
                            const icon = m.status === 'ok' ? <CheckCircle2 className="w-3.5 h-3.5" style={{ color: 'var(--color-success)' }} />
                                : m.status === 'error' ? <AlertTriangle className="w-3.5 h-3.5" style={{ color: 'var(--color-danger, #ef4444)' }} />
                                    : m.status === 'declined' ? <X className="w-3.5 h-3.5" style={{ color: 'var(--color-text-muted)' }} />
                                        : <Wrench className="w-3.5 h-3.5" style={{ color: 'var(--color-text-muted)' }} />;
                            return (
                                <div key={i} className="flex items-center gap-1.5 text-[11px]" style={{ color: 'var(--color-text-muted)' }}>
                                    {icon}<span className="truncate">{toolLabel(m)}</span>
                                    {m.status === 'declined' && <span>· {t('ai.declined')}</span>}
                                </div>
                            );
                        })}

                        {pending.length > 0 && (
                            <div className="rounded-xl p-3 space-y-2" style={{ border: `1px solid ${pending.some(p => p.dangerous) ? 'var(--color-danger, #ef4444)' : 'var(--color-border)'}` }} data-testid="ai-confirm">
                                <div className="flex items-center gap-2 text-xs font-semibold">
                                    <ShieldAlert className="w-4 h-4" style={{ color: pending.some(p => p.dangerous) ? 'var(--color-danger, #ef4444)' : 'var(--color-warning, #f59e0b)' }} />
                                    {t('ai.confirm.title', { n: pending.length })}
                                </div>
                                {pending.map((p) => {
                                    const card = describeCall(p.name, p.args, t, cache);
                                    return (
                                        <div key={p.id} className="text-[11px]" style={{ color: 'var(--color-text-secondary)' }}>
                                            <div className="font-semibold">{toolLabel(p)}{p.dangerous && <span style={{ color: 'var(--color-danger, #ef4444)' }}> · {t('ai.confirm.dangerous')}</span>}</div>
                                            <div className="mt-1">
                                                {card ? (
                                                    <ActionCard
                                                        card={card}
                                                        picks={picks[p.id]}
                                                        onPicks={(next) => setPicks((cur) => ({ ...cur, [p.id]: next }))}
                                                        skipped={!!skips[p.id]}
                                                        onSkip={(v) => setSkips((cur) => ({ ...cur, [p.id]: v }))}
                                                        showSkip={pending.length > 1}
                                                        t={t}
                                                    />
                                                ) : null}
                                            </div>
                                            {(!card || card.extra) && (
                                                <pre className="whitespace-pre-wrap break-all rounded-lg p-2 mt-1" style={{ background: 'var(--color-bg-soft)', maxHeight: 140, overflow: 'auto', fontSize: 10.5 }}>{JSON.stringify(p.args, null, 1)}</pre>
                                            )}
                                        </div>
                                    );
                                })}
                                <div className="flex gap-2">
                                    <button type="button" className="btn btn-primary text-xs" disabled={busy || nothingToRun} onClick={() => confirm(true)} data-testid="ai-approve">{t('ai.confirm.run')}</button>
                                    <button type="button" className="btn btn-secondary text-xs" disabled={busy} onClick={() => confirm(false)} data-testid="ai-decline">{t('ai.confirm.cancel')}</button>
                                </div>
                            </div>
                        )}

                        {busy && (
                            <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                <Loader2 className="w-3.5 h-3.5 animate-spin" /> {t('ai.thinking')}
                            </div>
                        )}
                        {error && !busy && (
                            <div className="rounded-xl p-2.5 text-xs space-y-1.5" style={{ border: '1px solid var(--color-danger, #ef4444)', color: 'var(--color-text-secondary)' }} data-testid="ai-error">
                                <div style={{ wordBreak: 'break-word' }}>{error}</div>
                                {chatId && <button type="button" className="btn btn-ghost text-xs" style={{ padding: '2px 6px' }} onClick={retry}>{t('ai.retry')}</button>}
                            </div>
                        )}
                    </div>

                    {/* input */}
                    {configured && (
                        <div className="px-3 py-2.5 flex items-end gap-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                            <textarea
                                ref={inputRef}
                                rows={1}
                                value={input}
                                onChange={e => setInput(e.target.value)}
                                onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
                                placeholder={t('ai.placeholder')}
                                className="input flex-1 text-[13px] resize-none"
                                style={{ padding: '0.5rem 0.7rem', maxHeight: 120, minHeight: 38 }}
                                data-testid="ai-input"
                            />
                            <button type="button" className="btn btn-primary btn-icon p-2" disabled={busy || !input.trim()} onClick={() => send()} title={t('ai.send')} data-testid="ai-send">
                                <Send className="w-4 h-4" />
                            </button>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}
