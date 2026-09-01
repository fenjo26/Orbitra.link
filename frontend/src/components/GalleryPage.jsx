import React, { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import {
    Check, Folder, FolderPlus, FolderPen, ImagePlus, Loader2,
    MoreVertical, Pencil, RotateCcw, Search, Trash2, X
} from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { copyToClipboard } from '../utils/clipboard';
import { canWriteResource, isAdminUser } from '../utils/permissions';

const API_URL = '/api.php';
const MAX_SELECT = 50;

// Content Gallery (docs/media-core-v1.md §7): shared image library with
// folders, soft-delete archive and bulk operations. The whole page reads and
// writes through the media_* API family; the backend resource gate decides
// visibility ('none' hides the tab) and write access.
const GalleryPage = ({ user }) => {
    const { t } = useLanguage();
    const [items, setItems] = useState([]);
    const [folders, setFolders] = useState([]);
    const [users, setUsers] = useState([]);
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(1);
    const [pages, setPages] = useState(1);
    const [loading, setLoading] = useState(true);
    const [uploading, setUploading] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const [status, setStatus] = useState('active');
    const [folderId, setFolderId] = useState('all');
    const [userId, setUserId] = useState('');
    const [q, setQ] = useState('');
    const [selected, setSelected] = useState(new Set());
    const [moveTarget, setMoveTarget] = useState(null); // folder picker modal
    const [folderMenu, setFolderMenu] = useState(null); // open ⋮ menu folder id
    const [dragOver, setDragOver] = useState(false);

    const fileInputRef = useRef(null);
    const searchTimer = useRef(null);

    const canWrite = canWriteResource(user, 'media');
    const isAdmin = isAdminUser(user);

    const tr = useCallback((key, fallback, params) => {
        let str = t(key, fallback);
        Object.entries(params || {}).forEach(([name, val]) => {
            str = str.split(`{${name}}`).join(String(val));
        });
        return str;
    }, [t]);

    const apiError = (err) => {
        const msg = err?.response?.data?.message || '';
        if (typeof msg === 'string' && msg.startsWith('media.')) return t(msg, msg);
        return t('media.err_upload', 'Upload failed');
    };

    const loadFolders = useCallback(async () => {
        try {
            const res = await axios.get(API_URL, { params: { action: 'media_folders' } });
            if (res.data?.status === 'success') setFolders(res.data.data?.items || []);
        } catch {
            /* the grid works without the folder strip */
        }
    }, []);

    const loadItems = useCallback(async (overrides = {}) => {
        const filters = {
            status, folderId, userId, q,
            page: 1,
            ...overrides
        };
        setLoading(true);
        try {
            const params = { action: 'media_list', status: filters.status, page: filters.page };
            // 'all' is the ROOT view: only folderless files (file-manager
            // semantics — folder contents appear when the folder is opened).
            // MediaPicker keeps sending 'all' → no filter → everything.
            params.folder_id = filters.folderId === 'all' ? 'root' : filters.folderId;
            if (filters.userId) params.user_id = filters.userId;
            if (filters.q) params.q = filters.q;
            const res = await axios.get(API_URL, { params });
            if (res.data?.status === 'success') {
                const data = res.data.data || {};
                setItems(data.items || []);
                setTotal(data.total || 0);
                setPage(data.page || 1);
                setPages(data.pages || 1);
                if (data.users) setUsers(data.users);
            }
            setSelected(new Set());
        } catch (err) {
            setError(apiError(err));
        } finally {
            setLoading(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [status, folderId, userId, q]);

    useEffect(() => {
        loadFolders();
        loadItems();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!notice) return undefined;
        const timer = window.setTimeout(() => setNotice(''), 3000);
        return () => window.clearTimeout(timer);
    }, [notice]);

    const uploadFiles = async (fileList) => {
        const files = Array.from(fileList || []);
        if (!files.length || !canWrite) return;
        setUploading(files.length);
        setError('');
        try {
            const fd = new FormData();
            files.forEach(f => fd.append('files[]', f));
            if (folderId !== 'all') fd.append('folder_id', folderId);
            const res = await axios.post(`${API_URL}?action=media_upload`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            const data = res.data?.status === 'success' ? (res.data.data || {}) : null;
            if (!data) throw new Error(res.data?.message || 'failed');
            await loadItems();
            loadFolders();
            const failed = data.failed || [];
            if (failed.length) {
                const reason = t(failed[0]?.reason || 'media.err_upload', failed[0]?.reason || 'Upload failed');
                setError(`${failed.map(f => f.name).join(', ')}: ${reason}`);
            }
        } catch (err) {
            setError(apiError(err));
        } finally {
            setUploading(0);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const toggleSelect = (id) => {
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else if (next.size < MAX_SELECT) next.add(id);
            return next;
        });
    };

    const runOp = async (op, extra = {}) => {
        if (!canWrite || !selected.size) return;
        setBusy(true);
        setError('');
        try {
            const res = await axios.post(`${API_URL}?action=media_op`, {
                op,
                ids: [...selected],
                ...extra
            });
            if (res.data?.status !== 'success') throw new Error(res.data?.message || 'failed');
            const { denied } = res.data.data || {};
            if (denied > 0) {
                setNotice(tr('media.deniedNotice', '{n} files belong to another user and were skipped.', { n: denied }));
            }
            await loadItems();
            if (op !== 'restore') loadFolders();
        } catch (err) {
            setError(apiError(err));
        } finally {
            setBusy(false);
        }
    };

    const promptCreateFolder = async () => {
        const name = (window.prompt(t('media.newFolder', 'New folder')) || '').trim();
        if (!name || !canWrite) return;
        setBusy(true);
        try {
            const res = await axios.post(`${API_URL}?action=media_folder_op`, { op: 'create', name });
            if (res.data?.status !== 'success') throw new Error(res.data?.message || 'failed');
            await loadFolders();
        } catch (err) {
            setError(apiError(err));
        } finally {
            setBusy(false);
        }
    };

    const renameFile = async (item) => {
        if (!canWrite) return;
        const name = (window.prompt(t('media.renameFile', 'Rename file'), item.orig_name) || '').trim();
        if (!name || name === item.orig_name) return;
        setBusy(true);
        try {
            const res = await axios.post(`${API_URL}?action=media_op`, { op: 'rename', ids: [item.id], name });
            if (res.data?.status !== 'success') throw new Error(res.data?.message || 'failed');
            await loadItems();
        } catch (err) {
            setError(apiError(err));
        } finally {
            setBusy(false);
        }
    };

    const renameFolder = async (folder) => {
        const name = (window.prompt(t('media.renameFolder', 'Rename folder'), folder.name) || '').trim();
        if (!name || name === folder.name) return;
        try {
            const res = await axios.post(`${API_URL}?action=media_folder_op`, { op: 'rename', id: folder.id, name });
            if (res.data?.status !== 'success') throw new Error(res.data?.message || 'failed');
            loadFolders();
        } catch (err) {
            setError(apiError(err));
        }
    };

    const deleteFolder = async (folder) => {
        if (!window.confirm(tr('media.deleteFolderConfirm', 'Delete the folder "{name}"? Files inside stay in the library (root).', { name: folder.name }))) return;
        try {
            const res = await axios.post(`${API_URL}?action=media_folder_op`, { op: 'delete', id: folder.id });
            if (res.data?.status !== 'success') throw new Error(res.data?.message || 'failed');
            if (folderId === folder.id) setFolderId('all');
            await Promise.all([loadFolders(), loadItems({ folderId: folderId === folder.id ? 'all' : folderId })]);
        } catch (err) {
            setError(apiError(err));
        }
    };

    const inactive = status === 'inactive';
    const folderName = (id) => folders.find(f => f.id === id)?.name || '—';

    return (
        <div className="space-y-4">
            <div className="page-card">
                <div className="page-header flex flex-wrap items-center justify-between gap-3" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div>
                        <h3 className="page-title m-0">{t('media.title', 'Content Gallery')}</h3>
                        <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                            {t('media.subtitle', 'Shared image library — pick from it in offers, landings and any file picker.')}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {isAdmin && (
                            <select
                                className="form-select text-sm"
                                style={{ width: 'auto' }}
                                value={userId}
                                onChange={(e) => { setUserId(e.target.value); loadItems({ userId: e.target.value }); }}
                                aria-label={t('media.filterUser', 'User')}
                            >
                                <option value="">{t('media.filterUser', 'User')}: {t('common.all')}</option>
                                {users.map(u => (
                                    <option key={u.id} value={u.id}>{u.username}</option>
                                ))}
                            </select>
                        )}
                        <select
                            className="form-select text-sm"
                            style={{ width: 'auto' }}
                            value={status}
                            onChange={(e) => { setStatus(e.target.value); loadItems({ status: e.target.value }); }}
                            aria-label={t('common.status')}
                        >
                            <option value="active">{t('media.statusActive', 'Active')}</option>
                            <option value="inactive">{t('media.statusInactive', 'Inactive (deleted)')}</option>
                        </select>
                        <div className="relative">
                            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5" style={{ color: 'var(--color-text-muted)' }} />
                            <input
                                type="text"
                                className="form-input text-sm"
                                style={{ paddingLeft: '2rem', width: '200px' }}
                                placeholder={t('media.searchPlaceholder', 'Search by file name…')}
                                value={q}
                                onChange={(e) => {
                                    setQ(e.target.value);
                                    clearTimeout(searchTimer.current);
                                    searchTimer.current = setTimeout(() => loadItems({ q: e.target.value }), 350);
                                }}
                            />
                        </div>
                    </div>
                </div>
            </div>

            {/* Breadcrumb bar — file-manager navigation */}
            <div className="page-card" style={{ padding: '10px 16px' }}>
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        className="btn btn-sm"
                        onClick={() => { setFolderId('all'); loadItems({ folderId: 'all' }); }}
                        style={folderId === 'all'
                            ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                            : { backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}
                    >
                        {t('media.allFiles', 'All files')}
                    </button>
                    {folderId !== 'all' && (
                        <span className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>
                            › {folderName(folderId) !== '—' ? folderName(folderId) : ''}
                        </span>
                    )}
                    <div className="flex-1" />
                    {canWrite && folderId === 'all' && !inactive && (
                        <button type="button" className="btn btn-secondary btn-sm" onClick={promptCreateFolder}>
                            <FolderPlus className="h-3.5 w-3.5" />
                            {t('media.newFolder', 'New folder')}
                        </button>
                    )}
                </div>
            </div>

            {error && <div className="alert alert-danger">{error}</div>}
            {notice && <div className="alert alert-info">{notice}</div>}

            {/* Bulk actions bar */}
            {selected.size > 0 && canWrite && (
                <div className="page-card flex flex-wrap items-center gap-2" style={{ padding: '12px 16px' }}>
                    <span className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>
                        {tr('media.selected', 'Selected: {n}', { n: selected.size })}
                    </span>
                    {!inactive && (
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setMoveTarget(folderId)} disabled={busy}>
                            <FolderPen className="h-3.5 w-3.5" />
                            {t('media.move', 'Move')}
                        </button>
                    )}
                    {inactive ? (
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => runOp('restore')} disabled={busy}>
                            <RotateCcw className="h-3.5 w-3.5" />
                            {t('media.restore', 'Restore')}
                        </button>
                    ) : (
                        <button type="button" className="btn btn-danger btn-sm" onClick={() => runOp('delete')} disabled={busy}>
                            <Trash2 className="h-3.5 w-3.5" />
                            {t('common.delete')}
                        </button>
                    )}
                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => setSelected(new Set())} disabled={busy}>
                        <X className="h-3.5 w-3.5" />
                        {t('common.clearSelection')}
                    </button>
                </div>
            )}

            {/* Move-to-folder dialog */}
            {moveTarget !== null && (
                <div className="modal-overlay" style={{ zIndex: 2000 }} onClick={() => setMoveTarget(null)}>
                    <div className="modal-content" style={{ maxWidth: '360px', overflow: 'visible' }} onClick={(e) => e.stopPropagation()}>
                        <h3 className="page-title m-0 mb-4">{t('media.moveTitle', 'Move to folder')}</h3>
                        <div className="space-y-1.5">
                            <button type="button" className="btn btn-secondary w-full justify-start" onClick={() => { runOp('move', { folder_id: null }); setMoveTarget(null); }}>
                                {t('media.noFolder', 'No folder (root)')}
                            </button>
                            {folders.map(folder => (
                                <button
                                    key={folder.id}
                                    type="button"
                                    className="btn btn-secondary w-full justify-start"
                                    onClick={() => { runOp('move', { folder_id: folder.id }); setMoveTarget(null); }}
                                    disabled={String(folder.id) === String(moveTarget)}
                                >
                                    {folder.name}
                                </button>
                            ))}
                        </div>
                        <div className="mt-4 flex justify-end">
                            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setMoveTarget(null)}>
                                {t('common.cancel')}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Grid */}
            <div className="page-card">
                {loading && !items.length ? (
                    <div className="py-12 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
                ) : (
                    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))' }}>
                        {folderMenu !== null && (
                            <div className="fixed inset-0 z-[10]" onClick={() => setFolderMenu(null)} />
                        )}
                        {canWrite && !inactive && (
                            <div
                                role="button"
                                tabIndex={0}
                                onClick={() => fileInputRef.current?.click()}
                                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') fileInputRef.current?.click(); }}
                                onDragEnter={(e) => { e.preventDefault(); setDragOver(true); }}
                                onDragOver={(e) => e.preventDefault()}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setDragOver(false);
                                    uploadFiles(e.dataTransfer.files);
                                }}
                                className="flex min-h-[150px] cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center transition"
                                style={{
                                    borderColor: dragOver ? 'var(--color-primary)' : 'var(--color-border)',
                                    backgroundColor: dragOver ? 'var(--color-primary-light)' : 'var(--color-bg-soft)'
                                }}
                            >
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".webp,.jpg,.jpeg,.png,.gif"
                                    multiple
                                    className="hidden"
                                    onChange={(e) => uploadFiles(e.target.files)}
                                />
                                {uploading > 0
                                    ? <Loader2 className="h-6 w-6 animate-spin" style={{ color: 'var(--color-primary)' }} />
                                    : <ImagePlus className="h-6 w-6" style={{ color: 'var(--color-primary)' }} />}
                                <div className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                    {uploading > 0 ? tr('media.uploadingCount', 'Uploading ({n})…', { n: uploading }) : t('media.upload', 'Upload images')}
                                </div>
                                <div className="text-[11px]" style={{ color: 'var(--color-text-muted)' }}>
                                    {t('media.uploadHint', 'Click or drop images here (webp, jpg, png, gif · up to 10 MB)')}
                                </div>
                            </div>
                        )}
                        {folderId === 'all' && !inactive && folders.map(folder => (
                            <div
                                key={'folder-' + folder.id}
                                className="relative flex min-h-[150px] cursor-pointer flex-col rounded-xl border p-2 transition"
                                style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
                                onClick={() => { setFolderId(folder.id); loadItems({ folderId: folder.id }); }}
                                title={t('media.openFolder', 'Open folder')}
                            >
                                <div className="relative mb-1.5 flex h-24 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--color-primary-light)' }}>
                                    <Folder className="h-8 w-8" style={{ color: 'var(--color-primary)' }} />
                                </div>
                                <div className="truncate text-xs font-medium" style={{ color: 'var(--color-text-primary)' }}>{folder.name}</div>
                                <div className="text-[10px]" style={{ color: 'var(--color-text-muted)' }}>{folder.asset_count ?? 0}</div>
                                {canWrite && (
                                    <button
                                        type="button"
                                        className="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-md"
                                        style={{ backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-muted)' }}
                                        onClick={(e) => { e.stopPropagation(); setFolderMenu(folderMenu === folder.id ? null : folder.id); }}
                                        title={t('media.folderMenu', 'Folder actions')}
                                    >
                                        <MoreVertical className="h-3.5 w-3.5" />
                                    </button>
                                )}
                                {folderMenu === folder.id && (
                                    <div className="absolute right-1 top-8 z-20 w-40 rounded-lg border py-1 shadow-lg" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }} onClick={(e) => e.stopPropagation()}>
                                        <button type="button" className="w-full px-3 py-1.5 text-left text-xs hover:opacity-80" onClick={(e) => { e.stopPropagation(); setFolderMenu(null); renameFolder(folder); }}>
                                            {t('media.renameFolder', 'Rename folder')}
                                        </button>
                                        <button type="button" className="w-full px-3 py-1.5 text-left text-xs hover:opacity-80" style={{ color: 'var(--color-danger)' }} onClick={(e) => { e.stopPropagation(); setFolderMenu(null); deleteFolder(folder); }}>
                                            {t('media.deleteFolder', 'Delete folder')}
                                        </button>
                                    </div>
                                )}
                            </div>
                        ))}
                        {items.map(item => {
                            const isSelected = selected.has(item.id);
                            return (
                                <div
                                    key={item.id}
                                    role="button"
                                    tabIndex={0}
                                    onClick={() => canWrite && toggleSelect(item.id)}
                                    onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === ' ') && canWrite) { e.preventDefault(); toggleSelect(item.id); } }}
                                    className="relative flex min-h-[150px] cursor-pointer flex-col rounded-xl border p-2 transition"
                                    style={{
                                        borderColor: isSelected ? 'var(--color-primary)' : 'var(--color-border)',
                                        backgroundColor: 'var(--color-bg-card)',
                                        opacity: inactive ? 0.6 : 1
                                    }}
                                    title={canWrite ? (isSelected ? t('media.deselectHint', 'Click to deselect') : t('media.selectHint', 'Click to select')) : item.orig_name}
                                >
                                    <div className="relative mb-1.5 flex h-24 items-center justify-center overflow-hidden rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                        <img src={item.url} alt={item.orig_name} className="max-h-24 max-w-full object-contain" loading="lazy" />
                                        {isSelected && (
                                            <span className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full" style={{ backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>
                                                <Check className="h-3 w-3" />
                                            </span>
                                        )}
                                    </div>
                                    <div className="truncate text-xs font-medium" style={{ color: 'var(--color-text-primary)' }} title={item.orig_name}>{item.orig_name}</div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                        <span>{item.width}×{item.height}</span>
                                        <span>· {folderName(item.folder_id)}</span>
                                        {isAdmin && item.owner_name && <span>· {item.owner_name}</span>}
                                    </div>
                                    <div className="mt-1 flex items-center gap-2">
                                        <button
                                            type="button"
                                            className="text-[10px] hover:underline"
                                            style={{ color: 'var(--color-primary)' }}
                                            onClick={async (e) => { e.stopPropagation(); await copyToClipboard(item.url); }}
                                            title={t('media.copyUrl', 'Copy URL')}
                                        >
                                            {t('media.copyUrl', 'Copy URL')}
                                        </button>
                                        {canWrite && (
                                            <button
                                                type="button"
                                                className="text-[10px] hover:underline flex items-center gap-0.5"
                                                style={{ color: 'var(--color-text-muted)' }}
                                                onClick={(e) => { e.stopPropagation(); renameFile(item); }}
                                                title={t('media.renameFile', 'Rename file')}
                                            >
                                                <Pencil className="h-3 w-3" />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {!loading && !items.length && (
                    <div className="py-10 text-center" style={{ color: 'var(--color-text-muted)' }}>
                        {inactive ? t('media.emptyInactive', 'No deleted files') : t('media.empty', 'No images yet — upload the first one')}
                    </div>
                )}

                {pages > 1 && (
                    <div className="mt-4 flex items-center justify-between gap-2">
                        <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                            {tr('media.totalFiles', 'Files: {n}', { n: total })}
                        </span>
                        <div className="flex items-center gap-2">
                            <button type="button" className="btn btn-secondary btn-sm" disabled={page <= 1 || loading} onClick={() => loadItems({ page: page - 1 })}>
                                ←
                            </button>
                            <span className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                {tr('media.pageOf', 'Page {p} of {n}', { p: page, n: pages })}
                            </span>
                            <button type="button" className="btn btn-secondary btn-sm" disabled={page >= pages || loading} onClick={() => loadItems({ page: page + 1 })}>
                                →
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default GalleryPage;
