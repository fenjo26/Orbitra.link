import React, { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Check, X, Search, Plus, Trash2, RefreshCw, Edit2, PlayCircle, AlertCircle, Download } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import InfoBanner from './InfoBanner';

const API_URL = '/api.php';

const statusMeta = (t, status) => {
    switch (status) {
        case 'available':
            return { label: t('backorder.statusAvailable'), cls: 'badge badge-success' };
        case 'dns_available':
            return { label: t('backorder.statusDnsAvailable'), cls: 'badge badge-success' };
        case 'registered':
            return { label: t('backorder.statusRegistered'), cls: 'badge bg-[var(--color-bg-soft)] text-[var(--color-text-secondary)] border-[var(--color-border)]' };
        case 'rate_limited':
            return { label: t('backorder.statusRateLimited'), cls: 'badge badge-warning' };
        case 'unsupported':
            return { label: t('backorder.statusUnsupported'), cls: 'badge bg-[var(--color-bg-soft)] text-[var(--color-text-secondary)] border-[var(--color-border)]' };
        case 'error':
            return { label: t('backorder.statusError'), cls: 'badge badge-danger' };
        case 'unknown':
        default:
            return { label: t('backorder.statusUnknown'), cls: 'badge badge-info' };
    }
};

const BackorderDomains = ({ onOpenAutomation = null }) => {
    const { t } = useLanguage();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [selectedIds, setSelectedIds] = useState(new Set());
    const [batchRunning, setBatchRunning] = useState(false);
    const [batchMsg, setBatchMsg] = useState('');
    const [batchTotalChecked, setBatchTotalChecked] = useState(0);
    const [autoRun, setAutoRun] = useState(() => {
        const v = localStorage.getItem('backorder_auto_run');
        return v === null ? true : (v !== '0');
    });
    const stopRef = useRef(false);
    const autoLoopRef = useRef(false);
    const rowsRef = useRef([]);
    const sessionCheckedRef = useRef(0);
    const loadingRef = useRef(true);
    const batchRunningRef = useRef(false);
    const autoRunRef = useRef(true);

    const neverCheckedCount = useMemo(() => {
        return rows.filter(r => !r.last_checked_at).length;
    }, [rows]);

    // Import modal
    const [showImport, setShowImport] = useState(false);
    const [importText, setImportText] = useState('');
    const [importResult, setImportResult] = useState(null);
    const [importError, setImportError] = useState('');

    // Edit modal
    const [showEdit, setShowEdit] = useState(false);
    const [editError, setEditError] = useState('');
    const [editForm, setEditForm] = useState({
        id: null,
        name: '',
        notes: '',
        ahrefs_dr: '',
        ahrefs_ur: '',
        ahrefs_ref_domains: '',
    });

    const fetchRows = async ({ silent = false } = {}) => {
        if (!silent) setLoading(true);
        try {
            const res = await axios.get(`${API_URL}?action=backorder_domains`);
            if (res.data.status === 'success') {
                setRows(res.data.data || []);
            }
        } catch (e) {
            console.error(e);
        } finally {
            if (!silent) setLoading(false);
        }
    };

    useEffect(() => {
        fetchRows();
    }, []);

    useEffect(() => {
        rowsRef.current = rows;
    }, [rows]);

    useEffect(() => {
        loadingRef.current = loading;
    }, [loading]);

    useEffect(() => {
        batchRunningRef.current = batchRunning;
    }, [batchRunning]);

    useEffect(() => {
        autoRunRef.current = autoRun;
    }, [autoRun]);

    const runOneStep = async ({ runStartedAt = 0 } = {}) => {
        const payload = { limit: 1 };
        if (runStartedAt && Number(runStartedAt) > 0) {
            payload.run_started_at = Number(runStartedAt);
        }

        const res = await axios.post(`${API_URL}?action=backorder_check_batch`, payload);
        if (res.data.status !== 'success') {
            const msg = res.data.message || t('common.error');
            throw new Error(msg);
        }

        const data = res.data.data || {};
        const checked = Number(data.checked || 0);
        const neverChecked = data.domains?.never_checked;
        const total = data.domains?.total;
        const dueRemaining = data.domains?.due_remaining;
        const dueTotal = data.domains?.due_total;

        if (checked > 0) {
            sessionCheckedRef.current += checked;
            setBatchTotalChecked(sessionCheckedRef.current);
        }

        setBatchMsg(
            t('backorder.batchProgress')
                .replace('{session_checked}', String(sessionCheckedRef.current))
                .replace('{due_remaining}', String(dueRemaining ?? '-'))
                .replace('{due_total}', String(dueTotal ?? '-'))
                .replace('{never_checked}', String(neverChecked ?? '-'))
                .replace('{total}', String(total ?? '-'))
        );

        // Refresh table without flickering the loading placeholder.
        await fetchRows({ silent: true });

        return { checked, neverChecked, total, dueRemaining, dueTotal };
    };

    const runBatch = async () => {
        if (batchRunning) return;
        setBatchRunning(true);
        sessionCheckedRef.current = 0;
        setBatchTotalChecked(0);
        setBatchMsg(t('backorder.batchStarting'));
        stopRef.current = false;

        try {
            // Manual run: use a fixed cutoff so each domain is checked at most once per run.
            const runStartedAt = Math.floor(Date.now() / 1000);

            // Reliable mode: check 1 domain per request so the UI can keep going without timeouts.
            for (let i = 0; i < 5000; i++) {
                if (stopRef.current) {
                    setBatchMsg(t('backorder.batchStopped'));
                    break;
                }

                const { checked, dueRemaining, dueTotal } = await runOneStep({ runStartedAt });

                if (checked <= 0) {
                    setBatchMsg(t('backorder.batchNothingToDo'));
                    break;
                }
                if (dueTotal === 0 || dueRemaining === 0) {
                    setBatchMsg(t('backorder.batchDone'));
                    break;
                }

                // Small delay so we don't hammer the server.
                await new Promise(r => setTimeout(r, 400));
            }
        } catch (e) {
            console.error(e);
            setBatchMsg(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setBatchRunning(false);
        }
    };

    // Auto-run checks while the page is open (default ON).
    useEffect(() => {
        localStorage.setItem('backorder_auto_run', autoRun ? '1' : '0');
    }, [autoRun]);

    useEffect(() => {
        if (!autoRun) {
            stopRef.current = true;
            return;
        }
        if (autoLoopRef.current) return;

        autoLoopRef.current = true;
        stopRef.current = false;
        sessionCheckedRef.current = 0;
        setBatchTotalChecked(0);
        if (autoRunRef.current) {
            setBatchMsg(t('backorder.autoStarting'));
        }

        let cancelled = false;

        const sleep = (ms) => new Promise(r => setTimeout(r, ms));

        const loop = async () => {
            while (!cancelled) {
                if (!autoRunRef.current) break;
                if (stopRef.current) break;

                // If user started a manual run, let it take over.
                if (batchRunningRef.current) {
                    await sleep(800);
                    continue;
                }
                if (loadingRef.current) {
                    await sleep(800);
                    continue;
                }

                try {
                    const { checked } = await runOneStep();
                    if (checked <= 0) {
                        setBatchMsg(t('backorder.autoIdle'));
                        await sleep(10000);
                        continue;
                    }
                } catch (e) {
                    // If server is busy (lock) or transient error, keep retrying.
                    setBatchMsg(e?.message ? String(e.message) : t('common.networkError'));
                    await sleep(5000);
                }

                await sleep(800);
            }

            autoLoopRef.current = false;
        };

        // Run after a short delay so UI renders first.
        const id = setTimeout(() => { loop(); }, 600);

        return () => {
            cancelled = true;
            clearTimeout(id);
            autoLoopRef.current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoRun]);

    const filtered = useMemo(() => {
        const q = searchTerm.trim().toLowerCase();
        return rows.filter(r => {
            if (q && !String(r.name || '').toLowerCase().includes(q)) return false;
            if (statusFilter !== 'all' && (r.status || 'unknown') !== statusFilter) return false;
            return true;
        });
    }, [rows, searchTerm, statusFilter]);

    const allVisibleSelected = useMemo(() => {
        if (filtered.length === 0) return false;
        return filtered.every(r => selectedIds.has(r.id));
    }, [filtered, selectedIds]);

    const toggleSelectAllVisible = () => {
        const next = new Set(selectedIds);
        if (allVisibleSelected) {
            filtered.forEach(r => next.delete(r.id));
        } else {
            filtered.forEach(r => next.add(r.id));
        }
        setSelectedIds(next);
    };

    const toggleOne = (id) => {
        const next = new Set(selectedIds);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        setSelectedIds(next);
    };

    const deleteOne = async (id) => {
        if (!window.confirm(t('common.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=backorder_delete`, { id });
            setSelectedIds(prev => {
                const n = new Set(prev);
                n.delete(id);
                return n;
            });
            fetchRows();
        } catch (e) {
            console.error(e);
        }
    };

    const deleteSelected = async () => {
        const ids = Array.from(selectedIds);
        if (ids.length === 0) return;
        if (!window.confirm(t('backorder.deleteSelectedConfirm').replace('{count}', String(ids.length)))) return;
        try {
            await axios.post(`${API_URL}?action=backorder_delete_selected`, { ids });
            setSelectedIds(new Set());
            fetchRows();
        } catch (e) {
            console.error(e);
        }
    };

    const openEdit = (row) => {
        setEditError('');
        setEditForm({
            id: row.id,
            name: row.name,
            notes: row.notes || '',
            ahrefs_dr: row.ahrefs_dr ?? '',
            ahrefs_ur: row.ahrefs_ur ?? '',
            ahrefs_ref_domains: row.ahrefs_ref_domains ?? '',
        });
        setShowEdit(true);
    };

    const saveEdit = async (e) => {
        e.preventDefault();
        setEditError('');
        try {
            const payload = {
                id: editForm.id,
                notes: editForm.notes,
                ahrefs_dr: editForm.ahrefs_dr,
                ahrefs_ur: editForm.ahrefs_ur,
                ahrefs_ref_domains: editForm.ahrefs_ref_domains,
            };
            const res = await axios.post(`${API_URL}?action=backorder_update`, payload);
            if (res.data.status === 'success') {
                setShowEdit(false);
                fetchRows();
            } else {
                setEditError(res.data.message || t('common.error'));
            }
        } catch (e2) {
            setEditError(t('common.networkError'));
        }
    };

    const checkNow = async (id) => {
        try {
            await axios.post(`${API_URL}?action=backorder_check_now`, { id });
            fetchRows();
        } catch (e) {
            console.error(e);
        }
    };

    const submitImport = async (e) => {
        e.preventDefault();
        setImportError('');
        setImportResult(null);
        try {
            const res = await axios.post(`${API_URL}?action=backorder_import`, { domains_text: importText });
            if (res.data.status === 'success') {
                setImportResult(res.data.data);
                fetchRows();
            } else {
                setImportError(res.data.message || t('common.error'));
            }
        } catch (e2) {
            setImportError(t('common.networkError'));
        }
    };

    const downloadText = (filename, content, mime = 'text/plain;charset=utf-8') => {
        const blob = new Blob([content], { type: mime });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    const getExportRows = () => {
        // If user selected any rows, export exactly those (across filters).
        // Otherwise, export the currently visible (filtered) list.
        if (selectedIds.size > 0) {
            return (rows || []).filter(r => selectedIds.has(r.id));
        }
        return filtered || [];
    };

    const exportTxt = () => {
        const exportRows = getExportRows();
        const lines = exportRows.map(r => String(r.name || '').trim()).filter(Boolean);
        const content = lines.join('\n') + (lines.length ? '\n' : '');
        downloadText('backorder_domains.txt', content, 'text/plain;charset=utf-8');
    };

    const csvEscape = (v) => {
        const s = v === null || v === undefined ? '' : String(v);
        return `"${s.replace(/\"/g, '""')}"`;
    };

    const exportCsv = () => {
        const exportRows = getExportRows();
        const header = ['domain', 'status', 'last_checked_at', 'last_http_code', 'last_error', 'last_rdap_url', 'notes'];
        const lines = [header.join(',')];
        exportRows.forEach(r => {
            lines.push([
                csvEscape(r.name),
                csvEscape(r.status),
                csvEscape(r.last_checked_at),
                csvEscape(r.last_http_code),
                csvEscape(r.last_error),
                csvEscape(r.last_rdap_url),
                csvEscape(r.notes),
            ].join(','));
        });
        downloadText('backorder_domains.csv', lines.join('\n') + '\n', 'text/csv;charset=utf-8');
    };

    return (
        <div className="page-card mb-6">
            <InfoBanner storageKey="help_backorder" title={t('backorder.bannerTitle')}>
                <p>{t('backorder.bannerText')}</p>
                {typeof onOpenAutomation === 'function' && (
                    <div className="mt-2">
                        <button className="btn btn-secondary" type="button" onClick={onOpenAutomation}>
                            {t('backorder.openAutomation')}
                        </button>
                    </div>
                )}
            </InfoBanner>

            <div className="flex justify-between items-center mb-6">
                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--color-text-secondary)' }} />
                        <input
                            type="text"
                            placeholder={t('backorder.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="form-input w-64"
                            style={{ paddingLeft: '36px' }}
                        />
                    </div>
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="form-select"
                    >
                        <option value="all">{t('common.all')}</option>
                        <option value="available">{t('backorder.statusAvailable')}</option>
                        <option value="dns_available">{t('backorder.statusDnsAvailable')}</option>
                        <option value="unknown">{t('backorder.statusUnknown')}</option>
                        <option value="registered">{t('backorder.statusRegistered')}</option>
                        <option value="rate_limited">{t('backorder.statusRateLimited')}</option>
                        <option value="error">{t('backorder.statusError')}</option>
                        <option value="unsupported">{t('backorder.statusUnsupported')}</option>
                    </select>
                </div>

                <div className="flex items-center gap-2">
                    <button
                        onClick={fetchRows}
                        className="btn btn-secondary flex items-center gap-2"
                        title={t('common.refresh')}
                    >
                        <RefreshCw size={16} /> {t('common.refresh')}
                    </button>

                    <button
                        onClick={runBatch}
                        disabled={batchRunning}
                        className="btn flex items-center gap-2"
                        style={{
                            background: batchRunning ? 'var(--color-bg-soft)' : 'var(--color-success, #10b981)',
                            color: batchRunning ? 'var(--color-text-muted)' : 'white',
                            cursor: batchRunning ? 'not-allowed' : 'pointer'
                        }}
                        title={t('backorder.batchRun')}
                    >
                        <PlayCircle size={16} /> {batchRunning ? t('backorder.batchRunning') : t('backorder.batchRun')}
                    </button>

                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                        <input
                            type="checkbox"
                            checked={autoRun}
                            onChange={(e) => setAutoRun(Boolean(e.target.checked))}
                        />
                        <span style={{ color: 'var(--color-text-primary)' }}>{t('backorder.autoRunLabel')}</span>
                    </label>

                    <button
                        onClick={exportTxt}
                        className="btn btn-secondary"
                        title={t('backorder.exportTxtHint')}
                    >
                        <Download size={16} /> {t('backorder.exportTxt')}
                    </button>

                    <button
                        onClick={exportCsv}
                        className="btn btn-secondary"
                        title={t('backorder.exportCsvHint')}
                    >
                        <Download size={16} /> {t('backorder.exportCsv')}
                    </button>

                    <button
                        onClick={() => {
                            setImportResult(null);
                            setImportError('');
                            setImportText('');
                            setShowImport(true);
                        }}
                        className="btn btn-primary"
                    >
                        <Plus size={16} /> {t('backorder.import')}
                    </button>

                    <button
                        onClick={deleteSelected}
                        disabled={selectedIds.size === 0}
                        className={`btn ${selectedIds.size === 0
                            ? 'bg-[var(--color-bg-soft)] text-[var(--color-text-muted)] cursor-not-allowed'
                            : 'btn-danger'
                            }`}
                    >
                        <Trash2 size={16} /> {t('backorder.deleteSelected')}
                    </button>
                </div>
            </div>

            {batchMsg && (
                <div className="mb-4 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                    <span className="inline-flex items-center gap-2 rounded px-3 py-2 border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                        <AlertCircle size={16} style={{ color: 'var(--color-text-secondary)' }} />
                        <span>{batchMsg}</span>
                        {batchRunning && (
                            <button
                                type="button"
                                onClick={() => { stopRef.current = true; }}
                                className="ml-2 text-xs underline"
                                style={{ color: 'var(--color-text-secondary)' }}
                            >
                                {t('common.cancel')}
                            </button>
                        )}
                    </span>
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th style={{ width: '40px' }}>
                                <input type="checkbox" checked={allVisibleSelected} onChange={toggleSelectAllVisible} />
                            </th>
                            <th>{t('backorder.domain')}</th>
                            <th>{t('backorder.status')}</th>
                            <th>{t('backorder.lastChecked')}</th>
                            <th>{t('backorder.notes')}</th>
                            <th className="text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="6" className="text-center py-8">{t('common.loading')}</td></tr>
                        ) : filtered.length === 0 ? (
                            <tr><td colSpan="6" className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('backorder.noRows')}</td></tr>
                        ) : (
                            filtered.map(r => {
                                const meta = statusMeta(t, r.status || 'unknown');
                                const checked = selectedIds.has(r.id);
                                return (
                                    <tr key={r.id}>
                                        <td>
                                            <input type="checkbox" checked={checked} onChange={() => toggleOne(r.id)} />
                                        </td>
                                        <td className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{r.name}</td>
                                        <td>
                                            <span
                                                className={`inline-flex items-center gap-1 ${meta.cls}`}
                                                title={[
                                                    meta.label,
                                                    r.last_http_code ? `HTTP ${r.last_http_code}` : '',
                                                    r.last_error ? `Error: ${r.last_error}` : '',
                                                    r.last_rdap_url ? `Source: ${r.last_rdap_url}` : '',
                                                ].filter(Boolean).join('\n')}
                                            >
                                                {(r.status === 'available' || r.status === 'dns_available') ? <Check size={14} /> : (r.status === 'registered' ? <X size={14} /> : <AlertCircle size={14} />)}
                                                {meta.label}
                                            </span>
                                        </td>
                                        <td className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                            {r.last_checked_at || <span className="italic" style={{ color: 'var(--color-text-muted)' }}>-</span>}
                                        </td>
                                        <td className="text-xs max-w-[360px] truncate" style={{ color: 'var(--color-text-primary)' }} title={r.notes || ''}>
                                            {r.notes ? r.notes : <span className="italic" style={{ color: 'var(--color-text-muted)' }}>-</span>}
                                        </td>
                                        <td className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button
                                                    onClick={() => checkNow(r.id)}
                                                    className="hover:text-emerald-500 transition"
                                                    style={{ color: 'var(--color-text-muted)' }}
                                                    title={t('backorder.checkNow')}
                                                >
                                                    <PlayCircle size={16} />
                                                </button>
                                                <button
                                                    onClick={() => openEdit(r)}
                                                    className="hover:text-[var(--color-primary)] transition"
                                                    style={{ color: 'var(--color-text-muted)' }}
                                                    title={t('components.edit') || 'Edit'}
                                                >
                                                    <Edit2 size={16} />
                                                </button>
                                                <button
                                                    onClick={() => deleteOne(r.id)}
                                                    className="hover:text-red-500 transition"
                                                    style={{ color: 'var(--color-text-muted)' }}
                                                    title={t('common.delete')}
                                                >
                                                    <Trash2 size={16} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {showImport && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-2xl" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('backorder.importTitle')}</h3>
                            <button className="btn btn-ghost btn-icon" onClick={() => setShowImport(false)}>
                                <X size={18} />
                            </button>
                        </div>

                        {importError && (
                            <div className="alert alert-danger mb-4 flex items-center gap-2">
                                <AlertCircle size={16} /> {importError}
                            </div>
                        )}

                        {importResult && (
                            <div className="alert alert-success mb-4">
                                {t('backorder.importResult')
                                    .replace('{inserted}', String(importResult.inserted || 0))
                                    .replace('{duplicates}', String(importResult.duplicates_ignored || 0))
                                    .replace('{invalid}', String(importResult.invalid || 0))}
                            </div>
                        )}

                        <form onSubmit={submitImport} className="space-y-4">
                            <textarea
                                value={importText}
                                onChange={(e) => setImportText(e.target.value)}
                                rows={10}
                                placeholder={t('backorder.importPlaceholder')}
                                className="form-input w-full font-mono"
                            />
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    onClick={() => setShowImport(false)}
                                    className="btn btn-secondary"
                                >
                                    {t('common.close')}
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                >
                                    {t('backorder.import')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {showEdit && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-lg" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{t('backorder.editTitle')}</h3>
                            <button className="btn btn-ghost btn-icon" onClick={() => setShowEdit(false)}>
                                <X size={18} />
                            </button>
                        </div>

                        <div className="text-sm mb-4" style={{ color: 'var(--color-text-secondary)' }}>
                            <span className="font-mono">{editForm.name}</span>
                        </div>

                        {editError && (
                            <div className="alert alert-danger mb-4 flex items-center gap-2">
                                <AlertCircle size={16} /> {editError}
                            </div>
                        )}

                        <form onSubmit={saveEdit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">{t('backorder.notes')}</label>
                                <textarea
                                    value={editForm.notes}
                                    onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                                    rows={4}
                                    className="form-input w-full"
                                />
                            </div>
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    onClick={() => setShowEdit(false)}
                                    className="btn btn-secondary"
                                >
                                    {t('common.cancel')}
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                >
                                    {t('common.save')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

export default BackorderDomains;
