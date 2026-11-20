import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Zap, Plus, Trash2, Edit3, Play, ChevronDown, ChevronUp, AlertTriangle } from 'lucide-react';

const API_URL = '/api.php';

// Campaign rules card (campaign editor → Settings): "if these numbers over
// the last N hours, then notify / switch the offer off in the split / pause
// the campaign". The backend is core/CampaignRules.php; rules run from the
// rotation optimiser cron, so nothing needs installing on the server.

const METRICS = ['cost', 'clicks', 'conversions', 'sales', 'registrations', 'deposits', 'revenue', 'profit', 'roi', 'cr', 'epc'];
const OPS = ['gte', 'gt', 'lte', 'lt', 'eq'];
const OP_SYMBOL = { gt: '>', gte: '≥', lt: '<', lte: '≤', eq: '=' };
const MONEY = new Set(['cost', 'revenue', 'profit', 'epc']);
const PCT = new Set(['roi', 'cr']);

// Ready-made rules a buyer recognises by name; each opens the form prefilled.
const PRESETS = [
    {
        id: 'burner',
        rule: {
            target: 'offers', action: 'disable_item', window_hours: 24, cooldown_hours: 24, interval_min: 15,
            conditions: [{ metric: 'cost', op: 'gte', value: 20 }, { metric: 'sales', op: 'eq', value: 0 }]
        }
    },
    {
        id: 'losing',
        rule: {
            target: 'campaign', action: 'notify', window_hours: 24, cooldown_hours: 12, interval_min: 30,
            conditions: [{ metric: 'cost', op: 'gte', value: 50 }, { metric: 'roi', op: 'lt', value: -50 }]
        }
    },
    {
        id: 'noTraffic',
        rule: {
            target: 'campaign', action: 'notify', window_hours: 2, cooldown_hours: 6, interval_min: 30,
            conditions: [{ metric: 'clicks', op: 'eq', value: 0 }]
        }
    },
    {
        id: 'stopLoss',
        rule: {
            target: 'campaign', action: 'pause_campaign', window_hours: 24, cooldown_hours: 24, interval_min: 15,
            conditions: [{ metric: 'cost', op: 'gte', value: 100 }, { metric: 'conversions', op: 'eq', value: 0 }]
        }
    }
];

const fmtNum = (metric, v) => {
    if (v === null || v === undefined) return '—';
    const n = Number(v);
    if (!isFinite(n)) return '—';
    if (PCT.has(metric)) return `${n.toFixed(1)}%`;
    if (metric === 'epc') return n.toFixed(4);
    if (MONEY.has(metric)) return n.toFixed(2);
    return n.toLocaleString();
};

const fmtTime = (iso) => {
    if (!iso) return '';
    const d = new Date(String(iso).replace(' ', 'T') + (String(iso).includes('Z') ? '' : 'Z'));
    return isNaN(d.getTime()) ? String(iso) : d.toLocaleString();
};

