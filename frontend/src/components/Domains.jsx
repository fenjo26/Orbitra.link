import React, { useState, useEffect } from 'react';
import { Plus, Globe, Check, X, AlertCircle, Search, Copy, Edit2, Trash2, ShieldAlert, RefreshCw, Server, Lock } from 'lucide-react';
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
    const [nginxStatus, setNginxStatus] = useState(null);
    const [nginxLoading, setNginxLoading] = useState(false);

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
        if (!window.confirm(t('domains.forceCheckConfirm') || 'Проверить DNS для всех доменов? Это может занять время.')) return;
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

    const fetchNginxStatus = async () => {
        try {
            setNginxLoading(true);
            const { data } = await cachedGet('get_nginx_status');
            if (data.status === 'success') {
                setNginxStatus(data.data);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setNginxLoading(false);
        }
    };

    const updateNginxConfig = async () => {
        if (!window.confirm('Обновить конфигурацию Nginx? Это перезагрузит веб-сервер.')) return;
        try {
            setNginxLoading(true);
            const { data } = await cachedGet('update_nginx_config');
            if (data.status === 'success') {
                alert(data.message);
                fetchNginxStatus();
            } else {
                alert('Ошибка: ' + data.message);
            }
        } catch (e) {
            alert('Ошибка обновления Nginx: ' + e.message);
        } finally {
            setNginxLoading(false);
        }
    };

    const installSslCertificates = async () => {
        const email = prompt('Введите email для уведомлений об истечении SSL (или оставьте пустым):');
        if (email === null) return; // Cancelled

        if (!window.confirm('Установить SSL сертификаты через Let\'s Encrypt?\n\nВажно:\n- Домены должны резолвиться на этот IP\n- Порт 80 должен быть доступен\n- Установка займёт 1-2 минуты')) return;

        try {
            setNginxLoading(true);
            const { data } = await cachedPost('install_ssl_certificates', { email });
            if (data.status === 'success') {
                alert('✅ ' + data.message);
                fetchNginxStatus();
            } else {
                alert('❌ ' + data.message);
            }
        } catch (e) {
            alert('Ошибка установки SSL: ' + e.message);
        } finally {
            setNginxLoading(false);
        }
    };

    useEffect(() => {
        fetchNginxStatus();
    }, []);

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
                            <button onClick={copyIp} className="ml-2 hover:text-blue-600 transition flex items-center gap-1" title={copiedIp ? t('migrations.copied') : t('common.copy')}>
                                {copiedIp ? <Check size={14} className="text-green-600" /> : <Copy size={14} />}
                                {copiedIp && <span className="text-xs text-green-600">{t('migrations.copied')}</span>}
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
                        onClick={forceCheckAllDns}
                        disabled={forceChecking}
                        className="bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-4 py-2 rounded text-sm font-medium flex items-center gap-2 transition"
                        title="Принудительно проверить DNS для всех доменов"
                    >
                        <RefreshCw size={16} className={forceChecking ? 'animate-spin' : ''} />
                        {forceChecking ? t('common.checking') || 'Проверка...' : 'Проверить DNS'}
                    </button>
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

            {/* Nginx & SSL Status */}
            {nginxStatus && (
                <div className="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div className="flex items-center justify-between flex-wrap gap-4">
                        <div className="flex items-center gap-6">
                            <div className="flex items-center gap-2">
                                <Server size={18} className={nginxStatus.nginx.running ? 'text-green-600' : 'text-red-600'} />
                                <span className="text-sm font-medium">Nginx:</span>
                                <span className={`text-sm ${nginxStatus.nginx.running ? 'text-green-600' : 'text-red-600'}`}>
                                    {nginxStatus.nginx.running ? '✓ Работает' : '✗ Остановлен'}
                                </span>
                                {nginxStatus.nginx.config_ok && (
                                    <span className="text-xs text-green-600 bg-green-100 px-2 py-0.5 rounded">Config OK</span>
                                )}
                                <span className="text-xs text-gray-500">
                                    {nginxStatus.nginx.domains_count} доменов в конфиге
                                    {nginxStatus.nginx.db_domains_count !== nginxStatus.nginx.domains_count && ` (из ${nginxStatus.nginx.db_domains_count} в БД)`}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Lock size={18} className={nginxStatus.ssl.installed ? 'text-green-600' : 'text-gray-400'} />
                                <span className="text-sm font-medium">SSL:</span>
                                {nginxStatus.ssl.installed ? (
                                    <span className="text-sm text-green-600">✓ Установлен ({nginxStatus.ssl.domains.length} доменов)</span>
                                ) : (
                                    <span className="text-sm text-gray-500">✗ Не установлен</span>
                                )}
                                {!nginxStatus.ssl.certbot_installed && (
                                    <span className="text-xs text-orange-600 bg-orange-100 px-2 py-0.5 rounded">Certbot не установлен</span>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                onClick={updateNginxConfig}
                                disabled={nginxLoading}
                                className="bg-purple-600 hover:bg-purple-700 disabled:bg-gray-400 text-white px-3 py-1.5 rounded text-sm font-medium flex items-center gap-2 transition"
                                title="Обновить server_name в Nginx из базы данных"
                            >
                                <RefreshCw size={14} className={nginxLoading ? 'animate-spin' : ''} />
                                Обновить Nginx
                            </button>
                            <button
                                onClick={installSslCertificates}
                                disabled={nginxLoading || !nginxStatus.ssl.certbot_installed}
                                className="bg-orange-600 hover:bg-orange-700 disabled:bg-gray-400 text-white px-3 py-1.5 rounded text-sm font-medium flex items-center gap-2 transition"
                                title="Установить SSL сертификаты через Let's Encrypt"
                            >
                                <Lock size={14} />
                                Установить SSL
                            </button>
                        </div>
                    </div>
                </div>
            )}

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
