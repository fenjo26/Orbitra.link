import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
    X, Plus, Trash2, Check, Save, PackageOpen, HardDrive, ExternalLink,
    Layers3, Zap, Code, Palette, FileCode, Image as ImageIcon, FileText,
    Columns2, WandSparkles, Maximize2, Minimize2, Upload, Search,
    Smartphone, Monitor, Eye, RotateCw
} from 'lucide-react';
import axios from 'axios';
import GeoSelector from './GeoSelector';
import HelpTooltip from './HelpTooltip';
import GroupsModal from './GroupsModal';
import AffiliateNetworkEditor from './AffiliateNetworkEditor';
import SegmentedControl from './common/SegmentedControl';
import FileDropzone from './common/FileDropzone';
import CodeSnippetCard from './common/CodeSnippetCard';
import CodeEditor from './common/CodeEditor';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet } from '../utils/apiCache';
import { getStayInEditorAfterSave } from '../utils/editorPreferences';
import { copyToClipboard as copyUtil } from '../utils/clipboard';
import { translateLandingError, translateLandingRequestError, describeSanitized } from '../utils/landingErrors';

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

const previewOfferDocument = (html, offerId) => {
    if (!html || !offerId) return '';
    const base = `<base href="/offers/${encodeURIComponent(offerId)}/">`;
    return /<head(?:\s[^>]*)?>/i.test(html)
        ? html.replace(/<head(?:\s[^>]*)?>/i, match => `${match}\n${base}`)
        : `${base}\n${html}`;
};

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

    // Local offer files & editor state
    const [uploadingZip, setUploadingZip] = useState(false);
    const [pendingZip, setPendingZip] = useState(null);
    const [lastZip, setLastZip] = useState(null);
    const [offerFiles, setOfferFiles] = useState([]);
    const [savedOfferId, setSavedOfferId] = useState(null);
    const [savedSomething, setSavedSomething] = useState(false);
    const [saveSuccess, setSaveSuccess] = useState(false);
    const currentOfferId = offerId || savedOfferId;
    const [copiedSnippet, setCopiedSnippet] = useState('');

    // Code Editor & Live Preview State
    const [selectedFile, setSelectedFile] = useState(null);
    const [fileContent, setFileContent] = useState('');
    const [savingFile, setSavingFile] = useState(false);
    const [viewMode, setViewMode] = useState('split');
    const [deviceMode, setDeviceMode] = useState('desktop');
    const [previewNonce, setPreviewNonce] = useState(0);
    const [livePreviewHtml, setLivePreviewHtml] = useState('');
    const [imageDimensions, setImageDimensions] = useState(null);
    const [imageLoadError, setImageLoadError] = useState(false);
    const [assetNonce, setAssetNonce] = useState(0);
    const [editorFullscreen, setEditorFullscreen] = useState(false);
    const assetInputRef = useRef(null);
    const replaceAssetInputRef = useRef(null);
    const codeEditorRef = useRef(null);

    // Show/hide advanced sections
    const [showCapping, setShowCapping] = useState(false);

    // Quick-create modals opened from the "+" next to the selects
    const [showGroupsModal, setShowGroupsModal] = useState(false);
    const [showNetworkEditor, setShowNetworkEditor] = useState(false);
    const [postbackKey, setPostbackKey] = useState('');

    const visibleFiles = useMemo(
        () => offerFiles.filter(file => !isJunkFile(file)).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })),
        [offerFiles]
    );
    const selectedIsImage = Boolean(selectedFile && isImageFile(selectedFile));
    const selectedLanguage = editorLanguage(selectedFile);
    const selectedImageAccept = normalizedImageExtension(selectedFile) === 'jpg'
        ? '.jpg,.jpeg'
        : `.${fileExtension(selectedFile)}`;
    const selectedAssetUrl = selectedFile && currentOfferId
        ? `/offers/${encodeURIComponent(currentOfferId)}/${encodeAssetPath(selectedFile)}?_asset=${assetNonce}`
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
        if (!selectedFile || selectedIsImage || selectedLanguage !== 'html' || !currentOfferId) {
            setLivePreviewHtml('');
            return undefined;
        }
        const timer = window.setTimeout(() => {
            setLivePreviewHtml(previewOfferDocument(fileContent, currentOfferId));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [fileContent, currentOfferId, selectedFile, selectedIsImage, selectedLanguage]);

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
                        if (data.is_local) {
                            fetchOfferFiles(offerId);
                        }
                    }
                })
                .finally(() => setLoading(false));
        }
    }, [offerId]);

    const handleSave = async (forceClose = false) => {
        if (loading || uploadingZip || saveSuccess) return;
        if (!formData.name) {
            setActiveTab('general');
            alert(t('offerEditor.fillName'));
            return;
        }
        if (!formData.is_local && !formData.url) {
            setActiveTab('general');
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

    const handleFormSubmit = (event) => {
        event.preventDefault();
        handleSave(false);
    };

    // Local offer file management functions
    const fetchOfferFiles = async (id) => {
        if (!id) return;
        try {
            const res = await axios.get(`${API_URL}?action=offer_files`, { params: { id } });
            if (res.data.status === 'success') {
                const list = res.data.data || [];
                setOfferFiles(list);
                // Auto-select index.html or first file if nothing is selected
                if (list.length > 0 && !selectedFile) {
                    const defaultFile = list.includes('index.html') ? 'index.html' : (list.includes('index.php') ? 'index.php' : list[0]);
                    loadFileContent(defaultFile, id);
                }
            }
        } catch (err) { console.error(err); }
    };

    const loadFileContent = async (path, idOverride = null) => {
        const id = idOverride || currentOfferId;
        if (!id || !path) return;
        setImageDimensions(null);
        setImageLoadError(false);
        if (isImageFile(path)) {
            setSelectedFile(path);
            setFileContent('');
            return;
        }
        try {
            const res = await axios.get(`${API_URL}?action=offer_file_content&id=${id}&path=${encodeURIComponent(path)}`);
            if (res.data.status === 'success') {
                setSelectedFile(path);
                setFileContent(res.data.data ?? '');
            } else {
                alert(res.data.message || t('landingEditor.fileReadError', 'File read error'));
            }
        } catch (error) {
            alert(`${t('landingEditor.fileReadError', 'File read error')}: ${translateLandingRequestError(t, error)}`);
        }
    };

    const saveFileContent = async () => {
        if (!selectedFile || selectedIsImage || !currentOfferId) return;
        setSavingFile(true);
        try {
            const res = await axios.post(`${API_URL}?action=offer_save_file`, {
                id: currentOfferId,
                path: selectedFile,
                content: fileContent
            });
            if (res.data.status === 'success') {
                setSavedSomething(true);
                setPreviewNonce(Date.now());
            } else {
                alert(res.data.message || t('landingEditor.fileSaveError', 'Could not save file'));
            }
        } catch (error) {
            alert(`${t('landingEditor.fileSaveError2', 'Error while saving')}: ${translateLandingRequestError(t, error)}`);
        } finally {
            setSavingFile(false);
        }
    };

    const fileOp = async (payload, okMessage) => {
        if (!currentOfferId) return false;
        try {
            const res = await axios.post(`${API_URL}?action=offer_file_op`, { id: currentOfferId, ...payload });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            fetchOfferFiles(currentOfferId);
            if (okMessage) alert(okMessage);
            return true;
        } catch (e) {
            alert(`${t('landingEditor.fileOpError', 'Operation failed')}: ${e.response?.data?.message || e.message}`);
            return false;
        }
    };

    const createFile = async () => {
        const path = window.prompt(t('landingEditor.fileNewPrompt', 'File name, e.g. page2.html or css/style.css'), 'page2.html');
        if (!path) return;
        const success = await fileOp({ op: 'create', path, content: '' });
        if (success) {
            loadFileContent(path);
        }
    };

    const renameFile = async (file) => {
        const to = window.prompt(t('landingEditor.fileRenamePrompt', 'New name or path'), file);
        if (!to || to === file) return;
        if (await fileOp({ op: 'rename', path: file, to }) && selectedFile === file) {
            setSelectedFile(to);
            loadFileContent(to);
        }
    };

    const deleteFile = async (file) => {
        if (!window.confirm(`${t('landingEditor.fileDeleteConfirm', 'Delete this file?')}\n\n${file}`)) return;
        if (await fileOp({ op: 'delete', path: file }) && selectedFile === file) {
            setSelectedFile(null);
            setFileContent('');
        }
    };

    const uploadFile = async (e) => {
        const file = e.target.files?.[0];
        if (!file || !currentOfferId) return;
        const dir = window.prompt(t('landingEditor.fileUploadDirPrompt', 'Folder to upload into (leave empty for the root)'), '') ?? '';
        const fd = new FormData();
        fd.append('file', file);
        fd.append('id', currentOfferId);
        fd.append('dir', dir);
        try {
            const res = await axios.post(`${API_URL}?action=upload_offer_file`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            fetchOfferFiles(currentOfferId);
        } catch (err) {
            alert(`${t('landingEditor.fileOpError', 'Operation failed')}: ${err.response?.data?.message || err.message}`);
        } finally {
            e.target.value = '';
        }
    };

    const replaceSelectedImage = async (event) => {
        const replacement = event.target.files?.[0];
        event.target.value = '';
        if (!replacement || !selectedFile || !currentOfferId) return;
        if (normalizedImageExtension(replacement.name) !== normalizedImageExtension(selectedFile)) {
            alert(t('landingEditor.replaceImageTypeError', 'Choose an image with the same file type so existing landing links keep working.'));
            return;
        }

        const fd = new FormData();
        fd.append('file', replacement);
        fd.append('id', currentOfferId);
        fd.append('path', selectedFile);
        try {
            const res = await axios.post(`${API_URL}?action=upload_offer_file`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            if (res.data.status !== 'success') throw new Error(res.data.message || 'failed');
            setAssetNonce(Date.now());
            setPreviewNonce(Date.now());
            setImageDimensions(null);
            setImageLoadError(false);
            setSavedSomething(true);
            fetchOfferFiles(currentOfferId);
        } catch (error) {
            alert(`${t('landingEditor.fileOpError', 'Operation failed')}: ${error.response?.data?.message || error.message}`);
        }
    };

    const handleBeautify = () => {
        const formatted = beautifyCode(fileContent, selectedLanguage);
        setFileContent(formatted);
        window.requestAnimationFrame(() => codeEditorRef.current?.setSelection(0, 0));
    };

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
                alert(translateLandingError(t, res.data.message, res.data.detail) || t('offerEditor.zipError', 'ZIP upload error'));
            } else {
                const note = describeSanitized(t, res.data.sanitized);
                if (note) alert(note);
                fetchOfferFiles(id);
                setPreviewNonce(Date.now());
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

    const timezones = [
        'UTC', 'Europe/Moscow', 'Europe/Kiev', 'Europe/Kyiv', 'Europe/London', 'Europe/Berlin',
        'Asia/Dubai', 'Asia/Kolkata', 'Asia/Bangkok', 'Asia/Singapore', 'Asia/Tokyo',
        'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'America/Sao_Paulo'
    ];

    const offerType = formData.is_local
        ? 'local'
        : ['preload', 'action'].includes(formData.redirect_type)
            ? formData.redirect_type
            : 'redirect';

    const isLocal = formData.is_local;
    const showFileEditor = activeTab === 'general' && isLocal && Boolean(currentOfferId);

    const tabs = [
        { id: 'general', label: t('editor.general') },
        { id: 'integration', label: `${t('editor.integrations')} & ${t('landingEditor.viewCode')}` },
        { id: 'details', label: `${t('editor.notes')} & ${t('editor.params')}` }
    ];

    const renderOfferPreview = (split = false) => {
        const useLiveDocument = Boolean(livePreviewHtml && selectedLanguage === 'html' && !selectedIsImage);
        return (
            <div className="flex h-full min-h-0 flex-col overflow-hidden" style={{ backgroundColor: '#fff' }}>
                {split ? (
                    <div className="flex items-center justify-between border-b px-3 py-2 text-xs font-semibold" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                        <span className="flex items-center gap-1.5"><Eye className="w-3.5 h-3.5" /> {t('landingEditor.livePreview', 'Live preview')}</span>
                        <span className="font-normal" style={{ color: 'var(--color-text-muted)' }}>
                            {useLiveDocument ? t('landingEditor.unsavedPreview', 'Unsaved HTML') : t('offerEditor.savedOffer', t('landingEditor.savedPreview', 'Saved offer'))}
                        </span>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2 text-xs font-semibold" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                        <div className="flex items-center gap-2">
                            <span className="flex items-center gap-1.5" style={{ color: 'var(--color-text-secondary)' }}>
                                <Eye className="w-3.5 h-3.5" /> {t('landingEditor.livePreview', 'Live preview')}
                            </span>
                            <span className="font-normal text-[11px]" style={{ color: 'var(--color-text-muted)' }}>
                                ({useLiveDocument ? t('landingEditor.unsavedPreview', 'Unsaved HTML') : t('offerEditor.savedOffer', 'Saved offer')})
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            {/* Device viewport toggle */}
                            <div className="flex overflow-hidden rounded-lg" style={{ border: '1px solid var(--color-border)' }}>
                                <button
                                    type="button"
                                    onClick={() => setDeviceMode('desktop')}
                                    className="flex items-center gap-1 px-2.5 py-1 text-xs transition"
                                    style={{
                                        backgroundColor: deviceMode === 'desktop' ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                        color: deviceMode === 'desktop' ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                        borderRight: '1px solid var(--color-border)'
                                    }}
                                >
                                    <Monitor className="w-3.5 h-3.5" />
                                    {t('offerEditor.desktop', 'Desktop')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setDeviceMode('mobile')}
                                    className="flex items-center gap-1 px-2.5 py-1 text-xs transition"
                                    style={{
                                        backgroundColor: deviceMode === 'mobile' ? 'var(--color-primary-light)' : 'var(--color-bg-card)',
                                        color: deviceMode === 'mobile' ? 'var(--color-primary)' : 'var(--color-text-primary)'
                                    }}
                                >
                                    <Smartphone className="w-3.5 h-3.5" />
                                    {t('offerEditor.mobile', 'Mobile (375px)')}
                                </button>
                            </div>

                            <button
                                type="button"
                                onClick={() => setPreviewNonce(Date.now())}
                                className="btn btn-ghost btn-sm p-1.5"
                                title={t('common.refresh', 'Refresh')}
                            >
                                <RotateCw className="w-3.5 h-3.5" />
                            </button>

                            {currentOfferId && (
                                <a
                                    href={`/offers/${currentOfferId}/`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="btn btn-secondary btn-sm"
                                    title={`/offers/${currentOfferId}/`}
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                    {t('landingEditor.openInTab', 'Open in tab')}
                                </a>
                            )}
                        </div>
                    </div>
                )}
                {currentOfferId ? (
                    <div className="flex h-full min-h-0 w-full flex-1 items-center justify-center overflow-auto p-0" style={{ backgroundColor: deviceMode === 'mobile' && !split ? 'var(--color-bg-soft)' : '#fff' }}>
                        <div
                            className={`h-full transition-all ${deviceMode === 'mobile' && !split ? 'w-[375px] my-3 border rounded-2xl shadow-lg overflow-hidden' : 'w-full'}`}
                            style={{
                                borderColor: 'var(--color-border)',
                                backgroundColor: '#fff',
                                height: deviceMode === 'mobile' && !split ? 'calc(100% - 24px)' : '100%'
                            }}
                        >
                            <iframe
                                key={`${previewNonce}-${useLiveDocument ? 'live' : 'saved'}`}
                                src={useLiveDocument ? undefined : `/offers/${currentOfferId}/?_preview=${previewNonce}`}
                                srcDoc={useLiveDocument ? livePreviewHtml : undefined}
                                title={t('landingEditor.viewPreview', 'Preview')}
                                className="h-full min-h-0 w-full flex-1"
                                style={{ border: 'none', minHeight: split ? '320px' : '400px' }}
                            />
                        </div>
                    </div>
                ) : (
                    <div className="flex h-full items-center justify-center p-6 text-center" style={{ color: 'var(--color-text-muted)' }}>
                        {t('offerEditor.saveFirst', 'Save the offer settings first to preview.')}
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
                        <p>{t('landingEditor.selectFile', 'Select file to edit')}</p>
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
                    ariaLabel={`${t('offerEditor.title', 'Offer Editor')}: ${selectedFile}`}
                />
            </div>
        );
    };

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
            <div
                className="modal-content"
                style={{
                    maxWidth: showFileEditor ? '1500px' : '880px',
                    width: '100%',
                    display: 'flex',
                    flexDirection: 'column',
                    overflow: 'hidden',
                    padding: 0
                }}
            >
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
                <div className="flex-1 overflow-y-auto p-0 flex flex-col md:flex-row">
                    {/* Settings Panel */}
                    <div className={`p-6 ${showFileEditor ? 'md:w-[28%]' : 'w-full'} flex flex-col pt-4`} style={{ borderRight: showFileEditor ? '1px solid var(--color-border)' : 'none' }}>
                        <form id="offer-form" onSubmit={handleFormSubmit} className="space-y-4">
                            {activeTab === 'general' && (
                                <div className="space-y-4">
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

                                    <div className="flex gap-4">
                                        <div className="flex-1">
                                            <label className="form-label">{t('offerEditor.groupOrProduct', 'Group / Product')}</label>
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
                                        <div className="flex-1">
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

                                    {/* Offer Type Buttons */}
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

                                    {/* Local offer: archive upload dropzone */}
                                    {Boolean(formData.is_local) && (
                                        <div className="p-4 rounded-2xl" style={{ border: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}>
                                            <div className="font-semibold mb-2 text-sm" style={{ color: 'var(--color-text-primary)' }}>
                                                {t('offerEditor.uploadZip', 'Upload ZIP')}
                                            </div>
                                            <FileDropzone
                                                file={pendingZip || lastZip}
                                                onFileSelect={selectOfferZip}
                                                disabled={uploadingZip}
                                                label={uploadingZip ? t('landingEditor.uploadingZip') : t('offerEditor.uploadZip', 'Upload ZIP')}
                                                emptyHint={t('landingEditor.zipDropHint', 'Drag & drop .zip here or click to browse files')}
                                                replaceHint={t('landingEditor.zipReplaceHint', 'Click to replace')}
                                            />
                                            <p className="mt-2 text-xs" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                                                {!currentOfferId
                                                    ? t('offerEditor.zipOnCreateHint', 'The archive uploads right after the offer is created (an upload needs the offer id). index.html becomes the offer page.')
                                                    : `${visibleFiles.length} ${t('offerEditor.filesLabel', 'files')}`}
                                            </p>
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
                                            <div className="w-9 h-5 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:rounded-full after:h-4 after:w-4 after:transition-all" style={{ backgroundColor: formData.allow_rebills ? 'var(--color-primary)' : 'var(--color-bg-hover)' }}>
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

                                    {/* Custom values */}
                                    <div className="pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                        <div className="flex justify-between items-center mb-3">
                                            <div>
                                                <h4 className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('offerEditor.valuesTitle')}</h4>
                                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                                    {t('offerEditor.valuesDesc')}
                                                </p>
                                            </div>
                                            <button
                                                type="button"
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
                                                            type="button"
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
                        </form>
                    </div>

                    {/* Editor Panel (Only for saved Local offers) */}
                    {showFileEditor && (
                        <div
                            className="flex min-h-[400px] flex-1 flex-col overflow-hidden"
                            style={{
                                backgroundColor: 'var(--color-bg-soft)',
                                ...(editorFullscreen ? {
                                    position: 'fixed', inset: '12px', zIndex: 2050,
                                    border: '1px solid var(--color-border)', borderRadius: '16px',
                                    boxShadow: '0 24px 80px rgba(0,0,0,.45)'
                                } : {})
                            }}
                        >
                            {/* Editor Header Toolbar */}
                            <div className="flex flex-wrap items-center justify-between gap-2 p-3" style={{ borderBottom: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                <h4 className="flex min-w-0 items-center font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                    <Code className="mr-2 h-4 w-4 flex-shrink-0" style={{ color: 'var(--color-accent-purple)' }} />
                                    <span>{t('offerEditor.title', 'Offer Editor')}</span>
                                    {selectedFile && <span className="ml-2 break-all text-xs font-normal" style={{ color: 'var(--color-text-muted)' }}>· {selectedFile}</span>}
                                </h4>
                                <div className="flex flex-wrap items-center justify-end gap-2">
                                    <div className="flex overflow-hidden rounded-lg" style={{ border: '1px solid var(--color-border)' }}>
                                        {[
                                            { value: 'code', label: t('landingEditor.viewCode', 'Code'), icon: Code },
                                            { value: 'split', label: t('landingEditor.viewSplit', 'Split'), icon: Columns2 },
                                            { value: 'preview', label: t('landingEditor.viewPreview', 'Preview'), icon: Eye },
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

                                    {viewMode !== 'code' && currentOfferId && (
                                        <a
                                            href={`/offers/${currentOfferId}/`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="btn btn-secondary btn-sm"
                                            title={`/offers/${currentOfferId}/`}
                                        >
                                            <ExternalLink className="h-4 w-4" />
                                            {t('landingEditor.openInTab', 'Open in tab')}
                                        </a>
                                    )}

                                    {viewMode !== 'preview' && selectedFile && !selectedIsImage && (
                                        <button
                                            type="button"
                                            onClick={saveFileContent}
                                            disabled={savingFile}
                                            className="btn btn-primary btn-sm"
                                            title="Ctrl/Cmd+S"
                                        >
                                            <Save className="h-4 w-4" />
                                            {savingFile ? t('common.saving') : t('landingEditor.save', 'Save')}
                                        </button>
                                    )}

                                    <button
                                        type="button"
                                        onClick={() => setEditorFullscreen(value => !value)}
                                        className="btn btn-ghost btn-sm"
                                        title={editorFullscreen ? t('landingEditor.exitFullscreen', 'Exit fullscreen') : t('landingEditor.fullscreen', 'Fullscreen')}
                                    >
                                        {editorFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                                    </button>
                                </div>
                            </div>

                            {/* Secondary Toolbar for quick formatting, search, etc. */}
                            {viewMode !== 'preview' && selectedFile && !selectedIsImage && (
                                <div className="flex flex-wrap items-center gap-1.5 border-b px-3 py-2" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                    <button type="button" className="btn btn-secondary btn-sm" onClick={handleBeautify}>
                                        <WandSparkles className="h-3.5 w-3.5" /> {t('landingEditor.formatCode', 'Format')}
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-secondary btn-sm"
                                        onClick={() => codeEditorRef.current?.openFind()}
                                        title="Ctrl/Cmd+F · Ctrl/Cmd+H"
                                    >
                                        <Search className="h-3.5 w-3.5" /> {t('landingEditor.findReplace', 'Find & Replace')}
                                    </button>
                                </div>
                            )}

                            {viewMode === 'preview' ? (
                                <div className="min-h-0 flex-1 overflow-hidden">{renderOfferPreview(false)}</div>
                            ) : (
                                <div className="flex min-h-0 flex-1 overflow-hidden">
                                    {/* File Tree */}
                                    <div className={`${viewMode === 'split' ? 'w-[20%]' : 'w-1/4'} min-w-[150px] overflow-y-auto`} style={{ borderRight: '1px solid var(--color-border)', backgroundColor: 'var(--color-bg-card)' }}>
                                        <div className="flex items-center gap-1 px-2 py-2" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                            <span className="mr-auto text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('landingEditor.filesTitle', 'Files')} ({visibleFiles.length})
                                            </span>
                                            <button type="button" onClick={createFile} className="btn btn-ghost btn-sm" title={t('landingEditor.fileNew', 'New file')}><Plus className="h-3.5 w-3.5" /></button>
                                            <button type="button" onClick={() => assetInputRef.current?.click()} className="btn btn-ghost btn-sm" title={t('landingEditor.fileUpload', 'Upload a file')}><Upload className="h-3.5 w-3.5" /></button>
                                            <input ref={assetInputRef} type="file" className="hidden" onChange={uploadFile} />
                                        </div>

                                        {visibleFiles.length === 0 ? (
                                            <div className="p-4 text-center text-sm italic" style={{ color: 'var(--color-text-muted)' }}>
                                                {t('offerEditor.noFiles', 'No files yet')}
                                            </div>
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
                                                                <button type="button" onClick={() => renameFile(file)} className="action-btn text-blue" title={t('landingEditor.fileRename', 'Rename')}><FileCode className="h-3.5 w-3.5" /></button>
                                                                <button type="button" onClick={() => deleteFile(file)} className="action-btn text-red" title={t('common.delete', 'Delete')}><X className="h-3.5 w-3.5" /></button>
                                                            </div>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </div>

                                    {/* Code Editor or Image Viewer */}
                                    <div className={`${viewMode === 'split' ? 'w-[40%]' : 'flex-1'} min-w-0 min-h-0`} style={{ backgroundColor: 'var(--color-bg-card)' }}>
                                        {renderSelectedAsset()}
                                    </div>

                                    {/* Split Preview Pane */}
                                    {viewMode === 'split' && (
                                        <div className="min-h-0 w-[40%] min-w-[280px] overflow-hidden border-l" style={{ borderColor: 'var(--color-border)' }}>
                                            {renderOfferPreview(true)}
                                        </div>
                                    )}
                                </div>
                            )}
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
                        type="submit"
                        form="offer-form"
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
                                : (currentOfferId ? t('landingEditor.saveChanges', 'Save Changes') : t('offers.createOffer'))}
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
