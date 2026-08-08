import React, { useState, useEffect, useRef } from 'react';
import { Save, X, Upload, FileText, Code, Check, Plus } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// A read-only code block with a copy button. Reused by the offer-link hint so
// every snippet gets the same one-click copy the JS-adapter block already had.
const CopyableCode = ({ text, copied, onCopy, t, muted = false }) => (
    <div className="relative mt-1">
        <button
            type="button"
            onClick={() => { navigator.clipboard.writeText(text); onCopy && onCopy(); }}
            className="btn btn-secondary btn-sm"
            style={{ position: 'absolute', top: '6px', right: '6px', padding: '2px 8px', fontSize: '11px', zIndex: 1 }}
        >
            {copied ? <Check className="w-3 h-3" /> : <Code className="w-3 h-3" />}
            {copied ? t('landingEditor.codeCopied') : t('landingEditor.copyCode')}
        </button>
        <pre className="p-2 rounded-lg overflow-x-auto" style={{
            backgroundColor: 'var(--color-bg-card)',
            border: '1px solid var(--color-border)',
            color: muted ? 'var(--color-text-muted)' : 'var(--color-text-primary)',
            fontSize: '12.5px',
            margin: 0,
            paddingRight: '90px'
        }}><code>{text}</code></pre>
    </div>
);

