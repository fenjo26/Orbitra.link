import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { X, Loader, PlayCircle } from 'lucide-react';
import { createPortal } from 'react-dom';
import ClickDirection from './ClickDirection';
import { useLanguage } from '../contexts/LanguageContext';
import { useTimezone } from '../utils/useTimezone';
import ReplayPlayerModal from './replay/ReplayPlayerModal';

const API_URL = '/api.php';

const ClickDetailsModal = ({ clickId, onClose }) => {
    const { t } = useLanguage();
    const [timezone] = useTimezone();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    // Session recording of this click, if the landing recorded it.
    const [hasReplay, setHasReplay] = useState(false);
    const [showReplay, setShowReplay] = useState(false);
    // Browser-check verdict of this click (core/BrowserCheck.php), if checked.
    const [browserCheck, setBrowserCheck] = useState(null);
    useEffect(() => {
        if (!clickId) return undefined;
        let cancelled = false;
        axios.get(`${API_URL}?action=browser_check_click&id=${encodeURIComponent(clickId)}`)
            .then(res => { if (!cancelled && res.data?.status === 'success') setBrowserCheck(res.data.data || null); })
            .catch(() => { /* no access or not checked */ });
        return () => { cancelled = true; };
    }, [clickId]);

    useEffect(() => {
        if (!clickId) return undefined;
        let cancelled = false;
        axios.get(`${API_URL}?action=replays&click_id=${encodeURIComponent(clickId)}&limit=1`)
            .then(res => { if (!cancelled) setHasReplay(res.data?.status === 'success' && (res.data.total || 0) > 0); })
            .catch(() => { /* no access to recordings or none: no button */ });
        return () => { cancelled = true; };
    }, [clickId]);

    useEffect(() => {
        if (!clickId) return;

        const fetchDetails = async () => {
            try {
                setLoading(true);
                // Same timezone the tables send, so the card's times match the row clicked.
                const response = await axios.get(`${API_URL}?action=click_details&id=${encodeURIComponent(clickId)}&timezone=${encodeURIComponent(timezone)}`);
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
    }, [clickId, timezone]);

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
            // Escape inside the recording player closes the player only.
            if (event.key === 'Escape' && !showReplay) {
                onClose();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [clickId, onClose, showReplay]);

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

    // Parsed server-side from the stored User-Agent (core/UserAgent.php), so
    // versions, model and bot name exist for old clicks too. The stored
    // os/browser columns are the coarse families used by filters.
    const ua = (data && data.ua) || {};
    // Stored 0/1 flags; null/undefined (older rows, flags not written) stays "-".
    const yesNo = (v) => (v === null || v === undefined || v === '' ? null : (Number(v) === 1 ? t('clickDetails.yes') : t('clickDetails.no')));
    const joinParts = (...parts) => parts.filter(Boolean).join(' ');
    // Panel-timezone time with its zone named, and the UTC original on hover —
    // the separate UTC row is what networks and ad platforms report in.
    const withZone = (local, utc) => (local ? (
        <span title={utc ? `UTC: ${utc}` : undefined}>
            {local}
            {data?.display_timezone && (
                <span className="ml-2 text-xs" style={{ color: 'var(--color-text-muted)' }}>{data.display_timezone}</span>
            )}
        </span>
    ) : null);
    // Writers store the literal 'Unknown' when detection found nothing; show
    // it translated, like Keitaro's "Неизвестно", never as an English token.
    const unknownLabel = t('clickDetails.unknown');
    const shown = (v) => (v === 'Unknown' ? unknownLabel : v);
    const known = (v) => (v && v !== 'Unknown' ? v : '');
    const uaOs = joinParts(known(ua.os_name) || known(data?.os), ua.os_version) || (data?.user_agent ? unknownLabel : '');
    const uaBrowser = joinParts(known(ua.browser_name) || known(data?.browser), ua.browser_version) || (data?.user_agent ? unknownLabel : '');
    const uaDeviceModel = joinParts(ua.device_brand, ua.device_model);

    // landing_at / offer_at arrive shifted by one panel-timezone offset (both
    // the same), so parsing them as UTC still yields the right delta.
    const lpTimeDelta = (() => {
        const parse = (v) => (v ? new Date(String(v).replace(' ', 'T') + 'Z').getTime() : NaN);
        const from = parse(data?.landing_at);
        const to = parse(data?.offer_at);
        if (!isFinite(from) || !isFinite(to) || to < from) return null;
        const s = Math.round((to - from) / 1000);
        return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`;
    })();

    // Time the visitor actually spent on the landing — reported by the page's
    // own timer, so it exists for a visit that never reached the offer. That is
    // the difference from timeToOffer above, which needs an offer click to be a
    // number at all.
    const lpDwell = (() => {
        const secs = data?.lp_seconds === null || data?.lp_seconds === undefined
            ? null : Number(data.lp_seconds);
        if (secs === null || !isFinite(secs)) return null;
        return secs < 60 ? `${secs}s` : `${Math.floor(secs / 60)}m ${secs % 60}s`;
    })();

    const lpScroll = (() => {
        const pct = data?.lp_scroll === null || data?.lp_scroll === undefined
            ? null : Number(data.lp_scroll);
        return pct === null || !isFinite(pct) ? null : `${pct}%`;
    })();

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
                            {/* Which stream took the click and what kind it is — for clicks
                                routed by filters (Keitaro-style Bots / Antibot streams) this,
                                with Direction, is the honest "why": they carry no cloak verdict. */}
                            <DetailRow
                                label={t('clickDetails.fields.stream')}
                                value={data.stream_name || data.stream_type
                                    ? [data.stream_name, data.stream_type ? `(${data.stream_type})` : ''].filter(Boolean).join(' ')
                                    : null}
                            />
                            <DetailRow label={t('clickDetails.fields.uniqCampaign')} value={yesNo(data.uniq_campaign)} />
                            <DetailRow label={t('clickDetails.fields.uniqStream')} value={yesNo(data.uniq_stream)} />
                            <DetailRow label={t('clickDetails.fields.uniqGlobal')} value={yesNo(data.uniq_global)} />
                            <DetailRow label={t('clickDetails.fields.referer')} value={data.referer} />
                            <DetailRow label={t('clickDetails.fields.domain')} value={data.domain} />
                            <DetailRow
                                label={t('clickDetails.fields.direction')}
                                value={<ClickDirection destination={data.destination} wrap />}
                            />

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
                            <DetailRow label={t('clickDetails.fields.proxy')} value={yesNo(data.is_proxy)} />
                            <DetailRow label={t('clickDetails.fields.proxyType')} value={data.proxy_type} />
                            <DetailRow label={t('clickDetails.fields.botScanner')} value={ua.bot || (Number(data.is_bot) === 1 ? t('clickDetails.yes') : t('clickDetails.no'))} />

                            {/* W3: Cloak verdict and reasons */}
                            {/* Only clicks a cloak stream actually judged. is_safe_page is 0 on
                                every click, so keying on it painted "Money" on clicks no cloak
                                ever saw. */}
                            {(data.cloak_verdict || data.cloak_reasons) && (
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

                            {browserCheck && (
                                <>
                                    <SectionHeader title={t('browserCheck.cardSection')} />
                                    <DetailRow
                                        label={t('browserCheck.cardVerdict')}
                                        value={
                                            <span
                                                className="text-xs px-2 py-0.5 rounded-full font-semibold"
                                                data-testid="click-browser-check-verdict"
                                                style={browserCheck.verdict === 'bot'
                                                    ? { background: 'rgba(239,68,68,.15)', color: 'rgb(220,38,38)' }
                                                    : browserCheck.verdict === 'suspect'
                                                        ? { background: 'rgba(245,158,11,.15)', color: 'rgb(180,83,9)' }
                                                        : { background: 'rgba(34,197,94,.15)', color: 'rgb(22,163,74)' }}
                                            >
                                                {t(`browserCheck.verdict_${browserCheck.verdict}`)}
                                            </span>
                                        }
                                    />
                                    {(browserCheck.flags || []).map(f => (
                                        <DetailRow
                                            key={f.code}
                                            label={f.level === 'hard' ? t('browserCheck.levelHard') : t('browserCheck.levelSoft')}
                                            value={<span title={f.detail}>{t(`browserCheck.flag_${f.code}`, f.code)}{f.detail ? <span className="text-[var(--color-text-muted)]"> · {f.detail}</span> : null}</span>}
                                        />
                                    ))}
                                    {browserCheck.gpu && <DetailRow label={t('browserCheck.cardGpu')} value={browserCheck.gpu} />}
                                    {browserCheck.platform && <DetailRow label={t('browserCheck.cardPlatform')} value={browserCheck.platform} />}
                                    {browserCheck.timezone && <DetailRow label={t('browserCheck.cardTimezone')} value={browserCheck.timezone} />}
                                    <DetailRow label={t('browserCheck.cardSource')} value={t(`browserCheck.source_${browserCheck.source}`, browserCheck.source)} />
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

                            {/* Every other parameter the click carried (ad-network IDs such as
                                campaign_id / adgroup_id / ad_id, click IDs, UTM tags). Cost import
                                matches on these, so users must be able to see them per click. */}
                            {(() => {
                                const params = data.parameters && typeof data.parameters === 'object' ? data.parameters : {};
                                const shown = new Set(['keyword', 'cost', 'currency', 'external_id', 'creative_id', 'ad_campaign_id', 'source', 'subid']);
                                const priority = ['campaign_id', 'campaignid', 'adset_id', 'adgroup_id', 'adgroupid', 'ad_group_id', 'adgroup', 'ad_id', 'creative', 'ttclid', 'fbclid', 'gclid'];
                                const rank = (k) => { const i = priority.indexOf(k); return i === -1 ? priority.length : i; };
                                return Object.keys(params)
                                    .filter((k) => !shown.has(k) && !/^sub_id_\d+$/.test(k) && params[k] !== null && params[k] !== '')
                                    .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b))
                                    .map((k) => (
                                        <DetailRow
                                            key={`param-${k}`}
                                            label={k}
                                            value={typeof params[k] === 'object' ? JSON.stringify(params[k]) : String(params[k])}
                                        />
                                    ));
                            })()}

                            <SectionHeader title={t('clickDetails.sections.geoDevice')} />
                            <DetailRow label={t('clickDetails.fields.country')} value={data.country} />
                            <DetailRow label={t('clickDetails.fields.region')} value={data.region} />
                            <DetailRow label={t('clickDetails.fields.city')} value={data.city} />
                            <DetailRow label={t('clickDetails.fields.zipcode')} value={data.zipcode} />
                            <DetailRow label={t('clickDetails.fields.timezone')} value={data.timezone} />
                            <DetailRow label={t('clickDetails.fields.language')} value={shown(data.language)} />
                            <DetailRow label={t('clickDetails.fields.acceptLanguageRaw')} value={data.accept_language_raw} />
                            <DetailRow label={t('clickDetails.fields.latitude')} value={data.latitude} />
                            <DetailRow label={t('clickDetails.fields.longitude')} value={data.longitude} />
                            <DetailRow label={t('clickDetails.fields.deviceType')} value={shown(data.device_type)} />
                            <DetailRow label={t('clickDetails.fields.deviceModel')} value={uaDeviceModel} />
                            <DetailRow label={t('clickDetails.fields.os')} value={uaOs} />
                            <DetailRow label={t('clickDetails.fields.browser')} value={uaBrowser} />
                            {ua.in_app && <DetailRow label={t('clickDetails.fields.inAppBrowser')} value={ua.in_app} />}
                            <DetailRow label={t('clickDetails.fields.userAgent')} value={data.user_agent} />

                            <SectionHeader title={t('clickDetails.sections.calendar')} />
                            <DetailRow label={t('clickDetails.fields.dateTime')} value={withZone(data.created_at, data.created_at_utc)} />
                            <DetailRow label={t('clickDetails.fields.dateTimeUtc')} value={data.created_at_utc} />
                            <DetailRow label={t('clickDetails.fields.landingAt')} value={withZone(data.landing_at, data.landing_at_utc)} />
                            <DetailRow label={t('clickDetails.fields.offerAt')} value={withZone(data.offer_at, data.offer_at_utc)} />
                            <DetailRow label={t('clickDetails.fields.timeToOffer')} value={lpTimeDelta} />
                            <DetailRow label={t('clickDetails.fields.timeOnLp')} value={lpDwell} />
                            <DetailRow label={t('clickDetails.fields.lpScroll')} value={lpScroll} />
                            <DetailRow label={t('clickDetails.fields.conversion')} value={data.is_conversion ? t('clickDetails.yes') : t('clickDetails.no')} />
                        </div>

                    </div>
                </div>

                {/* Footer */}
                <div className="px-6 py-4 border-t flex justify-end gap-2" style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                    {hasReplay && (
                        <button onClick={() => setShowReplay(true)} className="btn btn-primary text-xs py-2 px-4 rounded-xl font-medium flex items-center gap-1.5">
                            <PlayCircle size={14} /> {t('replay.watchClick')}
                        </button>
                    )}
                    <button onClick={onClose} className="btn btn-secondary text-xs py-2 px-5 rounded-xl font-medium">{t('clickDetails.close')}</button>
                </div>
                {showReplay && createPortal(
                    <div style={{ position: 'fixed', inset: 0, zIndex: 2147483001 }}>
                        <ReplayPlayerModal clickId={clickId} onClose={() => setShowReplay(false)} onDeleted={() => setHasReplay(false)} />
                    </div>,
                    document.body
                )}
        </div>
    );
};

export default ClickDetailsModal;
