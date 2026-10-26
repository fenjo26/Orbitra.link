import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { HardDrive, Save, Check, AlertTriangle } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { API_URL, formatBytes } from './replayUtils';

const RETENTION_OPTIONS = [1, 3, 7, 14, 30];
const SIZE_OPTIONS_MB = [256, 512, 1024, 2048, 5120, 10240, 20480];

/**
 * Storage limits for ALL recordings — one disk, one budget. Shown both in the
 * landing editor's recording tab and in Logs → Session recordings, so the
 * operator meets the limits wherever they switch recording on.
 *
 *   - keep N days → older recordings are deleted automatically;
 *   - at most X on disk → when full, the oldest recordings are overwritten.
 */
const ReplayStorageCard = ({ storage: initial, onChange, compact = false }) => {
    const { t } = useLanguage();
    const [storage, setStorage] = useState(initial || null);
    const [days, setDays] = useState(initial?.retention_days ?? 3);
    const [mb, setMb] = useState(initial?.max_mb ?? 1024);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        if (initial) {
            setStorage(initial);
            setDays(initial.retention_days);
            setMb(initial.max_mb);
            return;
        }
        axios.get(`${API_URL}?action=replay_storage`).then(res => {
            if (res.data?.status === 'success') {
                setStorage(res.data.data);
                setDays(res.data.data.retention_days);
                setMb(res.data.data.max_mb);
            }
        }).catch(() => {});
    }, [initial]);

    const dirty = storage && (Number(days) !== Number(storage.retention_days) || Number(mb) !== Number(storage.max_mb));

    const save = async () => {
        setSaving(true);
        try {
            const res = await axios.post(`${API_URL}?action=replay_storage_save`, { retention_days: Number(days), max_mb: Number(mb) });
            if (res.data?.status === 'success') {
                setStorage(res.data.data);
                onChange?.(res.data.data);
                setSaved(true);
                setTimeout(() => setSaved(false), 1500);
            }
        } finally {
            setSaving(false);
        }
    };

    if (!storage) return null;

    const capBytes = Number(mb) * 1024 * 1024;
    const usedPct = capBytes > 0 ? Math.min(100, Math.round((storage.used_bytes / capBytes) * 100)) : 0;
    const fits = storage.avg_bytes > 0 ? Math.floor(capBytes / storage.avg_bytes) : null;
    const sizeOptions = SIZE_OPTIONS_MB.includes(Number(mb)) ? SIZE_OPTIONS_MB : [...SIZE_OPTIONS_MB, Number(mb)].sort((a, b) => a - b);
    const diskFree = storage.disk_free_bytes;
    const capOverDisk = diskFree !== null && diskFree !== undefined && capBytes > storage.used_bytes + diskFree - storage.min_free_bytes;

    return (
        <div className="rounded-xl border p-4 space-y-3" style={{ borderColor: 'var(--color-border)', background: 'var(--color-bg-soft)' }}>
            <div className="flex items-center justify-between gap-2 flex-wrap">
                <div className="flex items-center gap-2 font-semibold text-sm text-[var(--color-text-primary)]">
                    <HardDrive size={16} /> {t('replay.storageTitle')}
                </div>
                <div className="text-xs text-[var(--color-text-secondary)]">
                    {t('replay.storageUsed', { used: formatBytes(storage.used_bytes), cap: formatBytes(capBytes), count: storage.count })}
                </div>
            </div>
            <div className="h-2 rounded-full overflow-hidden" style={{ background: 'var(--color-border)' }}>
                <div className="h-full rounded-full" style={{ width: `${usedPct}%`, background: usedPct > 90 ? 'rgb(239,68,68)' : 'var(--color-primary)' }} />
            </div>
            <div className={`grid gap-3 ${compact ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 md:grid-cols-2'}`}>
                <div>
                    <label className="form-label">{t('replay.retentionLabel')}</label>
                    <select className="form-select" value={days} onChange={e => setDays(Number(e.target.value))}>
                        {(RETENTION_OPTIONS.includes(Number(days)) ? RETENTION_OPTIONS : [...RETENTION_OPTIONS, Number(days)].sort((a, b) => a - b))
                            .map(d => <option key={d} value={d}>{t('replay.retentionDays', { days: d })}</option>)}
                    </select>
                </div>
                <div>
                    <label className="form-label">{t('replay.maxSizeLabel')}</label>
                    <select className="form-select" value={mb} onChange={e => setMb(Number(e.target.value))}>
                        {sizeOptions.map(v => <option key={v} value={v}>{formatBytes(v * 1024 * 1024)}</option>)}
                    </select>
                </div>
            </div>
            <p className="text-xs m-0 text-[var(--color-text-muted)]">
                {t(storage.avg_is_estimate ? 'replay.sizeEstimateInitial' : 'replay.sizeEstimate', { avg: formatBytes(storage.avg_bytes), fits: fits ?? '—' })}
                {' '}{t('replay.overwriteHint')}
            </p>
            {diskFree !== null && diskFree !== undefined && (
                <p className="text-xs m-0 text-[var(--color-text-muted)]">{t('replay.diskFree', { free: formatBytes(diskFree) })}</p>
            )}
            {(storage.disk_low || capOverDisk) && (
                <div className="flex items-start gap-2 text-xs p-2 rounded-lg" style={{ background: 'rgba(245,158,11,.12)', color: 'rgb(180,83,9)' }}>
                    <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
                    <span>{storage.disk_low ? t('replay.diskLow', { min: formatBytes(storage.min_free_bytes) }) : t('replay.capOverDisk')}</span>
                </div>
            )}
            {dirty && (
                <div className="flex justify-end">
                    <button type="button" className="btn btn-primary text-xs flex items-center gap-1.5" onClick={save} disabled={saving}>
                        {saved ? <Check size={14} /> : <Save size={14} />} {t('replay.saveLimits')}
                    </button>
                </div>
            )}
        </div>
    );
};

export default ReplayStorageCard;
