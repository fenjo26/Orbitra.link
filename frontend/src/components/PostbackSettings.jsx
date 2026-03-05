import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Save, Copy, RefreshCw, TestTube, Plus, Trash2, AlertCircle, CheckCircle, Code, Settings2, Key, TestTube2, Table } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const PostbackSettings = () => {
    const { t } = useLanguage();
    const [settings, setSettings] = useState({
        postback_key: 'fd12e72',
        currency: 'USD',
        postback_aliases: {}
    });
    const [postbackUrl, setPostbackUrl] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testSubid, setTestSubid] = useState('');
    const [testStatus, setTestStatus] = useState('lead');
    const [testPayout, setTestPayout] = useState('10');
    const [testResult, setTestResult] = useState(null);
    const [copied, setCopied] = useState(false);
    const [success, setSuccess] = useState('');
    const [error, setError] = useState('');

    const [aliases, setAliases] = useState([
        { param: 'subid', aliases: ['clickid', 'click_id', 'sub_id'] },
        { param: 'status', aliases: ['type', 'state', 'event'] },
        { param: 'payout', aliases: ['revenue', 'profit', 'sum', 'amount'] },
        { param: 'tid', aliases: ['transaction_id', 'txid', 'order_id'] },
    ]);

    useEffect(() => {
        fetchSettings();
    }, []);

    const showSuccess = (msg) => {
        setSuccess(msg);
        setTimeout(() => setSuccess(''), 3000);
    };

    const fetchSettings = async () => {
        setLoading(true);
        try {
            const [settingsRes, urlRes] = await Promise.all([
                axios.get(`${API_URL}?action=settings`),
                axios.get(`${API_URL}?action=postback_url`)
            ]);

            if (settingsRes.data.status === 'success') {
                setSettings(prev => ({ ...prev, ...settingsRes.data.data }));
            }
            if (urlRes.data.status === 'success') {
                setPostbackUrl(urlRes.data.data.postback_url);
            }
        } catch (err) {
            console.error('Error fetching settings:', err);
            setError(t('common.networkError'));
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            await axios.post(`${API_URL}?action=save_settings`, {
                postback_key: settings.postback_key,
                currency: settings.currency,
                postback_aliases: settings.postback_aliases
            });
            showSuccess(t('postback.copied'));
            fetchSettings();
        } catch (err) {
            console.error('Error saving settings:', err);
            setError(t('common.networkError'));
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

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        showSuccess(t('postback.copied'));
        setTimeout(() => setCopied(false), 2000);
    };

    const addAlias = (param) => {
        const newAlias = prompt(t('common.create') + ':');
        if (newAlias) {
            setAliases(prev => prev.map(a =>
                a.param === param
                    ? { ...a, aliases: [...a.aliases, newAlias] }
                    : a
            ));
        }
    };

    const removeAlias = (param, alias) => {
        setAliases(prev => prev.map(a =>
            a.param === param
                ? { ...a, aliases: a.aliases.filter(al => al !== alias) }
                : a
        ));
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
                        <p style={{ marginTop: '4px', fontSize: '12px', color: 'var(--color-text-muted)' }}>
                            {t('postback.postbackKeyHint')}
                        </p>
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
                <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', marginBottom: '16px' }}>
                    {t('postback.aliasesDesc')}
                </p>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                    {aliases.map(({ param, aliases: aliasList }) => (
                        <div key={param} style={{ display: 'flex', alignItems: 'center', gap: '16px', padding: '12px 0', borderBottom: '1px solid var(--color-border)' }}>
                            <div style={{ width: '80px', fontSize: '14px', fontWeight: 500, color: 'var(--color-text-primary)' }}>{param}</div>
                            <div style={{ flex: 1, display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                                {aliasList.map(alias => (
                                    <span
                                        key={alias}
                                        style={{
                                            padding: '4px 10px',
                                            background: 'var(--color-bg-soft)',
                                            borderRadius: '10px',
                                            fontSize: '13px',
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: '6px'
                                        }}
                                    >
                                        {alias}
                                        <button
                                            onClick={() => removeAlias(param, alias)}
                                            style={{ color: 'var(--color-danger)', display: 'flex', alignItems: 'center', background: 'none', border: 'none', cursor: 'pointer' }}
                                        >
                                            <Trash2 size={12} />
                                        </button>
                                    </span>
                                ))}
                                <button
                                    onClick={() => addAlias(param)}
                                    className="btn btn-secondary btn-sm"
                                    style={{ padding: '4px 10px' }}
                                >
                                    <Plus size={12} />
                                    {t('common.create')}
                                </button>
                            </div>
                        </div>
                    ))}
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
                                <th>{t('postback.param')}</th>
                                <th>{t('postback.required')}</th>
                                <th>{t('postback.description')}</th>
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