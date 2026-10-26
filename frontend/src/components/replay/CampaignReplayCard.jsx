import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Video, Flame, PlayCircle, ShieldCheck, Cookie, Loader2 } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import SegmentedControl from '../common/SegmentedControl';
import HeatmapModal from './HeatmapModal';
import { API_URL, formatBytes, formatUtcDateTime, openReplaysInLogs } from './replayUtils';
import { useTimezone } from '../../utils/useTimezone';

const SAMPLE_OPTIONS = [5, 10, 25, 50, 100];
// '' = until switched off; 'keep' = keep the current end time.
const DURATION_OPTIONS = ['', '1', '24', '72', '168'];

/**
 * Campaign editor → Integrations → "Session recordings" (organic sites). The
 * campaign-level twin of the landing editor's tab: records visits of a site
 * tracked with /tracking.js — multi-page sessions, per-page heatmaps. Saved
 * by its own button, like the cost connections around it.
 */
const CampaignReplayCard = ({ campaignId, campaignName, onNavigateAway }) => {
    const { t, language } = useLanguage();
    const [timezone] = useTimezone();
    const [enabled, setEnabled] = useState(false);
    const [samplePct, setSamplePct] = useState(10);
    const [duration, setDuration] = useState('');
    const [consent, setConsent] = useState(false);
    const [sessionContinue, setSessionContinue] = useState(false);
    const [info, setInfo] = useState(null);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [showHeatmap, setShowHeatmap] = useState(false);

    useEffect(() => {
        if (!campaignId) return;
        axios.get(`${API_URL}?action=replay_campaign_config&campaign_id=${campaignId}`).then(res => {
            if (res.data?.status !== 'success') return;
            const d = res.data.data;
            setInfo(d);
            setEnabled(!!d.config.enabled);
            setSamplePct(d.config.sample_pct || 10);
            setDuration(d.config.until_at && d.config.active ? 'keep' : '');
            setConsent(!!d.config.consent);
            setSessionContinue(!!d.config.session_continue);
            setDirty(false);
        }).catch(() => {});
    }, [campaignId]);

    const save = async () => {
        if (!campaignId) return;
        setSaving(true);
        setError('');
        try {
            const payload = { campaign_id: campaignId, enabled, sample_pct: samplePct, consent, session_continue: sessionContinue };
            if (duration === 'keep') payload.keep_until = true;
            else if (duration) payload.duration_hours = Number(duration);
            const res = await axios.post(`${API_URL}?action=replay_campaign_config_save`, payload);
            if (res.data?.status === 'success') {
                // Re-read the whole card state (recordings count, estimate).
                const cfg = await axios.get(`${API_URL}?action=replay_campaign_config&campaign_id=${campaignId}`);
                if (cfg.data?.status === 'success') setInfo(cfg.data.data);
                setDirty(false);
            } else {
                setError(res.data?.message || t('common.error', 'Error'));
            }
        } catch {
            setError(t('common.error', 'Error'));
        } finally {
            setSaving(false);
        }
    };

    const change = (fn) => (v) => { fn(v); setDirty(true); };

    const storage = info?.storage;
    const avg = storage?.avg_bytes || 150 * 1024;
    const visits = info?.visits_24h || 0;
    const perDay = Math.round(visits * samplePct / 100);
    const expired = info?.config && info.config.enabled && !info.config.active;

    const untilText = formatUtcDateTime(info?.config?.until_at, language, timezone);
    const durationLabel = (v) => {
        if (v === '') return t('replay.durationUnlimited');
        if (v === 'keep') return t('replay.durationKeep', { until: untilText });
        const h = Number(v);
        return h < 24 ? t('replay.durationHours', { hours: h }) : t('replay.durationDays', { days: h / 24 });
    };
    const durationOptions = info?.config?.until_at && info?.config?.active ? ['keep', ...DURATION_OPTIONS] : DURATION_OPTIONS;

    if (!campaignId) {
        return (
            <div className="flex items-start gap-2 p-3 rounded-xl" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                <Video size={16} className="flex-shrink-0 mt-0.5" />
                <span className="text-xs">{t('replay.campaignSaveFirst')}</span>
            </div>
        );
    }

    return (
        <div style={{
            border: '1px solid var(--color-border)',
            borderRadius: '16px',
            padding: '14px 16px',
            background: 'var(--color-bg-card)'
        }} data-testid="campaign-replay-card">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div className="flex items-center gap-2" style={{ fontWeight: 600, fontSize: '14px', color: 'var(--color-text-primary)' }}>
                    <Video size={16} style={{ color: 'var(--color-primary)' }} />
                    {t('replay.campaignTitle')}
                </div>
                <button
                    type="button"
                    className="btn btn-primary text-xs py-1 px-3 rounded-xl flex items-center gap-1.5"
                    disabled={!dirty || saving}
                    onClick={save}
                    data-testid="campaign-replay-save"
                >
                    {saving && <Loader2 size={13} className="animate-spin" />}
                    {t('common.save', 'Save')}
                </button>
            </div>

            <p className="text-xs m-0 mb-3" style={{ color: 'var(--color-text-secondary)' }}>{t('replay.campaignIntro')}</p>

            <label className="flex items-center justify-between gap-3 p-3 rounded-xl border cursor-pointer mb-3" style={{ borderColor: 'var(--color-border)' }}>
                <span>
                    <span className="block font-semibold text-sm text-[var(--color-text-primary)]">{t('replay.enableLabel')}</span>
                    <span className="block text-xs text-[var(--color-text-muted)]">{t('replay.campaignEnableHint')}</span>
                </span>
                <input type="checkbox" className="w-5 h-5" checked={enabled} onChange={e => change(setEnabled)(e.target.checked)} data-testid="campaign-replay-enable" />
            </label>

            {expired && (
                <div className="text-xs p-2 rounded-lg mb-3" style={{ background: 'rgba(245,158,11,.12)', color: 'rgb(180,83,9)' }}>
                    {t('replay.windowExpired', { until: untilText })}
                </div>
            )}
            {error && (
                <div className="text-xs p-2 rounded-lg mb-3" style={{ background: 'rgba(239,68,68,.12)', color: 'rgb(220,38,38)' }}>{error}</div>
            )}

            {/* Independent of the recording switch: it changes click stats for
                the whole campaign, so it must never ride along silently. */}
            <label className="flex items-start justify-between gap-3 p-3 rounded-xl border cursor-pointer mb-3" style={{ borderColor: 'var(--color-border)' }}>
                <span>
                    <span className="block font-semibold text-sm text-[var(--color-text-primary)]">{t('replay.sessionContinueLabel')}</span>
                    <span className="block text-xs text-[var(--color-text-muted)]">{t('replay.sessionContinueHint')}</span>
                </span>
                <input type="checkbox" className="w-5 h-5 mt-0.5" checked={sessionContinue} onChange={e => change(setSessionContinue)(e.target.checked)} data-testid="campaign-replay-continue" />
            </label>

            <div className={enabled ? 'space-y-4 mb-1' : 'space-y-4 mb-1 opacity-50 pointer-events-none'}>
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
                <label className="flex items-start justify-between gap-3 p-3 rounded-xl border cursor-pointer" style={{ borderColor: 'var(--color-border)' }}>
                    <span>
                        <span className="block font-semibold text-sm text-[var(--color-text-primary)]">{t('replay.consentLabel')}</span>
                        <span className="block text-xs text-[var(--color-text-muted)]">{t('replay.consentHint')}</span>
                    </span>
                    <input type="checkbox" className="w-5 h-5 mt-0.5" checked={consent} onChange={e => change(setConsent)(e.target.checked)} data-testid="campaign-replay-consent" />
                </label>
                <div className="text-xs p-3 rounded-lg border" style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                    {visits > 0
                        ? t('replay.dailyEstimate', { visits, count: perDay, size: formatBytes(perDay * avg), avg: formatBytes(avg) })
                        : t('replay.dailyEstimateNoTraffic', { avg: formatBytes(avg), size100: formatBytes(Math.round(1000 * samplePct / 100) * avg) })}
                </div>
            </div>

            <div className="flex items-start gap-2 text-xs p-3 rounded-lg mt-3" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                <Cookie size={14} className="flex-shrink-0 mt-0.5" />
                <span>{t('replay.consentCode', { code: 'OrbitraReplay.consent()', revoke: 'OrbitraReplay.revoke()' })}</span>
            </div>
            <div className="flex items-start gap-2 text-xs p-3 rounded-lg" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                <ShieldCheck size={14} className="flex-shrink-0 mt-0.5" />
                <span>{t('replay.privacyHint')}</span>
            </div>

            <div className="flex items-center gap-2 flex-wrap mt-3">
                <span className="text-sm text-[var(--color-text-secondary)]">
                    {t('replay.campaignStats', { count: info?.recordings ?? 0, size: formatBytes(info?.bytes ?? 0) })}
                </span>
                <div className="flex-1" />
                <button type="button" className="btn btn-secondary text-xs flex items-center gap-1.5" onClick={() => setShowHeatmap(true)}>
                    <Flame size={14} /> {t('replay.openHeatmap')}
                </button>
                <button
                    type="button"
                    className="btn btn-secondary text-xs flex items-center gap-1.5"
                    onClick={() => { onNavigateAway?.(); openReplaysInLogs(null, campaignId); }}
                >
                    <PlayCircle size={14} /> {t('replay.openRecordings')}
                </button>
            </div>

            {showHeatmap && (
                <HeatmapModal campaignId={campaignId} campaignName={campaignName} onClose={() => setShowHeatmap(false)} />
            )}
        </div>
    );
};

export default CampaignReplayCard;
