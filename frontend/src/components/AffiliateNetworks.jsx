import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Plus, Edit2, Trash2, Copy, ExternalLink, Check } from 'lucide-react';
import InfoBanner from './InfoBanner';
import AffiliateNetworkEditor from './AffiliateNetworkEditor';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const AffiliateNetworks = () => {
    const { t } = useLanguage();
    const [networks, setNetworks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editorOpen, setEditorOpen] = useState(false);
    const [editId, setEditId] = useState(null);
    const [copiedId, setCopiedId] = useState(null);
    const [postbackKey, setPostbackKey] = useState('');

    useEffect(() => {
        fetchNetworks();
        fetchPostbackKey();
    }, []);

    const fetchNetworks = async () => {
        try {
            const res = await axios.get(`${API_URL}?action=affiliate_networks`);
            if (res.data.status === 'success') {
                setNetworks(res.data.data);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    };

    const fetchPostbackKey = async () => {
        try {
            const res = await axios.get(`${API_URL}?action=settings`);
            if (res.data.status === 'success') {
                setPostbackKey(res.data.data.postback_key || 'fd12e72');
            }
        } catch (err) {
            console.error(err);
        }
    };

    const handleDelete = async (id) => {
        if (!window.confirm(t('networks.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=delete_affiliate_network`, { id });
            fetchNetworks();
        } catch (err) {
            console.error(err);
        }
    };

    const openEditor = (id = null) => {
        setEditId(id);
        setEditorOpen(true);
    };

    const closeEditor = (refresh = false) => {
        setEditorOpen(false);
        setEditId(null);
        if (refresh) fetchNetworks();
    };

    const getPostbackUrl = (network) => {
        const protocol = window.location.protocol;
        const host = window.location.host;
        return `${protocol}//${host}/${postbackKey}/postback`;
    };

    const copyToClipboard = async (text, id) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopiedId(id);
            setTimeout(() => setCopiedId(null), 2000);
        } catch (err) {
            console.error(err);
        }
    };

    if (loading) {
        return <div className="flex justify-center py-10">{t('common.loading')}</div>;
    }

    return (
        <div className="space-y-4">
            <InfoBanner storageKey="help_affiliate_networks" title={t('help.affiliateNetworkBannerTitle')}>
                <p>{t('help.affiliateNetworkBanner')}</p>
            </InfoBanner>
            {/* Header */}
            <div className="flex justify-between items-center">
                <p className="text-sm text-gray-500">
                    {t('networks.headerDesc')}
                </p>
                <button
                    onClick={() => openEditor()}
                    className="btn btn-primary"
                >
                    <Plus className="w-4 h-4" />
                    {t('common.create')}
                </button>
            </div>

            {/* Networks List */}
            {networks.length === 0 ? (
                <div className="text-center py-10 text-gray-400 bg-white border border-dashed border-gray-300 rounded">
                    {t('networks.noNetworksAdd')}
                </div>
            ) : (
                <div className="bg-white rounded shadow overflow-hidden">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('editor.name')}</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('networks.postbackUrl')}</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{t('networks.offerParams')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('networks.offersCount')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('components.status')}</th>
                                <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {networks.map((network) => (
                                <tr key={network.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-gray-900">{network.name}</div>
                                        {network.template && (
                                            <div className="text-xs text-gray-400">{t('sources.template')}: {network.template}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center space-x-2">
                                            <code className="text-xs bg-gray-100 px-2 py-1 rounded max-w-xs truncate">
                                                {getPostbackUrl(network)}
                                            </code>
                                            <button
                                                onClick={() => copyToClipboard(getPostbackUrl(network), `pb-${network.id}`)}
                                                className="text-gray-400 hover:text-blue-600"
                                                title={t('common.copy')}
                                            >
                                                {copiedId === `pb-${network.id}` ? (
                                                    <Check className="w-4 h-4 text-green-500" />
                                                ) : (
                                                    <Copy className="w-4 h-4" />
                                                )}
                                            </button>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <code className="text-xs bg-gray-100 px-2 py-1 rounded">
                                            {network.offer_params || <span className="text-gray-400">{t('common.notSet')}</span>}
                                        </code>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                            {network.offers_count || 0}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <span className={`inline-flex items-center px-2 py-1 rounded text-xs font-medium ${network.state === 'active'
                                            ? 'bg-green-100 text-green-800'
                                            : 'bg-gray-100 text-gray-600'
                                            }`}>
                                            {network.state === 'active' ? t('components.active') : t('common.disabled')}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        <div className="flex justify-center space-x-2">
                                            <button
                                                onClick={() => openEditor(network.id)}
                                                className="text-gray-400 hover:text-blue-600"
                                                title={t('common.edit')}
                                            >
                                                <Edit2 className="w-4 h-4" />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(network.id)}
                                                className="text-gray-400 hover:text-red-600"
                                                title={t('common.delete')}
                                            >
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Help Section */}
            <div className="bg-blue-50 border border-blue-200 rounded p-4">
                <h3 className="font-medium text-blue-800 mb-2">{t('networks.setupPostback')}</h3>
                <ol className="text-sm text-blue-700 space-y-1 list-decimal list-inside">
                    <li>{t('networks.step1')}</li>
                    <li>{t('networks.step2')}</li>
                    <li>{t('networks.step3')}</li>
                    <li>{t('networks.step4')}</li>
                    <li>{t('networks.step5')}</li>
                </ol>
                <div className="mt-3 text-xs text-blue-600">
                    <strong>{t('networks.examplePostback')}:</strong>
                    <code className="ml-2 bg-blue-100 px-2 py-1 rounded">
                        https://your-domain.com/{postbackKey}/postback?subid={'{subid}'}&status={'{status}'}&payout={'{payout}'}
                    </code>
                </div>
            </div>

            {/* Editor Modal */}
            {editorOpen && (
                <AffiliateNetworkEditor
                    networkId={editId}
                    onClose={closeEditor}
                    postbackKey={postbackKey}
                />
            )}
        </div>
    );
};

export default AffiliateNetworks;