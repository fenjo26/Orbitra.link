import React, { useState, useEffect } from 'react';
import { Plus, Globe, Check, X, AlertCircle, Search, Copy, Edit2, Trash2, ShieldAlert, RefreshCw, Clock } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost } from '../utils/apiCache';

const Domains = ({ campaigns }) => {
    const { t } = useLanguage();
    const [domains, setDomains] = useState([]);
    const [filteredDomains, setFilteredDomains] = useState([]);
    const [searchTerm, setSearchTerm] = useState('');
    const [serverIp, setServerIp] = useState('');
    const [loading, setLoading] = useState(true);
    const [ignoreDnsUi, setIgnoreDnsUi] = useState(() => {
        // UI-only toggle for migrations/tests when DNS isn't set yet.
        // Do not use this in production to "fix" misconfigured DNS.
        const v = localStorage.getItem('domains_ignore_dns_ui');
        return v === '1';
    });
    const [copiedIp, setCopiedIp] = useState(false);
    const [forceChecking, setForceChecking] = useState(false);

    // Edit Modal State
    const [showModal, setShowModal] = useState(false);
    const [formData, setFormData] = useState({
        id: null, name: '', index_campaign_id: '', catch_404: false,
        group_id: '', is_noindex: true, https_only: false
    });
    const [error, setError] = useState('');

    // DNS Warning Modal State
    const [showDnsModal, setShowDnsModal] = useState(false);

    useEffect(() => {
        fetchDomains();
    }, []);
    const fetchDomains = async () => {
        try {
            const { data } = await cachedGet('domains');
            if (data.status === 'success') {
                setDomains(data.data);
                setFilteredDomains(data.data);
                setServerIp(data.server_ip || t('common.notSet'));
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const lowercased = searchTerm.toLowerCase();
        setFilteredDomains(domains.filter(d => d.name.toLowerCase().includes(lowercased)));
    }, [searchTerm, domains]);

    useEffect(() => {
        localStorage.setItem('domains_ignore_dns_ui', ignoreDnsUi ? '1' : '0');
    }, [ignoreDnsUi]);

    // Poll for SSL status updates every 5 seconds when there are pending/installing domains
    useEffect(() => {
        const interval = setInterval(async () => {
            // Only poll if there are domains with pending/installing SSL
            const hasPending = domains.some(d => d.https_only && ['pending', 'installing'].includes(d.ssl_status));
            if (hasPending) {
                await fetchDomains();
            }
        }, 5000); // Check every 5 seconds
        return () => clearInterval(interval);
    }, [domains]);

    const handleEdit = (domain) => {
        setFormData({
            id: domain.id,
            name: domain.name,
            index_campaign_id: domain.index_campaign_id || '',
            catch_404: domain.catch_404 === 1,
            group_id: domain.group_id || '',
            is_noindex: domain.is_noindex === 1,
            https_only: domain.https_only === 1
        });
        setError('');
        setShowModal(true);
    };

    const forceCheckAllDns = async () => {
        if (!window.confirm(t('domains.forceCheckConfirm'))) return;
        setForceChecking(true);
        try {
            const { data } = await cachedGet('force_check_all_dns');
            if (data.status === 'success') {
                fetchDomains();
            }
        } catch (e) {
            console.error(e);
        } finally {
            setForceChecking(false);
        }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('domains.deleteConfirm'))) return;
        try {
            await cachedPost('delete_domain', { id });
            fetchDomains();
        } catch (e) {
            console.error(e);
        }
    };

    const copyIp = async () => {
        try {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(serverIp);
                setCopiedIp(true);
                setTimeout(() => setCopiedIp(false), 2000);
                return;
            }
        } catch (err) {
            console.warn('Clipboard API failed, falling back to execCommand', err);
        }

        // Fallback for non-HTTPS contexts
        try {
            const textarea = document.createElement('textarea');
            textarea.value = serverIp;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '-9999px';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);

            if (successful) {
                setCopiedIp(true);
                setTimeout(() => setCopiedIp(false), 2000);
            } else {
                throw new Error('execCommand failed');
            }
        } catch (err) {
            console.error('Failed to copy IP:', err);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        try {
            const res = await cachedPost('save_domain', formData);
            if (res.data.status === 'success') {
                setShowModal(false);
                setFormData({ id: null, name: '', index_campaign_id: '', catch_404: false, group_id: '', is_noindex: true, https_only: false });
                fetchDomains();
            } else {
                setError(res.data.message || t('common.error'));
            }
        } catch (e) {
            setError(t('common.networkError'));
        }
    };

    return (
        <div className="page-card mb-6">
            <InfoBanner storageKey="help_domains" title={t('help.domainBannerTitle')}>
                <p>{t('help.domainBanner')}</p>
            </InfoBanner>
            <div className="flex justify-between items-center mb-6">
                <div className="flex items-center gap-4">
                    <h2 className="text-lg font-semibold flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                        <Globe size={20} style={{ color: 'var(--color-text-secondary)' }} />
                        {t('domains.title')}
                    </h2>
                    {serverIp && (
                        <div className="flex items-center px-3 py-1 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}>
                            <span className="font-medium mr-2">{t('domains.serverIp')}</span>
                            <span className="font-mono">{serverIp}</span>
                            <button onClick={copyIp} className="ml-2 hover:text-[var(--color-primary)] transition flex items-center gap-1" style={{ color: 'var(--color-text-secondary)' }} title={copiedIp ? t('migrations.copied') : t('common.copy')}>
                                {copiedIp ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                                {copiedIp && <span className="text-xs text-green-500">{t('migrations.copied')}</span>}
                            </button>
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4" style={{ color: 'var(--color-text-secondary)' }} />
                        <input
                            type="text"
                            placeholder={t('domains.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="form-input w-64"
                            style={{ paddingLeft: '36px' }}
                        />
                    </div>
                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border" style={{ background: 'var(--color-bg-soft)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }} title={t('domains.ignoreDnsHint')}>
                        <input
                            type="checkbox"
                            checked={ignoreDnsUi}
                            onChange={(e) => setIgnoreDnsUi(Boolean(e.target.checked))}
                        />
                        <span style={{ color: 'var(--color-text-primary)' }}>{t('domains.ignoreDnsLabel')}</span>
                    </label>
                    <button
                        onClick={forceCheckAllDns}
                        disabled={forceChecking}
                        className="btn flex items-center gap-2"
                        style={{
                            background: forceChecking ? 'var(--color-bg-soft)' : 'var(--color-success, #10b981)',
                            color: forceChecking ? 'var(--color-text-muted)' : 'white',
                            cursor: forceChecking ? 'not-allowed' : 'pointer'
                        }}
                        title={t('domains.forceCheckTitle')}
                    >
                        <RefreshCw size={16} className={forceChecking ? 'animate-spin' : ''} />
                        {forceChecking ? t('domains.checkingShort') : t('domains.checkDns')}
                    </button>
                    <button
                        onClick={() => {
                            setFormData({ id: null, name: '', index_campaign_id: '', catch_404: false, group_id: '', is_noindex: true, https_only: false });
                            setShowModal(true);
                        }}
                        className="btn btn-primary"
                    >
                        <Plus size={16} /> {t('domains.addDomain')}
                    </button>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="page-table">
                    <thead>
                        <tr>
                            <th>{t('domains.domain')}</th>
                            <th>{t('domains.status')}</th>
                            <th>{t('domains.indexPage')}</th>
                            <th className="text-center">{t('domains.https')}</th>
                            <th className="text-center">{t('domains.sslStatus')}</th>
                            <th>{t('domains.dateAdded')}</th>
                            <th className="text-right">{t('domains.actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr><td colSpan="7" className="text-center py-8">{t('domains.loading')}</td></tr>
                        ) : filteredDomains.length === 0 ? (
                            <tr><td colSpan="7" className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('domains.noDomains')}</td></tr>
                        ) : (
                            filteredDomains.map(domain => (
                                <tr key={domain.id}>
                                    <td className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{domain.name}</td>
                                    <td>
                                        {(ignoreDnsUi || domain.status === 'active') ? (
                                            <span className="badge badge-success">
                                                <Check size={14} /> {t('domains.ok')}
                                            </span>
                                        ) : (
                                            <button
                                                onClick={() => setShowDnsModal(true)}
                                                className="badge badge-danger cursor-pointer hover:bg-red-500/20 transition"
                                            >
                                                <ShieldAlert size={14} /> {t('domains.awaitingDns')}
                                            </button>
                                        )}
                                    </td>
                                    <td>{domain.index_campaign_name || <span className="italic" style={{ color: 'var(--color-text-muted)' }}>{t('domains.notSelected')}</span>}</td>
                                    <td className="text-center">
                                        {domain.https_only ? <Check size={16} className="text-green-500 mx-auto" /> : <X size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} />}
                                    </td>
                                    <td className="text-center">
                                        {domain.https_only ? (
                                            domain.ssl_status === 'installed' ? (
                                                <Check size={16} className="text-green-500 mx-auto" title={t('domains.sslInstalled')} />
                                            ) : domain.ssl_status === 'installing' ? (
                                                <RefreshCw size={16} className="text-blue-500 mx-auto animate-spin" title={t('domains.sslInstalling')} />
                                            ) : domain.ssl_status === 'failed' ? (
                                                <X size={16} className="text-red-500 mx-auto" title={domain.ssl_error || t('domains.sslFailed')} />
                                            ) : domain.ssl_status === 'pending' ? (
                                                <Clock size={16} className="text-yellow-500 mx-auto" title={t('domains.sslPending')} />
                                            ) : (
                                                <Clock size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} title={t('domains.sslPending')} />
                                            )
                                        ) : (
                                            <X size={16} className="mx-auto" style={{ color: 'var(--color-text-muted)' }} />
                                        )}
                                    </td>
                                    <td className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{domain.created_at}</td>
                                    <td className="text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <button onClick={() => handleEdit(domain)} className="hover:text-[var(--color-primary)] transition" style={{ color: 'var(--color-text-muted)' }} title={t('components.edit')}>
                                                <Edit2 size={16} />
                                            </button>
                                            <button onClick={() => handleDelete(domain.id)} className="hover:text-red-500 transition" style={{ color: 'var(--color-text-muted)' }} title={t('common.delete')}>
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {showModal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title">{formData.id ? t('domains.editDomain') : t('domains.addDomainTitle')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowModal(false)}>
                                <X size={20} />
                            </button>
                        </div>
                        {error && <div className="alert alert-danger mb-4 flex items-center gap-2"><AlertCircle size={16} />{error}</div>}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    {t('domains.domainName')}{' '}
                                    <span className="text-xs font-normal" style={{ color: 'var(--color-text-muted)' }}>({t('domains.bulkHint')})</span>
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    className="form-input w-full"
                                    placeholder={t('domains.bulkPlaceholder')}
                                    value={formData.name}
                                    onChange={e => setFormData({ ...formData, name: e.target.value.toLowerCase() })}
                                />
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.bulkExample')}</p>
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.indexPageLabel')} <HelpTooltip textKey="help.indexCampaignTooltip" /></label>
                                <select
                                    className="form-select w-full"
                                    value={formData.index_campaign_id} onChange={e => setFormData({ ...formData, index_campaign_id: e.target.value })}
                                >
                                    <option value="">-- {t('domains.notSelected')} --</option>
                                    {campaigns.map(c => (
                                        <option key={c.id} value={c.id}>{c.name} ({c.alias})</option>
                                    ))}
                                </select>
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.indexPageHint')}</p>
                            </div>

                            <div className="flex items-center gap-2 mt-2">
                                <input
                                    type="checkbox" id="catch404"
                                    checked={formData.catch_404} onChange={e => setFormData({ ...formData, catch_404: e.target.checked })}
                                />
                                <label htmlFor="catch404" className="text-sm font-medium cursor-pointer">{t('domains.catch404')}</label>
                            </div>

                            <hr className="my-3" style={{ borderColor: 'var(--color-border)' }} />

                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.searchRobots')}</label>
                                <select
                                    className="form-select w-full"
                                    value={formData.is_noindex ? '1' : '0'} onChange={e => setFormData({ ...formData, is_noindex: e.target.value === '1' })}
                                >
                                    <option value="1">{t('domains.disallowRobots')}</option>
                                    <option value="0">{t('domains.allowRobots')}</option>
                                </select>
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t('domains.robotsHint')}</p>
                            </div>

                            <div className="flex items-center gap-2 pt-2">
                                <input
                                    type="checkbox" id="https_only"
                                    checked={formData.https_only} onChange={e => setFormData({ ...formData, https_only: e.target.checked })}
                                />
                                <label htmlFor="https_only" className="text-sm font-medium cursor-pointer">{t('domains.httpsOnly')} <HelpTooltip textKey="help.httpsTooltip" /></label>
                            </div>

                            <div className="modal-footer mt-6">
                                <button type="button" onClick={() => setShowModal(false)} className="btn btn-secondary">{t('common.cancel')}</button>
                                <button type="submit" className="btn btn-primary">{t('common.save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* DNS Warning Modal */}
            {showDnsModal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: 0 }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <AlertCircle className="text-orange-500" /> {t('domains.dnsTitle')}
                            </h3>
                            <button onClick={() => setShowDnsModal(false)} className="btn btn-ghost btn-icon">
                                <X size={20} />
                            </button>
                        </div>
                        <div className="p-6">
                            <p className="text-sm mb-4" style={{ color: 'var(--color-text-primary)' }}>
                                {t('domains.dnsInstruction')}
                            </p>
                            <div className="bg-[var(--color-bg-soft)] border border-[var(--color-border)] rounded p-4 mb-4 font-mono text-sm text-center text-[var(--color-text-primary)]">
                                @ &nbsp;&nbsp; IN &nbsp;&nbsp; <span className="font-bold text-blue-600">{serverIp}</span>
                            </div>
                            <p className="text-sm mb-2 items-center flex gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                <span className="w-1.5 h-1.5 rounded-full bg-gray-400"></span> {t('domains.dnsNote1')}
                            </p>
                            <p className="text-sm items-center flex gap-2" style={{ color: 'var(--color-text-secondary)' }}>
                                <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span> {t('domains.dnsNote2')}
                            </p>
                        </div>
                        <div className="modal-footer">
                            <button onClick={() => setShowDnsModal(false)} className="btn btn-secondary">{t('common.close')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Domains;
