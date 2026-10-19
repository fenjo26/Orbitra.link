import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import {
    AlertTriangle, Eye, EyeOff, PauseCircle, Play, Plus, RefreshCw, Search, Trash2, Pencil, X
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Ready-made rule templates (stage 3.2): multi-language dictionaries —
// Orbitra's languages plus Greek and Portuguese for the operator's own geos.
const PRESETS = {
    links: {
        block_links: true, block_contacts: true,
        stopwords: ['scam', 'fake', 'мошенники', 'απάτη', 'golpe', 'link', 'ссылка', 'σύνδεσμος']
            .join('\n'),
    },
    dm: {
        block_contacts: true,
        stopwords: ['dm me', 'text me', 'пиши мне', 'напиши в лс', 'στείλε μου', 'me chama', 'whatsapp', 'telegram', 'viber'].join('\n'),
    },
    scam: {
        whole_word: true,
        stopwords: ['scam', 'cheaters', 'rigged', 'fraud', 'мошенники', 'кидалово', 'развод', 'κλέφτες', 'απάτη', 'golpe', 'fraude', 'golpista'].join('\n'),
    },
    junk: {
        emoji_only: true, char_repeats: true, min_length: 3,
        stopwords: ['👍', 'first', '1st', 'привет', 'γεια', 'oi'].join('\n'),
    },
};

const EMPTY_FORM = {
    name: '', connection_ids: [], action_mode: 'hide', dry_run: true, mode: 'rules',
    include_organic: false, block_links: false, links_whitelist: '', block_contacts: false,
    emoji_only: false, char_repeats: false, min_length: 0, whole_word: false,
    stopwords: '', regexes: '', exceptions: '', state: 'active',
};

// The Comments tab of the aggregator page (stage 3): rule sets for the FB
// comment cleaner, the action journal, and the pause/worker banners. Dry-run
// is the default; the first turn-off shows the 24h "would have hidden" count.
const CommentCleanerPage = ({ user }) => {
    const { t } = useLanguage();
    const isAdmin = (user?.role ?? '') === 'admin';

    const [rulesets, setRulesets] = useState([]);
    const [logRows, setLogRows] = useState([]);
    const [logStats, setLogStats] = useState({ dry_24h: 0, done_24h: 0, errors_24h: 0 });
    const [status, setStatus] = useState(null);
    const [connections, setConnections] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);

    const [testText, setTestText] = useState('');
    const [testResult, setTestResult] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const [rs, log, st, conns] = await Promise.all([
                axios.get(API_URL, { params: { action: 'comment_rulesets' } }),
                axios.get(API_URL, { params: { action: 'comment_log', limit: 100 } }),
                axios.get(API_URL, { params: { action: 'comment_cleaner_status' } }),
                axios.get(API_URL, { params: { action: 'aggregator_connections' } }),
            ]);
            setRulesets(rs.data?.data || []);
            setLogRows(log.data?.data || []);
            setLogStats(log.data?.stats || {});
            setStatus(st.data?.data || null);
            setConnections((conns.data?.data || []).filter(c => c.engine === 'facebook' || c.engine === 'facebook_ads'));
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const openForm = (row = null) => {
        setEditingId(row ? row.id : null);
        setTestResult(null);
        setTestText('');
        if (row) {
            setForm({
                name: row.name, connection_ids: row.connection_ids || [], action_mode: row.action,
                dry_run: Number(row.dry_run) === 1, mode: row.mode, include_organic: Number(row.include_organic) === 1,
                block_links: Number(row.block_links) === 1, links_whitelist: row.links_whitelist || '',
                block_contacts: Number(row.block_contacts) === 1, emoji_only: Number(row.emoji_only) === 1,
                char_repeats: Number(row.char_repeats) === 1, min_length: Number(row.min_length) || 0,
                whole_word: Number(row.whole_word) === 1, stopwords: row.stopwords || '',
                regexes: row.regexes || '', exceptions: row.exceptions || '', state: row.state || 'active',
            });
        } else {
            setForm(EMPTY_FORM);
        }
        setShowForm(true);
    };

    const save = async () => {
        try {
            const res = await axios.post(API_URL, { ...form, id: editingId }, { params: { action: 'comment_rulesets' } });
            if (res.data?.status !== 'success') {
                setError(res.data?.message || t('cc.errSave', 'Save failed'));
                return;
            }
            setShowForm(false);
            load();
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        }
    };

    const remove = async (id) => {
        if (!confirm(t('cc.confirmDelete'))) return;
        await axios.post(API_URL, { action: 'delete', id }, { params: { action: 'comment_rulesets' } });
        load();
    };

    const applyPreset = (key) => {
        const preset = PRESETS[key];
        setForm(prev => ({
            ...prev,
            block_links: preset.block_links ?? prev.block_links,
            block_contacts: preset.block_contacts ?? prev.block_contacts,
            emoji_only: preset.emoji_only ?? prev.emoji_only,
            char_repeats: preset.char_repeats ?? prev.char_repeats,
            min_length: preset.min_length ?? prev.min_length,
            whole_word: preset.whole_word ?? prev.whole_word,
            stopwords: prev.stopwords ? prev.stopwords + '\n' + preset.stopwords : preset.stopwords,
        }));
    };

    const runTest = async () => {
        if (!editingId || !testText.trim()) return;
        try {
            const res = await axios.post(API_URL, { ruleset_id: editingId, text: testText }, { params: { action: 'comment_ruleset_test' } });
            setTestResult(res.data?.data?.matched ?? false);
        } catch (err) {
            setTestResult('error: ' + (err?.response?.data?.message || err.message));
        }
    };

    const logAction = async (logId, op) => {
        try {
            const res = await axios.post(API_URL, { log_id: logId, op }, { params: { action: 'comment_log_action' } });
            if (res.data?.status !== 'success') {
                setError(res.data?.message || 'Graph call failed');
                return;
            }
            load();
        } catch (err) {
            setError(err?.response?.data?.message || err.message);
        }
    };

    const resume = async () => {
        await axios.post(API_URL, {}, { params: { action: 'comment_cleaner_resume' } });
        load();
    };

    const workerStalled = status && status.due_posts > 0
        && (!status.last_run || (Date.now() - new Date(String(status.last_run).replace(' ', 'T') + 'Z').getTime()) > 30 * 60 * 1000);

    const connName = (id) => (connections.find(c => Number(c.id) === Number(id))?.name) || `#${id}`;
    const check = (key, labelText) => (
        <label className="flex items-center gap-2 text-sm cursor-pointer" style={{ color: 'var(--color-text-primary)' }}>
            <input type="checkbox" checked={!!form[key]} onChange={e => setForm({ ...form, [key]: e.target.checked })} />
            {labelText}
        </label>
    );

    return (
        <div>
            {/* Pause banner */}
            {status?.paused && (
                <div className="mb-3 flex items-center gap-3 rounded-xl border px-4 py-2.5 text-sm flex-wrap"
                     style={{ borderColor: 'color-mix(in srgb, var(--color-danger) 40%, transparent)', backgroundColor: 'color-mix(in srgb, var(--color-danger) 8%, transparent)' }}>
                    <PauseCircle className="h-4 w-4" style={{ color: 'var(--color-danger)' }} />
                    <span>{t('cc.pausedPrefix')} <b>{status.paused}</b></span>
                    <button type="button" className="btn btn-sm btn-primary ml-auto" onClick={resume}>
                        <Play className="h-3.5 w-3.5" /> {t('cc.resume')}
                    </button>
                </div>
            )}
            {/* Worker banner */}
            {workerStalled && (
                <div className="mb-3 flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm"
                     style={{ borderColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 40%, transparent)', backgroundColor: 'color-mix(in srgb, var(--color-warning, #f59e0b) 8%, transparent)' }}>
                    <AlertTriangle className="h-4 w-4" style={{ color: 'var(--color-warning, #f59e0b)' }} />
                    {t('cc.workerStalled', 'Posts are due but the comment worker has not run lately. Check the cron: php cli/comment_cleaner_cron.php')}
                </div>
            )}

            {/* Rule sets */}
            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                <div className="text-sm" style={{ color: 'var(--color-text-muted)' }}>
                    {t('cc.stats24h', { dry: logStats.dry_24h || 0, done: logStats.done_24h || 0, errors: logStats.errors_24h || 0 })}
                </div>
                <div className="flex gap-2">
                    <button type="button" className="btn btn-sm" onClick={load}><RefreshCw className="h-3.5 w-3.5" /></button>
                    <button type="button" className="btn btn-primary" onClick={() => openForm()}><Plus className="h-4 w-4" /> {t('cc.addRuleset')}</button>
                </div>
            </div>

            {loading && <div className="py-6 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>}

            {!loading && rulesets.length === 0 && (
                <div className="card p-8 text-center" style={{ color: 'var(--color-text-muted)' }}>
                    {t('cc.empty', 'No rule sets yet. Create one, attach it to a Facebook connection — posts appear after the cost sync sees your ads\' creatives.')}
                </div>
            )}

            {!loading && rulesets.length > 0 && (
                <div className="table-container mb-5">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ textAlign: 'left' }}>{t('cc.name')}</th>
                                <th>{t('cc.connections')}</th>
                                <th>{t('cc.action')}</th>
                                <th>{t('cc.modeCol')}</th>
                                <th>{t('cc.rulesCol')}</th>
                                <th>{t('cc.status')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {rulesets.map(r => (
                                <tr key={r.id}>
                                    <td style={{ textAlign: 'left', fontWeight: 600 }}>{r.name}</td>
                                    <td>{(r.connection_ids || []).map(connName).join(', ') || '—'}</td>
                                    <td>{r.action === 'delete' ? '🗑 ' : '🙈 '}{r.action === 'delete' ? t('cc.actDelete') : t('cc.actHide')}</td>
                                    <td>{Number(r.dry_run) === 1 ? <span className="badge badge-secondary">DRY</span> : t('cc.live')}</td>
                                    <td className="text-xs">{[
                                        Number(r.block_links) === 1 && t('cc.rLinks'),
                                        Number(r.block_contacts) === 1 && t('cc.rContacts'),
                                        Number(r.emoji_only) === 1 && t('cc.rEmoji'),
                                        Number(r.char_repeats) === 1 && t('cc.rRepeats'),
                                        Number(r.min_length) > 0 && `min ${r.min_length}`,
                                        (r.stopwords || '').split('\n').filter(Boolean).length > 0 && `${(r.stopwords || '').split('\n').filter(Boolean).length} ${t('cc.rWords')}`,
                                        (r.regexes || '').split('\n').filter(Boolean).length > 0 && `${(r.regexes || '').split('\n').filter(Boolean).length} ${t('cc.rRegexes')}`,
                                    ].filter(Boolean).join(' · ') || '—'}</td>
                                    <td>{r.state === 'active' ? t('cc.active') : t('cc.paused')}</td>
                                    <td>
                                        <div className="flex gap-1">
                                            <button type="button" className="action-btn" title={t('common.edit')} onClick={() => openForm(r)}><Pencil className="w-4 h-4" /></button>
                                            <button type="button" className="action-btn text-red" title={t('common.delete')} onClick={() => remove(r.id)}><Trash2 className="w-4 h-4" /></button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Journal */}
            {!loading && (
                <>
                    <h4 className="text-sm font-semibold mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('cc.journal')}</h4>
                    <div className="table-container">
                        <table className="page-table">
                            <thead>
                                <tr>
                                    <th>{t('cc.time')}</th>
                                    <th style={{ textAlign: 'left' }}>{t('cc.comment')}</th>
                                    <th>{t('cc.matchedRule')}</th>
                                    <th>{t('cc.actionDone')}</th>
                                    <th>{t('cc.result')}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {logRows.length === 0 && (
                                    <tr><td colSpan={6} className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('cc.noLog')}</td></tr>
                                )}
                                {logRows.map(l => (
                                    <tr key={l.id}>
                                        <td className="text-xs" style={{ whiteSpace: 'nowrap' }}>{String(l.created_at || '').slice(5, 16)}</td>
                                        <td style={{ textAlign: 'left', maxWidth: 360 }}>
                                            <div className="truncate" title={l.text || ''}>{l.text || <i style={{ color: 'var(--color-text-muted)' }}>(text expired)</i>}</div>
                                            <div className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{l.author_name || l.author_id || ''}</div>
                                        </td>
                                        <td className="text-xs">{l.matched_rule || '—'}</td>
                                        <td className="text-xs">{l.action}</td>
                                        <td>
                                            {l.status === 'dry' && <span className="badge badge-secondary">DRY</span>}
                                            {l.status === 'ok' && <span className="badge badge-success">OK</span>}
                                            {l.status === 'error' && <span className="badge badge-danger" title={l.error}>ERR</span>}
                                        </td>
                                        <td>
                                            <div className="flex gap-1">
                                                <button type="button" className="action-btn" title={t('cc.restore')} onClick={() => logAction(l.id, 'restore')}><Eye className="w-4 h-4" /></button>
                                                <button type="button" className="action-btn" title={t('cc.actHide')} onClick={() => logAction(l.id, 'hide')}><EyeOff className="w-4 h-4" /></button>
                                                <button type="button" className="action-btn text-red" title={t('cc.actDelete')} onClick={() => logAction(l.id, 'delete')}><Trash2 className="w-4 h-4" /></button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}

            {/* Editor modal */}
            {showForm && (
                <div className="modal-overlay" onClick={() => setShowForm(false)}>
                    <div className="modal-content" onClick={e => e.stopPropagation()} style={{ maxWidth: 680, maxHeight: '88vh', overflowY: 'auto' }}>
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-base font-bold m-0">{editingId ? t('cc.editRuleset') : t('cc.addRuleset')}</h3>
                            <button type="button" className="action-btn" onClick={() => setShowForm(false)}><X className="w-5 h-5" /></button>
                        </div>

                        <div className="space-y-3">
                            <div>
                                <label className="form-label">{t('cc.name')}</label>
                                <input className="form-input w-full" value={form.name} maxLength={100}
                                       onChange={e => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div>
                                <label className="form-label">{t('cc.connections')}</label>
                                <select className="form-select w-full" multiple size={Math.min(4, Math.max(2, connections.length))}
                                        value={form.connection_ids.map(String)}
                                        onChange={e => setForm({ ...form, connection_ids: Array.from(e.target.selectedOptions).map(o => Number(o.value)) })}>
                                    {connections.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('cc.connectionsHint')}</div>
                            </div>

                            <div className="flex flex-wrap gap-2 items-center">
                                <select className="form-select" style={{ width: 'auto' }} value={form.action_mode}
                                        onChange={e => setForm({ ...form, action_mode: e.target.value })}>
                                    <option value="hide">🙈 {t('cc.actHide')}</option>
                                    <option value="delete">🗑 {t('cc.actDelete')}</option>
                                </select>
                                <select className="form-select" style={{ width: 'auto' }} value={form.mode}
                                        onChange={e => setForm({ ...form, mode: e.target.value })}>
                                    <option value="rules">{t('cc.modeRules')}</option>
                                    <option value="all">{t('cc.modeAll')}</option>
                                </select>
                                {check('dry_run', t('cc.dryRun'))}
                                {check('include_organic', t('cc.organic'))}
                            </div>
                            {form.dry_run === false && logStats.dry_24h > 0 && (
                                <div className="text-xs rounded-lg px-3 py-2" style={{ background: 'color-mix(in srgb, var(--color-warning, #f59e0b) 10%, transparent)', color: 'var(--color-text-primary)' }}>
                                    {t('cc.dryOffWarning', { n: logStats.dry_24h })}
                                </div>
                            )}

                            <div className="flex flex-wrap gap-2">
                                {(['links', 'dm', 'scam', 'junk']).map(key => (
                                    <button key={key} type="button" className="btn btn-sm btn-secondary" onClick={() => applyPreset(key)}>
                                        {t('cc.preset.' + key)}
                                    </button>
                                ))}
                            </div>

                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {check('block_links', t('cc.rLinks'))}
                                {check('block_contacts', t('cc.rContacts'))}
                                {check('emoji_only', t('cc.rEmoji'))}
                                {check('char_repeats', t('cc.rRepeats'))}
                                {check('whole_word', t('cc.wholeWord'))}
                                <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                    {t('cc.minLength')}
                                    <input type="number" min={0} max={200} className="form-input" style={{ width: 80 }}
                                           value={form.min_length} onChange={e => setForm({ ...form, min_length: Number(e.target.value) || 0 })} />
                                </label>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label className="form-label">{t('cc.stopwords')}</label>
                                    <textarea className="form-input w-full font-mono text-xs" rows={5} value={form.stopwords}
                                              onChange={e => setForm({ ...form, stopwords: e.target.value })}
                                              placeholder={'scam\nмошенники\nκλέφτες'} />
                                </div>
                                <div>
                                    <label className="form-label">{isAdmin ? t('cc.regexes') : t('cc.regexesAdminOnly')}</label>
                                    <textarea className="form-input w-full font-mono text-xs" rows={5} value={isAdmin ? form.regexes : ''}
                                              disabled={!isAdmin}
                                              onChange={e => setForm({ ...form, regexes: e.target.value })}
                                              placeholder={isAdmin ? '/\\b(v[аa]v[аa]n\\d*)\\b/iu' : ''} />
                                </div>
                                <div>
                                    <label className="form-label">{t('cc.whitelist')}</label>
                                    <textarea className="form-input w-full font-mono text-xs" rows={3} value={form.links_whitelist}
                                              onChange={e => setForm({ ...form, links_whitelist: e.target.value })}
                                              placeholder={'facebook.com\nour-brand.com'} />
                                </div>
                                <div>
                                    <label className="form-label">{t('cc.exceptions')}</label>
                                    <textarea className="form-input w-full font-mono text-xs" rows={3} value={form.exceptions}
                                              onChange={e => setForm({ ...form, exceptions: e.target.value })}
                                              placeholder={'id:100123\nname:Admin\ngreat bonus'} />
                                </div>
                            </div>

                            {/* Text tester */}
                            {editingId && (
                                <div className="p-3 rounded-xl" style={{ background: 'var(--color-bg-soft)' }}>
                                    <label className="form-label">{t('cc.testTitle')}</label>
                                    <div className="flex gap-2">
                                        <input className="form-input flex-1" value={testText} onChange={e => setTestText(e.target.value)}
                                               placeholder={t('cc.testPh', 'Paste a comment here…')} />
                                        <button type="button" className="btn btn-sm" onClick={runTest}><Search className="h-3.5 w-3.5" /></button>
                                    </div>
                                    {testResult !== null && (
                                        <div className="text-sm mt-2" style={{ color: 'var(--color-text-primary)' }}>
                                            {testResult === false ? '✔ ' + t('cc.testPass') : '🎯 ' + t('cc.testMatched') + ': ' + String(testResult)}
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="flex justify-end gap-2 pt-2">
                                <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>{t('common.cancel')}</button>
                                <button type="button" className="btn btn-primary" disabled={!form.name.trim()} onClick={save}>{t('common.save')}</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {error && <div className="alert alert-danger mt-3">{error}</div>}
        </div>
    );
};

export default CommentCleanerPage;
