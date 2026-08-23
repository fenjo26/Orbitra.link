import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { X, Loader } from 'lucide-react';
import { createPortal } from 'react-dom';
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

    useEffect(() => {
        if (!clickId || typeof document === 'undefined') return;

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previousOverflow;
        };
    }, [clickId]);

    useEffect(() => {
        if (!clickId || typeof window === 'undefined') return;

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [clickId, onClose]);

    const SectionHeader = ({ title }) => (
        <h3 className="text-xs font-bold uppercase tracking-wider pb-2 mb-3 mt-6 border-b" style={{ color: 'var(--color-primary)', borderColor: 'var(--color-border)' }}>
            {title}
        </h3>
    );

    const DetailRow = ({ label, value }) => (
        <div
            className="flex flex-col sm:flex-row py-1.5 border-b last:border-0 transition px-2.5 rounded-lg"
            style={{ borderColor: 'color-mix(in srgb, var(--color-border) 40%, transparent)' }}
        >
            <span className="w-1/3 text-xs font-medium text-[var(--color-text-secondary)] truncate pr-4">{label}</span>
            <span className="w-2/3 text-sm text-[var(--color-text-primary)] break-all">{value || '-'}</span>
        </div>
    );

    const renderInPortal = (content) => {
        if (typeof document === 'undefined') return null;
        return createPortal(
            <div
                className="fixed inset-0 bg-black/50 backdrop-blur-sm p-4 sm:p-6 overflow-y-auto"
                style={{ zIndex: 2147483000 }}
                onClick={onClose}
            >
                <div
                    className="min-h-full flex items-center justify-center py-4"
                    onClick={(event) => event.stopPropagation()}
                >
                    {content}
                </div>
            </div>,
            document.body
        );
    };

    if (loading) {
        return renderInPortal(
            <div className="rounded-2xl shadow-2xl p-8 flex flex-col items-center w-full max-w-sm border" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                <Loader className="w-8 h-8 animate-spin mb-4" style={{ color: 'var(--color-primary)' }} />
                <p className="text-[var(--color-text-secondary)] font-medium">{t('clickDetails.loading')}</p>
            </div>
        );
    }

    if (error || !data) {
        return renderInPortal(
            <div className="rounded-2xl shadow-2xl w-full max-w-md p-6 border" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                <div className="flex justify-between items-center mb-4">
                    <h2 className="text-base font-semibold text-[var(--color-danger,#ef4444)]">{t('clickDetails.error')}</h2>
                    <button onClick={onClose} className="p-1 hover:bg-[var(--color-bg-soft)] rounded-full transition"><X size={20} className="text-[var(--color-text-secondary)]" /></button>
                </div>
                <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{error || t('clickDetails.notFound')}</p>
                <div className="mt-6 flex justify-end">
                    <button onClick={onClose} className="px-4 py-2 bg-[var(--color-bg-soft)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] rounded transition font-medium text-sm">{t('clickDetails.close')}</button>
                </div>
            </div>
        );
    }

    const formatMoney = (amount) => {
        return parseFloat(amount || 0).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
    };

    const profit = (parseFloat(data.revenue || 0) - parseFloat(data.cost || 0)).toFixed(2);

    return renderInPortal(
        <div
            className="rounded-2xl shadow-2xl w-full max-w-5xl flex flex-col max-h-[calc(100vh-3rem)] overflow-hidden border"
            style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
        >
                {/* Header */}
                <div className="flex justify-between items-center px-6 py-4 border-b" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    <div>
                        <h2 className="text-lg font-bold text-[var(--color-text-primary)] flex items-center gap-2">
                            {t('clickDetails.title')}
                            <span
                                className="text-xs font-mono px-2 py-0.5 rounded-lg border ml-2"
                                style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)', color: 'var(--color-text-muted)' }}
                            >
                                {data.id}
                            </span>
                        </h2>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] hover:bg-[var(--color-bg-soft)] transition">
                        <X size={20} />
                    </button>
                </div>

                {/* Body - Grid Layout */}
                <div className="flex-1 overflow-y-auto p-6" style={{ backgroundColor: 'var(--color-bg-card)' }}>
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
                            <DetailRow label={t('clickDetails.fields.isp')} value={data.isp} />
                            <DetailRow label={t('clickDetails.fields.asn')} value={data.asn} />
                            <DetailRow label={t('clickDetails.fields.proxyType')} value={data.proxy_type} />
                            <DetailRow label={t('clickDetails.fields.botScanner')} value={t('clickDetails.noData')} />

                            {/* W3: Cloak verdict and reasons */}
                            {(data.cloak_verdict || data.cloak_reasons || data.is_safe_page !== undefined) && (
                                <>
                                    <SectionHeader title={t('clickDetails.sections.cloak')} />
                                    <DetailRow
                                        label={t('clickDetails.fields.route')}
                                        value={
                                            data.is_safe_page === 1 ? (
                                                <span className="status-badge status-inactive text-[11px]">
                                                    {t('logs.routeSafe')}
                                                </span>
                                            ) : data.is_safe_page === 0 ? (
                                                <span className="status-badge status-active text-[11px]">
                                                    {t('logs.routeMoney')}
                                                </span>
                                            ) : '-'
                                        }
                                    />
                                    <DetailRow label={t('clickDetails.fields.cloakVerdict')} value={data.cloak_verdict} />
                                    {data.cloak_reasons && (
                                        <DetailRow
                                            label={t('clickDetails.fields.cloakReasons')}
                                            value={
                                                <div className="flex flex-wrap gap-1">
                                                    {data.cloak_reasons.split(',').filter(Boolean).map((reason, idx) => {
                                                        // `code:evidence` — split on the FIRST ':' so the
                                                        // label resolves and evidence stays visible.
                                                        const colon = reason.indexOf(':');
                                                        const code = colon === -1 ? reason : reason.slice(0, colon);
                                                        const evidence = colon === -1 ? '' : reason.slice(colon + 1);
                                                        return (
                                                            <span
                                                                key={idx}
                                                                className="text-[10px] px-1.5 py-0.5 rounded font-mono"
                                                                style={{
                                                                    backgroundColor: 'var(--color-bg-soft)',
                                                                    color: 'var(--color-text-secondary)',
                                                                    border: '1px solid var(--color-border)'
                                                                }}
                                                                title={t(`cloakReasons.${code}`, '') || code}
                                                            >
                                                                {code}{evidence ? <b style={{ color: 'var(--color-primary)', fontWeight: 600 }}>{` ${evidence}`}</b> : null}
                                                            </span>
                                                        );
                                                    })}
                                                </div>
                                            }
                                        />
                                    )}
                                </>
                            )}

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
                            <DetailRow label={t('clickDetails.fields.region')} value={data.region} />
                            <DetailRow label={t('clickDetails.fields.city')} value={data.city} />
                            <DetailRow label={t('clickDetails.fields.zipcode')} value={data.zipcode} />
                            <DetailRow label={t('clickDetails.fields.timezone')} value={data.timezone} />
                            <DetailRow label={t('clickDetails.fields.language')} value={data.language} />
                            <DetailRow label={t('clickDetails.fields.acceptLanguageRaw')} value={data.accept_language_raw} />
                            <DetailRow label={t('clickDetails.fields.latitude')} value={data.latitude} />
                            <DetailRow label={t('clickDetails.fields.longitude')} value={data.longitude} />
                            <DetailRow label={t('clickDetails.fields.deviceType')} value={data.device_type} />
                            <DetailRow label={t('clickDetails.fields.os')} value={data.os} />
                            <DetailRow label={t('clickDetails.fields.browser')} value={data.browser} />
                            <DetailRow label={t('clickDetails.fields.userAgent')} value={data.user_agent} />

                            <SectionHeader title={t('clickDetails.sections.calendar')} />
                            <DetailRow label={t('clickDetails.fields.dateTime')} value={data.created_at} />
                            <DetailRow label={t('clickDetails.fields.conversion')} value={data.is_conversion ? t('clickDetails.yes') : t('clickDetails.no')} />
                        </div>

                    </div>
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t flex justify-end" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    <button onClick={onClose} className="btn btn-secondary text-xs py-2 px-5 rounded-xl font-medium">{t('clickDetails.close')}</button>
                </div>
        </div>
    );
};

export default ClickDetailsModal;
