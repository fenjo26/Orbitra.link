import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Save, X, Upload, FileText, FileCode, Image as ImageIcon, Palette, Code, Check, Plus, Eye, ExternalLink, LayoutTemplate, HardDrive, Layers3, Zap, Columns2, WandSparkles, Maximize2, Minimize2, Link2, Undo2 } from 'lucide-react';
import axios from 'axios';
import GroupsModal from './GroupsModal';
import SegmentedControl from './common/SegmentedControl';
import FileDropzone from './common/FileDropzone';
import CodeSnippetCard from './common/CodeSnippetCard';
import CodeEditor from './common/CodeEditor';
import { useLanguage } from '../contexts/LanguageContext';
import { getStayInEditorAfterSave } from '../utils/editorPreferences';
import { translateLandingError, translateLandingRequestError } from '../utils/landingErrors';
import { copyToClipboard as copyUtil } from '../utils/clipboard';

const API_URL = '/api.php';
const IMAGE_EXTENSIONS = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp']);
const VOID_HTML_TAGS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const JUNK_FILE_NAMES = new Set(['__MACOSX', '.DS_Store', 'Thumbs.db', '.git']);

const fileExtension = (path = '') => String(path).split('.').pop()?.toLowerCase() || '';
const normalizedImageExtension = (path) => fileExtension(path).replace('jpeg', 'jpg');
const isImageFile = (path) => IMAGE_EXTENSIONS.has(fileExtension(path));
const isJunkFile = (path) => String(path).replace(/\\/g, '/').split('/').some(part => JUNK_FILE_NAMES.has(part));
const encodeAssetPath = (path = '') => String(path).split('/').map(encodeURIComponent).join('/');

const editorLanguage = (path) => {
    const ext = fileExtension(path);
    if (['html', 'htm', 'php'].includes(ext)) return 'html';
    if (ext === 'css') return 'css';
    if (['js', 'mjs'].includes(ext)) return 'javascript';
    if (ext === 'json') return 'json';
    if (ext === 'xml' || ext === 'svg') return 'xml';
    return 'text';
};

const fileAppearance = (path) => {
    const ext = fileExtension(path);
    if (['html', 'htm', 'php'].includes(ext)) return { Icon: Code, color: '#f97316' };
    if (ext === 'css') return { Icon: Palette, color: '#3b82f6' };
    if (['js', 'mjs'].includes(ext)) return { Icon: FileCode, color: '#eab308' };
    if (IMAGE_EXTENSIONS.has(ext)) return { Icon: ImageIcon, color: '#a855f7' };
    return { Icon: FileText, color: 'var(--color-text-muted)' };
};

// Conservative dependency-free formatter: split adjacent HTML tags and
// normalize indentation without rewriting attributes, script bodies or text.
const beautifyCode = (source, language) => {
    const normalized = String(source || '').replace(/\r\n?/g, '\n');
    const inputLines = language === 'html'
        ? normalized.replace(/>\s*</g, '>\n<').split('\n')
        : normalized.split('\n');
    let depth = 0;

    return inputLines.map((rawLine) => {
        const line = rawLine.trim();
        if (!line) return '';

        const closesBlock = language === 'html'
            ? /^<\//.test(line)
            : /^[}\])]/.test(line);
        if (closesBlock) depth = Math.max(0, depth - 1);

        const formatted = `${'  '.repeat(depth)}${line}`;
        if (language === 'html') {
            const opening = line.match(/^<([a-z][\w:-]*)(?:\s[^>]*)?>/i);
            const sameLineClose = opening && new RegExp(`<\\/${opening[1]}\\s*>`, 'i').test(line);
            if (opening && !sameLineClose && !line.endsWith('/>') && !VOID_HTML_TAGS.has(opening[1].toLowerCase())) {
                depth += 1;
            }
        } else {
            const openCount = (line.match(/[{[(]/g) || []).length;
            const closeCount = (line.match(/[}\])]/g) || []).length;
            depth = Math.max(0, depth + openCount - closeCount + (closesBlock ? 1 : 0));
        }
        return formatted;
    }).join('\n').replace(/\n{3,}/g, '\n\n').trim() + '\n';
};

const previewDocument = (html, slug) => {
    if (!html || !slug) return '';
    const base = `<base href="/lander/${encodeURIComponent(slug)}/">`;
    return /<head(?:\s[^>]*)?>/i.test(html)
        ? html.replace(/<head(?:\s[^>]*)?>/i, match => `${match}\n${base}`)
        : `${base}\n${html}`;
};

/**
 * The landing form. One implementation, used by the Landings page and by the
 * campaign stream alike — the stream used to carry its own copy of this markup,
 * which is why the two drifted apart (an archive picker in one, none in the
 * other) and why every fix had to be written twice.
 *
 * onSaved(id) fires after each successful write, so a caller that needs the
 * landing's id — the stream wires it straight into its rotation — gets it
 * without waiting for the modal to close.
 */
