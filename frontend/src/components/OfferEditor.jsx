import { useState, useEffect } from 'react';
import { X, Plus, Trash2, Check, Save, PackageOpen, HardDrive, ExternalLink, Layers3, Zap } from 'lucide-react';
import axios from 'axios';
import GeoSelector from './GeoSelector';
import HelpTooltip from './HelpTooltip';
import GroupsModal from './GroupsModal';
import AffiliateNetworkEditor from './AffiliateNetworkEditor';
import SegmentedControl from './common/SegmentedControl';
import FileDropzone from './common/FileDropzone';
import CodeSnippetCard from './common/CodeSnippetCard';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet } from '../utils/apiCache';
import { getStayInEditorAfterSave } from '../utils/editorPreferences';
import { copyToClipboard as copyUtil } from '../utils/clipboard';

const API_URL = '/api.php';

const OfferEditor = ({ offerId, onClose, onCreated }) => {
    const { t } = useLanguage();
    const [loading, setLoading] = useState(false);
    const [activeTab, setActiveTab] = useState('general');

    // Select options
    const [groups, setGroups] = useState([]);
    const [affiliateNetworks, setAffiliateNetworks] = useState([]);
    const [allOffers, setAllOffers] = useState([]);

    // Form state
    const [formData, setFormData] = useState({
        name: '',
        group_id: '',
        affiliate_network_id: '',
        url: '',
        redirect_type: 'redirect',
        is_local: false,
        geo: '',
        payout_type: 'cpa',
        payout_value: 0,
        payout_auto: false,
        allow_rebills: false,
        capping_limit: 0,
        capping_timezone: 'UTC',
        alt_offer_id: '',
        notes: '',
        values: [],
        state: 'active'
    });

    // Local offer files
    const [uploadingZip, setUploadingZip] = useState(false);
    const [pendingZip, setPendingZip] = useState(null);
    const [lastZip, setLastZip] = useState(null);
    const [offerFiles, setOfferFiles] = useState([]);
    const [savedOfferId, setSavedOfferId] = useState(null);
    const [savedSomething, setSavedSomething] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);
    const currentOfferId = offerId || savedOfferId;
    const [copiedSnippet, setCopiedSnippet] = useState('');

    // Show/hide advanced sections
    const [showCapping, setShowCapping] = useState(false);

    // Quick-create modals opened from the "+" next to the selects
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [showNetworkEditor, setShowNetworkEditor] = useState(false);
    const [postbackKey, setPostbackKey] = useState('');

    useEffect(() => {
        // The network editor builds postback URLs from the tracker's key.
        cachedGet('settings')
            .then(({ data }) => { if (data.status === 'success') setPostbackKey(data.data.postback_key || ''); })
            .catch(() => {});
    }, []);

    const refetchGroups = async () => {
        try {
            const res = await axios.get(`${API_URL}?action=offer_groups`);
            if (res.data.status === 'success') setGroups(res.data.data);
        } catch (err) { console.error(err); }
    };

    const refetchNetworks = async (selectNewest = false) => {
        try {
            const res = await axios.get(`${API_URL}?action=affiliate_networks`);
            if (res.data.status === 'success') {
                setAffiliateNetworks(res.data.data);
                if (selectNewest && res.data.data.length) {
                    const newest = res.data.data.reduce((a, b) => (a.id > b.id ? a : b));
                    setFormData(prev => ({ ...prev, affiliate_network_id: String(newest.id) }));
                }
            }
        } catch (err) { console.error(err); }
    };

    useEffect(() => {
        const id = currentOfferId;
        if (formData.is_local && id) {
            fetchOfferFiles(id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [formData.is_local, offerId, savedOfferId]);

    useEffect(() => {
        const fetchDeps = async () => {
            try {
                const [gRes, anRes, oRes] = await Promise.all([
                    axios.get(`${API_URL}?action=offer_groups`),
                    axios.get(`${API_URL}?action=affiliate_networks`),
                    axios.get(`${API_URL}?action=all_offers`)
                ]);
                if (gRes.data.status === 'success') setGroups(gRes.data.data);
                if (anRes.data.status === 'success') setAffiliateNetworks(anRes.data.data);
                if (oRes.data.status === 'success') setAllOffers(oRes.data.data.filter(o => o.id !== offerId));
            } catch (err) {
                console.error(err);
            }
        };
        fetchDeps();

        if (offerId) {
            setLoading(true);
            axios.get(`${API_URL}?action=get_offer&id=${offerId}`)
                .then(res => {
                    if (res.data.status === 'success') {
                        const data = res.data.data;
                        setFormData({
                            name: data.name || '',
                            group_id: data.group_id || '',
                            affiliate_network_id: data.affiliate_network_id || '',
                            url: data.url || '',
                            redirect_type: data.redirect_type || 'redirect',
                            is_local: !!data.is_local,
                            geo: data.geo || '',
                            payout_type: data.payout_type || 'cpa',
                            payout_value: parseFloat(data.payout_value) || 0,
                            payout_auto: !!data.payout_auto,
                            allow_rebills: !!data.allow_rebills,
                            capping_limit: data.capping_limit || 0,
                            capping_timezone: data.capping_timezone || 'UTC',
                            alt_offer_id: data.alt_offer_id || '',
                            notes: data.notes || '',
                            values: data.values || [],
                            state: data.state || 'active'
                        });
                        if (data.capping_limit > 0) setShowCapping(true);
                    }
                })
                .finally(() => setLoading(false));
        }
    }, [offerId]);

    const handleSave = async (forceClose = false) => {
        if (loading || uploadingZip || saveSuccess) return;
        if (!formData.name) {
            alert(t('offerEditor.fillName'));
            return;
        }
        if (!formData.is_local && !formData.url) {
            alert(t('offerEditor.fillUrl'));
            return;
        }

        try {
            setLoading(true);
            const payload = { ...formData };
            if (currentOfferId) payload.id = currentOfferId;

            const res = await axios.post(`${API_URL}?action=save_offer`, payload);
            if (res.data.status === 'success') {
                const newId = res.data.data?.id || currentOfferId;
                setSavedSomething(true);
                if (!currentOfferId && newId) {
                    setSavedOfferId(newId);
                    if (onCreated) onCreated(newId);
                }

                if (formData.is_local && pendingZip && newId) {
                    const zip = pendingZip;
                    setPendingZip(null);
                    setLastZip(zip);
                    await uploadOfferZip(newId, zip);
                }

                setSaveSuccess(true);
                setTimeout(() => {
                    setSaveSuccess(false);
                    if (forceClose || !getStayInEditorAfterSave()) {
                        onClose(true);
                    }
                }, 1000);
            } else {
                alert(t('offerEditor.saveError') + " " + res.data.message);
            }
        } catch {
            alert(t('offerEditor.networkError'));
        } finally {
            setLoading(false);
        }
    };

    // === Local offer archive: upload + file list (mirrors local landings) ===
    const uploadOfferZip = async (id, file) => {
        if (!id || !file) return;
        setUploadingZip(true);
        try {
            const fd = new FormData();
            fd.append('id', id);
            fd.append('file', file);
            const res = await axios.post(`${API_URL}?action=upload_offer`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status !== 'success') {
                alert(`${t('offerEditor.zipError', 'ZIP upload error')}: ${res.data.message || ''} ${res.data.detail ? JSON.stringify(res.data.detail) : ''}`.trim());
            } else {
                fetchOfferFiles(id);
            }
        } catch (err) {
            alert(`${t('offerEditor.zipError', 'ZIP upload error')}: ${err.response?.data?.message || err.message}`);
        } finally {
            setUploadingZip(false);
        }
    };

    const selectOfferZip = (file) => {
        if (currentOfferId) {
            setLastZip(file);
            uploadOfferZip(currentOfferId, file);
        } else {
            setPendingZip(file);
        }
    };

    const copySnippet = async (text, id) => {
        if (!await copyUtil(text)) return;
        setCopiedSnippet(id);
        setTimeout(() => setCopiedSnippet(''), 1800);
    };

    const fetchOfferFiles = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=offer_files`, { params: { id } });
            if (res.data.status === 'success') setOfferFiles(res.data.data || []);
        } catch (err) { console.error(err); }
    };

    const deleteOfferFile = async (path) => {
        if (!window.confirm(`${t('common.delete')} ${path}?`)) return;
        try {
            await axios.post(`${API_URL}?action=offer_file_op`, { id: currentOfferId, path, op: 'delete' });
            fetchOfferFiles(currentOfferId);
        } catch {
            alert(t('common.deleteError'));
        }
    };

    const addValue = () => {
        if (formData.values.length >= 10) {
            alert(t('offerEditor.maxValues'));
            return;
        }
        setFormData({
            ...formData,
            values: [...formData.values, { name: '', value: '' }]
        });
    };

    const updateValue = (index, field, value) => {
        const newValues = [...formData.values];
        newValues[index][field] = value;
        setFormData({ ...formData, values: newValues });
    };

    const removeValue = (index) => {
        const newValues = [...formData.values];
        newValues.splice(index, 1);
        setFormData({ ...formData, values: newValues });
    };

    // Keep 'Europe/Kiev' (deprecated IANA alias) so offers stored with it
    // still match an option and don't render a blank select.
    const timezones = [
        'UTC', 'Europe/Moscow', 'Europe/Kiev', 'Europe/Kyiv', 'Europe/London', 'Europe/Berlin',
        'Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Tokyo',
        'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Sao_Paulo'
    ];

    // Which segment (Local/Redirect/Preload/Action) the persisted fields map
    // to. Everything that is not local/preload/action is a redirect method.
    const offerType = formData.is_local
        ? 'local'
        : ['preload', 'action'].includes(formData.redirect_type)
            ? formData.redirect_type
            : 'redirect';
    const tabs = [
        { id: 'general', label: t('editor.general') },
        { id: 'integration', label: `${t('editor.integrations')} & ${t('landingEditor.viewCode')}` },
        { id: 'details', label: `${t('editor.notes')} & ${t('editor.params')}` }
    ];

    if (loading && offerId && !formData.name) {
        return (
            <div className="modal-overlay">
                <div className="modal-content" style={{ maxWidth: '300px' }}>
                    <div className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
                </div>
            </div>
        );
    }

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '880px', width: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden', padding: 0 }}>
                <div className="modal-header px-6 pt-5" style={{ marginBottom: 0, borderBottom: 'none', flexShrink: 0 }}>
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            <PackageOpen className="w-5 h-5" />
                        </div>
                        <h2 className="modal-title truncate">
                            {currentOfferId ? `${t('offers.titleSingular')}: ${formData.name}` : t('offers.createOffer')}
                        </h2>
                    </div>
                    <button type="button" onClick={() => onClose(savedSomething)} className="action-btn" aria-label={t('common.close', 'Close')}>
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div className="flex px-6 pt-1 gap-7 overflow-x-auto" style={{ borderBottom: '1px solid var(--color-border)', flexShrink: 0 }}>
                    {tabs.map(tab => (
                        <button
                            key={tab.id}
                            type="button"
                            className="pb-3 px-1 font-semibold text-sm transition border-b-2 whitespace-nowrap"
                            style={{
                                borderColor: activeTab === tab.id ? 'var(--color-primary)' : 'transparent',
                                color: activeTab === tab.id ? 'var(--color-primary)' : 'var(--color-text-secondary)'
                            }}
                            onClick={() => setActiveTab(tab.id)}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                {/* Content Area */}
                <div className="flex-1 overflow-y-auto p-6">
                    {activeTab === 'general' && (
                        <div className="space-y-5">
                            <div>
                                <label className="form-label">
                                    {t('offerEditor.nameLabel')} <span style={{ color: 'var(--color-danger)' }}>*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={formData.name}
                                    onChange={e => setFormData({ ...formData, name: e.target.value })}
                                    className="form-input"
                                    placeholder={t('offerEditor.namePlaceholder')}
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="form-label">{t('offerEditor.group')}</label>
                                    <div className="flex">
                                        <select
                                            value={formData.group_id}
                                            onChange={e => setFormData({ ...formData, group_id: e.target.value })}
                                            className="form-select rounded-r-none"
                                        >
                                            <option value="">{t('offerEditor.noGroup')}</option>
                                            {groups.map(g => (
                                                <option key={g.id} value={g.id}>{g.name}</option>
                                            ))}
                                        </select>
                                        <button type="button" className="btn btn-secondary rounded-l-none border-l-0" onClick={() => setShowGroupsModal(true)} title={t('groupsModal.offerGroups')}>
                                            <Plus className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">{t('landingEditor.status')}</label>
                                    <select
                                        value={formData.state}
                                        onChange={e => setFormData({ ...formData, state: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="active">{t('landingEditor.active')}</option>
                                        <option value="paused">{t('landingEditor.paused')}</option>
                                        <option value="archived">{t('landingEditor.archived')}</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="form-label">
                                    {t('offerEditor.affiliateNetwork')}
                                    <span className="ml-1 relative group cursor-pointer inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold rounded-full" style={{ color: 'var(--color-text-muted)', border: '1px solid var(--color-border)' }}>
                                        ?
                                        <span className="absolute bottom-full mb-2 hidden group-hover:block w-48 rounded-xl p-2 z-10 shadow-lg text-xs" style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)', border: '1px solid var(--color-border)' }}>
                                            {t('offerEditor.networkTooltip')}
                                        </span>
                                    </span>
                                </label>
                                <div className="flex">
                                    <select
                                        value={formData.affiliate_network_id}
                                        onChange={e => setFormData({ ...formData, affiliate_network_id: e.target.value })}
                                        className="form-select rounded-r-none"
                                    >
                                        <option value="">{t('offerEditor.noNetwork')}</option>
                                        {affiliateNetworks.map(an => (
                                            <option key={an.id} value={an.id}>{an.name}</option>
                                        ))}
                                    </select>
                                    <button type="button" className="btn btn-secondary rounded-l-none border-l-0" onClick={() => setShowNetworkEditor(true)} title={t('networks.title')}>
                                        <Plus className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>

                            {/* Offer Type Buttons. offerType is derived from the
                                persisted fields (is_local + redirect_type), and the
                                method select below only appears for the Redirect
                                family — the segmented control and the select used to
                                fight over redirect_type, deactivating each other. */}
                            <div>
                                <label className="form-label">{t('offerEditor.redirectType')} <HelpTooltip textKey="help.redirectTypeTooltip" /></label>
                                <div className="mb-3">
                                    <SegmentedControl
                                        ariaLabel={t('offerEditor.redirectType')}
                                        value={offerType}
                                        onChange={(type) => setFormData({
                                            ...formData,
                                            redirect_type: type,
                                            is_local: type === 'local'
                                        })}
                                        options={[
                                            { value: 'local', label: t('offers.local'), icon: HardDrive },
                                            { value: 'redirect', label: t('offers.redirect'), icon: ExternalLink },
                                            { value: 'preload', label: t('landingEditor.typePreload'), icon: Layers3 },
                                            { value: 'action', label: t('editor.action'), icon: Zap }
                                        ]}
                                    />
                                </div>
                                {offerType === 'redirect' && (
                                    <>
                                        <select
                                            value={formData.redirect_type}
                                            onChange={e => setFormData({ ...formData, redirect_type: e.target.value })}
                                            className="form-select"
                                        >
                                            <option value="redirect">{t('offerEditor.httpRedirect')}</option>
                                            <option value="js">{t('redirectTypes.jsName')}</option>
                                            <option value="meta_refresh">{t('redirectTypes.metaName')}</option>
                                            <option value="frame">{t('redirectTypes.iframeName')}</option>
                                            <option value="form_submit">{t('redirectTypes.formName')}</option>
                                            <option value="preload">{t('offerEditor.preloadCurl')}</option>
                                            <option value="curl_proxy">{t('redirectTypes.curlProxyName')}</option>
                                        </select>
                                        {(() => {
                                            const descKey = ({
                                                redirect: 'redirectTypes.redirectDesc',
                                                js: 'redirectTypes.jsDesc',
                                                meta_refresh: 'redirectTypes.metaDesc',
                                                frame: 'redirectTypes.iframeDesc',
                                                form_submit: 'redirectTypes.formDesc',
                                                preload: 'redirectTypes.preloadDesc',
                                                curl_proxy: 'redirectTypes.curlProxyDesc',
                                            })[formData.redirect_type];
                                            return descKey ? (
                                                <div className="form-hint">{t(descKey)}</div>
                                            ) : null;
                                        })()}
                                    </>
                                )}
                            </div>

                            {/* Local offer: archive upload + files (mirrors local landings) */}
                            {formData.is_local && (
                                <div className="p-4 rounded-2xl" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                                    <div className="font-semibold mb-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('offerEditor.localArchive', 'Local offer files')}
                                    </div>
                                    <FileDropzone
                                        file={pendingZip || lastZip}
                                        onFileSelect={selectOfferZip}
                                        disabled={uploadingZip}
                                        label={uploadingZip ? t('landingEditor.uploadingZip') : t('offerEditor.uploadZip', 'Upload ZIP Archive')}
                                        emptyHint={t('landingEditor.zipDropHint', 'Drag & drop .zip here or click to browse files')}
                                        replaceHint={t('landingEditor.zipReplaceHint', 'Click to replace')}
                                    />
                                    <p className="mt-2 text-xs" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                        {!currentOfferId
                                            ? t('offerEditor.zipOnCreateHint', 'The archive uploads right after the offer is created (an upload needs the offer id). index.html becomes the offer page.')
                                            : (offerFiles.length > 0
                                                ? `${t('offerEditor.filesLabel', 'files')}: ${offerFiles.length}`
                                                : t('offerEditor.noFiles', 'No files yet'))}
                                    </p>
                                    {currentOfferId && offerFiles.length > 0 && (
                                        <ul className="space-y-1 max-h-40 overflow-y-auto mt-3">
                                            {offerFiles.map(f => (
                                                <li key={f} className="flex items-center justify-between gap-2 text-xs px-2 py-1.5 rounded-lg" style={{ background: 'var(--color-bg-card)', border: '1px solid var(--color-border)' }}>
                                                    <span className="truncate font-mono" style={{ color: 'var(--color-text-secondary)' }}>{f}</span>
                                                    <button
                                                        type="button"
                                                        className="action-btn"
                                                        onClick={() => deleteOfferFile(f)}
                                                        title={t('common.delete')}
                                                    >
                                                        <Trash2 className="w-3.5 h-3.5" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}

                            {!formData.is_local && (
                                <div>
                                    <label className="form-label">
                                        URL <span style={{ color: 'var(--color-danger)' }}>*</span>
                                    </label>
                                    <input
                                        type="url"
                                        required
                                        value={formData.url}
                                        onChange={e => setFormData({ ...formData, url: e.target.value })}
                                        className="form-input"
                                        placeholder="https://offer.example.com/?subid={subid}"
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    {activeTab === 'integration' && (
                        <div className="space-y-4">
                            <CodeSnippetCard
                                title={t('landingEditor.offerLinkTitle')}
                                description={t('landingEditor.offerLinkHint')}
                                code="{offer}"
                                copyId="offer-macro"
                                onCopy={copySnippet}
                                copied={copiedSnippet}
                                copyLabel={t('landingEditor.copyCode')}
                                copiedLabel={t('landingEditor.codeCopied')}
                            />
                            <CodeSnippetCard
                                title={formData.is_local ? t('offerEditor.localArchive', 'Local offer files') : t('offerEditor.urlTemplate', 'Offer URL')}
                                description={formData.is_local
                                    ? t('offerEditor.zipOnCreateHint', 'index.html becomes the local offer page.')
                                    : t('offerEditor.networkTooltip')}
                                code={formData.is_local ? 'index.html' : (formData.url || 'https://offer.example.com/?subid={subid}')}
                                copyId="offer-url-template"
                                onCopy={copySnippet}
                                copied={copiedSnippet}
                                copyLabel={t('landingEditor.copyCode')}
                                copiedLabel={t('landingEditor.codeCopied')}
                            />
                            <CodeSnippetCard
                                title={t('sourceEditor.availableMacros')}
                                description={t('offerEditor.valuesDesc')}
                                code={['{subid}', ...formData.values.filter(value => value.name).map(value => `{offer_value:${value.name}}`)].join('\n')}
                                copyId="offer-available-macros"
                                onCopy={copySnippet}
                                copied={copiedSnippet}
                                copyLabel={t('landingEditor.copyCode')}
                                copiedLabel={t('landingEditor.codeCopied')}
                                muted
                            />
                        </div>
                    )}

                    {/* Notes & Parameters Tab */}
                    {activeTab === 'details' && (
                        <div className="space-y-5">
                            <div>
                                <label className="form-label">{t('offerEditor.countries')}</label>
                                <GeoSelector
                                    value={formData.geo}
                                    onChange={geo => setFormData({ ...formData, geo })}
                                    placeholder={t('offerEditor.countriesPlaceholder')}
                                />
                            </div>

                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <h4 className="text-sm font-bold mb-3" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.payouts')}</h4>
                                <div className="flex gap-4 mb-3">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payout_type"
                                            value="cpa"
                                            checked={formData.payout_type === 'cpa'}
                                            onChange={() => setFormData({ ...formData, payout_type: 'cpa' })}
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>CPA</span>
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payout_type"
                                            value="cpc"
                                            checked={formData.payout_type === 'cpc'}
                                            onChange={() => setFormData({ ...formData, payout_type: 'cpc' })}
                                            style={{ accentColor: 'var(--color-primary)' }}
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>CPC</span>
                                    </label>
                                </div>

                                <div className="flex gap-4 items-center">
                                    <div className="flex-1 max-w-[200px] relative">
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={formData.payout_value}
                                            onChange={e => setFormData({ ...formData, payout_value: parseFloat(e.target.value) || 0 })}
                                            className="form-input pr-8"
                                            disabled={formData.payout_auto}
                                        />
                                        <span className="absolute right-3 top-2" style={{ color: 'var(--color-text-muted)' }}>$</span>
                                    </div>
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.payout_auto}
                                            onChange={e => setFormData({ ...formData, payout_auto: e.target.checked })}
                                            className="rounded"
                                        />
                                        <span className="text-sm" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.payoutByParam')}</span>
                                    </label>
                                </div>
                            </div>

                            <div className="pt-2 flex items-center justify-between" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.rebills')}</h4>
                                <label className="relative inline-flex items-center cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="sr-only peer"
                                        checked={formData.allow_rebills}
                                        onChange={e => setFormData({ ...formData, allow_rebills: e.target.checked })}
                                    />
                                    <div className="w-9 h-5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-4 after:w-4 after:transition-all" style={{ backgroundColor: formData.allow_rebills ? 'var(--color-primary)' : 'var(--color-bg-hover)', '--tw-bg-opacity': 1, after: { backgroundColor: 'white' } }}>
                                        <div className="absolute top-[2px] left-[2px] w-4 h-4 rounded-full transition-transform" style={{ backgroundColor: 'white', transform: formData.allow_rebills ? 'translateX(16px)' : 'translateX(0)' }}></div>
                                    </div>
                                </label>
                            </div>

                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <div className="flex items-center justify-between mb-3">
                                    <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.conversionCap')}</h4>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="sr-only peer"
                                            checked={showCapping}
                                            onChange={() => setShowCapping(!showCapping)}
                                        />
                                        <div className="w-9 h-5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-4 after:w-4 after:transition-all" style={{ backgroundColor: showCapping ? 'var(--color-primary)' : 'var(--color-bg-hover)' }}>
                                            <div className="absolute top-[2px] left-[2px] w-4 h-4 rounded-full transition-transform" style={{ backgroundColor: 'white', transform: showCapping ? 'translateX(16px)' : 'translateX(0)' }}></div>
                                        </div>
                                    </label>
                                </div>

                                {showCapping && (
                                    <div className="space-y-4 p-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                        <div className="flex gap-4">
                                            <div className="flex-1">
                                                <label className="form-label">{t('offerEditor.dailyLimit')}</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    value={formData.capping_limit}
                                                    onChange={e => setFormData({ ...formData, capping_limit: parseInt(e.target.value) || 0 })}
                                                    className="form-input"
                                                />
                                            </div>
                                            <div className="flex-1">
                                                <label className="form-label">{t('offerEditor.timezone')}</label>
                                                <select
                                                    value={formData.capping_timezone}
                                                    onChange={e => setFormData({ ...formData, capping_timezone: e.target.value })}
                                                    className="form-select"
                                                >
                                                    {timezones.map(tz => (
                                                        <option key={tz} value={tz}>{tz}</option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div>
                                            <label className="form-label">{t('offerEditor.altOffer')}</label>
                                            <select
                                                value={formData.alt_offer_id}
                                                onChange={e => setFormData({ ...formData, alt_offer_id: e.target.value })}
                                                className="form-select"
                                            >
                                                <option value="">{t('offerEditor.notSelected')}</option>
                                                {allOffers.map(o => (
                                                    <option key={o.id} value={o.id}>{o.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Custom values (merged from the old mislabeled tab) */}
                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <div className="flex justify-between items-center mb-3">
                                    <div>
                                        <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.valuesTitle')}</h4>
                                        <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                            {t('offerEditor.valuesDesc')}
                                        </p>
                                    </div>
                                    <button
                                        onClick={addValue}
                                        disabled={formData.values.length >= 10}
                                        className="btn btn-secondary btn-sm"
                                    >
                                        <Plus className="w-3.5 h-3.5" />
                                        {t('offerEditor.add')}
                                    </button>
                                </div>

                                {formData.values.length === 0 ? (
                                    <div className="text-center py-8 rounded-xl border-2 border-dashed" style={{ color: 'var(--color-text-muted)', backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                        <p className="text-sm">{t('offerEditor.noValues')}</p>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {formData.values.map((val, idx) => (
                                            <div key={idx} className="flex items-center gap-3">
                                                <div className="flex-1">
                                                    <input
                                                        type="text"
                                                        value={val.name}
                                                        onChange={e => updateValue(idx, 'name', e.target.value)}
                                                        className="form-input"
                                                        placeholder={t('offerEditor.paramNamePlaceholder')}
                                                    />
                                                </div>
                                                <div className="flex-[2]">
                                                    <input
                                                        type="text"
                                                        value={val.value}
                                                        onChange={e => updateValue(idx, 'value', e.target.value)}
                                                        className="form-input"
                                                        placeholder={t('offerEditor.paramValuePlaceholder')}
                                                    />
                                                </div>
                                                <button
                                                    onClick={() => removeValue(idx)}
                                                    className="action-btn text-red"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {formData.values.length > 0 && (
                                    <div className="mt-4 pt-4" style={{ borderTop: '1px solid var(--color-border)' }}>
                                        <h4 className="font-semibold text-xs mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.usageExamples')}</h4>
                                        <ul className="text-xs space-y-1 font-mono p-3 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)', color: 'var(--color-text-secondary)' }}>
                                            {formData.values.filter(v => v.name).map((v, idx) => (
                                                <li key={idx}>{`{offer_value:${v.name}}`} → {v.value || t('offerEditor.empty')}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>

                            <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <label className="form-label">{t('editor.notes')}</label>
                                <textarea
                                    rows={6}
                                    value={formData.notes}
                                    onChange={e => setFormData({ ...formData, notes: e.target.value })}
                                    className="form-input resize-none"
                                    placeholder={t('offerEditor.notesPlaceholder')}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="modal-footer px-6 pb-5" style={{ marginTop: 0, flexShrink: 0 }}>
                    <button type="button" onClick={() => onClose(savedSomething)} className="btn btn-secondary rounded-xl">
                        <X className="w-4 h-4" />
                        {t('common.cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={() => handleSave(true)}
                        disabled={loading || uploadingZip || saveSuccess}
                        className="btn btn-secondary rounded-xl"
                    >
                        <Save className="w-4 h-4" />
                        {t('profile.saveAndClose')}
                    </button>
                    <button
                        type="button"
                        onClick={() => handleSave(false)}
                        disabled={loading || uploadingZip || saveSuccess}
                        className="btn btn-primary rounded-xl"
                        style={saveSuccess ? { backgroundColor: 'var(--color-success)' } : {}}
                    >
                        <Check className="w-4 h-4 mr-1.5" />
                        {saveSuccess
                            ? t('editor.saved')
                            : loading
                                ? t('common.saving')
                                : uploadingZip
                                ? t('landingEditor.uploadingZip')
                                : (currentOfferId ? t('common.save') : t('offers.createOffer'))}
                    </button>
                </div>
            </div>

            {/* Quick-create group from the "+" next to the group select */}
            {showGroupsModal && (
                <GroupsModal
                    type="offer"
                    onClose={() => { setShowGroupsModal(false); refetchGroups(); }}
                    onGroupCreated={(g) => {
                        if (!g || !g.id) return;
                        setGroups(prev => prev.some(x => x.id == g.id) ? prev : [...prev, g]);
                        setFormData(prev => ({ ...prev, group_id: String(g.id) }));
                    }}
                />
            )}

            {/* Quick-create network from the "+" next to the network select */}
            {showNetworkEditor && (
                <AffiliateNetworkEditor
                    networkId={null}
                    onClose={() => { setShowNetworkEditor(false); refetchNetworks(true); }}
                    postbackKey={postbackKey}
                />
            )}
        </div>
    );
};

export default OfferEditor;
