import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { RefreshCw, Search, Download, ChevronLeft, ChevronRight, ChevronUp, ChevronDown, ChevronsUpDown, BarChart3 } from 'lucide-react';
import InfoBanner from './InfoBanner';
import { useLanguage } from '../contexts/LanguageContext';
import { currencySymbol } from '../utils/currency';
import ClickDetailsModal from './ClickDetailsModal';
import MobileCards from './common/MobileCards';
import { ColumnResizeHandle } from './common/ColumnResize';
import { useIsDesktop, useResizableTableColumns } from './common/resizableColumns';
import { resolveConversionColor } from '../utils/conversionColors';
import DateRangePicker from './DateRangePicker';
import { useDateRange } from '../utils/dateRange';
import { useTimezone } from '../utils/useTimezone';
import { localizedCountryName } from '../utils/countryNames';
import { useEntityList } from '../utils/useEntityList';

const API_URL = '/api.php';

// One labelled <select> of the filters row. options: [{ value, label }].
const FilterSelect = ({ label, value, onChange, allLabel, options }) => (
    <div>
        <label className="form-label">{label}</label>
        <select value={value} onChange={(e) => onChange(e.target.value)} className="form-select">
            <option value="">{allLabel}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
            ))}
        </select>
    </div>
);

const ConvSortTh = ({ colId, label, sort, onSort, colResize, align = 'left', firstDir = 'asc' }) => {
    const active = sort.key === colId;
    const Icon = !active ? ChevronsUpDown : (sort.dir === 'asc' ? ChevronUp : ChevronDown);
    const icon = <Icon className="page-sort-icon" aria-hidden="true" />;
    const justify = align === 'right' ? 'flex-end' : (align === 'center' ? 'center' : 'flex-start');
    return (
        <th
            data-col={colId}
            className="resizable-th"
            aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
            style={{ textAlign: align }}
        >
            <button
                type="button"
                className="page-sort-btn"
                data-active={active ? 'true' : 'false'}
                style={{ justifyContent: justify, width: '100%' }}
                onClick={() => onSort(colId, firstDir)}
            >
                {align === 'right' && icon}
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{label}</span>
                {align !== 'right' && icon}
            </button>
            <ColumnResizeHandle rt={colResize} colId={colId} />
        </th>
    );
};