const LandingEditor = ({ landingId: initialLandingId, onClose, onSaved }) => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('general');
    // The id is state, not just the prop it started as. Creating a landing used
    // to say "now you can upload files" and immediately close the modal, so the
    // file panel it was pointing at could only be reached by reopening the
    // landing from the list. Saving now moves this component into edit mode in
    // place, which is what the campaign-stream modal already did.
    const [landingId, setLandingId] = useState(initialLandingId);
    // Whether anything was written, so closing can tell the list to refresh even
    // when the last click was Cancel.
    const [savedSomething, setSavedSomething] = useState(false);
    // A ZIP chosen before the landing exists. It cannot be uploaded yet — the
    // endpoint needs an id — so it is held here and sent the moment we have one.
    const [pendingZip, setPendingZip] = useState(null);
    const [lastZip, setLastZip] = useState(null);
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
    const [saving, setSaving] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);
    // Quick-create group from the "+" next to the group select, same as the offer editor.
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    // Only needed by the "send to campaign" action, so it is fetched on demand.
    const [campaigns, setCampaigns] = useState([]);
    const [postbackKey, setPostbackKey] = useState('');
    // Offer-link hint: which code format to show for redirect landings, and a
    // per-snippet "copied" toast for the copy button.
    const [linkFormat, setLinkFormat] = useState('html');
    const [copiedSnippet, setCopiedSnippet] = useState('');

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
    // Code or preview. The preview is an iframe on the landing's own
    // /lander/<slug>/ address; the nonce forces a reload when switching to it, so
    // it never shows the version from before the last save or upload.
    const [viewMode, setViewMode] = useState('code');
    const [previewNonce, setPreviewNonce] = useState(0);
    const [livePreviewHtml, setLivePreviewHtml] = useState('');
    const [imageDimensions, setImageDimensions] = useState(null);
    const [imageLoadError, setImageLoadError] = useState(false);
    const [assetNonce, setAssetNonce] = useState(0);
    const [editorFullscreen, setEditorFullscreen] = useState(false);
    const assetInputRef = useRef(null);
    const replaceAssetInputRef = useRef(null);
    const codeEditorRef = useRef(null);

    const visibleFiles = useMemo(
        () => files.filter(file => !isJunkFile(file)).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })),
        [files]
    );
    const selectedIsImage = Boolean(selectedFile && isImageFile(selectedFile));
    const selectedLanguage = editorLanguage(selectedFile);
    const canInsertHtmlSnippets = selectedLanguage === 'html';
    const selectedImageAccept = normalizedImageExtension(selectedFile) === 'jpg'
        ? '.jpg,.jpeg'
        : `.${fileExtension(selectedFile)}`;
    const selectedAssetUrl = selectedFile && landing.slug
        ? `/lander/${encodeURIComponent(landing.slug)}/${encodeAssetPath(selectedFile)}?_asset=${assetNonce}`
        : '';

    useEffect(() => {
        if (!editorFullscreen) return undefined;
        const handleEscape = (event) => {
            if (event.key === 'Escape') setEditorFullscreen(false);
        };
        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [editorFullscreen]);

    useEffect(() => {
        if (!selectedFile || selectedIsImage || selectedLanguage !== 'html' || !landing.slug) {
            setLivePreviewHtml('');
            return undefined;
        }
        const timer = window.setTimeout(() => {
            setLivePreviewHtml(previewDocument(fileContent, landing.slug));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [fileContent, landing.slug, selectedFile, selectedIsImage, selectedLanguage]);

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
    }, [landingId, t]);

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

    const handleSave = async (forceClose = false) => {
        if (loading || saving || uploadingZip || saveSuccess) return;
        if (!landing.name.trim()) {
            setActiveTab('general');
            alert(`${t('landingEditor.name')}: ${t('landingColumns.required')}`);
            return;
        }
        if (['redirect', 'preload'].includes(landing.type) && !String(landing.url || '').trim()) {
            setActiveTab('general');
            alert(`${t('landingEditor.urlLabel')}: ${t('landingColumns.required')}`);
            return;
        }
        if (landing.type === 'action'
            && ['to_campaign', 'show_text', 'show_html'].includes(landing.action_type || 'not_found')
            && !String(landing.action_payload || '').trim()) {
            setActiveTab('general');
            alert(`${t('landingEditor.actionTypeLabel')}: ${t('landingColumns.required')}`);
            return;
        }

        try {
            setSaving(true);
            const payload = { ...landing };
            if (landingId) payload.id = landingId;

            const res = await axios.post(`${API_URL}?action=save_landing`, payload);
            if (res.data.status === 'success') {
                setSavedSomething(true);
                const newId = res.data.data?.id;
                if (onSaved) onSaved(newId || landingId);
                if (!landingId && newId) setLandingId(newId);

                if (!landingId && newId && landing.type === 'local') {
                    if (pendingZip) {
                        const zip = pendingZip;
                        setPendingZip(null);
                        setLastZip(zip);
                        await uploadZip(newId, zip);
                    }
                }

                setSaveSuccess(true);
                setTimeout(() => {
                    setSaveSuccess(false);
                    if (forceClose || !getStayInEditorAfterSave()) {
                        onClose(true);
                    }
                }, 1000);
            } else {
                alert(translateLandingError(t, res.data.message, res.data.detail) || t('landingEditor.saveError'));
            }
        } catch (error) {
            // The server's own message is far more useful than "network error" —
            // a rejected slug and an unreachable host used to read the same.
            alert(translateLandingRequestError(t, error));
        } finally {
            setSaving(false);
        }
    };

    const handleFormSubmit = (event) => {
        event.preventDefault();
        handleSave(false);
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

    // Shared by the toolbar button and by the archive picked before the landing
    // existed, so both paths report success and failure identically.
    const uploadZip = async (id, file) => {
        setUploadingZip(true);
        const formData = new FormData();
        formData.append('file', file);
        formData.append('id', id);

        try {
            const res = await axios.post(`${API_URL}?action=upload_landing`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status === 'success') {
                alert(t('landingEditor.archiveUploaded'));
                fetchLandingFiles(id);
                return true;
            }
            alert(translateLandingError(t, res.data.message, res.data.detail) || t('landingEditor.archiveError'));
            return false;
        } catch (error) {
            // A 500 or a 413 from the upload used to arrive here and be reduced to
            // "ZIP upload error", which says nothing about a size limit, a missing
            // extension or a read-only directory.
            alert(`${t('landingEditor.archiveError')}: ${translateLandingRequestError(t, error)}`);
            return false;
        } finally {
            setUploadingZip(false);
        }
    };

    const selectLandingZip = (file) => {
        if (landingId) {
            setLastZip(file);
            uploadZip(landingId, file);
        } else {
            setPendingZip(file);
        }
    };

    const copySnippet = async (text, id) => {
        if (!await copyUtil(text)) return;
        setCopiedSnippet(id);
        setTimeout(() => setCopiedSnippet(''), 1800);
    };

    const loadFileContent = async (path) => {
        setImageDimensions(null);
        setImageLoadError(false);
        if (isImageFile(path)) {
            setSelectedFile(path);
            setFileContent('');
            return;
        }
        try {
            const res = await axios.get(`${API_URL}?action=get_landing_file&id=${landingId}&path=${encodeURIComponent(path)}`);
            if (res.data.status === 'success') {
                setSelectedFile(path);
                setFileContent(res.data.data);
            } else {
                alert(res.data.message || t('landingEditor.fileUploadError'));
            }
        } catch (error) {
            alert(`${t('landingEditor.fileReadError')}: ${translateLandingRequestError(t, error)}`);
        }
    };

    const saveFileContent = async () => {
        if (!selectedFile || selectedIsImage) return;
        setSavingFile(true);
        try {
            const res = await axios.post(`${API_URL}?action=save_landing_file`, {
                id: landingId,
                path: selectedFile,
                content: fileContent
            });
            if (res.data.status === 'success') {
                setSavedSomething(true);
                setPreviewNonce(Date.now());
            } else {
                alert(res.data.message || t('landingEditor.fileSaveError'));
            }
        } catch (error) {
            alert(`${t('landingEditor.fileSaveError2')}: ${translateLandingRequestError(t, error)}`);
        } finally {
            setSavingFile(false);
        }
    };

    const replaceSelectedImage = async (event) => {
        const replacement = event.target.files?.[0];
        event.target.value = '';
        if (!replacement || !selectedFile || !landingId) return;
        if (normalizedImageExtension(replacement.name) !== normalizedImageExtension(selectedFile)) {
            alert(t('landingEditor.replaceImageTypeError', 'Choose an image with the same file type so existing landing links keep working.'));
            return;
        }

        const fd = new FormData();
        fd.append('file', replacement);
        fd.append('id', landingId);
        fd.append('path', selectedFile);
        try {
            const res = await axios.post(`${API_URL}?action=upload_landing_file`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            setAssetNonce(Date.now());
            setPreviewNonce(Date.now());
            setImageDimensions(null);
            setImageLoadError(false);
            setSavedSomething(true);
            fetchLandingFiles(landingId);
        } catch (error) {
            alert(`${t('landingEditor.fileOpError')}: ${error.response?.data?.message || error.message}`);
        }
    };

    const insertAtCursor = (snippet) => {
        codeEditorRef.current?.insertText(snippet);
    };

    const insertBeforeClosingTag = (snippet, tag) => {
        const duplicateMarker = tag === 'head' ? '/js/orbitra-adapter.js' : 'data-orbitra-back-trap';
        if (fileContent.includes(duplicateMarker)) {
            codeEditorRef.current?.focus();
            return;
        }
        const closingTag = `</${tag}>`;
        const index = fileContent.toLowerCase().lastIndexOf(closingTag);
        const position = index >= 0 ? index : fileContent.length;
        const prefix = position > 0 && !fileContent.slice(0, position).endsWith('\n') ? '\n' : '';
        const suffix = index >= 0 ? '\n' : '';
        codeEditorRef.current?.replaceRange(`${prefix}${snippet}${suffix}`, position, position);
    };

    const backTrapSnippet = `<script data-orbitra-back-trap>\n(function () {\n  history.pushState(null, '', location.href);\n  window.addEventListener('popstate', function () {\n    location.href = '{offer}';\n  });\n})();\n</script>`;

    const handleBeautify = () => {
        const formatted = beautifyCode(fileContent, selectedLanguage);
        setFileContent(formatted);
        window.requestAnimationFrame(() => codeEditorRef.current?.setSelection(0, 0));
    };

    const renderLandingPreview = (split = false) => {
        const useLiveDocument = Boolean(livePreviewHtml && selectedLanguage === 'html' && !selectedIsImage);
        return (
            <div className="flex h-full min-h-0 flex-col overflow-hidden" style={{ backgroundColor: '#fff' }}>
                {split && (
                    <div className="flex items-center justify-between border-b px-3 py-2 text-xs font-semibold" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                        <span className="flex items-center gap-1.5"><Eye className="w-3.5 h-3.5" /> {t('landingEditor.livePreview', 'Live preview')}</span>
                        <span className="font-normal" style={{ color: 'var(--color-text-muted)' }}>
                            {useLiveDocument ? t('landingEditor.unsavedPreview', 'Unsaved HTML') : t('landingEditor.savedPreview', 'Saved landing')}
                        </span>
                    </div>
                )}
                {landing.slug ? (
                    <iframe
                        key={`${previewNonce}-${useLiveDocument ? 'live' : 'saved'}`}
                        src={useLiveDocument ? undefined : `/lander/${landing.slug}/?_preview=${previewNonce}`}
                        srcDoc={useLiveDocument ? livePreviewHtml : undefined}
                        title={t('landingEditor.viewPreview')}
                        className="h-full min-h-0 w-full flex-1"
                        style={{ border: 'none', minHeight: split ? '320px' : '400px' }}
                    />
                ) : (
                    <div className="flex h-full items-center justify-center p-6 text-center" style={{ color: 'var(--color-text-muted)' }}>
                        {t('landingEditor.previewNeedsSlug')}
                    </div>
                )}
            </div>
        );
    };

    const renderSelectedAsset = () => {
        if (!selectedFile) {
            return (
                <div className="flex h-full items-center justify-center" style={{ color: 'var(--color-text-muted)' }}>
                    <div className="text-center">
                        <Code className="mx-auto mb-3 h-12 w-12 opacity-20" />
                        <p>{t('landingEditor.selectFile')}</p>
                    </div>
                </div>
            );
        }

        if (selectedIsImage) {
            return (
                <div className="flex h-full min-h-0 items-center justify-center overflow-auto p-5">
                    <div className="flex max-w-full flex-col items-center gap-4 rounded-2xl border p-4 shadow-sm" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                        {imageLoadError ? (
                            <div className="flex min-h-48 min-w-64 items-center justify-center rounded-xl border p-6 text-center" style={{ borderColor: 'var(--color-border)', color: 'var(--color-danger)' }}>
                                {t('landingEditor.imageLoadError', 'Could not load this image')}
                            </div>
                        ) : (
                            <img
                                key={assetNonce}
                                src={selectedAssetUrl}
                                alt={selectedFile}
                                className="max-h-[52vh] max-w-full rounded-xl object-contain"
                                onLoad={(event) => setImageDimensions({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight })}
                                onError={() => setImageLoadError(true)}
                            />
                        )}
                        <div className="flex w-full flex-wrap items-center justify-between gap-3">
                            <div className="min-w-0 text-xs">
                                <div className="break-all font-semibold" style={{ color: 'var(--color-text-primary)' }}>{selectedFile}</div>
                                <div style={{ color: 'var(--color-text-muted)' }}>
                                    {imageDimensions ? `${imageDimensions.width} × ${imageDimensions.height}px` : t('common.loading')}
                                </div>
                            </div>
                            <button type="button" className="btn btn-secondary btn-sm" onClick={() => replaceAssetInputRef.current?.click()}>
                                <Upload className="h-3.5 w-3.5" />
                                {t('landingEditor.replaceImage', 'Replace image')}
                            </button>
                            <input
                                ref={replaceAssetInputRef}
                                type="file"
                                accept={selectedImageAccept}
                                className="hidden"
                                onChange={replaceSelectedImage}
                            />
                        </div>
                    </div>
                </div>
            );
        }

        return (
            <div className="h-full min-h-0 p-2">
                <CodeEditor
                    ref={codeEditorRef}
                    value={fileContent}
                    onChange={setFileContent}
                    onSave={saveFileContent}
                    language={selectedLanguage}
                    ariaLabel={`${t('landingEditor.title')}: ${selectedFile}`}
                />
            </div>
        );
    };

    if (loading) return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '300px' }}>
                <div className="text-center py-6" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
            </div>
        </div>
    );

    const isLocal = landing.type === 'local';
    const showFileEditor = activeTab === 'general' && isLocal && landingId;
    const tabs = [
        { id: 'general', label: t('editor.general') },
        { id: 'integration', label: `${t('editor.integrations')} & ${t('landingEditor.viewCode')}` },
        { id: 'details', label: `${t('editor.notes')} & ${t('editor.params')}` }
    ];
    const offerLinkSnippet = landing.type === 'redirect'
        ? (linkFormat === 'html'
            ? `<a href="${origin}/?_lp=1">${t('landingEditor.offerLinkWord')}</a>`
            : linkFormat === 'js'
                ? `<script>document.write('<a href="${origin}/?_lp=1&'+window.location.search.substring(1)+'">${t('landingEditor.offerLinkWord')}</a>');</script>`
                : `<a href="${origin}/?_lp=1&_token=<?= urlencode($_GET['_token']) ?>">${t('landingEditor.offerLinkWord')}</a>`)
        : t('landingEditor.offerLinkExampleSingle');

    return (
        <div className="modal-overlay">
            <div
                className="modal-content"
                style={{
                    maxWidth: showFileEditor ? '1500px' : '880px', width: '100%',
                    /* Flex column pins header/footer; only the body scrolls —
                       the file editor used to push Save below the fold. */
                    display: 'flex',
                    flexDirection: 'column',
                    overflow: 'hidden',
                    padding: 0
                }}
            >
                <div className="modal-header px-6 pt-5" style={{ flexShrink: 0, marginBottom: 0, borderBottom: 'none' }}>
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                            <LayoutTemplate className="w-5 h-5" />
                        </div>
                        <h3 className="modal-title truncate">
                            {landingId ? `${t('landingEditor.landing')}: ${landing.name}` : t('landingEditor.createLanding')}
                        </h3>
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

                <div className="flex-1 overflow-y-auto p-0 flex flex-col md:flex-row">
                    {/* Settings Panel */}
                    <div className={`p-6 ${showFileEditor ? 'md:w-[28%]' : 'w-full'} flex flex-col pt-4`} style={{ borderRight: showFileEditor ? '1px solid var(--color-border)' : 'none' }}>
                        <form id="landing-form" onSubmit={handleFormSubmit} className="space-y-4">
                            {activeTab === 'general' && (
                            <div className="space-y-4">
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
                                    <div className="flex">
                                        <select
                                            value={landing.group_id}
                                            onChange={e => setLanding({ ...landing, group_id: e.target.value })}
                                            className="form-select rounded-r-none"
                                        >
                                            <option value="">{t('landingEditor.noGroup')}</option>
                                            {groups.map(g => (
                                                <option key={g.id} value={g.id}>{g.name}</option>
                                            ))}
                                        </select>
                                        <button type="button" className="btn btn-secondary rounded-l-none border-l-0" onClick={() => setShowGroupsModal(true)} title={t('groupsModal.landingGroups')}>
                                            <Plus className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <label className="form-label">{t('landingEditor.status')}</label>
                                    <select
                                        value={landing.state}
                                        onChange={e => setLanding({ ...landing, state: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="active">{t('landingEditor.active')}</option>
                                        <option value="paused">{t('landingEditor.paused')}</option>
                                        <option value="archived">{t('landingEditor.archived')}</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="form-label">{t('landingEditor.landingType')}</label>
                                <SegmentedControl
                                    ariaLabel={t('landingEditor.landingType')}
                                    value={landing.type}
                                    onChange={(type) => setLanding({ ...landing, type })}
                                    options={[
                                        { value: 'local', label: t('landingEditor.typeLocal'), icon: HardDrive },
                                        { value: 'redirect', label: t('landingEditor.typeRedirect'), icon: ExternalLink },
                                        { value: 'preload', label: t('landingEditor.typePreload'), icon: Layers3 },
                                        { value: 'action', label: t('landingEditor.typeAction'), icon: Zap }
                                    ]}
                                />
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

                            {/* Redirect method for a redirect landing — support full set matching OfferEditor */}
                            {landing.type === 'redirect' && (
                                <div className="space-y-1.5">
                                    <label className="form-label">{t('landingEditor.redirectMethodLabel')}</label>
                                    <select
                                        value={landing.redirect_type || 'redirect'}
                                        onChange={e => setLanding({ ...landing, redirect_type: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="redirect">{t('offerEditor.httpRedirect', 'HTTP 302 Redirect')}</option>
                                        <option value="js">{t('redirectTypes.jsName', 'JS Redirect')}</option>
                                        <option value="meta_refresh">{t('redirectTypes.metaName', 'Meta Refresh')}</option>
                                        <option value="frame">{t('redirectTypes.iframeName', 'Iframe / Frame')}</option>
                                        <option value="form_submit">{t('redirectTypes.formName', 'Form Submit / POST')}</option>
                                        <option value="preload">{t('offerEditor.preloadCurl', 'Preload (cURL)')}</option>
                                        <option value="curl_proxy">{t('redirectTypes.curlProxyName', 'cURL Proxy (Reverse Proxy)')}</option>
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
                                        })[landing.redirect_type || 'redirect'];
                                        return descKey ? (
                                            <div className="form-hint" style={{ fontSize: '11.5px', color: 'var(--color-text-muted)' }}>{t(descKey)}</div>
                                        ) : null;
                                    })()}
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
                                            />
                                        </div>
                                    )}
                                </div>
                            )}
                            {landing.type === 'local' && (
                                <div className="p-4 rounded-2xl" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                                    <div className="font-semibold mb-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('landingEditor.uploadZip')}
                                    </div>
                                    <FileDropzone
                                        file={pendingZip || lastZip}
                                        onFileSelect={selectLandingZip}
                                        disabled={uploadingZip}
                                        label={uploadingZip ? t('landingEditor.uploadingZip') : t('landingEditor.uploadZip')}
                                        emptyHint={t('landingEditor.zipDropHint', 'Drag & drop .zip here or click to browse files')}
                                        replaceHint={t('landingEditor.zipReplaceHint', 'Click to replace')}
                                    />
                                    <p className="mt-2" style={{ fontSize: '12.5px', color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                        {!landingId ? t('landingEditor.zipOnCreateHint') : `${visibleFiles.length} ${t('offerEditor.filesLabel', 'files')}`}
                                    </p>
                                </div>
                            )}
                            </div>
                            )}

                            {activeTab === 'integration' && (
                                <div className="space-y-4">
                                    {(landing.type === 'local' || landing.type === 'preload' || landing.type === 'redirect') ? (
                                        <>
                                            <CodeSnippetCard
                                                title={t('landingEditor.offerLinkTitle')}
                                                description={t('landingEditor.offerLinkHint')}
                                                code={offerLinkSnippet}
                                                copyId="landing-offer-link"
                                                onCopy={copySnippet}
                                                copied={copiedSnippet}
                                                copyLabel={t('landingEditor.copyCode')}
                                                copiedLabel={t('landingEditor.codeCopied')}
                                                actions={landing.type === 'redirect' ? (
                                                    <div className="flex gap-1">
                                                        {['html', 'js', 'php'].map(format => (
                                                            <button
                                                                key={format}
                                                                type="button"
                                                                onClick={() => setLinkFormat(format)}
                                                                className="px-2 py-1 text-[10px] font-semibold rounded-md transition"
                                                                style={{
                                                                    backgroundColor: linkFormat === format ? 'var(--color-primary)' : 'var(--color-bg-card)',
                                                                    color: linkFormat === format ? '#fff' : 'var(--color-text-muted)',
                                                                    border: '1px solid var(--color-border)'
                                                                }}
                                                            >
                                                                {format.toUpperCase()}
                                                            </button>
                                                        ))}
                                                    </div>
                                                ) : null}
                                            />
                                            {(landing.type === 'local' || landing.type === 'preload') && (
                                                <CodeSnippetCard
                                                    title={t('landingEditor.offerLinkTitle')}
                                                    description={t('landingEditor.offerLinkExtra')}
                                                    code={t('landingEditor.offerLinkExampleMulti')}
                                                    copyId="landing-multiple-offers"
                                                    onCopy={copySnippet}
                                                    copied={copiedSnippet}
                                                    copyLabel={t('landingEditor.copyCode')}
                                                    copiedLabel={t('landingEditor.codeCopied')}
                                                    muted
                                                />
                                            )}
                                            <CodeSnippetCard
                                                title={t('landingEditor.adapterTitle')}
                                                description={landing.type === 'redirect'
                                                    ? t('landingEditor.adapterHintRedirect')
                                                    : t('landingEditor.adapterHintLocal')}
                                                code={adapterSnippet}
                                                copyId="landing-adapter"
                                                onCopy={copySnippet}
                                                copied={copiedSnippet}
                                                copyLabel={t('landingEditor.adapterCopy')}
                                                copiedLabel={t('landingEditor.adapterCopied')}
                                            />
                                            <CodeSnippetCard
                                                title={t('landingEditor.adapterPostbackHint')}
                                                code={t('landingEditor.postbackExample')}
                                                copyId="landing-postback"
                                                onCopy={copySnippet}
                                                copied={copiedSnippet}
                                                copyLabel={t('landingEditor.copyCode')}
                                                copiedLabel={t('landingEditor.codeCopied')}
                                                muted
                                            />
                                        </>
                                    ) : (
                                        <CodeSnippetCard
                                            title={t('landingEditor.actionPayloadLabel')}
                                            description={t('landingEditor.actionTypeLabel')}
                                            code={landing.action_payload || t('landingEditor.actionPayloadPlaceholder')}
                                            copyId="landing-action"
                                            onCopy={copySnippet}
                                            copied={copiedSnippet}
                                            copyLabel={t('landingEditor.copyCode')}
                                            copiedLabel={t('landingEditor.codeCopied')}
                                        />
                                    )}
                                </div>
                            )}

                            {activeTab === 'details' && (
                                <div className="space-y-4">
                                    <CodeSnippetCard
                                        title={t('sourceEditor.availableMacros')}
                                        description={t('landingEditor.offerLinkHint')}
                                        code={'{offer}\n{subid}\n{campaign_id}\n{source}\n{keyword}'}
                                        copyId="landing-macros"
                                        onCopy={copySnippet}
                                        copied={copiedSnippet}
                                        copyLabel={t('landingEditor.copyCode')}
                                        copiedLabel={t('landingEditor.codeCopied')}
                                    />
                                    <CodeSnippetCard
                                        title={t('editor.params')}
                                        description={t('landingEditor.adapterPostbackHint')}
                                        code={'?_lp=1&subid={subid}&campaign_id={campaign_id}'}
                                        copyId="landing-parameters"
                                        onCopy={copySnippet}
                                        copied={copiedSnippet}
                                        copyLabel={t('landingEditor.copyCode')}
                                        copiedLabel={t('landingEditor.codeCopied')}
                                        muted
                                    />
                                </div>
                            )}
                        </form>
                    </div>

                    {/* Editor Panel (Only for saved Local landings) */}
                    {showFileEditor && (
                        <div
                            className="flex min-h-[400px] flex-1 flex-col overflow-hidden"
                            style={{
                                backgroundColor: 'var(--color-bg-soft)',
                                ...(editorFullscreen ? {
                                    position: 'fixed', inset: '12px', zIndex: 1400,
                                    border: '1px solid var(--color-border)', borderRadius: '16px',
                                    boxShadow: '0 24px 80px rgba(0,0,0,.45)'
                                } : {})
                            }}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2 p-3" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                <h4 className="flex min-w-0 items-center font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                    <Code className="mr-2 h-4 w-4 flex-shrink-0" style={{ color: 'var(--color-accent-purple)' }} />
                                    <span>{t('landingEditor.title')}</span>
                                    {selectedFile && <span className="ml-2 break-all text-xs font-normal" style={{ color: 'var(--color-text-muted)' }}>· {selectedFile}</span>}
                                </h4>
                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <div className="flex overflow-hidden rounded-lg" style={{ border: '1px solid var(--color-border)' }}>
                                        {[
                                            { value: 'code', label: t('landingEditor.viewCode'), icon: Code },
                                            { value: 'split', label: t('landingEditor.viewSplit', 'Split'), icon: Columns2 },
                                            { value: 'preview', label: t('landingEditor.viewPreview'), icon: Eye },
                                        ].map((opt, index) => {
                                            const active = viewMode === opt.value;
                                            const Icon = opt.icon;
                                            return (
                                                <button
                                                    key={opt.value}
                                                    type="button"
                                                    onClick={() => {
                                                        if (opt.value !== 'code') setPreviewNonce(Date.now());
                                                        setViewMode(opt.value);
                                                    }}
                                                    className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium transition"
                                                    style={{
                                                        backgroundColor: active ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                                        color: active ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                        borderRight: index < 2 ? '1px solid var(--color-border)' : 'none'
                                                    }}
                                                >
                                                    <Icon className="h-3.5 w-3.5" />
                                                    {opt.label}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    {viewMode !== 'code' && landing.slug && (
                                        <a href={`/lander/${landing.slug}/`} target="_blank" rel="noopener noreferrer" className="btn btn-secondary btn-sm" title={`/lander/${landing.slug}/`}>
                                            <ExternalLink className="h-4 w-4" />
                                            {t('landingEditor.openInTab')}
                                        </a>
                                    )}

                                    {viewMode !== 'preview' && selectedFile && !selectedIsImage && (
                                        <button type="button" onClick={saveFileContent} disabled={savingFile} className="btn btn-primary btn-sm" title="Ctrl/Cmd+S">
                                            <Save className="h-4 w-4" />
                                            {savingFile ? t('common.saving') : t('landingEditor.save')}
                                        </button>
                                    )}

                                    <button type="button" onClick={() => setEditorFullscreen(value => !value)} className="btn btn-ghost btn-sm" title={editorFullscreen ? t('landingEditor.exitFullscreen', 'Exit fullscreen') : t('landingEditor.fullscreen', 'Fullscreen')}>
                                        {editorFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                                    </button>
                                </div>
                            </div>

                            {viewMode !== 'preview' && selectedFile && !selectedIsImage && (
                                <div className="flex flex-wrap items-center gap-1.5 border-b px-3 py-2" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                    <button type="button" disabled={!canInsertHtmlSnippets} className="btn btn-secondary btn-sm" onClick={() => insertAtCursor(`<a href="{offer}">${t('landingEditor.offerLinkWord')}</a>`)}>
                                        <Link2 className="h-3.5 w-3.5" /> + {'{offer}'}
                                    </button>
                                    <button type="button" disabled={!canInsertHtmlSnippets} className="btn btn-secondary btn-sm" onClick={() => insertBeforeClosingTag(adapterSnippet, 'head')}>
                                        <Zap className="h-3.5 w-3.5" /> + JS Adapter
                                    </button>
                                    <button type="button" disabled={!canInsertHtmlSnippets} className="btn btn-secondary btn-sm" onClick={() => insertBeforeClosingTag(backTrapSnippet, 'body')}>
                                        <Undo2 className="h-3.5 w-3.5" /> + Back Trap
                                    </button>
                                    <button type="button" className="btn btn-secondary btn-sm" onClick={handleBeautify}>
                                        <WandSparkles className="h-3.5 w-3.5" /> {t('landingEditor.formatCode', 'Format')}
                                    </button>
                                    <span className="ml-auto text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('landingEditor.quickInsertHint', 'Snippets are inserted at the cursor; adapter and back trap are placed before closing tags.')}
                                    </span>
                                </div>
                            )}

                            {viewMode === 'preview' ? (
                                <div className="min-h-0 flex-1 overflow-hidden">{renderLandingPreview(false)}</div>
                            ) : (
                                <div className="flex min-h-0 flex-1 overflow-hidden">
                                    {/* File tree */}
                                    <div className={`${viewMode === 'split' ? 'w-[20%]' : 'w-1/4'} min-w-[150px] overflow-y-auto`} style={{ borderRight: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                        <div className="flex items-center gap-1 px-2 py-2" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                            <span className="mr-auto text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('landingEditor.filesTitle', 'Files')} ({visibleFiles.length})
                                            </span>
                                            <button type="button" onClick={createFile} className="btn btn-ghost btn-sm" title={t('landingEditor.fileNew')}><Plus className="h-3.5 w-3.5" /></button>
                                            <button type="button" onClick={() => assetInputRef.current?.click()} className="btn btn-ghost btn-sm" title={t('landingEditor.fileUpload')}><Upload className="h-3.5 w-3.5" /></button>
                                            <input ref={assetInputRef} type="file" className="hidden" onChange={uploadFile} />
                                        </div>

                                        {visibleFiles.length === 0 ? (
                                            <div className="p-4 text-center text-sm italic" style={{ color: 'var(--color-text-muted)' }}>{t('landingEditor.selectFile')}</div>
                                        ) : (
                                            <ul className="py-2">
                                                {visibleFiles.map(file => {
                                                    const { Icon, color } = fileAppearance(file);
                                                    return (
                                                        <li key={file} className="group flex items-start">
                                                            <button
                                                                type="button"
                                                                onClick={() => loadFileContent(file)}
                                                                className={`flex min-w-0 flex-1 items-start px-3 py-2 text-left text-sm transition ${selectedFile === file ? 'font-medium' : ''}`}
                                                                style={{
                                                                    backgroundColor: selectedFile === file ? 'var(--color-primary-light)' : 'transparent',
                                                                    color: selectedFile === file ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                                    borderRight: selectedFile === file ? '2px solid var(--color-primary)' : 'none'
                                                                }}
                                                            >
                                                                <Icon className="mr-2 mt-0.5 h-3.5 w-3.5 flex-shrink-0" style={{ color }} />
                                                                <span className="break-all whitespace-normal" title={file}>{file}</span>
                                                            </button>
                                                            <div className="flex flex-shrink-0 items-center pt-1.5 opacity-50 transition group-hover:opacity-100">
                                                                <button type="button" onClick={() => renameFile(file)} className="action-btn text-blue" title={t('landingEditor.fileRename')}><FileCode className="h-3.5 w-3.5" /></button>
                                                                <button type="button" onClick={() => deleteFile(file)} className="action-btn text-red" title={t('common.delete')}><X className="h-3.5 w-3.5" /></button>
                                                            </div>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </div>

                                    <div className={`${viewMode === 'split' ? 'w-[40%]' : 'flex-1'} min-w-0 min-h-0`} style={{ backgroundColor: 'var(--color-bg-card)' }}>
                                        {renderSelectedAsset()}
                                    </div>

                                    {viewMode === 'split' && (
                                        <div className="min-h-0 w-[40%] min-w-[280px] overflow-hidden border-l" style={{ borderColor: 'var(--color-border)' }}>
                                            {renderLandingPreview(true)}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <div className="modal-footer px-6 pb-5" style={{ flexShrink: 0, marginTop: 0 }}>
                    <button onClick={() => onClose(savedSomething)} type="button" className="btn btn-secondary rounded-xl">
                        <X className="w-4 h-4" />
                        {t('common.cancel')}
                    </button>
                    <button
                        type="button"
                        onClick={() => handleSave(true)}
                        disabled={loading || saving || uploadingZip || saveSuccess}
                        className="btn btn-secondary rounded-xl"
                    >
                        <Save className="w-4 h-4" />
                        {t('profile.saveAndClose')}
                    </button>
                    <button
                        type="submit"
                        form="landing-form"
                        className="btn btn-primary rounded-xl"
                        // The archive upload must finish before the campaign link
                        // is worth testing — the first click used to race it.
                        disabled={loading || saving || uploadingZip || saveSuccess}
                        style={saveSuccess ? { backgroundColor: 'var(--color-success)' } : {}}
                    >
                        <Check className="w-4 h-4 mr-1.5" />
                        {saveSuccess
                            ? t('editor.saved')
                            : saving
                                ? t('common.saving')
                                : uploadingZip
                                ? t('landingEditor.uploadingZip')
                                : (landingId ? t('landingEditor.saveChanges') : t('landingEditor.createLanding'))}
                    </button>
                </div>
            </div>

            {/* Quick-create group from the "+" next to the group select */}
            {showGroupsModal && (
                <GroupsModal
                    type="landing"
                    onClose={() => {
                        setShowGroupsModal(false);
                        axios.get(`${API_URL}?action=landing_groups`)
                            .then(res => { if (res.data.status === 'success') setGroups(res.data.data); })
                            .catch(() => {});
                    }}
                    onGroupCreated={(g) => {
                        if (!g || !g.id) return;
                        setGroups(prev => prev.some(x => x.id == g.id) ? prev : [...prev, g]);
                        setLanding(prev => ({ ...prev, group_id: String(g.id) }));
                    }}
                />
            )}
        </div>
    );
};

export default LandingEditor;
