import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { Save, Copy, RefreshCw, TestTube, Plus, Trash2, AlertCircle, CheckCircle, Code, Settings2, Key, TestTube2, Table, Lock } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard as copyText } from '../utils/clipboard';

const API_URL = '/api.php';

// The parameters a postback carries; each one has aliases of its own.
const ALIAS_PARAMS = ['subid', 'status', 'payout', 'tid'];
// What the postback handler accepts out of the box. The settings response
// carries the same list (postback_builtin_aliases); this copy only serves a
// response that came without it.
const FALLBACK_BUILTIN_ALIASES = {
    subid: ['clickid', 'click_id', 'sub_id'],
    status: ['type'],
    payout: ['revenue', 'profit'],
    tid: []
};

const aliasChipStyle = {
    padding: '4px 10px',
    background: 'var(--color-bg-soft)',
    borderRadius: '10px',
    fontSize: '13px',
    display: 'flex',
    alignItems: 'center',
    gap: '6px'
};

// The key the repository ships with — public, so an install must not keep it.
// Mirrors orbitraPublicPostbackKey() in core/postback_key.php.
const PUBLIC_DEFAULT_KEY = 'fd12e72';

const PostbackSettings = () => {
    const { t } = useLanguage();
    const [settings, setSettings] = useState({
        postback_key: 'fd12e72',
        currency: 'USD',
        postback_aliases: {}
    });
    // The key as stored, whatever is typed in the field: the warning is about
    // the key in effect, and the server is asked to rotate this exact one.
    const [storedKey, setStoredKey] = useState('');
    const [postbackUrl, setPostbackUrl] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [rotating, setRotating] = useState(false);
    const [keyNotice, setKeyNotice] = useState(null);
    const [testSubid, setTestSubid] = useState('');
    const [testStatus, setTestStatus] = useState('lead');
    const [testPayout, setTestPayout] = useState('10');
    const [testResult, setTestResult] = useState(null);
    const [copied, setCopied] = useState(false);
    const [success, setSuccess] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        fetchSettings();
    }, []);

    const showSuccess = (msg) => {
        setSuccess(msg);
        setTimeout(() => setSuccess(''), 3000);
    };

    const fetchSettings = async () => {
        setLoading(true);
        setError('');
        try {
            const [settingsRes, urlRes] = await Promise.all([
                axios.get(`${API_URL}?action=settings`),
                axios.get(`${API_URL}?action=postback_url`)
            ]);

            if (settingsRes.data.status === 'success') {
                setSettings(prev => ({ ...prev, ...settingsRes.data.data }));
                setStoredKey(String(settingsRes.data.data.postback_key ?? ''));
            }
            if (urlRes.data.status === 'success') {
                setPostbackUrl(urlRes.data.data.postback_url);
            }
        } catch (err) {
            console.error('Error fetching settings:', err);
            setError((err?.message ? String(err.message) : t('common.networkError')));
        } finally {
            setLoading(false);
        }
    };

    // The aliases live in `settings`, with everything else the page saves:
    // they used to sit in a state of their own that nothing loaded or sent,
    // so the list reset on every reload.
    const builtinAliases = useMemo(() => (
        settings.postback_builtin_aliases && typeof settings.postback_builtin_aliases === 'object'
            ? settings.postback_builtin_aliases
            : FALLBACK_BUILTIN_ALIASES
    ), [settings.postback_builtin_aliases]);
    // alias => parameter. An empty set arrives as [] from older responses.
    const customAliases = useMemo(() => (
        settings.postback_aliases && !Array.isArray(settings.postback_aliases) ? settings.postback_aliases : {}
    ), [settings.postback_aliases]);
    // Every name in use, and the parameter it stands for.
    const aliasOwner = useMemo(() => {
        const owner = {};
        ALIAS_PARAMS.forEach(param => (builtinAliases[param] || []).forEach(alias => { owner[alias] = param; }));
        Object.entries(customAliases).forEach(([alias, param]) => { owner[alias] = param; });
        return owner;
    }, [builtinAliases, customAliases]);

    // A new postback key without the CLI. The server stores it and moves every
    // stored affiliate-network Postback URL onto it in one go; what it cannot
    // do is update the networks' own cabinets, which is what the confirmation
    // says before anything changes.
    const handleRotateKey = async () => {
        if (!window.confirm(t('postback.keyRotateConfirm'))) return;
        setRotating(true);
        setError('');
        setKeyNotice(null);
        try {
            // The stored key is the guard the server compares against; without it
            // (the settings never loaded) the rotation goes unguarded.
            const res = await axios.post(`${API_URL}?action=rotate_postback_key`, { current_key: storedKey || undefined });
            await fetchSettings();
            if (res.data?.status === 'success') {
                setKeyNotice('rotated');
            } else if (res.data?.code === 'postback_key_changed') {
                setKeyNotice('changed');
            } else {
                setError(res.data?.code === 'postback_key_invalid' ? t('postback.keyInvalid') : t('postback.keyRotateError'));
            }
        } catch (err) {
            console.error('Error rotating the postback key:', err);
            setError(t('postback.keyRotateError'));
        } finally {
            setRotating(false);
        }
    };

    const handleSave = async () => {
        if (!String(settings.postback_key || '').trim()) {
            setError(t('postback.keyInvalid'));
            return;
        }
        // A key typed by hand changes the postback address exactly like the
        // button does, so it gets the same confirmation.
        if (String(settings.postback_key).trim() !== storedKey && !window.confirm(t('postback.keyRotateConfirm'))) {
            return;
        }
        setSaving(true);
        setError('');
        setSuccess('');
        try {
            const res = await axios.post(`${API_URL}?action=save_settings`, {
                postback_key: settings.postback_key,
                currency: settings.currency,
                postback_aliases: customAliases
            });
            if (res.data?.status !== 'success') {
                setError(res.data?.code === 'postback_key_invalid' ? t('postback.keyInvalid') : (res.data?.message || t('common.networkError')));
                return;
            }
            showSuccess(t('postback.saved'));
            fetchSettings();
        } catch (err) {
            console.error('Error saving settings:', err);
            setError((err?.message ? String(err.message) : t('common.networkError')));
        } finally {
            setSaving(false);
        }
    };

    const handleTestPostback = async () => {
        if (!testSubid) {
            setError(t('postback.clickSubid'));
            return;
        }

        setTestResult(null);
        try {
            const res = await axios.post(`${API_URL}?action=test_postback`, {
                subid: testSubid,
                status: testStatus,
                payout: parseFloat(testPayout) || 0
            });

            setTestResult({
                success: res.data.status === 'success',
                message: res.data.status === 'success' ? res.data.message : res.data.message
            });
        } catch (err) {
            setTestResult({
                success: false,
                message: err.response?.data?.message || err.message
            });
        }
    };

    const copyToClipboard = async (text) => {
        // utils/clipboard falls back to execCommand: plain-HTTP panels have
        // no navigator.clipboard and the old direct call threw into the void.
        if (await copyText(text)) {
            setCopied(true);
            showSuccess(t('postback.copied'));
            setTimeout(() => setCopied(false), 2000);
        }
    };

    const addAlias = (param) => {
        const alias = (prompt(t('postback.aliasPrompt', { param })) || '').trim();
        if (!alias) return;
        const owner = aliasOwner[alias];
        const clashes = ALIAS_PARAMS.includes(alias) || (owner !== undefined && owner !== param);
        if (!/^[A-Za-z0-9_]{1,64}$/.test(alias) || clashes) {
            setError(t('postback.aliasInvalid', { alias }));
            return;
        }
        setError('');
        // A built-in alias of this very parameter already works; nothing to add.
        if (owner === param) return;
        setSettings(prev => ({
            ...prev,
            postback_aliases: { ...customAliases, [alias]: param }
        }));
    };

    const removeAlias = (alias) => {
        const next = { ...customAliases };
        delete next[alias];
        setSettings(prev => ({ ...prev, postback_aliases: next }));
    };

    if (loading) {
        return (
            <div className="empty-state">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {success && <div className="alert alert-success">{success}</div>}
            {error && <div className="alert alert-danger">{error}</div>}
            {storedKey === PUBLIC_DEFAULT_KEY && (
                <div className="alert alert-warning">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
                        <AlertCircle size={18} style={{ flexShrink: 0 }} />
                        <span style={{ flex: 1, minWidth: '240px' }}>{t('postback.postbackDefaultKeyWarning')}</span>
                        <button
                            type="button"
                            onClick={handleRotateKey}
                            disabled={rotating}
                            className="btn btn-primary btn-sm"
                            style={{ flexShrink: 0 }}
                        >
                            {rotating ? <RefreshCw className="animate-spin" size={16} /> : <RefreshCw size={16} />}
                            {t('postback.keyRotate')}
                        </button>
                    </div>
                </div>
            )}
            {/* Stays up, unlike the 3-second save toast: it names the one thing
                the operator still has to do by hand. */}
            {keyNotice === 'rotated' && (
                <div className="alert alert-success" role="status">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <CheckCircle size={18} style={{ flexShrink: 0 }} />
                        <span>{t('postback.keyRotated')}</span>
                    </div>
                </div>
            )}
            {keyNotice === 'changed' && (
                <div className="alert alert-warning" role="status">
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <AlertCircle size={18} style={{ flexShrink: 0 }} />
                        <span>{t('postback.keyRotatedElsewhere')}</span>
                    </div>
                </div>
            )}

            <InfoBanner storageKey="help_postback" title={t('help.postbackBannerTitle')}>
                <p>{t('help.postbackBanner')}</p>
                <p style={{ marginTop: '6px', fontStyle: 'italic' }}>{t('help.postbackMacros')}</p>
            </InfoBanner>

            {/* Postback URL */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div>
                        <h3 className="page-title" style={{ marginBottom: '4px' }}>{t('postback.title')}</h3>
                        <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                            {t('postback.subtitle')}
                        </p>
                    </div>
                </div>
                <div style={{ marginTop: '16px', display: 'flex', gap: '8px' }}>
                    <input
                        type="text"
                        value={postbackUrl}
                        readOnly
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    <button
                        onClick={() => copyToClipboard(postbackUrl)}
                        className="btn btn-primary btn-sm"
                        style={{ flexShrink: 0 }}
                    >
                        {copied ? <CheckCircle size={18} /> : <Copy size={18} />}
                        {copied ? t('postback.copied') : t('postback.copy')}
                    </button>
                </div>
            </div>

            {/* Example URLs */}
            <div className="page-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }}>
                    <Code size={20} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('postback.examplesTitle')}</h3>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                    <div style={{ padding: '12px 16px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '4px' }}>{t('postback.basicPostback')}</p>
                        <code style={{ color: 'var(--color-success)' }}>{postbackUrl}?subid={'{subid}'}&status=lead</code>
                    </div>
                    <div style={{ padding: '12px 16px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '4px' }}>{t('postback.withPayout')}</p>
                        <code style={{ color: 'var(--color-success)' }}>{postbackUrl}?subid={'{subid}'}&status=sale&payout={'{payout}'}</code>
                    </div>
                    <div style={{ padding: '12px 16px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '4px' }}>{t('postback.withTid')}</p>
                        <code style={{ color: 'var(--color-success)' }}>{postbackUrl}?subid={'{subid}'}&status=sale&payout={'{payout}'}&tid={'{transaction_id}'}</code>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-secondary)', marginTop: '6px' }}>{t('postback.withTidHint')}</p>
                    </div>
                    <div style={{ padding: '12px 16px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                        <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '4px' }}>{t('postback.statusMapping')}</p>
                        <code style={{ color: 'var(--color-success)' }}>{postbackUrl}?subid={'{subid}'}&status={'{status}'}&sale_status=approved,confirmed</code>
                    </div>
                </div>
            </div>

            {/* Settings */}
            <div className="page-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }}>
                    <Settings2 size={20} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('postback.settingsTitle')}</h3>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '16px' }}>
                    <div>
                        <label className="form-label">{t('postback.postbackKey')} <HelpTooltip textKey="help.postbackKeyTooltip" /></label>
                        <div className="relative">
                            <Key className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="text"
                                value={settings.postback_key}
                                onChange={(e) => setSettings(prev => ({ ...prev, postback_key: e.target.value }))}
                                className="form-input pl-12"
                            />
                        </div>
                        <div style={{ marginTop: '8px', display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                            <button
                                type="button"
                                onClick={handleRotateKey}
                                disabled={rotating}
                                className="btn btn-secondary btn-sm"
                            >
                                {rotating ? <RefreshCw className="animate-spin" size={16} /> : <RefreshCw size={16} />}
                                {t('postback.keyRotate')}
                            </button>
                            <span style={{ fontSize: '12px', color: 'var(--color-text-muted)' }}>
                                {t('postback.postbackKeyHint')}
                            </span>
                        </div>
                    </div>
                    <div>
                        <label className="form-label">{t('postback.defaultCurrency')}</label>
                        <select
                            value={settings.currency}
                            onChange={(e) => setSettings(prev => ({ ...prev, currency: e.target.value }))}
                            className="form-select"
                        >
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="RUB">RUB</option>
                            <option value="GBP">GBP</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Aliases */}
            <div className="page-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                    <Plus size={20} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('postback.aliasesTitle')} <HelpTooltip textKey="help.postbackAliasesTooltip" /></h3>
                </div>
                <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', marginBottom: '8px' }}>
                    {t('postback.aliasesDesc')}
                </p>
                <p style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '16px' }}>
                    {t('postback.aliasesHint')}
                </p>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                    {ALIAS_PARAMS.map(param => {
                        // The server sends the built-ins grouped by parameter; the
                        // configured ones come as one map and are grouped here.
                        const builtin = builtinAliases[param] || [];
                        const custom = Object.entries(customAliases)
                            .filter(([, target]) => target === param)
                            .map(([alias]) => alias);
                        return (
                            <div key={param} style={{ display: 'flex', alignItems: 'center', gap: '16px', padding: '12px 0', borderBottom: '1px solid var(--color-border)' }}>
                                <div style={{ width: '80px', fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>{param}</div>
                                <div style={{ flex: 1, display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px' }}>
                                    {builtin.map(alias => (
                                        <span
                                            key={alias}
                                            title={t('postback.builtinAlias')}
                                            style={{ ...aliasChipStyle, color: 'var(--color-text-muted)', cursor: 'help' }}
                                        >
                                            <Lock size={11} />
                                            {alias}
                                        </span>
                                    ))}
                                    {custom.map(alias => (
                                        <span key={alias} style={aliasChipStyle}>
                                            {alias}
                                            <button
                                                type="button"
                                                onClick={() => removeAlias(alias)}
                                                aria-label={`${t('common.delete')} ${alias}`}
                                                style={{ color: 'var(--color-danger)', display: 'flex', alignItems: 'center', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                                            >
                                                <Trash2 size={12} />
                                            </button>
                                        </span>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() => addAlias(param)}
                                        className="btn btn-secondary btn-sm"
                                        style={{ padding: '4px 10px' }}
                                    >
                                        <Plus size={12} />
                                        {t('common.create')}
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Test Postback */}
            <div className="page-card">
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }}>
                    <TestTube2 size={20} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('postback.testTitle')}</h3>
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '12px' }}>
                    <div>
                        <label className="form-label">{t('postback.clickSubid')}</label>
                        <input
                            type="text"
                            value={testSubid}
                            onChange={(e) => setTestSubid(e.target.value)}
                            placeholder={t('postback.clickIdPlaceholder')}
                            className="form-input"
                        />
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.status')}</label>
                        <select
                            value={testStatus}
                            onChange={(e) => setTestStatus(e.target.value)}
                            className="form-select"
                        >
                            <option value="lead">{t('conversions.lead')}</option>
                            <option value="sale">{t('conversions.sale')}</option>
                            <option value="rejected">{t('conversions.rejected')}</option>
                            <option value="registration">{t('conversions.registration')}</option>
                            <option value="deposit">{t('conversions.deposit')}</option>
                        </select>
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.payout')}</label>
                        <input
                            type="number"
                            value={testPayout}
                            onChange={(e) => setTestPayout(e.target.value)}
                            className="form-input"
                        />
                    </div>
                    <div style={{ display: 'flex', alignItems: 'flex-end' }}>
                        <button
                            onClick={handleTestPostback}
                            className="btn btn-primary"
                            style={{ width: '100%' }}
                        >
                            <TestTube size={18} />
                            {t('postback.test')}
                        </button>
                    </div>
                </div>
                {testResult && (
                    <div className={`alert ${testResult.success ? 'alert-success' : 'alert-danger'}`} style={{ marginTop: '16px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                            {testResult.success ? <CheckCircle size={18} /> : <AlertCircle size={18} />}
                            <span>{testResult.message}</span>
                        </div>
                    </div>
                )}
            </div>

            {/* Parameters reference */}
            <div className="page-card" style={{ padding: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '24px 24px 0 24px' }}>
                    <Table size={20} style={{ color: 'var(--color-primary)' }} />
                    <h3 className="page-title" style={{ margin: 0 }}>{t('postback.paramsTitle')}</h3>
                </div>
                <div className="overflow-x-auto" style={{ padding: '16px' }}>
                    <table className="page-table">
                        <thead>
                            <tr>
                                {/* data-col opts the headers out of the panel-wide centred default:
                                    every value here sits on the left edge of its column, and a
                                    centred header floated far right of its own values. The first
                                    two columns take their content's width, the description gets
                                    the rest of the row. */}
                                <th data-col style={{ width: '1%', whiteSpace: 'nowrap' }}>{t('postback.param')}</th>
                                <th data-col style={{ width: '1%', whiteSpace: 'nowrap' }}>{t('postback.required')}</th>
                                <th data-col>{t('postback.description')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>subid</code></td>
                                <td><span className="status-badge status-active">{t('clickDetails.yes')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.subidDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>status</code></td>
                                <td><span className="status-badge status-active">{t('clickDetails.yes')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.statusDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>payout</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.payoutDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>tid</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.tidDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>currency</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.currencyDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>sub_id_1-30</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.subIdDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>sale_status</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.saleStatusDesc')}</td>
                            </tr>
                            <tr>
                                <td><code style={{ background: 'transparent', padding: 0, color: 'var(--color-accent-turquoise)' }}>lead_status</code></td>
                                <td><span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>{t('clickDetails.no')}</span></td>
                                <td style={{ color: 'var(--color-text-secondary)' }}>{t('postback.leadStatusDesc')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Save Button */}
            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                <button
                    onClick={handleSave}
                    disabled={saving}
                    className="btn btn-primary"
                >
                    {saving ? <RefreshCw className="animate-spin" size={18} /> : <Save size={18} />}
                    <span>{t('postback.saveSettings')}</span>
                </button>
            </div>
        </div>
    );
};

export default PostbackSettings;