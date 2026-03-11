import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Plus, Globe, Check, X, AlertCircle, Search, Copy, Edit2, Trash2, ShieldAlert } from 'lucide-react';
import InfoBanner from './InfoBanner';
import HelpTooltip from './HelpTooltip';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

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
            const res = await axios.get(`${API_URL}?action=domains`);
            if (res.data.status === 'success') {
                setDomains(res.data.data);
                setFilteredDomains(res.data.data);
                setServerIp(res.data.server_ip || t('common.notSet'));
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

    const handleDelete = async (id) => {
        if (!window.confirm(t('domains.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=delete_domain`, { id });
            fetchDomains();
        } catch (e) {
            console.error(e);
        }
    };

    const copyIp = async () => {
        try {
            await navigator.clipboard.writeText(serverIp);
        } catch (err) {
            console.error(err);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        try {
            const res = await axios.post(`${API_URL}?action=save_domain`, formData);
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
        <div className="bg-white rounded shadow-sm p-5 mb-6">
            <InfoBanner storageKey="help_domains" title={t('help.domainBannerTitle')}>
                <p>{t('help.domainBanner')}</p>
            </InfoBanner>
            <div className="flex justify-between items-center mb-6">
                <div className="flex items-center gap-4">
                    <h2 className="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <Globe size={20} className="text-gray-500" />
                        {t('domains.title')}
                    </h2>
                    {serverIp && (
                        <div className="flex items-center bg-blue-50 text-blue-800 px-3 py-1 rounded text-sm border border-blue-100">
                            <span className="font-medium mr-2">{t('domains.serverIp')}</span>
                            <span className="font-mono">{serverIp}</span>
                            <button onClick={copyIp} className="ml-2 hover:text-blue-600 transition" title={t('common.copy')}>
                                <Copy size={14} />
                            </button>
                        </div>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 w-4 h-4" />
                        <input
                            type="text"
                            placeholder={t('domains.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="pl-9 pr-4 py-2 border border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500 w-64"
                        />
                    </div>
                    <label className="inline-flex items-center gap-2 px-3 py-2 rounded text-sm border border-gray-200 bg-white" title={t('domains.ignoreDnsHint')}>
                        <input
                            type="checkbox"
                            checked={ignoreDnsUi}
                            onChange={(e) => setIgnoreDnsUi(Boolean(e.target.checked))}
                        />
                        <span className="text-gray-700">{t('domains.ignoreDnsLabel')}</span>
                    </label>
                    <button
                        onClick={() => {
                            setFormData({ id: null, name: '', index_campaign_id: '', catch_404: false, group_id: '', is_noindex: true, https_only: false });
                            setShowModal(true);
                        }}
                        className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition"
                    >
                        <Plus size={16} /> {t('domains.addDomain')}
                    </button>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm border-collapse">
                    <thead className="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('domains.domain')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('domains.status')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('domains.indexPage')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600 text-center">{t('domains.https')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600">{t('domains.dateAdded')}</th>
                            <th className="px-5 py-3 font-semibold text-gray-600 text-right">{t('domains.actions')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {loading ? (
                            <tr><td colSpan="6" className="text-center py-8">{t('domains.loading')}</td></tr>
                        ) : filteredDomains.length === 0 ? (
                            <tr><td colSpan="6" className="text-center py-8 text-gray-500">{t('domains.noDomains')}</td></tr>
                        ) : (
                            filteredDomains.map(domain => (
                                <tr key={domain.id} className="hover:bg-gray-50 transition">
                                    <td className="px-5 py-3 font-medium text-gray-800">{domain.name}</td>
                                    <td className="px-5 py-3">
                                        {(ignoreDnsUi || domain.status === 'active') ? (
                                            <span className="inline-flex items-center gap-1 text-green-600 text-sm font-medium bg-green-50 px-2 py-1 rounded">
                                                <Check size={14} /> {t('domains.ok')}
                                            </span>
                                        ) : (
                                            <button
                                                onClick={() => setShowDnsModal(true)}
                                                className="inline-flex items-center gap-1 text-red-600 text-sm font-medium bg-red-50 px-2 py-1 rounded hover:bg-red-100 transition"
                                            >
                                                <ShieldAlert size={14} /> {t('domains.awaitingDns')}
                                            </button>
                                        )}
                                    </td>
                                    <td className="px-5 py-3 text-gray-600">{domain.index_campaign_name || <span className="text-gray-400 italic">{t('domains.notSelected')}</span>}</td>
                                    <td className="px-5 py-3 text-center">
                                        {domain.https_only ? <Check size={16} className="text-green-500 mx-auto" /> : <X size={16} className="text-gray-300 mx-auto" />}
                                    </td>
                                    <td className="px-5 py-3 text-gray-500 text-xs">{domain.created_at}</td>
                                    <td className="px-5 py-3 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            <button onClick={() => handleEdit(domain)} className="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded transition" title={t('components.edit')}>
                                                <Edit2 size={16} />
                                            </button>
                                            <button onClick={() => handleDelete(domain.id)} className="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded transition" title={t('common.delete')}>
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
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                        <h3 className="text-lg font-bold mb-4">{formData.id ? t('domains.editDomain') : t('domains.addDomainTitle')}</h3>
                        {error && <div className="bg-red-50 text-red-600 p-3 rounded text-sm mb-4 flex items-center gap-2"><AlertCircle size={16} />{error}</div>}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.domainName')} <span className="text-xs text-gray-400 font-normal">{t('domains.domainNameHint')}</span></label>
                                <input
                                    type="text" required
                                    className="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none"
                                    value={formData.name} onChange={e => setFormData({ ...formData, name: e.target.value.toLowerCase().trim() })}
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.indexPageLabel')} <HelpTooltip textKey="help.indexCampaignTooltip" /></label>
                                <select
                                    className="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none"
                                    value={formData.index_campaign_id} onChange={e => setFormData({ ...formData, index_campaign_id: e.target.value })}
                                >
                                    <option value="">-- {t('domains.notSelected')} --</option>
                                    {campaigns.map(c => (
                                        <option key={c.id} value={c.id}>{c.name} ({c.alias})</option>
                                    ))}
                                </select>
                                <p className="text-xs text-gray-500 mt-1">{t('domains.indexPageHint')}</p>
                            </div>

                            <div className="flex items-center gap-2 mt-2">
                                <input
                                    type="checkbox" id="catch404"
                                    checked={formData.catch_404} onChange={e => setFormData({ ...formData, catch_404: e.target.checked })}
                                />
                                <label htmlFor="catch404" className="text-sm font-medium cursor-pointer">{t('domains.catch404')}</label>
                            </div>

                            <hr className="my-3" />

                            <div>
                                <label className="block text-sm font-medium mb-1">{t('domains.searchRobots')}</label>
                                <select
                                    className="w-full border border-gray-300 p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none"
                                    value={formData.is_noindex ? '1' : '0'} onChange={e => setFormData({ ...formData, is_noindex: e.target.value === '1' })}
                                >
                                    <option value="1">{t('domains.disallowRobots')}</option>
                                    <option value="0">{t('domains.allowRobots')}</option>
                                </select>
                                <p className="text-xs text-gray-500 mt-1">{t('domains.robotsHint')}</p>
                            </div>

                            <div className="flex items-center gap-2 pt-2">
                                <input
                                    type="checkbox" id="https_only"
                                    checked={formData.https_only} onChange={e => setFormData({ ...formData, https_only: e.target.checked })}
                                />
                                <label htmlFor="https_only" className="text-sm font-medium cursor-pointer">{t('domains.httpsOnly')} <HelpTooltip textKey="help.httpsTooltip" /></label>
                            </div>

                            <div className="flex justify-end gap-3 mt-6 pt-4 border-t">
                                <button type="button" onClick={() => setShowModal(false)} className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded">{t('common.cancel')}</button>
                                <button type="submit" className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium">{t('common.save')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* DNS Warning Modal */}
            {showDnsModal && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl w-full max-w-md">
                        <div className="flex justify-between items-center p-4 border-b">
                            <h3 className="text-lg font-bold flex items-center gap-2 text-gray-800">
                                <AlertCircle className="text-orange-500" /> {t('domains.dnsTitle')}
                            </h3>
                            <button onClick={() => setShowDnsModal(false)} className="text-gray-400 hover:text-gray-600">
                                <X size={20} />
                            </button>
                        </div>
                        <div className="p-6">
                            <p className="text-sm text-gray-600 mb-4">
                                {t('domains.dnsInstruction')}
                            </p>
                            <div className="bg-gray-50 border border-gray-200 rounded p-4 mb-4 font-mono text-sm text-center">
                                @ &nbsp;&nbsp; IN &nbsp;&nbsp; <span className="font-bold text-blue-600">{serverIp}</span>
                            </div>
                            <p className="text-sm text-gray-500 mb-2 items-center flex gap-2">
                                <span className="w-1.5 h-1.5 rounded-full bg-gray-400"></span> {t('domains.dnsNote1')}
                            </p>
                            <p className="text-sm text-gray-500 items-center flex gap-2">
                                <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span> {t('domains.dnsNote2')}
                            </p>
                        </div>
                        <div className="p-4 border-t bg-gray-50 flex justify-end">
                            <button onClick={() => setShowDnsModal(false)} className="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded font-medium">{t('common.close')}</button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Domains;
