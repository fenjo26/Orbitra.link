import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { 
    Layers, Search, Filter, Download, Plus, RefreshCw, Eye, CheckCircle2, 
    XCircle, Clock, AlertTriangle, Trash2, ArrowUpRight, DollarSign, UserCheck, 
    Phone, Mail, MapPin, Globe, Calendar, ChevronLeft, ChevronRight, X, Check, Copy
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const STATUS_CONFIG = {
    sale: { label: 'Approved (Sale)', color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800', icon: CheckCircle2 },
    approved: { label: 'Approved (Sale)', color: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800', icon: CheckCircle2 },
    lead: { label: 'In Process (Hold)', color: 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800', icon: Clock },
    processing: { label: 'In Process (Hold)', color: 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-300 dark:border-amber-800', icon: Clock },
    rejected: { label: 'Rejected', color: 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border-rose-300 dark:border-rose-800', icon: XCircle },
    trash: { label: 'Trash / Spam', color: 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300 border-slate-300 dark:border-slate-700', icon: AlertTriangle }
};

const CRMPage = ({ setActiveTab, user }) => {
    const { t } = useLanguage();
    const [leads, setLeads] = useState([]);
    const [loading, setLoading] = useState(true);
    const [campaigns, setCampaigns] = useState([]);

    // Filters
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [campaignFilter, setCampaignFilter] = useState('all');
    const [dateRange, setDateRange] = useState('all');

    // Modals
    const [selectedLead, setSelectedLead] = useState(null);
    const [showNewLeadModal, setShowNewLeadModal] = useState(false);
    const [newLeadData, setNewLeadData] = useState({
        name: '',
        phone: '',
        subid: '',
        campaign_id: '',
        status: 'lead',
        payout: '25',
        currency: 'USD'
    });

    const fetchLeads = async () => {
        setLoading(true);
        try {
            const [convRes, campRes] = await Promise.all([
                axios.get(`${API_URL}?action=conversions&per_page=200`).catch(() => ({ data: { status: 'error', data: [] } })),
                axios.get(`${API_URL}?action=campaigns`).catch(() => ({ data: { status: 'error', data: [] } }))
            ]);

            if (convRes.data?.status === 'success') {
                // Conversions rows carry click_id — the CRM speaks "subid".
                setLeads((convRes.data.data || []).map(c => ({ ...c, subid: c.subid || c.click_id })));
            }
            if (campRes.data?.status === 'success') {
                setCampaigns(campRes.data.data || []);
            }
        } catch (error) {
            console.error('Failed to fetch CRM leads:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLeads();
    }, []);

    // Filtered leads
    const filteredLeads = useMemo(() => {
        return leads.filter(lead => {
            if (statusFilter !== 'all') {
                const normStatus = (lead.status || '').toLowerCase();
                if (statusFilter === 'approved' && !['sale', 'approved'].includes(normStatus)) return false;
                if (statusFilter === 'processing' && !['lead', 'processing'].includes(normStatus)) return false;
                if (statusFilter === 'rejected' && normStatus !== 'rejected') return false;
                if (statusFilter === 'trash' && normStatus !== 'trash') return false;
            }
            if (campaignFilter !== 'all' && String(lead.campaign_id) !== String(campaignFilter)) {
                return false;
            }
            if (search) {
                const q = search.toLowerCase();
                const matchSub = (lead.subid || '').toLowerCase().includes(q);
                const matchCamp = (lead.campaign_name || '').toLowerCase().includes(q);
                const matchOffer = (lead.offer_name || '').toLowerCase().includes(q);
                const matchTid = (lead.tid || '').toLowerCase().includes(q);
                if (!matchSub && !matchCamp && !matchOffer && !matchTid) return false;
            }
            return true;
        });
    }, [leads, statusFilter, campaignFilter, search]);

    // KPI Metrics calculation
    const metrics = useMemo(() => {
        const total = leads.length;
        let approved = 0;
        let processing = 0;
        let rejected = 0;
        let trash = 0;
        let revenue = 0;

        leads.forEach(l => {
            const st = (l.status || '').toLowerCase();
            const p = parseFloat(l.payout || 0);
            if (['sale', 'approved'].includes(st)) {
                approved++;
                revenue += p;
            } else if (['lead', 'processing'].includes(st)) {
                processing++;
            } else if (st === 'rejected') {
                rejected++;
            } else if (st === 'trash') {
                trash++;
            }
        });

        const approvalRate = total > 0 ? Math.round((approved / total) * 100) : 0;

        return { total, approved, processing, rejected, trash, revenue: revenue.toFixed(2), approvalRate };
    }, [leads]);

    const handleCreateLead = async (e) => {
        e.preventDefault();
        try {
            const subid = (newLeadData.subid || '').trim() || `crm_${Date.now()}_${Math.random().toString(36).substr(2, 5)}`;
            const res = await axios.post(`${API_URL}?action=crm_lead`, {
                subid,
                status: newLeadData.status,
                payout: parseFloat(newLeadData.payout) || 0,
                currency: newLeadData.currency || 'USD',
                campaign_id: newLeadData.campaign_id || null
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

    const handleExportCsv = () => {
        if (filteredLeads.length === 0) return;
        const headers = ['ID', 'Date', 'SubID', 'Campaign', 'Offer', 'Status', 'Payout', 'Currency'];
        const rows = filteredLeads.map(l => [
            l.id || l.tid || '',
            l.created_at || l.time || '',
            l.subid || '',
            `"${(l.campaign_name || '').replace(/"/g, '""')}"`,
            `"${(l.offer_name || '').replace(/"/g, '""')}"`,
            l.status || '',
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

    return (
        <div className="space-y-6 max-w-7xl mx-auto pb-12">
            {/* Hero Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-6 rounded-2xl border bg-[var(--color-bg-card)] border-[var(--color-border)] shadow-sm">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-2xl flex items-center justify-center bg-gradient-to-br from-indigo-500 to-blue-600 text-white shadow-lg shadow-blue-500/20">
                        <Layers size={28} />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--color-text-primary)' }}>
                                CRM — Order & Lead Pipeline
                            </h1>
                            <span className="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                3-in-1 Suite Module
                            </span>
                        </div>
                        <p className="text-sm mt-0.5" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('crm.subtitle', 'Track, filter and manage all customer leads and orders in real time')}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={fetchLeads}
                        disabled={loading}
                        className="p-2.5 rounded-xl border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] transition cursor-pointer"
                        title="Refresh leads"
                    >
                        <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
                    </button>
                    <button
                        type="button"
                        onClick={handleExportCsv}
                        disabled={filteredLeads.length === 0}
                        className="px-4 py-2 rounded-xl text-sm font-medium border flex items-center gap-2 hover:bg-[var(--color-bg-hover)] transition cursor-pointer disabled:opacity-50"
                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                    >
                        <Download size={16} />
                        <span>{t('crm.exportCsv', 'Export CSV')}</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowNewLeadModal(true)}
                        className="px-4 py-2 rounded-xl text-sm font-semibold text-white flex items-center gap-2 bg-[var(--color-primary)] hover:opacity-90 transition shadow-sm cursor-pointer"
                    >
                        <Plus size={16} />
                        <span>{t('crm.newLead', '+ New Lead')}</span>
                    </button>
                </div>
            </div>

            {/* KPI Cards Grid */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">{t('crm.totalLeads', 'Total Leads')}</span>
                    <div className="text-2xl font-bold mt-1" style={{ color: 'var(--color-text-primary)' }}>{metrics.total}</div>
                </div>

                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">{t('crm.inProcess', 'Hold / Processing')}</span>
                    <div className="text-2xl font-bold mt-1 text-amber-600 dark:text-amber-400">{metrics.processing}</div>
                </div>

                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-emerald-600 dark:text-emerald-400">{t('crm.approved', 'Approved Sales')}</span>
                    <div className="text-2xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">{metrics.approved}</div>
                </div>

                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-rose-600 dark:text-rose-400">{t('crm.rejected', 'Rejected')}</span>
                    <div className="text-2xl font-bold mt-1 text-rose-600 dark:text-rose-400">{metrics.rejected}</div>
                </div>

                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">{t('crm.approvalRate', 'Approval Rate')}</span>
                    <div className="text-2xl font-bold mt-1 text-blue-600 dark:text-blue-400">{metrics.approvalRate}%</div>
                </div>

                <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm">
                    <span className="text-xs font-semibold text-[var(--color-text-secondary)]">{t('crm.revenue', 'Earned Revenue')}</span>
                    <div className="text-2xl font-bold mt-1 text-emerald-600 dark:text-emerald-400">${metrics.revenue}</div>
                </div>
            </div>

            {/* Filter & Search Bar */}
            <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl p-4 shadow-sm flex flex-col md:flex-row items-center justify-between gap-3">
                <div className="relative w-full md:w-80">
                    <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)]" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('crm.searchPlaceholder', 'Search by SubID, Campaign, Offer...')}
                        className="w-full pl-10 pr-4 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]"
                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                    />
                </div>

                <div className="flex items-center gap-2 w-full md:w-auto overflow-x-auto">
                    {/* Status filter tabs */}
                    <div className="inline-flex p-1 rounded-xl border items-center gap-1 bg-[var(--color-bg-main)]" style={{ borderColor: 'var(--color-border)' }}>
                        {[
                            { id: 'all', label: 'All' },
                            { id: 'processing', label: 'Hold' },
                            { id: 'approved', label: 'Approved' },
                            { id: 'rejected', label: 'Rejected' },
                            { id: 'trash', label: 'Trash' }
                        ].map(st => (
                            <button
                                key={st.id}
                                type="button"
                                onClick={() => setStatusFilter(st.id)}
                                className={`px-3 py-1 rounded-lg text-xs font-semibold transition cursor-pointer ${
                                    statusFilter === st.id
                                        ? 'bg-[var(--color-primary)] text-white shadow-sm'
                                        : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]'
                                }`}
                            >
                                {st.label}
                            </button>
                        ))}
                    </div>

                    {/* Campaign filter */}
                    {campaigns.length > 0 && (
                        <select
                            value={campaignFilter}
                            onChange={(e) => setCampaignFilter(e.target.value)}
                            className="px-3 py-1.5 rounded-xl border bg-[var(--color-bg-main)] text-xs font-medium focus:outline-none"
                            style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                        >
                            <option value="all">All Campaigns</option>
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
                                <th className="px-4 py-3.5">ID / ClickID</th>
                                <th className="px-4 py-3.5">{t('crm.date', 'Date / Time')}</th>
                                <th className="px-4 py-3.5">Campaign</th>
                                <th className="px-4 py-3.5">Offer</th>
                                <th className="px-4 py-3.5">{t('crm.status', 'Status')}</th>
                                <th className="px-4 py-3.5 text-right">{t('crm.payout', 'Payout')}</th>
                                <th className="px-4 py-3.5 text-center">{t('crm.actions', 'Actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--color-border)]">
                            {loading ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>
                                        <RefreshCw size={20} className="animate-spin mx-auto mb-2" />
                                        <span>Loading orders and leads...</span>
                                    </td>
                                </tr>
                            ) : filteredLeads.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center text-sm" style={{ color: 'var(--color-text-muted)' }}>
                                        <Layers size={28} className="mx-auto mb-2 opacity-40" />
                                        <span>{t('crm.noLeads', 'No leads found matching current filters.')}</span>
                                    </td>
                                </tr>
                            ) : (
                                filteredLeads.map((lead, idx) => {
                                    const stKey = (lead.status || 'lead').toLowerCase();
                                    const stInfo = STATUS_CONFIG[stKey] || STATUS_CONFIG.lead;
                                    const StIcon = stInfo.icon;

                                    return (
                                        <tr key={lead.id || lead.tid || idx} className="hover:bg-[var(--color-bg-hover)] transition">
                                            <td className="px-4 py-3 font-mono font-medium" style={{ color: 'var(--color-text-primary)' }}>
                                                <div className="flex items-center gap-1.5">
                                                    <span>{lead.subid || lead.tid || `LEAD-${idx + 1}`}</span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-[var(--color-text-secondary)] whitespace-nowrap">
                                                {lead.created_at || lead.time || 'Just now'}
                                            </td>
                                            <td className="px-4 py-3 font-medium" style={{ color: 'var(--color-text-primary)' }}>
                                                {lead.campaign_name || 'Direct / Generic'}
                                            </td>
                                            <td className="px-4 py-3 text-[var(--color-text-secondary)]">
                                                {lead.offer_name || 'LeadForge Lander'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${stInfo.color}`}>
                                                    <StIcon size={12} />
                                                    <span>{stInfo.label}</span>
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                                ${parseFloat(lead.payout || 0).toFixed(2)}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedLead(lead)}
                                                    className="p-1.5 rounded-lg border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] transition cursor-pointer"
                                                    title="View Lead Details"
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

            {/* Lead Details Modal */}
            {selectedLead && (
                <div className="fixed inset-0 bg-black/50 z-[2000] flex items-center justify-center p-4">
                    <div className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between pb-3 border-b border-[var(--color-border)]">
                            <h3 className="text-base font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                {t('crm.leadDetails', 'Lead Details')}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setSelectedLead(null)}
                                className="p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        <div className="space-y-3 text-xs">
                            <div className="p-3 rounded-xl bg-[var(--color-bg-main)] border border-[var(--color-border)] space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">SubID / Click ID:</span>
                                    <span className="font-mono font-bold" style={{ color: 'var(--color-text-primary)' }}>{selectedLead.subid || selectedLead.tid}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">Date:</span>
                                    <span style={{ color: 'var(--color-text-primary)' }}>{selectedLead.created_at || selectedLead.time}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">Campaign:</span>
                                    <span className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>{selectedLead.campaign_name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">Offer:</span>
                                    <span className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>{selectedLead.offer_name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">Status:</span>
                                    <span className="font-bold uppercase text-emerald-600 dark:text-emerald-400">{selectedLead.status}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-[var(--color-text-secondary)]">Payout:</span>
                                    <span className="font-bold text-emerald-600 dark:text-emerald-400">${selectedLead.payout || '0.00'}</span>
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() => setSelectedLead(null)}
                            className="w-full py-2.5 rounded-xl font-bold bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] hover:opacity-80 transition cursor-pointer"
                        >
                            Close
                        </button>
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
                                className="p-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-primary)]"
                            >
                                <X size={18} />
                            </button>
                        </div>

                        <div className="space-y-3 text-xs">
                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">Customer Name</label>
                                <input
                                    type="text"
                                    value={newLeadData.name}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, name: e.target.value })}
                                    placeholder="e.g. Marco Rossi"
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="font-semibold text-[var(--color-text-secondary)]">Phone Number</label>
                                <input
                                    type="text"
                                    value={newLeadData.phone}
                                    onChange={(e) => setNewLeadData({ ...newLeadData, phone: e.target.value })}
                                    placeholder="e.g. +39 345 1234567"
                                    className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm focus:outline-none"
                                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1">
                                    <label className="font-semibold text-[var(--color-text-secondary)]">Initial Status</label>
                                    <select
                                        value={newLeadData.status}
                                        onChange={(e) => setNewLeadData({ ...newLeadData, status: e.target.value })}
                                        className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-xs font-semibold focus:outline-none"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    >
                                        <option value="lead">Lead / Processing</option>
                                        <option value="sale">Approved / Sale</option>
                                        <option value="rejected">Rejected</option>
                                        <option value="trash">Trash</option>
                                    </select>
                                </div>
                                <div className="space-y-1">
                                    <label className="font-semibold text-[var(--color-text-secondary)]">Payout ($)</label>
                                    <input
                                        type="number"
                                        value={newLeadData.payout}
                                        onChange={(e) => setNewLeadData({ ...newLeadData, payout: e.target.value })}
                                        className="w-full px-3 py-2 rounded-xl border bg-[var(--color-bg-main)] text-sm font-semibold focus:outline-none"
                                        style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' }}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="flex gap-2 pt-2">
                            <button
                                type="button"
                                onClick={() => setShowNewLeadModal(false)}
                                className="flex-1 py-2.5 rounded-xl font-medium border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] text-xs text-[var(--color-text-primary)]"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="flex-1 py-2.5 rounded-xl font-bold bg-[var(--color-primary)] text-white text-xs hover:opacity-90 transition shadow-sm"
                            >
                                Save Lead
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
};

export default CRMPage;