export default function CampaignRulesCard({ campaignId, t }) {
    const [rules, setRules] = useState(null);
    const [form, setForm] = useState(null);
    const [preview, setPreview] = useState(null);
    const [journal, setJournal] = useState(null);
    const [journalOpen, setJournalOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);

    const load = async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'campaign_rules', campaign_id: campaignId } });
            if (res.data.status === 'success') setRules(res.data.data.rules || []);
        } catch { setRules([]); }
    };
    const loadJournal = async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'campaign_rule_log', campaign_id: campaignId } });
            if (res.data.status === 'success') setJournal(res.data.data || []);
        } catch { setJournal([]); }
    };

    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => { load(); loadJournal(); }, [campaignId]);

    const metricLabel = (m) => t(`campaignRules.metric.${m}`);
    const targetLabel = (tg) => t(`campaignRules.target.${tg}`);

    const describe = (rule) => {
        const conds = (rule.conditions || [])
            .map(c => `${metricLabel(c.metric)} ${OP_SYMBOL[c.op] || c.op} ${fmtNum(c.metric, c.value)}`)
            .join(` ${t('campaignRules.and')} `);
        return t('campaignRules.sentence', {
            target: targetLabel(rule.target),
            hours: rule.window_hours,
            conditions: conds,
            action: t(`campaignRules.action.${rule.action}`)
        });
    };

    const openNew = (preset) => {
        setPreview(null);
        setMessage(null);
        const base = preset ? preset.rule : PRESETS[0].rule;
        setForm({
            id: null,
            name: preset ? t(`campaignRules.preset.${preset.id}`) : '',
            is_active: true,
            ...JSON.parse(JSON.stringify(base))
        });
    };
    const openEdit = (rule) => {
        setPreview(null);
        setMessage(null);
        setForm({ ...JSON.parse(JSON.stringify(rule)), is_active: !!Number(rule.is_active) });
    };

    // Actions available for the chosen target; switching target resets an
    // action the new target cannot have.
    const actionsFor = (target) => target === 'campaign' ? ['notify', 'pause_campaign'] : ['notify', 'disable_item'];
    const setTarget = (target) => {
        setForm(f => ({ ...f, target, action: actionsFor(target).includes(f.action) ? f.action : 'notify' }));
        setPreview(null);
    };
    const setCond = (i, patch) => setForm(f => ({ ...f, conditions: f.conditions.map((c, j) => (j === i ? { ...c, ...patch } : c)) }));
    const addCond = () => setForm(f => ({ ...f, conditions: [...f.conditions, { metric: 'clicks', op: 'gte', value: 100 }] }));
    const removeCond = (i) => setForm(f => ({ ...f, conditions: f.conditions.filter((_, j) => j !== i) }));

    const errorText = (code) => t(`campaignRules.error.${code}`, code || t('common.networkError'));

    const save = async () => {
        if (form.action === 'pause_campaign' && !window.confirm(t('campaignRules.confirmPause'))) return;
        setBusy(true);
        setMessage(null);
        try {
            const res = await axios.post(`${API_URL}?action=campaign_rule_save`, { ...form, campaign_id: campaignId });
            if (res.data.status === 'success') {
                setForm(null);
                setPreview(null);
                setMessage({ ok: true, text: t('campaignRules.saved') });
                await load();
            } else {
                setMessage({ ok: false, text: errorText(res.data.code) });
            }
        } catch {
            setMessage({ ok: false, text: t('common.networkError') });
        } finally {
            setBusy(false);
        }
    };

    const runPreview = async () => {
        setBusy(true);
        setMessage(null);
        try {
            const res = await axios.get(API_URL, { params: { action: 'campaign_rule_preview', campaign_id: campaignId, rule: JSON.stringify(form) } });
            if (res.data.status === 'success') setPreview(res.data.data.targets || []);
            else setMessage({ ok: false, text: errorText(res.data.code) });
        } catch {
            setMessage({ ok: false, text: t('common.networkError') });
        } finally {
            setBusy(false);
        }
    };

    const toggleActive = async (rule) => {
        try {
            await axios.post(`${API_URL}?action=campaign_rule_save`, { ...rule, is_active: Number(rule.is_active) ? 0 : 1, campaign_id: campaignId });
            await load();
        } catch { /* the list stays as it was */ }
    };

    const remove = async (rule) => {
        if (!window.confirm(t('campaignRules.confirmDelete'))) return;
        try {
            await axios.post(`${API_URL}?action=campaign_rule_delete`, { id: rule.id, campaign_id: campaignId });
            await load();
        } catch { /* the list stays as it was */ }
    };

    const previewMatches = useMemo(() => (preview || []).filter(r => r.match).length, [preview]);

    if (rules === null) return null;

    const box = { border: '1px solid var(--color-border)', borderRadius: 12, padding: 12, background: 'var(--color-bg-card)' };
    const muted = { color: 'var(--color-text-muted)' };
    const label = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: '0.78rem', ...muted };

    return (
        <div data-testid="campaign-rules-card">
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
                <Zap size={16} style={{ color: 'var(--color-primary)' }} />
                <span style={{ fontWeight: 600, fontSize: '0.85rem' }}>{t('campaignRules.title')}</span>
                {rules.length > 0 && (
                    <span className="badge" style={{ fontSize: '0.7rem' }}>
                        {t('campaignRules.activeCount', { n: rules.filter(r => Number(r.is_active)).length })}
                    </span>
                )}
            </div>
            <p style={{ fontSize: '0.78rem', margin: '0 0 10px', ...muted }}>{t('campaignRules.hint')}</p>

            {rules.length > 0 && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
                    {rules.map(rule => (
                        <div key={rule.id} style={{ ...box, padding: '8px 12px', display: 'flex', alignItems: 'center', gap: 10, opacity: Number(rule.is_active) ? 1 : 0.6 }}>
                            <input type="checkbox" checked={!!Number(rule.is_active)} onChange={() => toggleActive(rule)} title={t('campaignRules.activeToggle')} />
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <div style={{ fontWeight: 600, fontSize: '0.8rem' }}>{rule.name || `#${rule.id}`}</div>
                                <div style={{ fontSize: '0.75rem', ...muted }}>{describe(rule)}</div>
                                {rule.last_run_at && (
                                    <div style={{ fontSize: '0.7rem', ...muted }}>
                                        {t('campaignRules.lastRun')}: {fmtTime(rule.last_run_at)}
                                        {rule.last_status && rule.last_status.startsWith('error') && (
                                            <span style={{ color: 'var(--color-danger, #ef4444)' }}> · {rule.last_status}</span>
                                        )}
                                    </div>
                                )}
                            </div>
                            <button type="button" className="btn btn-ghost btn-icon p-1.5" onClick={() => openEdit(rule)} title={t('common.edit')}><Edit3 className="w-4 h-4" /></button>
                            <button type="button" className="btn btn-ghost btn-icon p-1.5" onClick={() => remove(rule)} title={t('common.delete')}><Trash2 className="w-4 h-4" /></button>
                        </div>
                    ))}
                </div>
            )}

            {!form && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                    <span style={{ fontSize: '0.75rem', ...muted }}>{t('campaignRules.presetsLabel')}</span>
                    {PRESETS.map(p => (
                        <button key={p.id} type="button" className="btn btn-secondary text-xs" onClick={() => openNew(p)} data-preset={p.id}>
                            {t(`campaignRules.preset.${p.id}`)}
                        </button>
                    ))}
                    <button type="button" className="btn btn-ghost text-xs" onClick={() => openNew(null)}>
                        <Plus className="w-3.5 h-3.5" /> {t('campaignRules.custom')}
                    </button>
                </div>
            )}

            {form && (
                <div style={{ ...box, display: 'flex', flexDirection: 'column', gap: 10 }} data-testid="campaign-rule-form">
                    <label style={label}>
                        {t('campaignRules.name')}
                        <input className="input" style={{ padding: '0.3rem 0.6rem' }} value={form.name} maxLength={120}
                            placeholder={t('campaignRules.namePlaceholder')}
                            onChange={e => setForm({ ...form, name: e.target.value })} />
                    </label>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                        <label style={label}>
                            {t('campaignRules.whatToWatch')}
                            <select className="form-select text-xs" value={form.target} onChange={e => setTarget(e.target.value)} data-testid="rule-target">
                                {['campaign', 'offers', 'landings'].map(tg => <option key={tg} value={tg}>{targetLabel(tg)}</option>)}
                            </select>
                        </label>
                        <label style={label}>
                            {t('campaignRules.window')}
                            <select className="form-select text-xs" value={form.window_hours} onChange={e => setForm({ ...form, window_hours: Number(e.target.value) })}>
                                {[1, 2, 3, 6, 12, 24, 48, 72, 168].map(h => <option key={h} value={h}>{t('campaignRules.lastHours', { n: h })}</option>)}
                            </select>
                        </label>
                    </div>
                    {form.target !== 'campaign' && (
                        <p style={{ fontSize: '0.72rem', margin: 0, ...muted }}>{t('campaignRules.itemsHint')}</p>
                    )}

                    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                        <span style={{ fontSize: '0.78rem', ...muted }}>{t('campaignRules.when')}</span>
                        {form.conditions.map((c, i) => (
                            <div key={i} style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                                {i > 0 && <span style={{ fontSize: '0.72rem', fontWeight: 700, ...muted }}>{t('campaignRules.and').toUpperCase()}</span>}
                                <select className="form-select text-xs" style={{ width: 160 }} value={c.metric} onChange={e => setCond(i, { metric: e.target.value })}>
                                    {METRICS.map(m => <option key={m} value={m}>{metricLabel(m)}</option>)}
                                </select>
                                <select className="form-select text-xs" style={{ width: 64 }} value={c.op} onChange={e => setCond(i, { op: e.target.value })}>
                                    {OPS.map(o => <option key={o} value={o}>{OP_SYMBOL[o]}</option>)}
                                </select>
                                <input type="number" className="input" style={{ width: 110, padding: '0.3rem 0.6rem' }} step="any"
                                    value={c.value} onChange={e => setCond(i, { value: e.target.value === '' ? '' : Number(e.target.value) })} />
                                <span style={{ fontSize: '0.72rem', ...muted }}>{PCT.has(c.metric) ? '%' : ''}</span>
                                {form.conditions.length > 1 && (
                                    <button type="button" className="btn btn-ghost btn-icon p-1" onClick={() => removeCond(i)} title={t('common.delete')}><Trash2 className="w-3.5 h-3.5" /></button>
                                )}
                            </div>
                        ))}
                        {form.conditions.length < 5 && (
                            <button type="button" className="btn btn-ghost text-xs" style={{ alignSelf: 'flex-start' }} onClick={addCond}>
                                <Plus className="w-3.5 h-3.5" /> {t('campaignRules.addCondition')}
                            </button>
                        )}
                        {form.conditions.some(c => c.metric === 'roi' || c.metric === 'profit' || c.metric === 'cost') && (
                            <p style={{ fontSize: '0.72rem', margin: 0, ...muted }}>{t('campaignRules.costHint')}</p>
                        )}
                    </div>

                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10 }}>
                        <label style={label}>
                            {t('campaignRules.then')}
                            <select className="form-select text-xs" value={form.action} onChange={e => setForm({ ...form, action: e.target.value })} data-testid="rule-action">
                                {actionsFor(form.target).map(a => <option key={a} value={a}>{t(`campaignRules.action.${a}`)}</option>)}
                            </select>
                        </label>
                        <label style={label}>
                            {t('campaignRules.cooldown')}
                            <select className="form-select text-xs" value={form.cooldown_hours} onChange={e => setForm({ ...form, cooldown_hours: Number(e.target.value) })}>
                                {[1, 3, 6, 12, 24, 48, 168].map(h => <option key={h} value={h}>{t('campaignRules.hours', { n: h })}</option>)}
                            </select>
                        </label>
                        <label style={label}>
                            {t('campaignRules.interval')}
                            <select className="form-select text-xs" value={form.interval_min} onChange={e => setForm({ ...form, interval_min: Number(e.target.value) })}>
                                {[5, 15, 30, 60, 180].map(m => <option key={m} value={m}>{t('campaignRules.minutes', { n: m })}</option>)}
                            </select>
                        </label>
                    </div>
                    <p style={{ fontSize: '0.72rem', margin: 0, ...muted }}>{t(`campaignRules.actionHint.${form.action}`)}</p>
                    {form.action === 'pause_campaign' && (
                        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: '0.75rem', padding: '8px 10px', borderRadius: 8,
                            background: 'color-mix(in srgb, var(--color-warning, #f59e0b) 12%, transparent)', color: 'var(--color-text-secondary)' }}>
                            <AlertTriangle className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--color-warning, #f59e0b)' }} />
                            <span>{t('campaignRules.pauseWarning')}</span>
                        </div>
                    )}

                    <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: '0.78rem' }}>
                        <input type="checkbox" checked={!!form.is_active} onChange={e => setForm({ ...form, is_active: e.target.checked })} />
                        {t('campaignRules.activeToggle')}
                    </label>

                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button type="button" className="btn btn-primary text-xs" disabled={busy} onClick={save} data-testid="rule-save">{t('common.save')}</button>
                        <button type="button" className="btn btn-secondary text-xs" disabled={busy} onClick={runPreview} data-testid="rule-preview">
                            <Play className="w-3.5 h-3.5" /> {t('campaignRules.checkNow')}
                        </button>
                        <button type="button" className="btn btn-ghost text-xs" onClick={() => { setForm(null); setPreview(null); }}>{t('common.cancel')}</button>
                    </div>

                    {preview && (
                        <div data-testid="rule-preview-result">
                            <div style={{ fontSize: '0.78rem', fontWeight: 600, marginBottom: 4 }}>
                                {previewMatches > 0 ? t('campaignRules.previewHits', { n: previewMatches }) : t('campaignRules.previewNone')}
                            </div>
                            {preview.length === 0 ? (
                                <p style={{ fontSize: '0.75rem', ...muted }}>{t('campaignRules.previewEmpty')}</p>
                            ) : (
                                <div style={{ overflowX: 'auto' }}>
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr style={muted}>
                                                <th style={{ textAlign: 'left', padding: '4px 6px' }}>{targetLabel(form.target)}</th>
                                                {form.conditions.map((c, i) => <th key={i} style={{ textAlign: 'right', padding: '4px 6px' }}>{metricLabel(c.metric)}</th>)}
                                                <th style={{ textAlign: 'right', padding: '4px 6px' }}></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {preview.map(row => (
                                                <tr key={row.target_id} style={{ borderTop: '1px solid var(--color-border)' }}>
                                                    <td style={{ padding: '4px 6px', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={row.target_name}>{row.target_name}</td>
                                                    {form.conditions.map((c, i) => (
                                                        <td key={i} style={{ textAlign: 'right', padding: '4px 6px', fontVariantNumeric: 'tabular-nums' }}>{fmtNum(c.metric, row.metrics?.[c.metric])}</td>
                                                    ))}
                                                    <td style={{ textAlign: 'right', padding: '4px 6px', whiteSpace: 'nowrap' }}>
                                                        {row.match
                                                            ? <span style={{ color: 'var(--color-danger, #ef4444)', fontWeight: 600 }}>{row.cooldown ? t('campaignRules.matchCooldown') : t('campaignRules.match')}</span>
                                                            : <span style={muted}>—</span>}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {message && (
                <p style={{ fontSize: '0.78rem', marginTop: 8, color: message.ok ? 'var(--color-success)' : 'var(--color-danger, #ef4444)' }}>{message.text}</p>
            )}

            {journal && journal.length > 0 && (
                <div style={{ marginTop: 10 }}>
                    <button type="button" className="btn btn-ghost text-xs" onClick={() => setJournalOpen(o => !o)}>
                        {journalOpen ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                        {t('campaignRules.journal', { n: journal.length })}
                    </button>
                    {journalOpen && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 6 }}>
                            {journal.map(j => (
                                <div key={j.id} style={{ fontSize: '0.72rem', display: 'flex', gap: 8, flexWrap: 'wrap', color: 'var(--color-text-secondary)' }}>
                                    <span style={muted}>{fmtTime(j.created_at)}</span>
                                    <span style={{ fontWeight: 600 }}>{j.rule_name || `#${j.rule_id}`}</span>
                                    <span>{j.target_name}</span>
                                    <span>{t(`campaignRules.logStatus.${j.status}`, j.status)}{j.message ? ` (${t(`campaignRules.logMessage.${j.message}`, j.message)})` : ''}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