// embedded: the log as a section of the Reports page — no helper banner and
// no closing note around the table.
const ConversionsLog = ({ campaignId: propCampaignId, onClose, embedded = false }) => {
    const { t, language } = useLanguage();
    const [conversions, setConversions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [pagination, setPagination] = useState({ total: 0, page: 1, per_page: 50, total_pages: 0 });
    const [selectedClickId, setSelectedClickId] = useState(null);
    // Custom label colors from Settings → Conversion Types; empty until loaded.
    const [conversionTypes, setConversionTypes] = useState([]);

    // Filters
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    // Narrowing filters: geo (ISO code), campaign, offer, traffic source and landing.
    // The campaign is fixed when the log opens from one campaign's editor.
    const [countryFilter, setCountryFilter] = useState('');
    const [campaignFilter, setCampaignFilter] = useState('');
    const [offerFilter, setOfferFilter] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [landingFilter, setLandingFilter] = useState('');
    const [countryOptions, setCountryOptions] = useState([]);
    // Dropdown lists — one request per session, each already limited to what
    // the signed-in user may see.
    const { items: campaignOptions } = useEntityList('campaigns_simple');
    const { items: offerOptions } = useEntityList('offers_simple');
    const { items: sourceOptions } = useEntityList('traffic_sources_simple');
    const { items: landingOptions } = useEntityList('landings_simple');
    // Country names follow the UI language and sort by that name.
    const countryChoices = useMemo(() => countryOptions
        .map((code) => ({ code, name: localizedCountryName(code, language) }))
        .sort((a, b) => a.name.localeCompare(b.name, language || undefined)), [countryOptions, language]);

    // useDateRange: "today" re-derives on every mount and rolls over at
    // midnight in a long-lived tab, instead of the day the tab loaded.
    const [dateRange, setDateRange] = useDateRange();
    const dateFrom = dateRange.from;
    const dateTo = dateRange.to;
    const [timezone, setTimezone] = useTimezone();
    const [sort, setSort] = useState({ key: 'date', dir: 'desc' });

    const handleSort = (colKey, firstDir = 'asc') => {
        setSort((prev) => (
            prev.key === colKey
                ? { key: colKey, dir: prev.dir === 'asc' ? 'desc' : 'asc' }
                : { key: colKey, dir: firstDir }
        ));
    };

    // Resizable columns — desktop table only; below lg the list renders as
    // MobileCards and skips resizing entirely.
    const isDesktop = useIsDesktop();
    const colResize = useResizableTableColumns({
        tableId: 'conversions',
        enabled: isDesktop,
        columns: [
            { id: 'id', width: 70 },
            { id: 'click_id', width: 120 },
            { id: 'tid', width: 90 },
            { id: 'status', width: 110 },
            { id: 'payout', width: 100 },
            { id: 'campaign', width: 200 },
            { id: 'offer', width: 200 },
            { id: 'country', width: 130 },
            { id: 'source', width: 160 },
            { id: 'landing', width: 160 },
            { id: 'ip', width: 150 },
            { id: 'date', width: 170 }
        ]
    });

    const fetchConversions = async (page = 1) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ action: 'conversions', page, per_page: pagination.per_page });
            if (search) params.append('search', search);
            if (statusFilter) params.append('status', statusFilter);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);
            if (propCampaignId) params.append('campaign_id', propCampaignId);
            else if (campaignFilter) params.append('campaign_id', campaignFilter);
            if (offerFilter) params.append('offer_id', offerFilter);
            if (sourceFilter) params.append('source_id', sourceFilter);
            if (landingFilter) params.append('landing_id', landingFilter);
            if (countryFilter) params.append('country', countryFilter);
            // Date bucketing happens server-side against this; sending only
            // date_from/date_to left the selector with nothing to act on.
            if (timezone) params.append('timezone', timezone);
            if (sort.key) params.append('sort_by', sort.key);
            if (sort.dir) params.append('sort_dir', sort.dir);

            const res = await axios.get(`${API_URL}?${params.toString()}`);
            if (res.data.status === 'success') {
                setConversions(res.data.data);
                setPagination(res.data.pagination);
            }
        } catch (error) {
            console.error('Error fetching conversions:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchConversions(1);
    }, [statusFilter, countryFilter, campaignFilter, offerFilter, sourceFilter, landingFilter, dateFrom, dateTo, propCampaignId, timezone, sort]);

    // Label colors live on the conversion_types rows — one fetch per mount.
    useEffect(() => {
        axios.get(`${API_URL}?action=conversion_types`)
            .then(res => {
                if (res.data.status === 'success') setConversionTypes(res.data.data || []);
            })
            .catch(() => {});
    }, []);

    // The geo filter offers only the countries that really have conversions,
    // inside the signed-in user's campaign scope.
    useEffect(() => {
        axios.get(`${API_URL}?action=conversions_countries`)
            .then(res => {
                if (res.data.status === 'success') setCountryOptions(res.data.data || []);
            })
            .catch(() => {});
    }, []);

    const handleSearch = () => {
        fetchConversions(1);
    };

    const handlePageChange = (newPage) => {
        if (newPage >= 1 && newPage <= pagination.total_pages) {
            fetchConversions(newPage);
        }
    };

    const exportCSV = () => {
        if (conversions.length === 0) return;
        const headers = ['ID', 'Click ID', 'TID', 'Status', 'Payout', 'Currency', 'Campaign', 'Offer', 'Country', 'Source', 'Landing', 'IP', 'Created'];
        const rows = conversions.map(c => [
            c.id,
            c.click_id,
            c.tid || '',
            c.status,
            c.payout,
            c.currency,
            c.campaign_name || '',
            c.offer_name || '',
            c.country_code || '',
            c.source_name || '',
            c.landing_name || '',
            c.ip || '',
            c.created_at
        ]);

        const csv = [headers, ...rows].map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `conversions_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
    };

    const getStatusBadge = (status) => {
        // Custom color from Settings → Conversion Types wins; the built-in
        // palette covers the standard statuses, gray everything else. The
        // color-mix translucency keeps the badge readable on light and dark
        // themes alike.
        const badgeColor = resolveConversionColor(status, conversionTypes);

        const statusLabels = {
            lead: t('conversions.lead'),
            sale: t('conversions.sale'),
            rejected: t('conversions.rejected'),
            registration: t('conversions.registration'),
            deposit: t('conversions.deposit'),
            trash: t('conversions.trash')
        };

        return (
            <span style={{
                display: 'inline-flex',
                alignItems: 'center',
                padding: '4px 10px',
                fontSize: '12px',
                fontWeight: 500,
                borderRadius: '12px',
                color: badgeColor,
                backgroundColor: `color-mix(in srgb, ${badgeColor} 14%, transparent)`,
                border: `1px solid color-mix(in srgb, ${badgeColor} 25%, transparent)`
            }}>
                <span style={{
                    width: '6px',
                    height: '6px',
                    borderRadius: '50%',
                    backgroundColor: badgeColor,
                    marginRight: '6px',
                    flexShrink: 0
                }} />
                {statusLabels[status] || status}
            </span>
        );
    };

    // When used in modal (onClose provided), hide banner and adjust spacing;
    // the same on the Reports page (embedded).
    const isModalMode = onClose !== undefined;
    const standalone = !isModalMode && !embedded;

    return (
        <div className={isModalMode ? '' : 'space-y-4'}>
            {standalone && (
                <InfoBanner storageKey="help_conversions" title={t('help.conversionsBannerTitle')}>
                    <p>{t('help.conversionsBanner')}</p>
                </InfoBanner>
            )}
            {/* Filters */}
            <div className="page-card">
                {/* conversions-filters / conversions-search: below 480px the
                    auto-fit grid collapses to one column and the search must
                    stop spanning two (index.css, !important vs these inline
                    styles). */}
                <div className="conversions-filters" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '12px', alignItems: 'end' }}>
                    <div className="conversions-search" style={{ gridColumn: 'span 2' }}>
                        <label className="form-label">{t('conversions.search')}</label>
                        <div className="relative">
                            <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 pointer-events-none" />
                            <input
                                type="text"
                                placeholder={t('conversions.searchPlaceholder')}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                className="form-input pl-12"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="form-label">{t('conversions.status')}</label>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="form-select"
                        >
                            <option value="">{t('conversions.allStatuses')}</option>
                            <option value="lead">{t('conversions.lead')}</option>
                            <option value="sale">{t('conversions.sale')}</option>
                            <option value="rejected">{t('conversions.rejected')}</option>
                            <option value="registration">{t('conversions.registration')}</option>
                            <option value="deposit">{t('conversions.deposit')}</option>
                            <option value="trash">{t('conversions.trash')}</option>
                        </select>
                    </div>
                    <FilterSelect
                        label={t('conversions.geo')}
                        value={countryFilter}
                        onChange={setCountryFilter}
                        allLabel={t('conversions.allCountries')}
                        options={countryChoices.map((c) => ({ value: c.code, label: c.name }))}
                    />
                    {!propCampaignId && (
                        <FilterSelect
                            label={t('conversions.campaign')}
                            value={campaignFilter}
                            onChange={setCampaignFilter}
                            allLabel={t('campaignReports.allCampaigns', 'All Campaigns')}
                            options={campaignOptions.map((c) => ({ value: String(c.id), label: c.name }))}
                        />
                    )}
                    <FilterSelect
                        label={t('conversions.offer')}
                        value={offerFilter}
                        onChange={setOfferFilter}
                        allLabel={t('conversions.allOffers')}
                        options={offerOptions.map((o) => ({ value: String(o.id), label: o.name }))}
                    />
                    <FilterSelect
                        label={t('conversions.source')}
                        value={sourceFilter}
                        onChange={setSourceFilter}
                        allLabel={t('conversions.allSources')}
                        options={sourceOptions.map((s) => ({ value: String(s.id), label: s.name }))}
                    />
                    <FilterSelect
                        label={t('conversions.landing')}
                        value={landingFilter}
                        onChange={setLandingFilter}
                        allLabel={t('conversions.allLandings')}
                        options={landingOptions.map((l) => ({ value: String(l.id), label: l.name }))}
                    />
                    {/* The picker (two dates and the timezone) is wider than one cell
                        of this row since the four filters joined it: two cells, or
                        it overlaps the buttons after it. */}
                    <div className="conversions-dates" style={{ gridColumn: 'span 2' }}>
                        {/* Shared DateRangePicker with i18n, theme tokens, and presets */}
                        <DateRangePicker
                            dateFrom={dateFrom}
                            dateTo={dateTo}
                            initialPreset={dateRange.preset}
                            onChange={setDateRange}
                            selectedTimezone={timezone}
                            onTimezoneChange={setTimezone}
                        />
                    </div>
                    <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                            onClick={() => fetchConversions(pagination.page)}
                            className="btn btn-secondary btn-icon"
                            title={t('common.refresh')}
                        >
                            <RefreshCw size={18} />
                        </button>
                        <button
                            onClick={exportCSV}
                            className="btn btn-secondary btn-icon"
                            title="CSV"
                        >
                            <Download size={18} />
                        </button>
                    </div>
                </div>
            </div>

            {/* Table */}
            {loading ? (
                <div className="page-card">
                    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '200px' }}>
                        <div style={{ width: '32px', height: '32px', border: '3px solid var(--color-border)', borderTopColor: 'var(--color-primary)', borderRadius: '50%', animation: 'spin 1s linear infinite' }}></div>
                    </div>
                </div>
            ) : (
                <div className="page-card" style={{ padding: 0 }}>
                    {/* The modal frame no longer scrolls (it clipped the date
                        picker popover), so the table itself owns the vertical
                        scroll here. The bound leaves room for the toolbar,
                        header and pagination row below this wrapper. */}
                    <div
                        className="overflow-x-auto hidden lg:block"
                        style={{ maxHeight: 'calc(100vh - 400px)', overflowY: 'auto' }}
                    >
                        <table className="page-table tracker-table conversions-table" style={{ fontVariantNumeric: 'tabular-nums', ...colResize.tableStyle }}>
                            {colResize.colgroup}
                            <thead>
                                <tr>
                                    <ConvSortTh colId="id" label="ID" sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                    <ConvSortTh colId="click_id" label="Click ID" sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="tid" label="TID" sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="status" label={t('conversions.status')} sort={sort} onSort={handleSort} colResize={colResize} align="center" firstDir="asc" />
                                    <ConvSortTh colId="payout" label={t('conversions.payout')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                    <ConvSortTh colId="campaign" label={t('conversions.campaign')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="offer" label={t('conversions.offer')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="country" label={t('conversions.geo')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="source" label={t('conversions.source')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="landing" label={t('conversions.landing')} sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="ip" label="IP" sort={sort} onSort={handleSort} colResize={colResize} align="left" firstDir="asc" />
                                    <ConvSortTh colId="date" label={t('conversions.date')} sort={sort} onSort={handleSort} colResize={colResize} align="right" firstDir="desc" />
                                </tr>
                            </thead>
                            <tbody>
                                {conversions.length === 0 ? (
                                    <tr>
                                        <td colSpan={12} className="text-center" style={{ padding: '48px' }}>
                                            <div className="empty-state">
                                                <p className="empty-state-title">{t('conversions.noConversions')}</p>
                                                <p className="empty-state-text">{t('conversions.noConversionsText')}</p>
                                            </div>
                                        </td>
                                    </tr>
                                ) : (
                                    conversions.map((conv) => (
                                        <tr key={conv.id}>
                                            <td style={{ color: 'var(--color-text-secondary)', textAlign: 'right' }}>{conv.id}</td>
                                            <td style={{ textAlign: 'left' }}>
                                                <button
                                                    onClick={() => setSelectedClickId(conv.click_id)}
                                                    title={conv.click_id}
                                                    style={{
                                                        fontFamily: 'monospace',
                                                        fontSize: '11px',
                                                        color: 'var(--color-primary)',
                                                        background: 'var(--color-bg-soft)',
                                                        padding: '2px 6px',
                                                        borderRadius: '5px',
                                                        border: 'none',
                                                        cursor: 'pointer',
                                                        transition: 'all 0.2s',
                                                        maxWidth: '100%',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                        display: 'inline-block',
                                                        verticalAlign: 'middle'
                                                    }}
                                                >
                                                    {conv.click_id}
                                                </button>
                                            </td>
                                            <td title={conv.tid || ''} style={{ color: 'var(--color-text-secondary)', textAlign: 'left' }}>{conv.tid || '-'}</td>
                                            <td style={{ textAlign: 'center' }}>{getStatusBadge(conv.status)}</td>
                                            <td style={{ color: conv.payout === null ? 'var(--color-text-muted)' : 'var(--color-success)', fontWeight: 600, textAlign: 'right' }}>
                                                {conv.payout === null || conv.payout === undefined ? '—' : `${currencySymbol()}${Number(conv.payout).toFixed(2)}`}
                                            </td>
                                            <td title={conv.campaign_name || ''} style={{ color: 'var(--color-text-primary)', textAlign: 'left' }}>{conv.campaign_name || '-'}</td>
                                            <td title={conv.offer_name || ''} style={{ color: 'var(--color-text-primary)', textAlign: 'left' }}>{conv.offer_name || '-'}</td>
                                            <td title={conv.country_code ? localizedCountryName(conv.country_code, language) : ''} style={{ color: 'var(--color-text-secondary)', textAlign: 'left' }}>{conv.country_code ? localizedCountryName(conv.country_code, language) : '-'}</td>
                                            <td title={conv.source_name || ''} style={{ color: 'var(--color-text-primary)', textAlign: 'left' }}>{conv.source_name || '-'}</td>
                                            <td title={conv.landing_name || ''} style={{ color: 'var(--color-text-primary)', textAlign: 'left' }}>{conv.landing_name || '-'}</td>
                                            <td title={conv.ip || ''} style={{ color: 'var(--color-text-muted)', fontFamily: 'monospace', fontSize: '12px', textAlign: 'left' }}>{conv.ip || '-'}</td>
                                            <td style={{ textAlign: 'right' }}>
                                                <button
                                                    onClick={() => setSelectedClickId(conv.click_id)}
                                                    style={{
                                                        color: 'var(--color-text-secondary)',
                                                        fontSize: '12px',
                                                        background: 'none',
                                                        border: 'none',
                                                        cursor: 'pointer',
                                                        padding: 0,
                                                        transition: 'color 0.2s',
                                                        maxWidth: '100%',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                        display: 'inline-block',
                                                        verticalAlign: 'middle'
                                                    }}
                                                >
                                                    {new Date(conv.created_at).toLocaleString('ru-RU')}
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Mobile stacked cards (below lg) — one card per conversion,
                        click_id opens the click details modal. Own scroll bound:
                        the modal frame's overflow is visible now. */}
                    <div className="lg:hidden p-3" style={{ maxHeight: 'calc(100vh - 330px)', overflowY: 'auto' }}>
                        <MobileCards
                            rows={conversions}
                            getId={(conv) => conv.id}
                            renderTitle={(conv) => (
                                <button
                                    onClick={() => setSelectedClickId(conv.click_id)}
                                    className="font-mono text-xs px-2 py-1 rounded-md"
                                    style={{
                                        color: 'var(--color-primary)',
                                        backgroundColor: 'var(--color-bg-soft)',
                                        border: 'none',
                                        cursor: 'pointer'
                                    }}
                                >
                                    {conv.click_id}
                                </button>
                            )}
                            renderHeaderRight={(conv) => getStatusBadge(conv.status)}
                            fields={[
                                {
                                    id: 'payout',
                                    label: t('conversions.payout'),
                                    render: (c) => (c.payout === null || c.payout === undefined ? '—' : <span style={{ color: 'var(--color-success)', fontWeight: 500 }}>{`${currencySymbol()}${Number(c.payout).toFixed(2)}`}</span>),
                                },
                                { id: 'campaign', label: t('conversions.campaign'), render: (c) => c.campaign_name || '-' },
                                { id: 'offer', label: t('conversions.offer'), render: (c) => c.offer_name || '-' },
                                { id: 'country', label: t('conversions.geo'), render: (c) => (c.country_code ? localizedCountryName(c.country_code, language) : '-') },
                                { id: 'source', label: t('conversions.source'), render: (c) => c.source_name || '-' },
                                { id: 'landing', label: t('conversions.landing'), render: (c) => c.landing_name || '-' },
                                {
                                    id: 'created',
                                    label: t('conversions.date'),
                                    render: (c) => new Date(c.created_at).toLocaleString('ru-RU'),
                                },
                                { id: 'id', label: 'ID', render: (c) => c.id },
                                { id: 'tid', label: 'TID', render: (c) => c.tid || '-' },
                                { id: 'ip', label: 'IP', render: (c) => c.ip || '-' },
                            ]}
                            primaryIds={['payout', 'campaign', 'offer', 'created']}
                            emptyState={
                                <div className="text-center py-10">
                                    <div className="empty-state">
                                        <p className="empty-state-title">{t('conversions.noConversions')}</p>
                                        <p className="empty-state-text">{t('conversions.noConversionsText')}</p>
                                    </div>
                                </div>
                            }
                        />
                    </div>

                    {/* Pagination */}
                    {pagination.total_pages > 1 && (
                        <div style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            padding: '16px',
                            borderTop: '1px solid var(--color-border)'
                        }}>
                            <div style={{ fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                                {t('conversions.shown')} {((pagination.page - 1) * pagination.per_page) + 1} - {Math.min(pagination.page * pagination.per_page, pagination.total)} {t('conversions.of')} {pagination.total}
                            </div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                <button
                                    onClick={() => handlePageChange(pagination.page - 1)}
                                    disabled={pagination.page <= 1}
                                    className="btn btn-secondary btn-sm"
                                    style={{ opacity: pagination.page <= 1 ? 0.5 : 1 }}
                                >
                                    <ChevronLeft size={16} />
                                </button>
                                <span style={{ color: 'var(--color-text-primary)', fontSize: '14px' }}>
                                    {pagination.page} / {pagination.total_pages}
                                </span>
                                <button
                                    onClick={() => handlePageChange(pagination.page + 1)}
                                    disabled={pagination.page >= pagination.total_pages}
                                    className="btn btn-secondary btn-sm"
                                    style={{ opacity: pagination.page >= pagination.total_pages ? 0.5 : 1 }}
                                >
                                    <ChevronRight size={16} />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Info */}
            {standalone && (
                <div className="page-card" style={{ background: 'var(--color-info-bg)', borderColor: 'var(--color-info)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '12px' }}>
                    <BarChart3 size={20} style={{ color: 'var(--color-info)', flexShrink: 0, marginTop: '2px' }} />
                    <div>
                        <h3 style={{ fontWeight: 500, marginBottom: '4px', color: 'var(--color-text-primary)' }}>
                            {t('conversions.title')}
                        </h3>
                        <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)', margin: 0 }}>
                            {t('conversions.logInfo')}
                        </p>
                    </div>
                </div>
            </div>
            )}

            {selectedClickId && (
                <ClickDetailsModal
                    clickId={selectedClickId}
                    onClose={() => setSelectedClickId(null)}
                />
            )}
        </div>
    );
};

export default ConversionsLog;