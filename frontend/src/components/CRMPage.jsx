import React, { useState, useEffect, useMemo, useCallback } from 'react';
import axios from 'axios';
import {
    Layers, Search, Download, Plus, RefreshCw, Eye, CheckCircle2,
    XCircle, Clock, AlertTriangle, X, Phone, WifiOff,
    FileSearch, Network, Crosshair, ShieldAlert, Repeat2, Copy
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';

const API_URL = '/api.php';

const STATUS_CONFIG = {
    sale: { label: 'Approved (Sale)', color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800', icon: CheckCircle2 },
    approved: { label: 'Approved (Sale)', color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800', icon: CheckCircle2 },
    lead: { label: 'In Process (Hold)', color: 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800', icon: Clock },
    processing: { label: 'In Process (Hold)', color: 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800', icon: Clock },
    rejected: { label: 'Rejected', color: 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border-rose-300 dark:border-rose-800', icon: XCircle },
    trash: { label: 'Trash / Spam', color: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300 border-slate-300 dark:border-slate-700', icon: AlertTriangle },
};

const TABS = [
    { id: 'all', label: 'crm.tabAll' },
    { id: 'processing', label: 'crm.inProcess' },
    { id: 'approved', label: 'crm.approved' },
    { id: 'rejected', label: 'crm.rejected' },
    { id: 'trash', label: 'crm.trash' },
    { id: 'qa', label: 'crm.tabQa' },
    { id: 'suspect', label: 'crm.tabSuspect' },
    { id: 'lost', label: 'crm.tabLost' },
];

const prettyJson = (raw) => {
    if (!raw) return '{}';
    try {
        const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
        return JSON.stringify(parsed, null, 2);
    } catch (e) {
        return String(raw);
    }
};

const CRMPage = ({ setActiveTab, user }) => {
    const { t } = useLanguage();
    const [leads, setLeads] = useState([]);
    const [kpi, setKpi] = useState({ total: 0, approved: 0, processing: 0, rejected: 0, trash: 0, qa: 0, suspects: 0, duplicates: 0, lost: 0, revenue: 0 });
    const [loading, setLoading] = useState(true);
    const [campaigns, setCampaigns] = useState([]);

    // Server-side filters
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [campaignFilter, setCampaignFilter] = useState('all');

    // Modals
    const [selectedLead, setSelectedLead] = useState(null);
    const [inspectorTab, setInspectorTab] = useState('raw');
    const [savingStatus, setSavingStatus] = useState(false);
    const [manualStatus, setManualStatus] = useState('lead');
    const [showNewLeadModal, setShowNewLeadModal] = useState(false);
    const [newLeadData, setNewLeadData] = useState({
        name: '', phone: '', subid: '', campaign_id: '', status: 'lead', payout: '25', currency: 'USD'
    });
    // "<leadId>:<field>" of the value copied from a table row, for the check-mark feedback
    const [copiedField, setCopiedField] = useState('');

    const handleCopyField = async (key, value) => {
        if (!value || !await copyToClipboard(value)) return;
        setCopiedField(key);
        setTimeout(() => setCopiedField(''), 1500);
    };

    const fetchLeads = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ action: 'crm_leads', per_page: '200', status: statusFilter });
            if (search) params.set('search', search);
            if (campaignFilter !== 'all') params.set('campaign_id', campaignFilter);
            const [leadsRes, campRes] = await Promise.all([
                axios.get(`${API_URL}?${params.toString()}`).catch(() => ({ data: { status: 'error', data: [] } })),
                axios.get(`${API_URL}?action=campaigns`).catch(() => ({ data: { status: 'error', data: [] } }))
            ]);
            if (leadsRes.data?.status === 'success') {
                setLeads(leadsRes.data.data || []);
                if (leadsRes.data.kpi) setKpi(leadsRes.data.kpi);
            }
            if (campRes.data?.status === 'success') {
                setCampaigns(campRes.data.data || []);
            }
        } catch (error) {
            console.error('Failed to fetch CRM leads:', error);
        } finally {
            setLoading(false);
        }
    }, [statusFilter, search, campaignFilter]);

    useEffect(() => {
        fetchLeads();
    }, [fetchLeads]);

    // Debounced server search
    useEffect(() => {
        const timer = setTimeout(() => setSearch(searchInput.trim()), 450);
        return () => clearTimeout(timer);
    }, [searchInput]);

    const handleCreateLead = async (e) => {
        e.preventDefault();
        try {
            const subid = (newLeadData.subid || '').trim() || `crm_${Date.now()}_${Math.random().toString(36).substr(2, 5)}`;
            const res = await axios.post(`${API_URL}?action=crm_lead`, {
                subid,
                customer_name: newLeadData.name || '',
                raw_phone: newLeadData.phone || '',
                geo: '',
                status: newLeadData.status,
                payout: parseFloat(newLeadData.payout) || 0,
                currency: newLeadData.currency || 'USD',
                campaign_id: newLeadData.campaign_id || null,
                network: 'manual',
            });
            if (res.data?.status !== 'success') {
                alert(res.data?.message || t('common.error', 'Failed to save lead'));
                return;
            }
            setShowNewLeadModal(false);
            setNewLeadData({ name: '', phone: '', subid: '', campaign_id: '', status: 'lead', payout: '25', currency: 'USD' });
            fetchLeads();
        } catch (err) {
            console.error('Failed to create lead:', err);
            alert(t('common.error', 'Failed to save lead'));
        }
    };

    const openInspector = (lead) => {
        setSelectedLead(lead);
        setManualStatus((lead.status || 'lead').toLowerCase());
        setInspectorTab('raw');
    };

    const handleSaveStatus = async () => {
        if (!selectedLead) return;
        setSavingStatus(true);
        try {
            const res = await axios.post(`${API_URL}?action=crm_lead_update`, {
                id: selectedLead.id,
                status: manualStatus,
            });
            if (res.data?.status === 'success') {
                setSelectedLead(prev => (prev ? { ...prev, status: manualStatus } : prev));
                fetchLeads();
            } else {
                alert(res.data?.message || t('common.error', 'Failed to save'));
            }
        } catch (err) {
            alert(err.response?.data?.message || err.message);
        } finally {
            setSavingStatus(false);
        }
    };

    const handleExportCsv = () => {
        if (leads.length === 0) return;
        const headers = ['ID', 'Date', 'SubID', 'Customer', 'Raw Phone', 'E.164 Phone', 'Network', 'Network Lead', 'Campaign', 'Status', 'S2S', 'Suspect', 'Duplicate', 'QA', 'Payout', 'Currency'];
        const rows = leads.map(l => [
            l.id || '',
            l.created_at || '',
            l.click_id || '',
            `"${(l.customer_name || '').replace(/"/g, '""')}"`,
            `"${(l.raw_phone || '').replace(/"/g, '""')}"`,
            l.clean_phone || '',
            l.network || '',
            l.network_lead_id || '',
            `"${(l.campaign_name || '').replace(/"/g, '""')}"`,
            l.status || '',
            l.s2s_postback_status || '',
            l.shave_suspect ? 1 : 0,
            l.is_duplicate ? 1 : 0,
            l.is_qa_test ? 1 : 0,
            l.payout || '0',
            l.currency || 'USD'
        ]);
        const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', `orbitra_crm_leads_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const parseSub = (raw) => {
        try {
            const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    };

    return (
        <div className="space-y-6 w-full pb-12">
            {/* Hero Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl border bg-[var(--color-bg-card)] border-[var(--color-border)]" style={{ boxShadow: 'var(--shadow-main)' }}>
                <div className="flex items-center gap-4">
                    <div
                        className="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0"
                        style={{
                            background: 'color-mix(in srgb, var(--color-primary) 14%, transparent)',
                            color: 'var(--color-primary)',
                            boxShadow: 'inset 0 0 0 1px color-mix(in srgb, var(--color-primary) 22%, transparent)',
                        }}
                    >
                        <Layers size={26} />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--color-text-primary)' }}>
                            {t('crm.title2', 'CRM — Orders & Anti-Shaving Vault')}
                        </h1>
                        <p className="text-sm mt-0.5" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('crm.subtitle2', 'Every lead with the exact phone delivered to the network, the raw API exchange, and S2S reconciliation')}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={fetchLeads}
                        disabled={loading}
                        className="p-2.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] transition cursor-pointer"
                        title={t('crm.refresh', 'Refresh leads')}
                    >
                        <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
                    </button>
                    <button
                        type="button"
                        onClick={handleExportCsv}
                        disabled={leads.length === 0}
                        className="btn-secondary !py-2 !px-4 text-sm flex items-center gap-2 disabled:opacity-50 cursor-pointer"
                    >
                        <Download size={16} />
                        <span>{t('crm.exportCsv', 'Export CSV')}</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowNewLeadModal(true)}
                        className="btn-primary !py-2.5 !px-4 text-sm flex items-center gap-2 cursor-pointer"
                    >
                        <Plus size={16} />
                        <span>{t('crm.newLead', '+ New Lead')}</span>
                    </button>
                </div>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">{t('crm.totalLeads', 'Total Leads')}</span>
                    <div className="text-2xl font-bold mt-1" style={{ color: 'var(--color-text-primary)' }}>{kpi.total}</div>
                </div>
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">{t('crm.inProcess', 'Hold / Processing')}</span>
                    <div className="text-2xl font-bold mt-1 text-amber-600 dark:text-amber-400">{kpi.processing}</div>
                </div>
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{t('crm.approved', 'Approved Sales')}</span>
                    <div className="text-2xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">{kpi.approved}</div>
                    <span className="text-[10px] text-[var(--color-text-muted)]">
                        {t('crm.approvalRate', 'Approval Rate')}: {kpi.total > 0 ? Math.round((kpi.approved / Math.max(1, kpi.total - kpi.qa)) * 100) : 0}%
                    </span>
                </div>
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-rose-600 dark:text-rose-400">{t('crm.rejected', 'Rejected')}</span>
                    <div className="text-2xl font-bold mt-1 text-rose-600 dark:text-rose-400">{kpi.rejected}</div>
                </div>
                <div className="bg-rose-50/60 dark:bg-rose-950/20 border border-rose-300 dark:border-rose-900 rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-rose-600 dark:text-rose-400 flex items-center gap-1.5">
                        <ShieldAlert size={13} /> {t('crm.suspects', 'Shave Suspects')}
                    </span>
                    <div className="text-2xl font-bold mt-1 text-rose-600 dark:text-rose-400">{kpi.suspects}</div>
                    {kpi.lost > 0 && (
                        <span className="text-[10px] text-[var(--color-text-muted)]">
                            {kpi.lost} {t('crm.lostWord', 'lost in transit')}
                        </span>
                    )}
                </div>
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">{t('crm.revenue', 'Earned Revenue')}</span>
                    <div className="text-2xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">{kpi.revenue ? `$${Number(kpi.revenue).toFixed(2)}` : '—'}</div>
                    <span className="text-[10px] text-[var(--color-text-muted)]">{t('crm.qaCount', 'QA tests')}: {kpi.qa}</span>
                </div>
            </div>

            {/* Filter & Search Bar */}
            <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3">
                <div className="relative w-full md:w-80">
                    <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]" />
                    <input
                        type="text"
                        value={searchInput}
                        onChange={(e) => setSearchInput(e.target.value)}
                        placeholder={t('crm.searchPlaceholder', 'Search by name, phone, SubID, network lead…')}
                        className="w-full pl-10 pr-4 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                    />
                </div>

                <div className="flex items-center gap-2 w-full md:w-auto overflow-x-auto pb-1 md:pb-0">
                    <div className="inline-flex p-1 rounded-xl border items-center gap-1 bg-[var(--color-bg-main)]" style={{ borderColor: 'var(--color-border)' }}>
                        {TABS.map(st => (
                            <button
                                key={st.id}
                                type="button"
                                onClick={() => setStatusFilter(st.id)}
                                className={`px-3 py-1 rounded-lg text-xs font-semibold transition cursor-pointer whitespace-nowrap ${
                                    statusFilter === st.id
                                        ? 'bg-[var(--color-primary)] shadow-sm'
                                        : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                                }`}
                                style={statusFilter === st.id ? { color: 'var(--color-text-inverse, white)' } : undefined}
                            >
                                {t(st.label, st.id)}
                            </button>
                        ))}
                    </div>

                    {campaigns.length > 0 && (
                        <select
                            value={campaignFilter}
                            onChange={(e) => setCampaignFilter(e.target.value)}
                            className="px-3 py-1.5 rounded-xl border bg-[var(--color-bg-main)] text-xs font-medium focus:outline-none whitespace-nowrap"
                            style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                        >
                            <option value="all">{t('crm.allCampaigns', 'All Campaigns')}</option>
                            {campaigns.map(c => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                    )}
                </div>
            </div>

            {/* Leads Data Table */}
            <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-[var(--color-bg-main)] border-b border-[var(--color-border)] uppercase tracking-wider font-semibold text-[var(--color-text-muted)]">
                            <tr>
                                <th className="px-4 py-3.5 whitespace-nowrap">SubID / ClickID</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">{t('crm.customer', 'Customer')}</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">{t('crm.phoneCol', 'Phone (raw → E.164)')}</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">{t('crm.networkCol', 'Network')}</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">{t('crm.date', 'Date / Time')}</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">{t('crm.status', 'Status')}</th>
                                <th className="px-4 py-3.5 whitespace-nowrap">S2S</th>
                                <th className="px-4 py-3.5 text-right whitespace-nowrap">{t('crm.payout', 'Payout')}</th>
                                <th className="px-4 py-3.5 text-center whitespace-nowrap">{t('crm.actions', 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--color-border)]">
                            {loading ? (
                                <tr>
                                    <td colSpan={9} className="py-12 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>
                                        <RefreshCw size={20} className="animate-spin mx-auto mb-2" />
                                        <span>{t('crm.loading', 'Loading orders and leads…')}</span>
                                    </td>
                                </tr>
                            ) : leads.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="py-12 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>
                                        <Layers size={28} className="mx-auto mb-2 opacity-40" />
                                        <span>{t('crm.noLeads', 'No leads found matching current filters.')}</span>
                                    </td>
                                </tr>
                            ) : (
                                leads.map((lead, idx) => {
                                    const stKey = (lead.status || 'lead').toLowerCase();
                                    const stInfo = STATUS_CONFIG[stKey] || STATUS_CONFIG.lead;
                                    return (
                                        <tr
                                            key={lead.id || idx}
                                            className={`group hover:bg-[var(--color-bg-hover)] transition ${
                                                (lead.shave_suspect || lead.lost_in_transit) ? 'bg-rose-50 dark:bg-rose-950/20' : ''
                                            }`}
                                        >
                                            <td className="px-4 py-3 font-mono font-medium whitespace-nowrap" style={{ color: 'var(--color-text-primary)' }}>
                                                <div className="flex items-center gap-1.5">
                                                    <span className="truncate max-w-[160px]" title={lead.click_id}>{lead.click_id}</span>
                                                    {lead.click_id && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopyField(`${lead.id}:subid`, lead.click_id)}
                                                            className="opacity-0 group-hover:opacity-100 transition cursor-pointer text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                                                            title={copiedField === `${lead.id}:subid` ? t('crm.copied', 'Copied') : t('crm.copySubid', 'Copy SubID')}
                                                        >
                                                            {copiedField === `${lead.id}:subid` ? <CheckCircle2 size={12} className="text-emerald-500" /> : <Copy size={12} />}
                                                        </button>
                                                    )}
                                                </div>
                                                {lead.campaign_name && (
                                                    <div className="text-[10px] text-[var(--color-text-muted)] truncate max-w-[160px]">{lead.campaign_name}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap" style={{ color: 'var(--color-text-primary)' }}>
                                                {lead.customer_name || '—'}
                                                {lead.product && <div className="text-[10px] text-[var(--color-text-muted)] truncate max-w-[120px]">{lead.product}</div>}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex items-center gap-1.5" style={{ color: 'var(--color-text-primary)' }}>
                                                    <Phone size={12} className="text-[var(--color-text-muted)]" />
                                                    <span className="font-mono">{lead.raw_phone || '—'}</span>
                                                    {lead.raw_phone && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopyField(`${lead.id}:phone`, lead.raw_phone)}
                                                            className="opacity-0 group-hover:opacity-100 transition cursor-pointer text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                                                            title={copiedField === `${lead.id}:phone` ? t('crm.copied', 'Copied') : t('crm.copyPhone', 'Copy phone')}
                                                        >
                                                            {copiedField === `${lead.id}:phone` ? <CheckCircle2 size={12} className="text-emerald-500" /> : <Copy size={12} />}
                                                        </button>
                                                    )}
                                                </div>
                                                {lead.clean_phone && lead.clean_phone !== lead.raw_phone && (
                                                    <div className="text-[10px] font-mono text-emerald-600 dark:text-emerald-400">→ {lead.clean_phone}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-950/70 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                                    {lead.network || 'custom'}
                                                </span>
                                                {lead.network_lead_id && (
                                                    <div className="text-[10px] font-mono text-[var(--color-text-muted)] mt-0.5 truncate max-w-[110px]" title={lead.network_lead_id}>{lead.network_lead_id}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-[var(--color-text-secondary)] whitespace-nowrap">
                                                {lead.created_at || '—'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex flex-wrap items-center gap-1">
                                                    {lead.is_qa_test ? (
                                                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border bg-sky-100 text-sky-800 dark:bg-sky-950/70 dark:text-sky-300 border-sky-300 dark:border-sky-800">
                                                            <Repeat2 size={12} /> {t('crm.qaTest', 'QA-Test')}
                                                        </span>
                                                    ) : (
                                                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${stInfo.color}`}>
                                                            {(() => { const I = stInfo.icon; return <I size={12} />; })()}
                                                            <span>{stInfo.label}</span>
                                                        </span>
                                                    )}
                                                    {lead.shave_suspect ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border-rose-300 dark:border-rose-800" title={t('crm.suspectHint', 'Rejected with a provably valid E.164 phone after the network answered 200 — open a ticket with the evidence below')}>
                                                            <ShieldAlert size={11} /> {t('crm.suspectBadge', 'Suspected Shave')}
                                                        </span>
                                                    ) : null}
                                                    {lead.lost_in_transit ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-orange-100 text-orange-800 dark:bg-orange-950/70 dark:text-orange-300 border-orange-300 dark:border-orange-800" title={t('crm.lostHint2', 'Network answered 200 but no S2S postback arrived within 24h')}>
                                                            <WifiOff size={11} /> {t('crm.lostBadge', 'Missing Network ACK')}
                                                        </span>
                                                    ) : null}
                                                    {lead.is_duplicate ? (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border-slate-300 dark:border-slate-600" title={t('crm.duplicateHint', 'Same E.164 phone on the same network within 30 days')}>
                                                            <Repeat2 size={11} /> {t('crm.duplicateBadge', 'Duplicate')}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className={`text-[11px] font-semibold ${
                                                    (lead.s2s_postback_status || 'pending') === 'pending'
                                                        ? 'text-[var(--color-text-muted)]'
                                                        : lead.s2s_postback_status === 'sale' || lead.s2s_postback_status === 'approved'
                                                            ? 'text-emerald-600 dark:text-emerald-400'
                                                            : 'text-rose-600 dark:text-rose-400'
                                                }`}>
                                                    {lead.s2s_postback_status || 'pending'}
                                                </span>
                                                {lead.status_reason && (
                                                    <div className="text-[10px] text-[var(--color-text-muted)] truncate max-w-[100px]" title={lead.status_reason}>{lead.status_reason}</div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                                                {lead.payout ? `$${parseFloat(lead.payout).toFixed(2)}` : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => openInspector(lead)}
                                                    className="p-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] transition cursor-pointer"
                                                    title={t('crm.inspect', 'Inspect Lead Evidence')}
                                                >
                                                    <Eye size={13} />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Lead Inspector Modal */}
            {selectedLead && (
                <div className="fixed inset-0 bg-black/50 z-[2000] flex items-center justify-center p-4">
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl">
                        <div className="flex items-center justify-between p-5 pb-3 border-b border-[var(--color-border)]">
                            <div>
                                <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                    {t('crm.inspectorTitle', 'Lead Inspector')}
                                </h3>
                                <p className="text-[11px] font-mono text-[var(--color-text-secondary)] mt-0.5">
                                    {selectedLead.click_id} · {selectedLead.network} · {selectedLead.created_at}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedLead(null)}
                                className="p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] cursor-pointer"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        {/* Tabs */}
                        <div className="flex gap-1 px-5 pt-3 border-b border-[var(--color-border)]">
                            {[
                                { id: 'raw', label: t('crm.tabRaw', 'Raw Lead Data'), icon: FileSearch },
                                { id: 'network', label: t('crm.tabNetwork', 'Network Transaction'), icon: Network },
                                { id: 'tracking', label: t('crm.tabTracking', 'Tracking Attribution'), icon: Crosshair },
                            ].map(tab => {
                                const Icon = tab.icon;
                                return (
                                    <button
                                        key={tab.id}
                                        type="button"
                                        onClick={() => setInspectorTab(tab.id)}
                                        className={`flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 -mb-px transition cursor-pointer ${
                                            inspectorTab === tab.id
                                                ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                                                : 'border-transparent text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                                        }`}
                                    >
                                        <Icon size={14} />
                                        {tab.label}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Tab content */}
                        <div className="flex-1 overflow-y-auto p-5 space-y-4">
                            {inspectorTab === 'raw' && (
                                <>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        {[
                                            [t('crm.fCustomer', 'Customer name'), selectedLead.customer_name || '—'],
                                            [t('crm.fProduct', 'Product'), selectedLead.product || '—'],
                                            [t('crm.fRawPhone', 'Raw phone (as typed)'), selectedLead.raw_phone || '—'],
                                            [t('crm.fCleanPhone', 'E.164 delivered to network'), selectedLead.clean_phone || '—'],
                                            [t('crm.fPrice', 'Price'), selectedLead.price ? `${selectedLead.price} ${selectedLead.currency || ''}` : '—'],
                                            [t('crm.fPayout', 'Payout'), selectedLead.payout ? `${selectedLead.payout} ${selectedLead.currency || ''}` : '—'],
                                            [t('crm.fGeo', 'GEO'), selectedLead.geo || '—'],
                                            [t('crm.fIp', 'IP'), selectedLead.ip || '—'],
                                            [t('crm.fCreatedAt', 'Submitted at'), selectedLead.created_at || '—'],
                                            [t('crm.fLeadId', 'Vault row'), `#${selectedLead.id}`],
                                        ].map(([label, value]) => (
                                            <div key={label} className="p-3 rounded-xl bg-[var(--color-bg-main)] border border-[var(--color-border)]">
                                                <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold">{label}</div>
                                                <div className="font-mono mt-1 break-all" style={{ color: 'var(--color-text-primary)' }}>{value}</div>
                                            </div>
                                        ))}
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold mb-1.5">{t('crm.fUserAgent', 'User Agent')}</div>
                                        <pre className="p-3 rounded-xl bg-slate-950 text-slate-300 text-[10px] overflow-x-auto whitespace-pre-wrap break-all border border-slate-800">{selectedLead.user_agent || '—'}</pre>
                                    </div>
                                </>
                            )}

                            {inspectorTab === 'network' && (
                                <>
                                    <div className="flex flex-wrap gap-2 text-xs">
                                        <span className="px-2.5 py-1 rounded-full font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-950/70 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                            {selectedLead.network || 'custom'}
                                        </span>
                                        {selectedLead.network_lead_id && (
                                            <span className="px-2.5 py-1 rounded-full font-mono bg-[var(--color-bg-main)] border border-[var(--color-border)]" style={{ color: 'var(--color-text-primary)' }}>
                                                {selectedLead.network_lead_id}
                                            </span>
                                        )}
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold mb-1.5">{t('crm.fNetRequest', 'Network request (exactly what we sent)')}</div>
                                        <pre className="p-3 rounded-xl bg-slate-950 text-sky-300 text-[10px] overflow-x-auto whitespace-pre-wrap break-all border border-slate-800">{prettyJson(selectedLead.network_request_json)}</pre>
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold mb-1.5">{t('crm.fNetResponse', 'Network response (exactly what came back)')}</div>
                                        <pre className={`p-3 rounded-xl text-[10px] overflow-x-auto whitespace-pre-wrap break-all border ${
                                            (() => {
                                                try {
                                                    const r = JSON.parse(selectedLead.network_response_json || '{}');
                                                    return (r.http_code || 0) === 200
                                                        ? 'bg-slate-950 text-emerald-300 border-slate-800'
                                                        : 'bg-slate-950 text-rose-300 border-slate-800';
                                                } catch (e) {
                                                    return 'bg-slate-950 text-slate-300 border-slate-800';
                                                }
                                            })()
                                        }`}>{prettyJson(selectedLead.network_response_json)}</pre>
                                    </div>
                                    {(selectedLead.shave_suspect || selectedLead.lost_in_transit) && (
                                        <div className="p-3 rounded-xl border border-rose-300 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/30 text-xs text-rose-800 dark:text-rose-300 leading-relaxed">
                                            <div className="font-bold flex items-center gap-1.5 mb-1"><ShieldAlert size={14} /> {t('crm.evidenceTitle', 'Anti-shaving evidence pack')}</div>
                                            {t('crm.evidenceText', 'This lead was delivered with a valid E.164 phone and the network accepted it with HTTP 200, yet the S2S verdict is negative or missing. Export this row (CSV) and open a ticket with the network support quoting the SubID and the network lead id.')}
                                        </div>
                                    )}
                                </>
                            )}

                            {inspectorTab === 'tracking' && (
                                <>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        {[
                                            ['SubID / Click ID', selectedLead.click_id],
                                            ['Campaign', selectedLead.campaign_name || `#${selectedLead.campaign_id || 0}`],
                                            ['Landing', selectedLead.landing_name || (selectedLead.lander_id ? `#${selectedLead.lander_id}` : '—')],
                                            ['Offer', selectedLead.offer_id || '—'],
                                            ['utm_source', selectedLead.utm_source || '—'],
                                            ['utm_campaign', selectedLead.utm_campaign || '—'],
                                            ['utm_placement', selectedLead.utm_placement || '—'],
                                            ['adset_id / adset_name', [selectedLead.adset_id, selectedLead.adset_name].filter(Boolean).join(' / ') || '—'],
                                            ['ad_id / ad_name', [selectedLead.ad_id, selectedLead.ad_name].filter(Boolean).join(' / ') || '—'],
                                            ['S2S postback', selectedLead.s2s_postback_status || 'pending'],
                                            ['Status source', selectedLead.status_source || 'form_submit'],
                                            ['Rejection reason', selectedLead.status_reason || '—'],
                                        ].map(([label, value]) => (
                                            <div key={label} className="p-3 rounded-xl bg-[var(--color-bg-main)] border border-[var(--color-border)]">
                                                <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold">{label}</div>
                                                <div className="font-mono mt-1 break-all" style={{ color: 'var(--color-text-primary)' }}>{value}</div>
                                            </div>
                                        ))}
                                    </div>
                                    <div>
                                        <div className="text-[10px] uppercase tracking-wide text-[var(--color-text-muted)] font-semibold mb-1.5">{t('crm.fSubParams', 'Sub parameters carried by the click')}</div>
                                        <pre className="p-3 rounded-xl bg-slate-950 text-amber-300 text-[10px] overflow-x-auto whitespace-pre-wrap break-all border border-slate-800">{prettyJson(selectedLead.sub_data_json)}</pre>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Manual status control */}
                        <div className="p-4 border-t border-[var(--color-border)] flex flex-col sm:flex-row items-center gap-2">
                            <div className="flex items-center gap-2 flex-1 w-full">
                                <label className="text-xs font-semibold text-[var(--color-text-secondary)] whitespace-nowrap">{t('crm.setStatus', 'Set status')}</label>
                                <select
                                    value={manualStatus}
                                    onChange={(e) => setManualStatus(e.target.value)}
                                    className="flex-1 px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                >
                                    <option value="lead">Lead / Hold</option>
                                    <option value="sale">Approved / Sale</option>
                                    <option value="rejected">Rejected</option>
                                    <option value="trash">Trash</option>
                                </select>
                            </div>
                            <button
                                type="button"
                                onClick={handleSaveStatus}
                                disabled={savingStatus}
                                className="btn-primary !py-2.5 !px-5 !text-xs w-full sm:w-auto disabled:opacity-50 cursor-pointer"
                            >
                                {savingStatus ? t('crm.saving', 'Saving…') : t('crm.saveStatus', 'Save status')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* New Lead Modal */}
            {showNewLeadModal && (
                <div className="fixed inset-0 bg-black/50 z-[2000] flex items-center justify-center p-4">
                    <form onSubmit={handleCreateLead} className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-[var(--color-border)]">
                            <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('crm.newLead', '+ Create New Order / Lead')}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowNewLeadModal(false)}
                                className="p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)] cursor-pointer"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        <div className="space-y-3 text-xs">
                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.customer', 'Customer name')}</label>
                                <input
                                    type="text"
                                    value={newLeadData.name}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, name: e.target.value })}
                                    placeholder="e.g. Mario Rossi"
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.phone', 'Phone number')}</label>
                                <input
                                    type="text"
                                    value={newLeadData.phone}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, phone: e.target.value })}
                                    placeholder="e.g. +39 345 1234567"
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                />
                                <p className="text-[10px] text-[var(--color-text-muted)]">{t('crm.phoneNote', 'Stored raw and normalized to E.164 in the vault.')}</p>
                            </div>

                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">SubID</label>
                                <input
                                    type="text"
                                    value={newLeadData.subid}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, subid: e.target.value })}
                                    placeholder="auto"
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm font-mono focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.campaign', 'Campaign')}</label>
                                <select
                                    value={newLeadData.campaign_id}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, campaign_id: e.target.value })}
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-xs font-semibold focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                >
                                    <option value="">{t('crm.noCampaign', 'No campaign')}</option>
                                    {campaigns.map(c => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div className="space-y-1 col-span-1">
                                    <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.status', 'Status')}</label>
                                    <select
                                        value={newLeadData.status}
                                        onChange={(e) => setNewLeadData({ ...newLeadData, status: e.target.value })}
                                        className="w-full px-2 py-2 rounded-xl border bg-[var(--color-bg-main)] text-xs font-semibold focus:outline-none"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    >
                                        <option value="lead">Hold</option>
                                        <option value="sale">Sale</option>
                                        <option value="rejected">Rejected</option>
                                        <option value="trash">Trash</option>
                                    </select>
                                </div>
                                <div className="space-y-1">
                                    <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.payout', 'Payout')}</label>
                                    <input
                                        type="number"
                                        value={newLeadData.payout}
                                        onChange={(e) => setNewLeadData({ ...newLeadData, payout: e.target.value })}
                                        className="w-full px-2 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm font-semibold focus:outline-none"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <label className="font-semibold text-[var(--color-text-secondary)]">{t('crm.currency', 'Currency')}</label>
                                    <input
                                        type="text"
                                        value={newLeadData.currency}
                                        onChange={(e) => setNewLeadData({ ...newLeadData, currency: e.target.value.toUpperCase() })}
                                        className="w-full px-2 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm font-bold text-center focus:outline-none"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="flex gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() => setShowNewLeadModal(false)}
                                className="flex-1 py-2.5 rounded-xl font-medium border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-xs text-[var(--color-text-primary)] cursor-pointer"
                            >
                                {t('common.cancel', 'Cancel')}
                            </button>
                            <button
                                type="submit"
                                className="btn-primary flex-1 !text-xs cursor-pointer"
                            >
                                {t('crm.saveLead', 'Save Lead')}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
};

export default CRMPage;
