import React, { useEffect, useMemo, useState } from 'react';
import { Clock, Copy, RefreshCw, Save, AlertCircle, CheckCircle2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const formatAge = (t, seconds) => {
    if (seconds === null || seconds === undefined) return '-';
    const s = Math.max(0, Number(seconds) || 0);
    const days = Math.floor(s / 86400);
    const hours = Math.floor((s % 86400) / 3600);
    const mins = Math.floor((s % 3600) / 60);
    if (days > 0) return `${days} ${t('automation.days')} ${hours} ${t('automation.hours')}`;
    if (hours > 0) return `${hours} ${t('automation.hours')} ${mins} ${t('automation.minutes')}`;
    return `${mins} ${t('automation.minutes')}`;
};

const AutomationSettings = () => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [cronBusy, setCronBusy] = useState(false);
    const [message, setMessage] = useState({ text: '', type: '' });

    const [info, setInfo] = useState(null);
    const [enabled, setEnabled] = useState(true);
    const [intervalMin, setIntervalMin] = useState(15);

    const fetchInfo = async () => {
        setLoading(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=backorder_cron_info`);
            const data = await res.json();
            if (data.status === 'success') {
                setInfo(data.data || null);
                setEnabled((data.data?.enabled ?? '1') !== '0');
                const sec = Number(data.data?.check_interval_sec ?? 900) || 900;
                setIntervalMin(Math.max(1, Math.round(sec / 60)));
            } else {
                setMessage({ text: data.message || t('automation.loadError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchInfo();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const cronCmd = useMemo(() => {
        const examples = info?.cron_examples || [];
        const preferred = examples.find(x => x.id === 'every_3_min') || examples[0];
        return preferred?.value || '';
    }, [info]);

    const copyText = async (text) => {
        if (!text) return;
        try {
            await navigator.clipboard.writeText(text);
            setMessage({ text: t('automation.copied'), type: 'success' });
            setTimeout(() => setMessage({ text: '', type: '' }), 1500);
        } catch (e) {
            setMessage({ text: t('automation.copyError'), type: 'error' });
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage({ text: '', type: '' });
        try {
            const cleanMin = Math.max(1, Math.min(1440, Number(intervalMin) || 15));
            const intervalSec = String(Math.max(15, Math.round(cleanMin * 60)));

            const res = await fetch(`${API_URL}?action=save_settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    backorder_cron_enabled: enabled ? '1' : '0',
                    backorder_check_interval_sec: intervalSec,
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('automation.saveSuccess'), type: 'success' });
                await fetchInfo();
            } else {
                setMessage({ text: data.message || t('automation.saveError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setSaving(false);
        }
    };

    const installCron = async () => {
        setCronBusy(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=backorder_install_cron`, { method: 'POST' });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('automation.installSuccess'), type: 'success' });
                await fetchInfo();
            } else {
                setMessage({ text: data.message || t('automation.installError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setCronBusy(false);
        }
    };

    const removeCron = async () => {
        setCronBusy(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=backorder_remove_cron`, { method: 'POST' });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('automation.removeSuccess'), type: 'success' });
                await fetchInfo();
            } else {
                setMessage({ text: data.message || t('automation.removeError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setCronBusy(false);
        }
    };

    const installUserCron = async () => {
        setCronBusy(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=backorder_install_user_cron`, { method: 'POST' });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('automation.installUserSuccess'), type: 'success' });
                await fetchInfo();
            } else {
                setMessage({ text: data.message || t('automation.installUserError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setCronBusy(false);
        }
    };

    const removeUserCron = async () => {
        setCronBusy(true);
        setMessage({ text: '', type: '' });
        try {
            const res = await fetch(`${API_URL}?action=backorder_remove_user_cron`, { method: 'POST' });
            const data = await res.json();
            if (data.status === 'success') {
                setMessage({ text: t('automation.removeUserSuccess'), type: 'success' });
                await fetchInfo();
            } else {
                setMessage({ text: data.message || t('automation.removeUserError'), type: 'error' });
            }
        } catch (e) {
            setMessage({ text: t('automation.networkError'), type: 'error' });
        } finally {
            setCronBusy(false);
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <p className="text-[var(--color-text-muted)]">{t('common.loading')}</p>
            </div>
        );
    }

    const bootstrap = info?.rdap_bootstrap || {};
    const bootstrapOk = Boolean(bootstrap?.mtime);
    const bootstrapAge = formatAge(t, bootstrap?.age_seconds);
    const cronInstalled = Boolean(info?.cron_file_exists);
    const cronDirWritable = Boolean(info?.cron_dir_writable);
    const cronFile = info?.cron_file || '/etc/cron.d/orbitra-backorder';
    const phpUser = info?.php_user || 'www-data';
    const shellExecAllowed = Boolean(info?.shell_exec_allowed);
    const crontabPath = info?.crontab_path;
    const userCrontabInstalled = Boolean(info?.user_crontab_installed);
    const intervalSecNow = Number(info?.check_interval_sec ?? 900) || 900;
    const intervalHuman = formatAge(t, intervalSecNow);

    const cronFileInstallCmd = useMemo(() => {
        const script = info?.script_path || 'backorder_cron.php';
        const log = info?.log_path || '/var/log/orbitra_backorder.log';
        const line = `*/3 * * * * ${phpUser} php ${script} >> ${log} 2>&1`;
        return [
            `sudo tee ${cronFile} > /dev/null <<'EOF'`,
            `# Orbitra backorder checks`,
            line,
            `EOF`
        ].join('\n');
    }, [info, phpUser, cronFile]);

    const cronFileRemoveCmd = useMemo(() => {
        return `sudo rm -f ${cronFile}`;
    }, [cronFile]);

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                <div className="flex items-center gap-2">
                    <Clock size={18} className="text-[var(--color-primary)]" />
                    <h3 className="page-title m-0">{t('automation.title')}</h3>
                </div>
            </div>

            <div className="mt-6" style={{ maxWidth: '760px' }}>
                {message.text && (
                    <div className={`alert ${message.type === 'success' ? 'alert-success' : 'alert-danger'} mb-4`}>
                        <div className="flex items-center gap-2">
                            {message.type === 'success' ? <CheckCircle2 size={16} /> : <AlertCircle size={16} />}
                            <span>{String(message.text)}</span>
                        </div>
                    </div>
                )}

                <div className="form-section">
                    <div className="mb-3">
                        <div className="text-sm font-semibold text-gray-800">{t('automation.backorderCronTitle')}</div>
                        <div className="text-sm text-[var(--color-text-muted)] mt-1">{t('automation.backorderCronDesc')}</div>
                    </div>

                    <label className="form-checkbox-label">
                        <input
                            type="checkbox"
                            checked={enabled}
                            onChange={(e) => setEnabled(Boolean(e.target.checked))}
                        />
                        <div className="form-checkbox-content">
                            <span className="form-checkbox-title">{t('automation.enableBackorderCron')}</span>
                            <p className="form-checkbox-description">{t('automation.enableBackorderCronDesc')}</p>
                        </div>
                    </label>

                    <div className="mt-4 bg-white border border-gray-100 rounded p-3">
                        <div className="text-sm font-semibold text-gray-800">{t('automation.intervalTitle')}</div>
                        <div className="text-sm text-[var(--color-text-muted)] mt-1">
                            {t('automation.intervalDesc').replace('{interval}', String(intervalHuman))}
                        </div>

                        <div className="mt-3 flex flex-col gap-2">
                            <label className="form-label m-0">{t('automation.intervalLabel')}</label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="1"
                                    max="1440"
                                    value={intervalMin}
                                    onChange={(e) => setIntervalMin(Number(e.target.value))}
                                    className="input"
                                    style={{ width: '140px' }}
                                />
                                <div className="text-sm text-gray-600">{t('automation.minutes')}</div>
                                <div className="text-xs text-gray-500">
                                    {t('automation.intervalExample')}
                                </div>
                            </div>

                            <div className="flex gap-2 flex-wrap">
                                <button className="btn btn-secondary" type="button" onClick={() => setIntervalMin(1)}>
                                    {t('automation.intervalPreset1m')}
                                </button>
                                <button className="btn btn-secondary" type="button" onClick={() => setIntervalMin(5)}>
                                    {t('automation.intervalPreset5m')}
                                </button>
                                <button className="btn btn-secondary" type="button" onClick={() => setIntervalMin(15)}>
                                    {t('automation.intervalPreset15m')}
                                </button>
                                <button className="btn btn-secondary" type="button" onClick={() => setIntervalMin(60)}>
                                    {t('automation.intervalPreset60m')}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="mt-3">
                        <label className="form-label">{t('automation.cronCommand')}</label>
                        <div className="flex gap-2 items-stretch">
                            <pre
                                className="flex-1 bg-gray-50 border border-gray-200 rounded px-3 py-2 overflow-x-auto"
                                style={{ fontFamily: 'monospace', fontSize: '12px', lineHeight: 1.5, margin: 0 }}
                            >
                                {String(cronCmd || t('automation.noCronExample'))}
                            </pre>
                            <button
                                className="btn btn-secondary"
                                onClick={() => copyText(cronCmd)}
                                disabled={!cronCmd}
                                title={t('automation.copy')}
                                style={{ whiteSpace: 'nowrap' }}
                            >
                                <Copy size={16} />
                                {t('automation.copy')}
                            </button>
                        </div>
                        <p className="form-hint mt-2">{t('automation.cronHint')}</p>
                    </div>

                    <div className="mt-4 bg-white border border-gray-100 rounded p-3">
                        <div className="text-sm font-semibold text-gray-800">{t('automation.cronFileTitle')}</div>
                        <div className="text-sm text-[var(--color-text-muted)] mt-1">{t('automation.cronFileDesc')}</div>

                        <div className="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.cronFile')}</div>
                                <div className="text-sm font-mono text-gray-800 mt-1">{String(cronFile)}</div>
                            </div>
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.cronInstalled')}</div>
                                <div className="text-sm font-semibold text-gray-800 mt-1">
                                    {cronInstalled ? t('automation.yes') : t('automation.no')}
                                </div>
                            </div>
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.cronWritable')}</div>
                                <div className="text-sm font-semibold text-gray-800 mt-1">
                                    {cronDirWritable ? t('automation.yes') : t('automation.no')}
                                </div>
                                {!cronDirWritable && (
                                    <div className="text-xs text-gray-500 mt-1">{t('automation.rootRequired')}</div>
                                )}
                            </div>
                        </div>

                        <div className="mt-3 flex gap-2 flex-wrap">
                            <button
                                onClick={installCron}
                                className="btn btn-primary"
                                disabled={cronBusy}
                                title={t('automation.installCron')}
                            >
                                {t('automation.installCron')}
                            </button>
                            <button
                                onClick={removeCron}
                                className="btn btn-secondary"
                                disabled={cronBusy}
                                title={t('automation.removeCron')}
                            >
                                {t('automation.removeCron')}
                            </button>
                            <div className="text-xs text-gray-500 self-center">
                                {t('automation.cronUserHint').replace('{user}', String(phpUser))}
                            </div>
                        </div>

                        <div className="mt-3">
                            <div className="text-xs text-gray-500">{t('automation.rootCommandsHint')}</div>
                            <div className="mt-2">
                                <div className="text-xs text-gray-500 mb-1">{t('automation.installCommand')}</div>
                                <div className="flex gap-2 items-stretch">
                                    <pre
                                        className="flex-1 bg-gray-50 border border-gray-200 rounded px-3 py-2 overflow-x-auto"
                                        style={{ fontFamily: 'monospace', fontSize: '12px', lineHeight: 1.5, margin: 0 }}
                                    >
                                        {String(cronFileInstallCmd)}
                                    </pre>
                                    <button className="btn btn-secondary" onClick={() => copyText(cronFileInstallCmd)} title={t('automation.copy')}>
                                        <Copy size={16} />
                                        {t('automation.copy')}
                                    </button>
                                </div>
                            </div>

                            <div className="mt-2">
                                <div className="text-xs text-gray-500 mb-1">{t('automation.removeCommand')}</div>
                                <div className="flex gap-2 items-stretch">
                                    <pre
                                        className="flex-1 bg-gray-50 border border-gray-200 rounded px-3 py-2 overflow-x-auto"
                                        style={{ fontFamily: 'monospace', fontSize: '12px', lineHeight: 1.5, margin: 0 }}
                                    >
                                        {String(cronFileRemoveCmd)}
                                    </pre>
                                    <button className="btn btn-secondary" onClick={() => copyText(cronFileRemoveCmd)} title={t('automation.copy')}>
                                        <Copy size={16} />
                                        {t('automation.copy')}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 bg-white border border-gray-100 rounded p-3">
                        <div className="text-sm font-semibold text-gray-800">{t('automation.userCronTitle')}</div>
                        <div className="text-sm text-[var(--color-text-muted)] mt-1">{t('automation.userCronDesc')}</div>

                        <div className="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.shellExec')}</div>
                                <div className="text-sm font-semibold text-gray-800 mt-1">
                                    {shellExecAllowed ? t('automation.yes') : t('automation.no')}
                                </div>
                            </div>
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.crontab')}</div>
                                <div className="text-sm font-mono text-gray-800 mt-1">{String(crontabPath || '-')}</div>
                            </div>
                            <div className="bg-gray-50 border border-gray-100 rounded p-3">
                                <div className="text-xs text-gray-500">{t('automation.userCronInstalled')}</div>
                                <div className="text-sm font-semibold text-gray-800 mt-1">
                                    {userCrontabInstalled ? t('automation.yes') : t('automation.no')}
                                </div>
                            </div>
                        </div>

                        <div className="mt-3 flex gap-2 flex-wrap">
                            <button
                                onClick={installUserCron}
                                className="btn btn-primary"
                                disabled={cronBusy || !shellExecAllowed || !crontabPath}
                                title={t('automation.installUserCron')}
                            >
                                {t('automation.installUserCron')}
                            </button>
                            <button
                                onClick={removeUserCron}
                                className="btn btn-secondary"
                                disabled={cronBusy || !shellExecAllowed || !crontabPath}
                                title={t('automation.removeUserCron')}
                            >
                                {t('automation.removeUserCron')}
                            </button>
                        </div>
                    </div>

                    <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div className="bg-gray-50 border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.lastPing')}</div>
                            <div className="text-sm font-mono text-gray-800 mt-1">{String(info?.last_ping_at || '-')}</div>
                        </div>
                        <div className="bg-gray-50 border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.lastChecked')}</div>
                            <div className="text-sm font-mono text-gray-800 mt-1">{String(info?.last_checked_at || '-')}</div>
                        </div>
                        <div className="bg-gray-50 border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.lastDomain')}</div>
                            <div className="text-sm font-mono text-gray-800 mt-1">{String(info?.last_domain || '-')}</div>
                        </div>
                        <div className="bg-gray-50 border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.lastResult')}</div>
                            <div className="text-sm font-mono text-gray-800 mt-1">
                                {info?.last_status ? `${String(info.last_status)} (HTTP ${String(info?.last_http_code || 0)})` : '-'}
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div className="bg-white border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.domainsTotal')}</div>
                            <div className="text-lg font-semibold text-gray-800 mt-1">{String(info?.domains?.total ?? 0)}</div>
                        </div>
                        <div className="bg-white border border-gray-100 rounded p-3">
                            <div className="text-xs text-gray-500">{t('automation.domainsNeverChecked')}</div>
                            <div className="text-lg font-semibold text-gray-800 mt-1">{String(info?.domains?.never_checked ?? 0)}</div>
                        </div>
                    </div>

                    <div className="mt-4 bg-slate-50 border border-slate-100 rounded p-3">
                        <div className="text-sm font-semibold text-slate-800">{t('automation.rdapTitle')}</div>
                        <div className="text-sm text-slate-700 mt-1">
                            {bootstrapOk ? (
                                <span>
                                    {t('automation.rdapBootstrapOk').replace('{mtime}', String(bootstrap.mtime)).replace('{age}', String(bootstrapAge))}
                                </span>
                            ) : (
                                <span>{t('automation.rdapBootstrapMissing')}</span>
                            )}
                        </div>
                        <div className="text-xs text-slate-600 mt-2">{t('automation.rdapHint')}</div>
                    </div>
                </div>
            </div>

            <div className="mt-6 flex justify-end gap-2">
                <button onClick={fetchInfo} className="btn btn-secondary" disabled={loading}>
                    <RefreshCw size={18} />
                    {t('automation.refresh')}
                </button>
                <button onClick={handleSave} disabled={saving} className="btn btn-primary">
                    <Save size={18} />
                    {saving ? t('common.saving') : t('common.save')}
                </button>
            </div>
        </div>
    );
};

export default AutomationSettings;
