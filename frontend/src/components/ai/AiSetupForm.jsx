import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { CheckCircle2, ExternalLink, AlertTriangle, ChevronDown, ChevronUp } from 'lucide-react';
import { AI_PROVIDERS } from './aiProviders';

const API_URL = '/api.php';

// Provider + key form for the AI assistant. Used inside the chat window (the
// first-run onboarding) and on the Integrations card. Saving also runs a
// one-word test call so a bad key is caught right here, not in the chat.
export default function AiSetupForm({ t, compact = false, onSaved }) {
    const [form, setForm] = useState(null);
    const [saved, setSaved] = useState(null);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const [advanced, setAdvanced] = useState(false);
    const [models, setModels] = useState({ list: null, loading: false, error: null });

    // Ask the provider which models this key can use; picks the provider's
    // economical default when nothing is chosen yet.
    const loadModels = async (override) => {
        const f = override || form;
        if (!f) return;
        setModels({ list: null, loading: true, error: null });
        try {
            const res = await axios.post(`${API_URL}?action=ai_models`, { provider: f.provider, base_url: f.base_url, api_key: f.api_key });
            if (res.data.status === 'success') {
                const list = res.data.data.models || [];
                setModels({ list, loading: false, error: null });
                if (!f.model && list.length) {
                    const pick = list.find(m => m.recommended) || list[0];
                    setForm(cur => (cur && !cur.model ? { ...cur, model: pick.id } : cur));
                }
            } else {
                setModels({ list: null, loading: false, error: res.data.message || res.data.code || '' });
            }
        } catch {
            setModels({ list: null, loading: false, error: t('common.networkError') });
        }
    };

    useEffect(() => {
        axios.get(API_URL, { params: { action: 'ai_settings' } })
            .then(res => {
                if (res.data.status !== 'success') return;
                const d = res.data.data;
                setSaved(d);
                setForm({ provider: d.provider || 'glm', base_url: d.base_url || '', api_key: '', model: d.model || '', confirm_mode: d.confirm_mode || 'writes' });
                setAdvanced(!!d.base_url && d.provider !== 'custom');
                if (d.configured) loadModels({ provider: d.provider, base_url: d.base_url || '', api_key: '', model: d.model || 'x' });
            })
            .catch(() => setForm({ provider: 'glm', base_url: '', api_key: '', model: '', confirm_mode: 'writes' }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (!form) return null;
    const preset = AI_PROVIDERS[form.provider] || AI_PROVIDERS.openai;
    const needsKey = preset.needsKey;
    const keepsKey = saved?.has_key && saved.provider === form.provider;
    const showBase = advanced || form.provider === 'custom' || form.provider === 'ollama';
    const currentModel = form.model || '';
    const customModel = !!form.ownModel;

    const save = async () => {
        setBusy(true);
        setResult(null);
        try {
            const res = await axios.post(`${API_URL}?action=ai_settings`, { ...form, model: form.model.trim(), ownModel: undefined });
            if (res.data.status !== 'success') {
                setResult({ ok: false, text: t(`ai.setup.error.${res.data.code}`, res.data.message || t('common.networkError')) });
                return;
            }
            setSaved(res.data.data);
            setForm(f => ({ ...f, api_key: '' }));
            if (!res.data.data.configured) {
                setResult({ ok: false, text: t('ai.setup.keyMissing') });
                return;
            }
            const test = await axios.post(`${API_URL}?action=ai_test`, {});
            if (test.data.status === 'success') {
                setResult({ ok: true, text: t('ai.setup.connected', { model: test.data.data.model, ms: test.data.data.ms }) });
                onSaved && onSaved(res.data.data);
            } else {
                setResult({ ok: false, text: test.data.message || t('common.networkError') });
            }
        } catch {
            setResult({ ok: false, text: t('common.networkError') });
        } finally {
            setBusy(false);
        }
    };

    const muted = { color: 'var(--color-text-muted)' };
    const label = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: '0.78rem', ...muted };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }} data-testid="ai-setup-form">
            <label style={label}>
                {t('ai.setup.provider')}
                <select className="form-select text-xs" value={form.provider} data-testid="ai-provider"
                    onChange={e => { setForm({ ...form, provider: e.target.value, model: '', base_url: '', ownModel: false }); setModels({ list: null, loading: false, error: null }); }}>
                    {Object.keys(AI_PROVIDERS).map(id => (
                        <option key={id} value={id}>{t(`ai.provider.${id}`)}</option>
                    ))}
                </select>
            </label>

            {(needsKey || form.provider === 'custom') && (
                <label style={label}>
                    <span className="flex items-center justify-between gap-2">
                        <span>{t('ai.setup.apiKey')}</span>
                        {preset.keyUrl && (
                            <a href={preset.keyUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1" style={{ color: 'var(--color-primary)' }}>
                                {t('ai.setup.getKey')} <ExternalLink className="w-3 h-3" />
                            </a>
                        )}
                    </span>
                    <input type="password" className="input" style={{ padding: '0.35rem 0.6rem' }} autoComplete="off" data-testid="ai-key"
                        placeholder={keepsKey ? t('ai.setup.keyKept', { hint: saved.key_hint }) : t('ai.setup.keyPlaceholder')}
                        value={form.api_key} onChange={e => setForm({ ...form, api_key: e.target.value })}
                        onBlur={() => form.api_key.trim() && loadModels()} />
                </label>
            )}

            <div style={label}>
                <span className="flex items-center justify-between gap-2">
                    <span>{t('ai.setup.model')}</span>
                    <button type="button" className="btn btn-ghost text-xs" style={{ padding: '0 4px' }} onClick={loadModels} disabled={models.loading} data-testid="ai-load-models">
                        {models.loading ? t('ai.setup.modelsLoading') : t('ai.setup.loadModels')}
                    </button>
                </span>
                {models.list && models.list.length > 0 && !customModel ? (
                    <select className="form-select text-xs" data-testid="ai-model" value={currentModel}
                        onChange={e => e.target.value === '__custom'
                            ? setForm({ ...form, model: '', ownModel: true })
                            : setForm({ ...form, model: e.target.value, ownModel: false })}>
                        {!models.list.some(m => m.id === currentModel) && currentModel && <option value={currentModel}>{currentModel}</option>}
                        {models.list.map(m => (
                            <option key={m.id} value={m.id}>
                                {m.id}{m.recommended ? ` — ${t('ai.setup.recommended')}` : ''}{m.price_in !== undefined ? ` · $${m.price_in} / $${m.price_out} ${t('ai.setup.perMillion')}` : ''}
                            </option>
                        ))}
                        <option value="__custom">{t('ai.setup.ownModel')}</option>
                    </select>
                ) : (
                    <input className="input" style={{ padding: '0.35rem 0.6rem' }} data-testid="ai-model-custom"
                        placeholder={preset.model || t('ai.setup.modelPlaceholder')}
                        value={form.model} onChange={e => setForm({ ...form, model: e.target.value })} />
                )}
                {models.error && <span style={{ fontSize: '0.7rem', color: 'var(--color-warning, #f59e0b)', wordBreak: 'break-word' }}>{t('ai.setup.modelsError')} {models.error}</span>}
                {models.list && models.list.length > 0 && <span style={{ fontSize: '0.7rem' }}>{t('ai.setup.modelsFound', { n: models.list.length })}</span>}
                <span style={{ fontSize: '0.7rem' }}>{t('ai.setup.modelHint')}</span>
            </div>

            {!compact && (
                <label style={label}>
                    {t('ai.setup.confirmMode')}
                    <select className="form-select text-xs" value={form.confirm_mode} onChange={e => setForm({ ...form, confirm_mode: e.target.value })}>
                        <option value="writes">{t('ai.setup.confirmWrites')}</option>
                        <option value="dangerous">{t('ai.setup.confirmDangerous')}</option>
                    </select>
                </label>
            )}

            {form.provider !== 'custom' && form.provider !== 'ollama' && (
                <button type="button" className="btn btn-ghost text-xs" style={{ alignSelf: 'flex-start', padding: '2px 4px' }} onClick={() => setAdvanced(a => !a)}>
                    {advanced ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />} {t('ai.setup.advanced')}
                </button>
            )}
            {showBase && (
                <label style={label}>
                    {t('ai.setup.baseUrl')}
                    <input className="input" style={{ padding: '0.35rem 0.6rem' }} placeholder={preset.baseUrl || 'https://…/v1'}
                        value={form.base_url} onChange={e => setForm({ ...form, base_url: e.target.value })} />
                    {preset.baseHint && <span style={{ fontSize: '0.7rem' }}>{t(preset.baseHint)}</span>}
                </label>
            )}

            <p style={{ fontSize: '0.72rem', margin: 0, ...muted }}>{t('ai.setup.privacy')}</p>

            <button type="button" className="btn btn-primary text-xs" disabled={busy || (needsKey && !form.api_key && !keepsKey)}
                onClick={save} data-testid="ai-save">
                {busy ? t('ai.setup.checking') : t('ai.setup.saveAndCheck')}
            </button>
            {result && (
                <div className="flex items-start gap-2" style={{ fontSize: '0.78rem', color: result.ok ? 'var(--color-success)' : 'var(--color-danger, #ef4444)' }} data-testid="ai-setup-result">
                    {result.ok ? <CheckCircle2 className="w-4 h-4 flex-shrink-0" /> : <AlertTriangle className="w-4 h-4 flex-shrink-0" />}
                    <span style={{ wordBreak: 'break-word' }}>{result.text}</span>
                </div>
            )}
        </div>
    );
}
