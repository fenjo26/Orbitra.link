import React, { useState } from 'react';
import axios from 'axios';
import { useLanguage } from '../../contexts/LanguageContext';

const API_URL = '/api.php';
const INTERVALS = [15, 30, 60, 180, 360, 720, 1440];

// Per-link settings, admins only: how often this one link is checked, through
// which proxy, what destination it is expected to lead to. Everything left
// empty means "as the defaults say".
export default function LinkCheckSettingsModal({ row, proxies, onClose, onSaved }) {
    const { t } = useLanguage();
    const [form, setForm] = useState({
        is_enabled: row.is_enabled !== false,
        interval: row.interval_min ? String(row.interval_min) : '',
        proxy_id: row.proxy_id ? String(row.proxy_id) : '',
        expected_mode: row.expected_mode || 'auto',
        expected_domains: (row.expected_domains || []).join('\n'),
        user_agent: row.user_agent || '',
        forget: false,
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const customInterval = form.interval !== '' && !INTERVALS.includes(Number(form.interval));

    const save = async () => {
        setSaving(true);
        setError('');
        const payload = {
            id: row.id,
            is_enabled: form.is_enabled,
            interval_min: form.interval === '' ? null : Number(form.interval),
            proxy_id: form.proxy_id === '' ? null : Number(form.proxy_id),
            expected_mode: form.expected_mode,
            expected_domains: form.expected_domains,
            user_agent: form.user_agent,
        };
        if (form.forget) payload.learned_domain = null;
        try {
            const res = await axios.post(API_URL, payload, { params: { action: 'save_link_check' } });
            if (res.data?.status === 'success') {
                onSaved(res.data.data);
            } else {
                // The server names the problem with a code; the panel says it in its own language.
                setError(t(`linkChecks.errors.${res.data?.code}`) || res.data?.message || t('common.error'));
            }
        } catch {
            setError(t('common.error'));
        } finally {
            setSaving(false);
        }
    };

    const label = 'block text-xs font-medium mb-1';
    const labelText = { color: 'var(--color-text-secondary)' };
    const input = (extra = '') => `form-input w-full ${extra}`;

    return (
        <div className="modal-overlay" onClick={onClose}>
            <div className="modal-content w-full max-w-lg" style={{ padding: '24px' }} onClick={(e) => e.stopPropagation()}>
                <div className="modal-header" style={{ marginBottom: 16 }}>
                    <h3 className="modal-title">{t('linkChecks.settings')}</h3>
                    <p className="text-xs mt-1 truncate" style={{ color: 'var(--color-text-muted)' }} title={row.label}>{row.label}</p>
                </div>

                <div className="space-y-4">
                    <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                        <input
                            type="checkbox"
                            checked={form.is_enabled}
                            onChange={(e) => set('is_enabled', e.target.checked)}
                            style={{ accentColor: 'var(--color-primary)' }}
                        />
                        {t('linkChecks.enabledLabel')}
                    </label>

                    <div>
                        <label className={label} style={labelText} htmlFor="lc-interval">{t('linkChecks.interval')}</label>
                        <select id="lc-interval" className="form-select w-full" value={customInterval ? 'custom' : form.interval} onChange={(e) => set('interval', e.target.value === 'custom' ? '45' : e.target.value)}>
                            <option value="">{t('linkChecks.intervalDefault')}</option>
                            {INTERVALS.map((minutes) => (
                                <option key={minutes} value={minutes}>{t('linkChecks.everyMin', { n: minutes })}</option>
                            ))}
                            <option value="custom">{t('linkChecks.intervalCustom')}</option>
                        </select>
                        {customInterval && (
                            <input
                                type="number"
                                min="5"
                                max="10080"
                                className={input('mt-2')}
                                value={form.interval}
                                onChange={(e) => set('interval', e.target.value)}
                                aria-label={t('linkChecks.intervalCustom')}
                            />
                        )}
                    </div>

                    <div>
                        <label className={label} style={labelText} htmlFor="lc-proxy">{t('linkChecks.colProxy')}</label>
                        <select id="lc-proxy" className="form-select w-full" value={form.proxy_id} onChange={(e) => set('proxy_id', e.target.value)}>
                            <option value="">{t('linkChecks.noProxy')}</option>
                            {proxies.filter((proxy) => proxy.is_active).map((proxy) => (
                                <option key={proxy.id} value={proxy.id}>
                                    {proxy.name}{proxy.exit_country ? ` (${proxy.exit_country})` : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className={label} style={labelText} htmlFor="lc-expected">{t('linkChecks.expected')}</label>
                        <select
                            id="lc-expected"
                            className="form-select w-full"
                            value={form.expected_mode}
                            onChange={(e) => set('expected_mode', e.target.value)}
                        >
                            <option value="auto">{t('linkChecks.expectedAuto')}</option>
                            <option value="list">{t('linkChecks.expectedList')}</option>
                            <option value="off">{t('linkChecks.expectedOff')}</option>
                        </select>
                        {form.expected_mode === 'list' && (
                            <textarea
                                className="form-input w-full mt-2 font-mono"
                                rows={3}
                                value={form.expected_domains}
                                onChange={(e) => set('expected_domains', e.target.value)}
                                placeholder="example.com"
                                aria-label={t('linkChecks.expectedDomains')}
                            />
                        )}
                        {row.learned_domain && (
                            <label className="flex items-center gap-2 text-xs mt-2" style={{ color: 'var(--color-text-secondary)' }}>
                                <input
                                    type="checkbox"
                                    checked={form.forget}
                                    onChange={(e) => set('forget', e.target.checked)}
                                    style={{ accentColor: 'var(--color-primary)' }}
                                />
                                {t('linkChecks.learned', { domain: row.learned_domain })}
                            </label>
                        )}
                        {form.forget && (
                            <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.forget')}</p>
                        )}
                    </div>

                    <div>
                        <label className={label} style={labelText} htmlFor="lc-ua">{t('linkChecks.userAgent')}</label>
                        <input id="lc-ua" type="text" className={input('font-mono')} value={form.user_agent} onChange={(e) => set('user_agent', e.target.value)} placeholder={t('linkChecks.userAgentDefault')} />
                        <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('linkChecks.userAgentHint')}</p>
                    </div>

                    {error && <p className="text-xs" style={{ color: 'var(--color-danger, #dc2626)' }}>{error}</p>}
                </div>

                <div className="modal-footer" style={{ marginTop: 20 }}>
                    <button type="button" className="btn btn-ghost" onClick={onClose}>{t('linkChecks.cancel')}</button>
                    <button type="button" className="btn btn-primary" onClick={save} disabled={saving}>
                        {saving ? t('linkChecks.saving') : t('linkChecks.save')}
                    </button>
                </div>
            </div>
        </div>
    );
}
