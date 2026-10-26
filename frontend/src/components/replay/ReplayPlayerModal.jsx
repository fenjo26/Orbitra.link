import React, { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { X, Play, Pause, RotateCcw, Loader2, MousePointerClick, Monitor, Smartphone, Flame, Target, Trash2 } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { useTimezone } from '../../utils/useTimezone';
import { API_URL, formatBytes, formatDuration, formatUtcDateTime, flagEmoji, loadRrweb, sortEvents } from './replayUtils';
import Dialog from '../common/Dialog';

const SPEEDS = [1, 2, 4, 8];

// rrweb event constants (rrweb/types): IncrementalSnapshot + MouseInteraction.Click
const EVENT_INCREMENTAL = 3;
const SOURCE_MOUSE_INTERACTION = 2;
const MOUSE_CLICK = 2;
const MOUSE_TOUCH_END = 9;

/**
 * Plays one recorded visit. The rrweb Replayer renders the landing's DOM in a
 * sandboxed iframe (scripts never run there) and moves a cursor over it; we
 * add the controls, a timeline with click marks and the visit's facts.
 */
const ReplayPlayerModal = ({ clickId, onClose, onDeleted }) => {
    const { t, language } = useLanguage();
    const [timezone] = useTimezone();
    const stageRef = useRef(null);
    const hostRef = useRef(null);
    const replayerRef = useRef(null);
    const rafRef = useRef(0);
    const [state, setState] = useState('loading'); // loading | ready | error
    const [error, setError] = useState('');
    const [meta, setMeta] = useState(null);
    const [playing, setPlaying] = useState(false);
    const [current, setCurrent] = useState(0);
    const [total, setTotal] = useState(0);
    const [speed, setSpeed] = useState(1);
    const [skipInactive, setSkipInactive] = useState(true);
    const [marks, setMarks] = useState([]);
    const [viewport, setViewport] = useState({ w: 1280, h: 720 });
    const [scale, setScale] = useState(1);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // Fit the recorded viewport into the stage.
    const refit = useCallback(() => {
        const stage = stageRef.current;
        if (!stage) return;
        const pad = 16;
        const s = Math.min((stage.clientWidth - pad) / viewport.w, (stage.clientHeight - pad) / viewport.h, 1);
        setScale(s > 0 ? s : 1);
    }, [viewport]);

    useEffect(() => {
        refit();
        window.addEventListener('resize', refit);
        return () => window.removeEventListener('resize', refit);
    }, [refit]);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            try {
                const [res, rrweb] = await Promise.all([
                    axios.get(`${API_URL}?action=replay_events&id=${encodeURIComponent(clickId)}`),
                    loadRrweb(),
                ]);
                if (cancelled) return;
                if (res.data?.status !== 'success') throw new Error(res.data?.message || 'error');
                const events = sortEvents(res.data.events);
                if (events.length < 2 || !events.some(e => e.type === 2)) {
                    setMeta(res.data.meta || null);
                    setError(t('replay.playerTooShort'));
                    setState('error');
                    return;
                }
                setMeta(res.data.meta || null);
                const metaEv = events.find(e => e.type === 4 && e.data && e.data.width);
                if (metaEv) setViewport({ w: metaEv.data.width, h: metaEv.data.height });

                const replayer = new rrweb.Replayer(events, {
                    root: hostRef.current,
                    skipInactive: true,
                    showWarning: false,
                    showDebug: false,
                    mouseTail: { strokeStyle: 'rgba(239, 68, 68, 0.7)', lineWidth: 3, duration: 600 },
                    UNSAFE_replayCanvas: false,
                    triggerFocus: false,
                    speed: 1,
                });
                replayerRef.current = replayer;
                replayer.on('resize', (d) => {
                    if (d && d.width && d.height) setViewport({ w: d.width, h: d.height });
                });
                replayer.on('finish', () => setPlaying(false));
                const md = replayer.getMetaData();
                setTotal(md.totalTime || 0);
                const start = md.startTime || events[0].timestamp;
                setMarks(events
                    .filter(e => e.type === EVENT_INCREMENTAL && e.data && e.data.source === SOURCE_MOUSE_INTERACTION
                        && (e.data.type === MOUSE_CLICK || e.data.type === MOUSE_TOUCH_END))
                    .map(e => e.timestamp - start));
                setState('ready');
                replayer.play(0);
                setPlaying(true);
            } catch (e) {
                if (cancelled) return;
                setError(e?.response?.status === 404 ? t('replay.playerNotFound') : t('replay.playerLoadError'));
                setState('error');
            }
        })();
        return () => {
            cancelled = true;
            cancelAnimationFrame(rafRef.current);
            try { replayerRef.current?.destroy(); } catch { /* already gone */ }
            replayerRef.current = null;
        };
    }, [clickId, t]);

    // Progress ticker.
    useEffect(() => {
        const tick = () => {
            const r = replayerRef.current;
            if (r) {
                try { setCurrent(Math.min(r.getCurrentTime(), total)); } catch { /* not ready */ }
            }
            rafRef.current = requestAnimationFrame(tick);
        };
        rafRef.current = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(rafRef.current);
    }, [total]);

    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape' && !confirmDelete) onClose();
            if (e.key === ' ' && e.target === document.body) { e.preventDefault(); togglePlay(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const togglePlay = () => {
        const r = replayerRef.current;
        if (!r) return;
        if (playing) {
            r.pause();
            setPlaying(false);
        } else {
            const at = current >= total - 50 ? 0 : current;
            r.play(at);
            setPlaying(true);
        }
    };

    const seek = (ms) => {
        const r = replayerRef.current;
        if (!r) return;
        const at = Math.max(0, Math.min(ms, total));
        if (playing) r.play(at); else r.pause(at);
        setCurrent(at);
    };

    const changeSpeed = (s) => {
        setSpeed(s);
        replayerRef.current?.setConfig({ speed: s });
    };

    const toggleSkip = () => {
        const next = !skipInactive;
        setSkipInactive(next);
        replayerRef.current?.setConfig({ skipInactive: next });
    };

    const doDelete = async () => {
        setDeleting(true);
        try {
            await axios.post(`${API_URL}?action=replay_delete`, { ids: [clickId] });
            onDeleted?.(clickId);
            onClose();
        } catch {
            setDeleting(false);
            setConfirmDelete(false);
        }
    };

    const isMobile = meta && Number(meta.viewport_w) > 0 && Number(meta.viewport_w) < 768;
    const country = meta?.country_code || meta?.country || '';

    return (
        <div className="fixed inset-0 z-[1900] bg-black/70 flex items-center justify-center p-2 md:p-6" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl w-full h-full max-w-[1400px] flex flex-col overflow-hidden">
                {/* Header */}
                <div className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                    <div className="min-w-0 flex items-center gap-3 flex-wrap text-sm">
                        <span className="font-semibold text-[var(--color-text-primary)]">{t('replay.playerTitle')}</span>
                        {meta && (
                            <>
                                <span className="text-[var(--color-text-muted)]">{formatUtcDateTime(meta.created_at, language, timezone)}</span>
                                {country && <span title={meta.country || ''}>{flagEmoji(country)} {country}</span>}
                                <span className="flex items-center gap-1 text-[var(--color-text-secondary)]">
                                    {isMobile ? <Smartphone size={14} /> : <Monitor size={14} />}
                                    {meta.viewport_w}×{meta.viewport_h}
                                </span>
                                {meta.landing_name && <span className="truncate max-w-[220px] text-[var(--color-text-secondary)]" title={meta.landing_name}>{meta.landing_name}</span>}
                                {meta.went_to_offer && (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold" style={{ background: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                        <Target size={12} /> {t('replay.wentToOffer')}
                                    </span>
                                )}
                                {Number(meta.is_conversion) > 0 && (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" style={{ background: 'rgba(34,197,94,.15)', color: 'rgb(22,163,74)' }}>
                                        {t('replay.converted')}
                                    </span>
                                )}
                                {Number(meta.rage_clicks) > 0 && (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold" style={{ background: 'rgba(239,68,68,.15)', color: 'rgb(220,38,38)' }} title={t('replay.rageHint')}>
                                        <Flame size={12} /> {t('replay.rageClicks')}: {meta.rage_clicks}
                                    </span>
                                )}
                            </>
                        )}
                    </div>
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <button type="button" className="action-btn" onClick={() => setConfirmDelete(true)} title={t('replay.delete')} aria-label={t('replay.delete')}>
                            <Trash2 className="w-4 h-4" />
                        </button>
                        <button type="button" className="action-btn" onClick={onClose} aria-label={t('common.close', 'Close')}>
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Stage */}
                <div ref={stageRef} className="flex-1 min-h-0 relative overflow-hidden flex items-center justify-center" style={{ background: 'var(--color-bg-soft)' }}>
                    {state === 'loading' && (
                        <div className="flex items-center gap-2 text-[var(--color-text-muted)]"><Loader2 className="w-5 h-5 animate-spin" /> {t('replay.playerLoading')}</div>
                    )}
                    {state === 'error' && (
                        <div className="text-sm text-[var(--color-text-muted)] px-6 text-center">{error}</div>
                    )}
                    <div
                        className="orbitra-replay-host"
                        style={{
                            width: viewport.w, height: viewport.h,
                            transform: `scale(${scale})`, transformOrigin: 'center center',
                            position: 'absolute', left: '50%', top: '50%',
                            marginLeft: -viewport.w / 2, marginTop: -viewport.h / 2,
                            visibility: state === 'ready' ? 'visible' : 'hidden',
                            boxShadow: '0 8px 30px rgba(0,0,0,.25)', background: '#fff',
                        }}
                    >
                        <div ref={hostRef} style={{ width: '100%', height: '100%' }} />
                    </div>
                </div>

                {/* Controls */}
                <div className="px-4 py-3 border-t border-[var(--color-border)] flex flex-col gap-2">
                    <div className="relative h-6 flex items-center">
                        <input
                            type="range"
                            min={0}
                            max={Math.max(total, 1)}
                            value={Math.min(current, total)}
                            onChange={(e) => seek(Number(e.target.value))}
                            className="w-full"
                            disabled={state !== 'ready'}
                            aria-label={t('replay.timeline')}
                        />
                        {total > 0 && marks.map((m, i) => (
                            <span
                                key={i}
                                className="absolute top-0 w-0.5 h-1.5 rounded pointer-events-none"
                                style={{ left: `${(m / total) * 100}%`, background: 'rgb(239,68,68)' }}
                            />
                        ))}
                    </div>
                    <div className="flex items-center justify-between gap-3 flex-wrap">
                        <div className="flex items-center gap-2">
                            <button type="button" className="btn btn-primary flex items-center gap-1.5" onClick={togglePlay} disabled={state !== 'ready'}>
                                {playing ? <Pause size={16} /> : <Play size={16} />}
                                <span>{playing ? t('replay.pause') : t('replay.play')}</span>
                            </button>
                            <button type="button" className="btn btn-secondary" onClick={() => seek(0)} disabled={state !== 'ready'} title={t('replay.restart')} aria-label={t('replay.restart')}>
                                <RotateCcw size={16} />
                            </button>
                            <span className="font-mono text-xs text-[var(--color-text-secondary)] tabular-nums">
                                {formatDuration(current)} / {formatDuration(total)}
                            </span>
                        </div>
                        <div className="flex items-center gap-3 flex-wrap text-xs">
                            {marks.length > 0 && (
                                <span className="flex items-center gap-1 text-[var(--color-text-muted)]"><MousePointerClick size={14} /> {marks.length}</span>
                            )}
                            <label className="flex items-center gap-1.5 cursor-pointer text-[var(--color-text-secondary)]">
                                <input type="checkbox" checked={skipInactive} onChange={toggleSkip} />
                                {t('replay.skipInactive')}
                            </label>
                            <div className="flex items-center gap-1">
                                {SPEEDS.map(s => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => changeSpeed(s)}
                                        className="px-2 py-1 rounded-md font-semibold"
                                        style={{
                                            background: speed === s ? 'var(--color-primary)' : 'var(--color-bg-soft)',
                                            color: speed === s ? 'var(--color-text-inverse)' : 'var(--color-text-secondary)',
                                        }}
                                    >{s}×</button>
                                ))}
                            </div>
                            {meta && <span className="text-[var(--color-text-muted)]">{formatBytes(meta.bytes)}</span>}
                        </div>
                    </div>
                </div>
            </div>
            <Dialog
                open={confirmDelete}
                title={t('replay.deleteOneTitle')}
                message={t('replay.deleteOneText')}
                confirmLabel={t('replay.delete')}
                cancelLabel={t('common.cancel', 'Cancel')}
                danger
                busy={deleting}
                onConfirm={doDelete}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
};

export default ReplayPlayerModal;