const LandingEditor = ({ landingId, onClose }) => {
    const { t } = useLanguage();
    const [landing, setLanding] = useState({
        name: '',
        group_id: '',
        type: 'local',
        url: '',
        action_payload: '',
        action_type: '',
        state: 'active',
        slug: '',
        redirect_type: 'redirect'
    });
    const [groups, setGroups] = useState([]);
    const [loading, setLoading] = useState(false);
    // Only needed by the "send to campaign" action, so it is fetched on demand.
    const [campaigns, setCampaigns] = useState([]);
    const [postbackKey, setPostbackKey] = useState('');
    const [adapterCopied, setAdapterCopied] = useState(false);
    // Offer-link hint: which code format to show for redirect landings, and a
    // per-snippet "copied" toast for the copy button.
    const [linkFormat, setLinkFormat] = useState('html');
    const [linkCopied, setLinkCopied] = useState(false);

    const origin = (typeof window !== 'undefined' && window.location && window.location.origin)
        ? window.location.origin
        : 'https://your-tracker.example.com';
    // Absolute on purpose: an external landing loads this from its own domain and
    // must still reach the tracker.
    const adapterSnippet = `<script src="${origin}/js/orbitra-adapter.js"`
        + (postbackKey ? `\n        data-postback="${origin}/${postbackKey}/postback"` : '')
        + `></script>`;

    // Local Landing File Management State
    const [files, setFiles] = useState([]);
    const [selectedFile, setSelectedFile] = useState(null);
    const [fileContent, setFileContent] = useState('');
    const [savingFile, setSavingFile] = useState(false);
    const [uploadingZip, setUploadingZip] = useState(false);
    const fileInputRef = useRef(null);
    const assetInputRef = useRef(null);

    // The campaign list is only meaningful for one action, so it is not part of
    // the initial load every landing pays for.
    useEffect(() => {
        if (landing.type !== 'action' || landing.action_type !== 'to_campaign' || campaigns.length) return;
        let cancelled = false;
        axios.get(`${API_URL}?action=campaigns`)
            .then(res => { if (!cancelled && res.data.status === 'success') setCampaigns(res.data.data || []); })
            .catch(() => { /* the field still accepts a typed id */ });
        return () => { cancelled = true; };
    }, [landing.type, landing.action_type, campaigns.length]);

    useEffect(() => {
        const fetchInitialData = async () => {
            setLoading(true);
            try {
                const [groupsRes, settingsRes] = await Promise.all([
                    axios.get(`${API_URL}?action=landing_groups`),
                    // Needed to build the adapter's postback URL, which is keyed by
                    // the instance's postback_key.
                    axios.get(`${API_URL}?action=settings`).catch(() => null)
                ]);
                if (groupsRes.data.status === 'success') {
                    setGroups(groupsRes.data.data);
                }
                if (settingsRes && settingsRes.data && settingsRes.data.status === 'success') {
                    setPostbackKey(settingsRes.data.data?.postback_key || '');
                }

                if (landingId) {
                    const landingRes = await axios.get(`${API_URL}?action=get_landing&id=${landingId}`);
                    if (landingRes.data.status === 'success') {
                        setLanding(landingRes.data.data);
                        if (landingRes.data.data.type === 'local') {
                            fetchLandingFiles(landingId);
                        }
                    }
                }
            } catch (error) {
                console.error("Error fetching data:", error);
                alert(t('landingEditor.loadError'));
            } finally {
                setLoading(false);
            }
        };

        fetchInitialData();
    }, [landingId]);

    const fetchLandingFiles = async (id) => {
        try {
            const res = await axios.get(`${API_URL}?action=landing_files&id=${id}`);
            if (res.data.status === 'success') {
                setFiles(res.data.data);
            }
        } catch (error) {
            console.error(error);
        }
    };

    const handleFormSubmit = async (e) => {
        e.preventDefault();
        try {
            const payload = { ...landing };
            if (landingId) payload.id = landingId;

            const res = await axios.post(`${API_URL}?action=save_landing`, payload);
            if (res.data.status === 'success') {
                if (!landingId && res.data.data.id && landing.type === 'local') {
                    alert(t('landingEditor.savedFiles'));
                    onClose(true);
                } else {
                    alert(t('landingEditor.savedSuccess'));
                    onClose(true);
                }
            } else {
                alert(res.data.message || t('landingEditor.saveError'));
            }
        } catch (error) {
            alert(t('landingEditor.networkError'));
        }
    };

    // File operations inside the landing folder. The server re-checks every path
    // and extension; these prompts are convenience, not the security boundary.
    const fileOp = async (payload, okMessage) => {
        try {
            const res = await axios.post(`${API_URL}?action=landing_file_op`, { id: landingId, ...payload });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            fetchLandingFiles(landingId);
            if (okMessage) alert(okMessage);
            return true;
        } catch (e) {
            alert(`${t('landingEditor.fileOpError')}: ${e.response?.data?.message || e.message}`);
            return false;
        }
    };

    const createFile = async () => {
        const path = window.prompt(t('landingEditor.fileNewPrompt'), 'page2.html');
        if (!path) return;
        await fileOp({ op: 'create', path, content: '' });
    };

    const renameFile = async (file) => {
        const to = window.prompt(t('landingEditor.fileRenamePrompt'), file);
        if (!to || to === file) return;
        if (await fileOp({ op: 'rename', path: file, to }) && selectedFile === file) {
            setSelectedFile(null);
            setFileContent('');
        }
    };

    const deleteFile = async (file) => {
        if (!window.confirm(`${t('landingEditor.fileDeleteConfirm')}\n\n${file}`)) return;
        if (await fileOp({ op: 'delete', path: file }) && selectedFile === file) {
            setSelectedFile(null);
            setFileContent('');
        }
    };

    const uploadFile = async (e) => {
        const file = e.target.files[0];
        if (!file || !landingId) return;
        const dir = window.prompt(t('landingEditor.fileUploadDirPrompt'), '') ?? '';
        const fd = new FormData();
        fd.append('file', file);
        fd.append('id', landingId);
        fd.append('dir', dir);
        try {
            const res = await axios.post(`${API_URL}?action=upload_landing_file`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            fetchLandingFiles(landingId);
        } catch (err) {
            alert(`${t('landingEditor.fileOpError')}: ${err.response?.data?.message || err.message}`);
        } finally {
            e.target.value = null;
        }
    };

    const handleZipUpload = async (e) => {
        const file = e.target.files[0];
        if (!file || !landingId) return;

        setUploadingZip(true);
        const formData = new FormData();
        formData.append('file', file);
        formData.append('id', landingId);

        try {
            const res = await axios.post(`${API_URL}?action=upload_landing`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status === 'success') {
                alert(t('landingEditor.archiveUploaded'));
                fetchLandingFiles(landingId);
            } else {
                alert(res.data.message || t('landingEditor.archiveError'));
            }
        } catch (error) {
            alert(t('landingEditor.archiveError'));
        } finally {
            setUploadingZip(false);
            e.target.value = null;
        }
    };

    const loadFileContent = async (path) => {
        try {
            const res = await axios.get(`${API_URL}?action=get_landing_file&id=${landingId}&path=${encodeURIComponent(path)}`);
            if (res.data.status === 'success') {
                setSelectedFile(path);
                setFileContent(res.data.data);
            } else {
                alert(t('landingEditor.fileUploadError'));
            }
        } catch (error) {
            alert(t('landingEditor.fileReadError'));
        }
    };

    const saveFileContent = async () => {
        if (!selectedFile) return;
        setSavingFile(true);
        try {
            const res = await axios.post(`${API_URL}?action=save_landing_file`, {
                id: landingId,
                path: selectedFile,
                content: fileContent
            });
            if (res.data.status === 'success') {
                // Success marker
            } else {
                alert(t('landingEditor.fileSaveError'));
            }
        } catch (error) {
            alert(t('landingEditor.fileSaveError2'));
        } finally {
            setSavingFile(false);
        }
    };

    if (loading) return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '300px' }}>
                <div className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
            </div>
        </div>
    );

    const isLocal = landing.type === 'local';

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '1200px', width: '100%' }}>
                <div className="modal-header">
                    <h3 className="modal-title">
                        {landingId ? t('landingEditor.saveChanges') : t('landingEditor.createLanding')}
                    </h3>
                    <button onClick={() => onClose(false)} className="action-btn">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto p-0 flex flex-col md:flex-row">
                    {/* Settings Panel */}
                    <div className={`p-6 ${isLocal && landingId ? 'md:w-1/3' : 'w-full'} flex flex-col pt-4`} style={{ borderRight: isLocal && landingId ? '1px solid var(--color-border)' : 'none' }}>
                        <form id="landing-form" onSubmit={handleFormSubmit} className="space-y-4">
                            <div>
                                <label className="form-label">{t('landingEditor.name')}</label>
                                <input
                                    type="text"
                                    required
                                    value={landing.name}
                                    onChange={e => setLanding({ ...landing, name: e.target.value })}
                                    className="form-input"
                                    placeholder={t('landingEditor.namePlaceholder')}
                                />
                            </div>

                            <div className="flex gap-4">
                                <div className="flex-1">
                                    <label className="form-label">{t('landingEditor.group')}</label>
                                    <select
                                        value={landing.group_id}
                                        onChange={e => setLanding({ ...landing, group_id: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="">{t('landingEditor.noGroup')}</option>
                                        {groups.map(g => (
                                            <option key={g.id} value={g.id}>{g.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="flex-1">
                                    <label className="form-label">{t('landingEditor.status')}</label>
                                    <select
                                        value={landing.state}
                                        onChange={e => setLanding({ ...landing, state: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="active">{t('landingEditor.active')}</option>
                                        <option value="archived">{t('landingEditor.archived')}</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="form-label">{t('landingEditor.landingType')}</label>
                                <div className="flex rounded-xl overflow-hidden mb-3" style={{ border: '1px solid var(--color-border)' }}>
                                    {[
                                        { value: 'local', label: t('landingEditor.typeLocal') },
                                        { value: 'redirect', label: t('landingEditor.typeRedirect') },
                                        { value: 'preload', label: t('landingEditor.typePreload') },
                                        { value: 'action', label: t('landingEditor.typeAction') },
                                    ].map((opt, idx, arr) => {
                                        const active = landing.type === opt.value;
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() => setLanding({ ...landing, type: opt.value })}
                                                className="flex-1 px-4 py-2 text-sm font-medium transition"
                                                style={{
                                                    backgroundColor: active ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                                    color: active ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                    borderRight: idx < arr.length - 1 ? '1px solid var(--color-border)' : 'none'
                                                }}
                                            >
                                                {opt.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Folder name for a local landing. Files land in
                                landings/<slug>/ instead of landings/<id>/, so the
                                directory a campaign archive unpacks into is readable. */}
                            {landing.type === 'local' && (
                                <div>
                                    <label className="form-label">{t('landingEditor.slugLabel')}</label>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-mono" style={{ color: 'var(--color-text-muted)' }}>/lander/</span>
                                        <input
                                            type="text"
                                            value={landing.slug || ''}
                                            onChange={e => setLanding({ ...landing, slug: e.target.value })}
                                            className="form-input font-mono"
                                            style={{ flex: 1 }}
                                            placeholder={t('landingEditor.slugPlaceholder')}
                                            autoComplete="off"
                                            spellCheck="false"
                                        />
                                    </div>
                                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('landingEditor.slugHint')}
                                    </p>
                                </div>
                            )}

                            {/* Redirect method for a redirect landing — an offer can
                                already pick HTTP/JS/meta; a landing that simply
                                forwards should be able to do the same. */}
                            {landing.type === 'redirect' && (
                                <div>
                                    <label className="form-label">{t('landingEditor.redirectMethodLabel')}</label>
                                    <select
                                        value={landing.redirect_type || 'redirect'}
                                        onChange={e => setLanding({ ...landing, redirect_type: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="redirect">{t('landingEditor.redirectHttp')}</option>
                                        <option value="js">{t('landingEditor.redirectJs')}</option>
                                        <option value="meta_refresh">{t('landingEditor.redirectMeta')}</option>
                                    </select>
                                </div>
                            )}

                            {(landing.type === 'redirect' || landing.type === 'preload') && (
                                <div>
                                    <label className="form-label">{t('landingEditor.urlLabel')}</label>
                                    <input
                                        type="url"
                                        required
                                        value={landing.url || ''}
                                        onChange={e => setLanding({ ...landing, url: e.target.value })}
                                        className="form-input"
                                        placeholder={t('landingEditor.urlPlaceholder')}
                                    />
                                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {landing.type === 'preload' && t('landingEditor.preloadHint')}
                                    </p>
                                </div>
                            )}

                            {landing.type === 'action' && (
                                <div className="space-y-3">
                                    <div>
                                        <label className="form-label">{t('landingEditor.actionTypeLabel')}</label>
                                        <select
                                            value={landing.action_type || 'not_found'}
                                            onChange={e => setLanding({ ...landing, action_type: e.target.value })}
                                            className="form-input"
                                        >
                                            <option value="to_campaign">{t('landingEditor.actionToCampaign')}</option>
                                            <option value="not_found">{t('landingEditor.actionNotFound')}</option>
                                            <option value="show_text">{t('landingEditor.actionShowText')}</option>
                                            <option value="show_html">{t('landingEditor.actionShowHtml')}</option>
                                            <option value="do_nothing">{t('landingEditor.actionDoNothing')}</option>
                                        </select>
                                        <p className="mt-1" style={{ fontSize: '12.5px', color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                            {{
                                                to_campaign: t('landingEditor.actionToCampaignHint'),
                                                not_found: t('landingEditor.actionNotFoundHint'),
                                                show_text: t('landingEditor.actionShowTextHint'),
                                                show_html: t('landingEditor.actionShowHtmlHint'),
                                                do_nothing: t('landingEditor.actionDoNothingHint'),
                                            }[landing.action_type || 'not_found']}
                                        </p>
                                    </div>

                                    {landing.action_type === 'to_campaign' && (
                                        <div>
                                            <label className="form-label">{t('landingEditor.actionTargetCampaign')}</label>
                                            <select
                                                required
                                                value={landing.action_payload || ''}
                                                onChange={e => setLanding({ ...landing, action_payload: e.target.value })}
                                                className="form-input"
                                            >
                                                <option value="">{t('landingEditor.actionPickCampaign')}</option>
                                                {campaigns.map(c => (
                                                    <option key={c.id} value={c.id}>{c.name} (#{c.id})</option>
                                                ))}
                                            </select>
                                        </div>
                                    )}

                                    {(landing.action_type === 'show_text' || landing.action_type === 'show_html') && (
                                        <div>
                                            <label className="form-label">
                                                {landing.action_type === 'show_text'
                                                    ? t('landingEditor.actionTextLabel')
                                                    : t('landingEditor.actionHtmlLabel')}
                                            </label>
                                            <textarea
                                                required
                                                rows={6}
                                                value={landing.action_payload || ''}
                                                onChange={e => setLanding({ ...landing, action_payload: e.target.value })}
                                                className="form-input font-mono text-sm"
                                                placeholder={t('landingEditor.actionPayloadPlaceholder')}
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* How to point the landing's buy button at the offer.
                                This is the first thing people get wrong when moving
                                a landing over from another tracker, so it lives next
                                to the upload rather than in the documentation. */}
                            {(landing.type === 'local' || landing.type === 'preload' || landing.type === 'redirect') && (
                                <div className="mt-4 p-4 rounded-2xl text-sm" style={{
                                    border: '1px solid var(--color-primary)',
                                    backgroundColor: 'var(--color-bg-soft)'
                                }}>
                                    <div className="flex items-center justify-between" style={{ gap: '8px', marginBottom: '4px' }}>
                                        <div className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                            {t('landingEditor.offerLinkTitle')}
                                        </div>
                                        {/* For a redirect landing the integration code has three
                                            shapes (an external page can build the link with plain
                                            HTML, document.write JS, or server-side PHP). Local and
                                            preload landings live on the tracker, where {offer} is
                                            substituted directly, so a single HTML snippet is enough. */}
                                        {landing.type === 'redirect' && (
                                            <div className="flex" style={{ gap: '2px' }}>
                                                {['html', 'js', 'php'].map(fmt => {
                                                    const active = linkFormat === fmt;
                                                    return (
                                                        <button
                                                            key={fmt}
                                                            type="button"
                                                            onClick={() => setLinkFormat(fmt)}
                                                            className="px-2 py-1 text-xs rounded-md transition"
                                                            style={{
                                                                backgroundColor: active ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                                                color: active ? 'var(--color-primary)' : 'var(--color-text-muted)',
                                                                border: '1px solid var(--color-border)'
                                                            }}
                                                        >
                                                            {fmt.toUpperCase()}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                    <p className="mb-2" style={{ color: 'var(--color-text-secondary)', lineHeight: 1.55 }}>
                                        {t('landingEditor.offerLinkHint')}
                                    </p>
                                    <CopyableCode
                                        text={landing.type === 'redirect'
                                            ? (linkFormat === 'html'
                                                ? `<a href="${origin}/?_lp=1">${t('landingEditor.offerLinkWord')}</a>`
                                                : linkFormat === 'js'
                                                    ? `<script>document.write('<a href="${origin}/?_lp=1&'+window.location.search.substring(1)+'">${t('landingEditor.offerLinkWord')}</a>');</script>`
                                                    : `<a href="${origin}/?_lp=1&_token=<?= urlencode($_GET['_token']) ?>">${t('landingEditor.offerLinkWord')}</a>`)
                                            : t('landingEditor.offerLinkExampleSingle')}
                                        copied={linkCopied}
                                        onCopy={() => { setLinkCopied(true); setTimeout(() => setLinkCopied(false), 1800); }}
                                        t={t}
                                    />
                                    {(landing.type === 'local' || landing.type === 'preload') && (
                                        <>
                                            <p className="mt-2" style={{ color: 'var(--color-text-muted)', fontSize: '12.5px', lineHeight: 1.55 }}>
                                                {t('landingEditor.offerLinkExtra')}
                                            </p>
                                            <CopyableCode
                                                text={t('landingEditor.offerLinkExampleMulti')}
                                                copied={linkCopied}
                                                onCopy={() => { setLinkCopied(true); setTimeout(() => setLinkCopied(false), 1800); }}
                                                t={t}
                                                muted
                                            />
                                        </>
                                    )}
                                </div>
                            )}

                            {/* The adapter is what makes a landing on someone else's
                                hosting able to identify the click at all, so the
                                snippet lives right next to the redirect URL field. */}
                            {(landing.type === 'redirect' || landing.type === 'local' || landing.type === 'preload') && (
                                <div className="mt-4 p-4 rounded-2xl text-sm" style={{
                                    border: '1px solid var(--color-border)',
                                    backgroundColor: 'var(--color-bg-soft)'
                                }}>
                                    <div className="flex items-center justify-between" style={{ gap: '8px', marginBottom: '4px' }}>
                                        <div className="font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                            {t('landingEditor.adapterTitle')}
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => { navigator.clipboard.writeText(adapterSnippet); setAdapterCopied(true); setTimeout(() => setAdapterCopied(false), 1800); }}
                                            className="btn btn-secondary btn-sm"
                                            style={{ flexShrink: 0 }}
                                        >
                                            {adapterCopied ? <Check className="w-4 h-4" /> : <Code className="w-4 h-4" />}
                                            {adapterCopied ? t('landingEditor.adapterCopied') : t('landingEditor.adapterCopy')}
                                        </button>
                                    </div>
                                    <p style={{ color: 'var(--color-text-secondary)', lineHeight: 1.55, margin: 0 }}>
                                        {landing.type === 'redirect'
                                            ? t('landingEditor.adapterHintRedirect')
                                            : t('landingEditor.adapterHintLocal')}
                                    </p>
                                    <pre className="p-2 rounded-lg overflow-x-auto mt-2" style={{
                                        backgroundColor: 'var(--color-bg-card)',
                                        border: '1px solid var(--color-border)',
                                        color: 'var(--color-text-primary)', fontSize: '12.5px', margin: 0
                                    }}><code>{adapterSnippet}</code></pre>
                                    <p className="mt-2" style={{ color: 'var(--color-text-muted)', fontSize: '12.5px', lineHeight: 1.55 }}>
                                        {t('landingEditor.adapterPostbackHint')}
                                    </p>
                                    <pre className="p-2 rounded-lg overflow-x-auto mt-1" style={{
                                        backgroundColor: 'var(--color-bg-card)',
                                        border: '1px solid var(--color-border)',
                                        color: 'var(--color-text-muted)', fontSize: '12.5px', margin: 0
                                    }}><code>{t('landingEditor.postbackExample')}</code></pre>
                                </div>
                            )}

                            {/* Info for Local landing when NOT saved yet */}
                            {landing.type === 'local' && !landingId && (
                                <div className="mt-4 p-4 rounded-2xl text-sm" style={{
                                    backgroundColor: 'var(--color-warning-bg)',
                                    border: '1px solid var(--color-warning)',
                                    color: 'var(--color-warning)'
                                }}>
                                    {t('landingEditor.saveFirst')}
                                </div>
                            )}
                        </form>
                    </div>

                    {/* Editor Panel (Only for saved Local landings) */}
                    {isLocal && landingId && (
                        <div className="flex-1 flex flex-col overflow-hidden min-h-[400px]" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                            <div className="flex justify-between items-center p-3" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                <div className="flex items-center gap-3">
                                    <h4 className="font-semibold flex items-center" style={{ color: 'var(--color-text-primary)' }}>
                                        <Code className="w-4 h-4 mr-2" style={{ color: 'var(--color-accent-purple)' }} />
                                        {t('landingEditor.title')}
                                    </h4>
                                    <input
                                        type="file"
                                        accept=".zip"
                                        ref={fileInputRef}
                                        className="hidden"
                                        onChange={handleZipUpload}
                                    />
                                    <button
                                        onClick={() => fileInputRef.current.click()}
                                        disabled={uploadingZip}
                                        className="btn btn-secondary btn-sm"
                                    >
                                        <Upload className="w-4 h-4" />
                                        {uploadingZip ? t('common.loading') : t('landingEditor.uploadZip')}
                                    </button>
                                </div>
                                {selectedFile && (
                                    <button
                                        onClick={saveFileContent}
                                        disabled={savingFile}
                                        className="btn btn-primary btn-sm"
                                    >
                                        {savingFile ? t('common.saving') : <><Save className="w-4 h-4 mr-1" /> {t('landingEditor.save')} {selectedFile}</>}
                                    </button>
                                )}
                            </div>

                            <div className="flex flex-1 overflow-hidden">
                                {/* File Tree view */}
                                <div className="w-1/4 overflow-y-auto" style={{ borderRight: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                    <div className="flex gap-1 px-2 py-2" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                        <button onClick={createFile} className="btn btn-ghost btn-sm" title={t('landingEditor.fileNew')}>
                                            <Plus className="w-3.5 h-3.5" />
                                        </button>
                                        <button onClick={() => assetInputRef.current?.click()} className="btn btn-ghost btn-sm" title={t('landingEditor.fileUpload')}>
                                            <Upload className="w-3.5 h-3.5" />
                                        </button>
                                        <input ref={assetInputRef} type="file" className="hidden" onChange={uploadFile} />
                                    </div>

                                    {files.length === 0 ? (
                                        <div className="p-4 text-sm text-center italic" style={{ color: 'var(--color-text-muted)' }}>{t('landingEditor.selectFile')}</div>
                                    ) : (
                                        <ul className="py-2">
                                            {files.map(file => (
                                                <li key={file} className="flex items-center group">
                                                    <button
                                                        onClick={() => loadFileContent(file)}
                                                        className={`flex-1 min-w-0 text-left px-4 py-2 text-sm flex items-center transition ${selectedFile === file ? 'font-medium' : ''}`}
                                                        style={{
                                                            backgroundColor: selectedFile === file ? 'var(--color-primary-light)' : 'transparent',
                                                            color: selectedFile === file ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                            borderRight: selectedFile === file ? '2px solid var(--color-primary)' : 'none'
                                                        }}
                                                    >
                                                        <FileText className="w-3.5 h-3.5 mr-2 flex-shrink-0" style={{ color: 'var(--color-text-muted)' }} />
                                                        <span className="truncate" title={file}>{file}</span>
                                                    </button>
                                                    <button onClick={() => renameFile(file)} className="action-btn text-blue" title={t('landingEditor.fileRename')} style={{ flexShrink: 0 }}>
                                                        <Code className="w-3.5 h-3.5" />
                                                    </button>
                                                    <button onClick={() => deleteFile(file)} className="action-btn text-red" title={t('common.delete')} style={{ flexShrink: 0 }}>
                                                        <X className="w-3.5 h-3.5" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {/* Text Area / Code Viewer */}
                                <div className="flex-1 relative" style={{ backgroundColor: 'var(--color-bg-card)' }}>
                                    {selectedFile ? (
                                        <textarea
                                            value={fileContent}
                                            onChange={e => setFileContent(e.target.value)}
                                            className="absolute inset-0 w-full h-full p-4 font-mono text-sm leading-relaxed border-none resize-none focus:outline-none"
                                            style={{ backgroundColor: '#1e1e1e', color: '#d4d4d4' }}
                                            spellCheck={false}
                                        />
                                    ) : (
                                        <div className="flex h-full items-center justify-center" style={{ color: 'var(--color-text-muted)' }}>
                                            <div className="text-center">
                                                <Code className="w-12 h-12 mx-auto mb-3 opacity-20" />
                                                <p>{t('landingEditor.selectFile')}</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                <div className="modal-footer">
                    <button onClick={() => onClose(false)} type="button" className="btn btn-secondary">
                        {t('landingEditor.cancel')}
                    </button>
                    <button type="submit" form="landing-form" className="btn btn-primary">
                        <Check className="w-4 h-4 mr-2" />
                        {landingId ? t('landingEditor.saveChanges') : t('landingEditor.createLanding')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default LandingEditor;