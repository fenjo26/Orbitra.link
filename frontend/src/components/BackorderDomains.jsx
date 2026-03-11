import React, { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Check, X, Search, Plus, Trash2, RefreshCw, Edit2, PlayCircle, AlertCircle, Download } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import InfoBanner from './InfoBanner';

const API_URL = '/api.php';

const statusMeta = (t, status) => {
    switch (status) {
        case 'available':
            return { label: t('backorder.statusAvailable'), cls: 'text-green-700 bg-green-50 border-green-100' };
        case 'dns_available':
            return { label: t('backorder.statusDnsAvailable'), cls: 'text-lime-700 bg-lime-50 border-lime-100' };
        case 'registered':
            return { label: t('backorder.statusRegistered'), cls: 'text-gray-700 bg-gray-50 border-gray-100' };
        case 'rate_limited':
            return { label: t('backorder.statusRateLimited'), cls: 'text-amber-700 bg-amber-50 border-amber-100' };
        case 'unsupported':
            return { label: t('backorder.statusUnsupported'), cls: 'text-slate-700 bg-slate-50 border-slate-100' };
        case 'error':
            return { label: t('backorder.statusError'), cls: 'text-red-700 bg-red-50 border-red-100' };
        case 'unknown':
        default:
            return { label: t('backorder.statusUnknown'), cls: 'text-blue-700 bg-blue-50 border-blue-100' };
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
        <div className="bg-white rounded shadow-sm p-5 mb-6">
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
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
                        <input
                            type="text"
                            placeholder={t('backorder.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="pl-9 pr-4 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500 w-64"
                        />
                    </div>
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500"
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
                        className="bg-gray-100 hover:bg-gray-200 text-gray-800 px-3 py-2 rounded text-sm font-medium flex items-center gap-2 transition"
                        title={t('common.refresh')}
                    >
                        <RefreshCw size={16} /> {t('common.refresh')}
                    </button>

                    <button
                        onClick={runBatch}
                        disabled={batchRunning}
                        className={`px-3 py-2 rounded text-sm font-medium flex items-center gap-2 transition ${batchRunning
                            ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                            : 'bg-emerald-600 hover:bg-emerald-700 text-white'
                            }`}
                        title={t('backorder.batchRun')}
                    >
                        <PlayCircle size={16} /> {batchRunning ? t('backorder.batchRunning') : t('backorder.batchRun')}
                    </button>

                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border border-gray-200 bg-white">
                        <input
                            type="checkbox"
                            checked={autoRun}
                            onChange={(e) => setAutoRun(Boolean(e.target.checked))}
                        />
                        <span className="text-gray-700">{t('backorder.autoRunLabel')}</span>
                    </label>

                    <button
                        onClick={exportTxt}
                        className="bg-white hover:bg-gray-50 text-gray-800 px-3 py-2 rounded text-sm font-medium flex items-center gap-2 transition border border-gray-200"
                        title={t('backorder.exportTxtHint')}
                    >
                        <Download size={16} /> {t('backorder.exportTxt')}
                    </button>

                    <button
                        onClick={exportCsv}
                        className="bg-white hover:bg-gray-50 text-gray-800 px-3 py-2 rounded text-sm font-medium flex items-center gap-2 transition border border-gray-200"
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
                        className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition"
                    >
                        <Plus size={16} /> {t('backorder.import')}
                    </button>

                    <button
                        onClick={deleteSelected}
                        disabled={selectedIds.size === 0}
                        className={`px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition ${selectedIds.size === 0
                            ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                            : 'bg-red-600 hover:bg-red-700 text-white'
                            }`}
                    >
                        <Trash2 size={16} /> {t('backorder.deleteSelected')}
                    </button>
                </div>
            </div>

            {batchMsg && (
                <div className="mb-4 text-sm text-gray-700">
                    <span className="inline-flex items-center gap-2 bg-gray-50 border border-gray-100 rounded px-3 py-2">
                        <AlertCircle size={16} className="text-gray-400" />
                        <span>{batchMsg}</span>
                        {batchRunning && (
                            <button
                                type="button"
                                onClick={() => { stopRef.current = true; }}
                                className="ml-2 text-xs text-gray-600 underline"
                            >
                                {t('common.cancel')}
                            </button>
                        )}
                    </span>
                </div>
            )}

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                    <thead className="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th className="px-4 py-3 w-10">
                                <input type="checkbox" checked={allVisibleSelected} onChange={toggleSelectAllVisible} />
                            </th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('backorder.domain')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('backorder.status')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('backorder.lastChecked')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('backorder.notes')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600 text-right">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {loading ? (
                            <tr><td colSpan="6" className="text-center py-8">{t('common.loading')}</td></tr>
                        ) : filtered.length === 0 ? (
                            <tr><td colSpan="6" className="text-center py-8 text-gray-500">{t('backorder.noRows')}</td></tr>
                        ) : (
                            filtered.map(r => {
                                const meta = statusMeta(t, r.status || 'unknown');
                                const checked = selectedIds.has(r.id);
                                return (
                                    <tr key={r.id} className="hover:bg-gray-50 transition">
                                        <td className="px-4 py-3">
                                            <input type="checkbox" checked={checked} onChange={() => toggleOne(r.id)} />
                                        </td>
                                        <td className="px-5 py-3 font-medium text-gray-800">{r.name}</td>
                                        <td className="px-5 py-3">
                                            <span
                                                className={`inline-flex items-center gap-1 text-sm font-medium px-2 py-1 rounded border ${meta.cls}`}
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
                                        <td className="px-5 py-3 text-gray-600 text-xs">
                                            {r.last_checked_at || <span className="text-gray-400 italic">-</span>}
                                        </td>
                                        <td className="px-5 py-3 text-gray-700 text-xs max-w-[360px] truncate" title={r.notes || ''}>
                                            {r.notes ? r.notes : <span className="text-gray-400 italic">-</span>}
                                        </td>
                                        <td className="px-5 py-3 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button
                                                    onClick={() => checkNow(r.id)}
                                                    className="p-1.5 text-gray-400 hover:text-emerald-700 hover:bg-emerald-50 rounded transition"
                                                    title={t('backorder.checkNow')}
                                                >
                                                    <PlayCircle size={16} />
                                                </button>
                                                <button
                                                    onClick={() => openEdit(r)}
                                                    className="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded transition"
                                                    title={t('components.edit') || 'Edit'}
                                                >
                                                    <Edit2 size={16} />
                                                </button>
                                                <button
                                                    onClick={() => deleteOne(r.id)}
                                                    className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition"
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
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl p-6">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-lg font-bold">{t('backorder.importTitle')}</h3>
                            <button className="text-gray-400 hover:text-gray-700" onClick={() => setShowImport(false)}>
                                <X size={18} />
                            </button>
                        </div>

                        {importError && (
                            <div className="bg-red-50 text-red-600 p-3 rounded text-sm mb-4 flex items-center gap-2">
                                <AlertCircle size={16} /> {importError}
                            </div>
                        )}

                        {importResult && (
                            <div className="bg-green-50 text-green-700 p-3 rounded text-sm mb-4">
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
                                className="w-full border border-gray-300 p-3 rounded text-sm focus:ring-2 focus:ring-blue-500 outline-none font-mono"
                            />
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowImport(false)}
                                    className="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded text-sm font-medium transition"
                                >
                                    {t('common.close')}
                                </button>
                                <button
                                    type="submit"
                                    className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition"
                                >
                                    {t('backorder.import')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {showEdit && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-lg p-6">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-lg font-bold">{t('backorder.editTitle')}</h3>
                            <button className="text-gray-400 hover:text-gray-700" onClick={() => setShowEdit(false)}>
                                <X size={18} />
                            </button>
                        </div>

                        <div className="text-sm text-gray-600 mb-4">
                            <span className="font-mono">{editForm.name}</span>
                        </div>

                        {editError && (
                            <div className="bg-red-50 text-red-600 p-3 rounded text-sm mb-4 flex items-center gap-2">
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
                                    className="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm"
                                />
                            </div>
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowEdit(false)}
                                    className="bg-gray-100 hover:bg-gray-200 text-gray-800 px-4 py-2 rounded text-sm font-medium transition"
                                >
                                    {t('common.cancel')}
                                </button>
                                <button
                                    type="submit"
                                    className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium transition"
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
