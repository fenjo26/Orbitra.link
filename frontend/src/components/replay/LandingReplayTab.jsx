import React, { forwardRef, useEffect, useImperativeHandle, useState } from 'react';
import axios from 'axios';
import { Video, Flame, ShieldCheck, Info, PlayCircle } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import SegmentedControl from '../common/SegmentedControl';
import ReplayStorageCard from './ReplayStorageCard';
import HeatmapModal from './HeatmapModal';
import { API_URL, formatBytes, formatUtcDateTime, openReplaysInLogs } from './replayUtils';
import { useTimezone } from '../../utils/useTimezone';

const SAMPLE_OPTIONS = [5, 10, 25, 50, 100];
// '' = until switched off; 'keep' = keep the current end time.
const DURATION_OPTIONS = ['', '1', '24', '72', '168'];

/**
 * Landing editor → "Session recordings". Saved together with the landing
 * (LandingEditor calls ref.save(id) after save_landing succeeds), so the
 * switch behaves like every other field of the form.
 */
const LandingReplayTab = forwardRef(({ landingId, landingName, landingType, onNavigateAway }, ref) => {
    const { t, language } = useLanguage();
    const [timezone] = useTimezone();
    const [enabled, setEnabled] = useState(false);
    const [samplePct, setSamplePct] = useState(10);
    const [duration, setDuration] = useState('');
    const [current, setCurrent] = useState(null);
    const [info, setInfo] = useState(null);
    const [dirty, setDirty] = useState(false);
    const [showHeatmap, setShowHeatmap] = useState(false);

    useEffect(() => {
        if (!landingId) return;
        axios.get(`${API_URL}?action=replay_landing_config&landing_id=${landingId}`).then(res => {
            if (res.data?.status !== 'success') return;
            const d = res.data.data;
            setInfo(d);
            setCurrent(d.config);
            setEnabled(!!d.config.enabled);
            setSamplePct(d.config.sample_pct || 10);
            setDuration(d.config.until_at && d.config.active ? 'keep' : '');
            setDirty(false);
        }).catch(() => {});
    }, [landingId]);

    useImperativeHandle(ref, () => ({
        isDirty: () => dirty,
        save: async (id) => {
            if (!dirty || !id) return true;
            const payload = { landing_id: id, enabled, sample_pct: samplePct };
            if (duration === 'keep') payload.keep_until = true;
            else if (duration) payload.duration_hours = Number(duration);
            const res = await axios.post(`${API_URL}?action=replay_landing_config_save`, payload);
            if (res.data?.status === 'success') {
                setCurrent(res.data.data.config);
                setDirty(false);
                return true;
            }
            return false;
        },
    }), [dirty, enabled, samplePct, duration]);

    const change = (fn) => (v) => { fn(v); setDirty(true); };

    const storage = info?.storage;
    const avg = storage?.avg_bytes || 150 * 1024;
    const visits = info?.visits_24h || 0;
    const perDay = Math.round(visits * samplePct / 100);
    const expired = current && current.enabled && !current.active;

    const untilText = formatUtcDateTime(current?.until_at, language, timezone);
    const durationLabel = (v) => {
        if (v === '') return t('replay.durationUnlimited');
        if (v === 'keep') return t('replay.durationKeep', { until: untilText });
        const h = Number(v);
        return h < 24 ? t('replay.durationHours', { hours: h }) : t('replay.durationDays', { days: h / 24 });
    };
    const durationOptions = current?.until_at && current?.active ? ['keep', ...DURATION_OPTIONS] : DURATION_OPTIONS;

    return (
        <div className="space-y-4">
            <div className="flex items-start gap-3 p-3 rounded-xl" style={{ background: 'var(--color-primary-light)' }}>
                <Video size={20} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--color-primary)' }} />
                <div className="text-sm" style={{ color: 'var(--color-text-primary)' }}>
                    <div className="font-semibold mb-1">{t('replay.tabIntroTitle')}</div>
                    <div className="text-[var(--color-text-secondary)]">{t('replay.tabIntro')}</div>
                </div>
            </div>

            <label className="flex items-center justify-between gap-3 p-3 rounded-xl border cursor-pointer" style={{ borderColor: 'var(--color-border)' }}>
                <span>
                    <span className="block font-semibold text-sm text-[var(--color-text-primary)]">{t('replay.enableLabel')}</span>
                    <span className="block text-xs text-[var(--color-text-muted)]">{t('replay.enableHint')}</span>
                </span>
                <input type="checkbox" className="w-5 h-5" checked={enabled} onChange={e => change(setEnabled)(e.target.checked)} data-testid="replay-enable" />
            </label>

            {expired && (
                <div className="text-xs p-2 rounded-lg" style={{ background: 'rgba(245,158,11,.12)', color: 'rgb(180,83,9)' }}>
                    {t('replay.windowExpired', { until: untilText })}
                </div>
            )}

            <div className={enabled ? '' : 'opacity-50 pointer-events-none'}>
                <div className="space-y-4">
                    <div>
                        <label className="form-label">{t('replay.sampleLabel')}</label>
                        <SegmentedControl
                            ariaLabel={t('replay.sampleLabel')}
                            value={samplePct}
                            onChange={change(setSamplePct)}
                            options={SAMPLE_OPTIONS.map(p => ({ value: p, label: `${p}%` }))}
                        />
                        <p className="text-xs mt-1 mb-0 text-[var(--color-text-muted)]">{t('replay.sampleHint', { pct: samplePct })}</p>
                    </div>
                    <div>
                        <label className="form-label">{t('replay.durationLabel')}</label>
                        <select className="form-select" value={duration} onChange={e => change(setDuration)(e.target.value)}>
                            {durationOptions.map(v => <option key={v} value={v}>{durationLabel(v)}</option>)}
                        </select>
                        <p className="text-xs mt-1 mb-0 text-[var(--color-text-muted)]">{t('replay.durationHint')}</p>
                    </div>
                    <div className="text-xs p-3 rounded-lg border" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                        {visits > 0
                            ? t('replay.dailyEstimate', { visits, count: perDay, size: formatBytes(perDay * avg), avg: formatBytes(avg) })
                            : t('replay.dailyEstimateNoTraffic', { avg: formatBytes(avg), size100: formatBytes(Math.round(1000 * samplePct / 100) * avg) })}
                    </div>
                </div>
            </div>

            {landingType && landingType !== 'local' && landingType !== 'action' && (
                <div className="flex items-start gap-2 text-xs p-3 rounded-lg" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                    <Info size={14} className="flex-shrink-0 mt-0.5" />
                    <span>{t('replay.externalLandingHint')}</span>
                </div>
            )}

            <div className="flex items-start gap-2 text-xs p-3 rounded-lg" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                <ShieldCheck size={14} className="flex-shrink-0 mt-0.5" />
                <span>{t('replay.privacyHint')}</span>
            </div>

            {landingId ? (
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm text-[var(--color-text-secondary)]">
                        {t('replay.landingStats', { count: info?.recordings ?? 0, size: formatBytes(info?.bytes ?? 0) })}
                    </span>
                    <div className="flex-1" />
                    <button type="button" className="btn btn-secondary text-xs flex items-center gap-1.5" onClick={() => setShowHeatmap(true)}>
                        <Flame size={14} /> {t('replay.openHeatmap')}
                    </button>
                    <button
                        type="button"
                        className="btn btn-secondary text-xs flex items-center gap-1.5"
                        onClick={() => { onNavigateAway?.(); openReplaysInLogs(landingId); }}
                    >
                        <PlayCircle size={14} /> {t('replay.openRecordings')}
                    </button>
                </div>
            ) : (
                <p className="text-xs text-[var(--color-text-muted)] m-0">{t('replay.saveLandingFirst')}</p>
            )}

            <ReplayStorageCard storage={storage} compact />

            {showHeatmap && landingId && (
                <HeatmapModal landingId={landingId} landingName={landingName} onClose={() => setShowHeatmap(false)} />
            )}
        </div>
    );
});

LandingReplayTab.displayName = 'LandingReplayTab';

export default LandingReplayTab;
