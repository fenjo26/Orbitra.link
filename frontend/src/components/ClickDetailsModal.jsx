import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { X, Loader } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const ClickDetailsModal = ({ clickId, onClose }) => {
    const { t } = useLanguage();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!clickId) return;

        const fetchDetails = async () => {
            try {
                setLoading(true);
                const response = await axios.get(`${API_URL}?action=click_details&id=${clickId}`);
                if (response.data.status === 'success') {
                    setData(response.data.data);
                } else {
                    setError(response.data.message || 'Error fetching click details');
                }
            } catch (err) {
                setError('Network error');
            } finally {
                setLoading(false);
            }
        };

        fetchDetails();
    }, [clickId]);

    const SectionHeader = ({ title }) => (
        <h3 className="text-sm font-semibold text-gray-800 border-b border-gray-200 pb-2 mb-3 mt-6 uppercase tracking-wide">
            {title}
        </h3>
    );

    const DetailRow = ({ label, value }) => (
        <div className="flex flex-col sm:flex-row py-1.5 border-b border-gray-50 last:border-0 hover:bg-gray-50 transition px-2 rounded">
            <span className="w-1/3 text-xs font-medium text-gray-500 truncate pr-4">{label}</span>
            <span className="w-2/3 text-sm text-gray-800 break-all">{value || '-'}</span>
        </div>
    );

    if (loading) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 backdrop-blur-sm">
                <div className="bg-white rounded-lg shadow-xl p-8 flex flex-col items-center">
                    <Loader className="w-8 h-8 text-blue-500 animate-spin mb-4" />
                    <p className="text-gray-600 font-medium">{t('clickDetails.loading')}</p>
                </div>
            </div>
        );
    }

    if (error || !data) {
        return (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 backdrop-blur-sm">
                <div className="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <div className="flex justify-between items-center mb-4">
                        <h2 className="text-lg font-semibold text-red-600">{t('clickDetails.error')}</h2>
                        <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded-full transition"><X size={20} className="text-gray-500" /></button>
                    </div>
                    <p className="text-gray-700">{error || t('clickDetails.notFound')}</p>
                    <div className="mt-6 flex justify-end">
                        <button onClick={onClose} className="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition font-medium text-sm">{t('clickDetails.close')}</button>
                    </div>
                </div>
            </div>
        );
    }

    const formatMoney = (amount) => {
        return parseFloat(amount || 0).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
    };

    const profit = (parseFloat(data.revenue || 0) - parseFloat(data.cost || 0)).toFixed(2);

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm p-4 sm:p-6 overflow-y-auto">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-5xl flex flex-col max-h-full overflow-hidden">
                {/* Header */}
                <div className="flex justify-between items-center px-6 py-4 border-b border-gray-100 bg-gray-50">
                    <div>
                        <h2 className="text-lg font-bold text-gray-800 flex items-center gap-2">
                            {t('clickDetails.title')}
                            <span className="text-xs font-mono bg-gray-200 text-gray-600 px-2 py-1 rounded-md ml-2 border border-gray-300">
                                {data.id}
                            </span>
                        </h2>
                    </div>
                    <button onClick={onClose} className="p-2 hover:bg-gray-200 rounded-full transition text-gray-500">
                        <X size={20} />
                    </button>
                </div>

                {/* Body - Grid Layout */}
                <div className="flex-1 overflow-y-auto p-6 bg-white">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-4">

                        {/* Column 1 */}
                        <div>
                            <SectionHeader title={t('clickDetails.sections.data')} />
                            <DetailRow label={t('clickDetails.fields.campaign')} value={data.campaign_name} />
                            <DetailRow label={t('clickDetails.fields.campaignAlias')} value={data.campaign_alias} />
                            <DetailRow label={t('clickDetails.fields.landing')} value={data.landing_name} />
                            <DetailRow label={t('clickDetails.fields.offer')} value={data.offer_name} />
                            <DetailRow label={t('clickDetails.fields.affiliateNetwork')} value={data.affiliate_network_name} />
                            <DetailRow label={t('clickDetails.fields.source')} value={data.source_name} />
                            <DetailRow label={t('clickDetails.fields.streamType')} value={data.stream_type} />
                            <DetailRow label={t('clickDetails.fields.referer')} value={data.referer} />

                            <SectionHeader title={t('clickDetails.sections.id')} />
                            <DetailRow label={t('clickDetails.fields.clickId')} value={data.id} />
                            <DetailRow label={t('clickDetails.fields.campaignId')} value={data.campaign_id} />
                            <DetailRow label={t('clickDetails.fields.offerId')} value={data.offer_id} />
                            <DetailRow label={t('clickDetails.fields.landingId')} value={data.landing_id} />
                            <DetailRow label={t('clickDetails.fields.streamId')} value={data.stream_id} />
                            <DetailRow label={t('clickDetails.fields.sourceId')} value={data.source_id} />

                            <SectionHeader title={t('clickDetails.sections.connection')} />
                            <DetailRow label={t('clickDetails.fields.ip')} value={data.ip} />
                            <DetailRow label={t('clickDetails.fields.botScanner')} value={t('clickDetails.noData')} />

                            <SectionHeader title={t('clickDetails.sections.finance')} />
                            <DetailRow label={t('clickDetails.fields.cost')} value={formatMoney(data.cost)} />
                            <DetailRow label={t('clickDetails.fields.revenue')} value={formatMoney(data.revenue)} />
                            <DetailRow label={t('clickDetails.fields.profit')} value={formatMoney(profit)} />
                        </div>

                        {/* Column 2 */}
                        <div>
                            <SectionHeader title={t('clickDetails.sections.parameters')} />
                            <DetailRow label={t('clickDetails.fields.keyword')} value={data.parameters?.keyword} />
                            <DetailRow label="Cost" value={data.parameters?.cost} />
                            <DetailRow label="Currency" value={data.parameters?.currency} />
                            <DetailRow label={t('clickDetails.fields.externalId')} value={data.parameters?.external_id} />
                            <DetailRow label={t('clickDetails.fields.creativeId')} value={data.parameters?.creative_id} />
                            <DetailRow label={t('clickDetails.fields.adCampaignId')} value={data.parameters?.ad_campaign_id} />
                            <DetailRow label={t('clickDetails.fields.sourceParam')} value={data.parameters?.source} />

                            {[...Array(30)].map((_, i) => (
                                data.parameters && data.parameters[`sub_id_${i + 1}`] ? (
                                    <DetailRow key={i} label={`Sub ID ${i + 1}`} value={data.parameters[`sub_id_${i + 1}`]} />
                                ) : null
                            ))}

                            <SectionHeader title={t('clickDetails.sections.geoDevice')} />
                            <DetailRow label={t('clickDetails.fields.country')} value={data.country} />
                            <DetailRow label={t('clickDetails.fields.deviceType')} value={data.device_type} />
                            <DetailRow label={t('clickDetails.fields.userAgent')} value={data.user_agent} />

                            <SectionHeader title={t('clickDetails.sections.calendar')} />
                            <DetailRow label={t('clickDetails.fields.dateTime')} value={data.created_at} />
                            <DetailRow label={t('clickDetails.fields.conversion')} value={data.is_conversion ? t('clickDetails.yes') : t('clickDetails.no')} />
                        </div>

                    </div>
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                    <button onClick={onClose} className="px-6 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded shadow-sm transition font-medium">{t('clickDetails.close')}</button>
                </div>
            </div>
        </div>
    );
};

export default ClickDetailsModal;