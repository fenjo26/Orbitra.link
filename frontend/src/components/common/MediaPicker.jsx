import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Check, FolderOpen, ImagePlus, Loader2, Scissors, Search, X } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import { copyToClipboard } from '../../utils/clipboard';

const API_URL = '/api.php';

// Shared media library picker (docs/media-core-v1.md §5). Renders inside its
// own modal overlay and resolves through onSelect — consumers (Offer/Landing
// editors today, the PWA constructor tomorrow) never touch the storage itself:
//
//   <MediaPicker open onClose onSelect={({id, url, width, height, mime}) => …}
//                sizeContract={{ width: 512, height: 512, crop: true, label: '512×512' }} />
//
// With a sizeContract every tile gets a fit badge (exact / croppable / too
// small) and too-small assets cannot be confirmed; with crop enabled a crop
// overlay (frame locked to the contract aspect) produces a NEW library asset
// rendered at exactly contract width × height. The original always survives.
export const MediaPicker = ({
    open,
    onClose,
    onSelect,
    accept = '.webp,.jpg,.jpeg,.png,.gif',
    multiple = false,
    sizeContract = null
}) => {
    const { t } = useLanguage();
    const [items, setItems] = useState([]);
    const [folders, setFolders] = useState([]);
    const [folderId, setFolderId] = useState('all');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [pages, setPages] = useState(1);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(0);
    const [selected, setSelected] = useState(null);
    // multiple mode: a Set of picked ids; onSelect then resolves a LIST of
    // assets so consumers (e.g. PWA screenshots) fill in one pass.
    const [selectedMany, setSelectedMany] = useState(() => new Set());
    const [error, setError] = useState('');
    const [cropAsset, setCropAsset] = useState(null);
    const [cropRect, setCropRect] = useState(null); // natural-pixel frame
    const [cropImg, setCropImg] = useState(null);
    const [cropScale, setCropScale] = useState(1); // displayed px per natural px
    const [cropBusy, setCropBusy] = useState(false);
    const stageImgRef = useRef(null);
    const fileInputRef = useRef(null);
    const gridRef = useRef(null);
    const dragState = useRef(null);

    const tr = useCallback((key, fallback, params) => {
        let str = t(key, fallback);
        Object.entries(params || {}).forEach(([name, val]) => {
            str = str.split(`{${name}}`).join(String(val));
        });
        return str;
    }, [t]);

    const loadFolders = useCallback(async () => {
        try {
            const res = await axios.get(`${API_URL}`, { params: { action: 'media_folders' } });
            if (res.data?.status === 'success') setFolders(res.data.data?.items || []);
        } catch {
            /* folder strip is optional chrome — the grid works without it */
        }
    }, []);

    const loadItems = useCallback(async ({ append = false, page: pageNum = 1, search = q, folder = folderId } = {}) => {
        setLoading(true);
        try {
            const params = { action: 'media_list', status: 'active', page: pageNum };
            if (folder !== 'all') params.folder_id = folder;
            if (search) params.q = search;
            const res = await axios.get(`${API_URL}`, { params });
            if (res.data?.status === 'success') {
                const data = res.data.data || {};
                setItems(prev => append ? [...prev, ...(data.items || [])] : (data.items || []));
                setPages(data.pages || 1);
                setPage(data.page || 1);
            }
        } catch (err) {
            setError(apiError(err, tr));
        } finally {
            setLoading(false);
        }
    }, [q, folderId, tr]);

    useEffect(() => {
        if (!open) return;
        setSelected(null);
        setSelectedMany(new Set());
        setCropAsset(null);
        setError('');
        loadFolders();
        loadItems();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const togglePicked = (id) => {
        setSelectedMany((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const apiError = (err, translate) => {
        const msg = err?.response?.data?.message || '';
        if (typeof msg === 'string' && msg.startsWith('media.')) return translate(msg, msg);
        return translate('media.err_upload', 'Upload failed');
    };

    const uploadFiles = async (fileList) => {
        const files = Array.from(fileList || []);
        if (!files.length) return;
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
            await loadItems({ page: 1 });
            const uploaded = data.items || [];
            if (uploaded.length) {
                const first = uploaded[0];
                if (sizeContract?.crop && !fitsContract(first)) {
                    openCrop(first);
                } else if (multiple) {
                    togglePicked(first.id);
                } else {
                    setSelected(first.id);
                }
            }
            if ((data.failed || []).length) {
                const names = data.failed.map(f => f.name).join(', ');
                const reason = tr(data.failed[0]?.reason || 'media.err_upload', 'Upload failed');
                setError(`${names}: ${reason}`);
            }
        } catch (err) {
            setError(apiError(err, tr));
        } finally {
            setUploading(0);
            if (fileInputRef.current) fileInputRef.current.value = '';
        }
    };

    const fitsContract = (item) => {
        if (!sizeContract) return true;
        return item.width === sizeContract.width && item.height === sizeContract.height;
    };

    // green = exact contract size, yellow = croppable (some side is big
    // enough), red = too small — selection blocked.
    const fitBadge = (item) => {
        if (!sizeContract) return null;
        if (fitsContract(item)) {
            return { level: 'ok', title: tr('media.exactSize', 'Exact size') };
        }
        if (item.width >= sizeContract.width || item.height >= sizeContract.height) {
            return { level: 'crop', title: tr('media.croppable', 'Can be cropped to {w}×{h}', { w: sizeContract.width, h: sizeContract.height }) };
        }
        return { level: 'small', title: tr('media.tooSmall', 'Too small for {w}×{h}', { w: sizeContract.width, h: sizeContract.height }) };
    };

    const confirmSelect = () => {
        if (multiple) {
            const picked = items.filter(i => selectedMany.has(i.id) && fitBadge(i)?.level !== 'small');
            if (!picked.length) return;
            onSelect?.(picked.map(item => ({ id: item.id, url: item.url, width: item.width, height: item.height, mime: item.mime })));
            onClose?.();
            return;
        }
        const item = items.find(i => i.id === selected);
        if (!item) return;
        const badge = fitBadge(item);
        if (badge?.level === 'small') return;
        onSelect?.({ id: item.id, url: item.url, width: item.width, height: item.height, mime: item.mime });
        onClose?.();
    };

    // ---------- Crop overlay ----------
    const openCrop = (item) => {
        const img = new Image();
        img.onload = () => {
            setCropImg(img);
            const contract = sizeContract;
            const aspect = contract.width / contract.height;
            // Largest contract-aspect frame that fits inside the image.
            let w = img.naturalWidth;
            let h = w / aspect;
            if (h > img.naturalHeight) {
                h = img.naturalHeight;
                w = h * aspect;
            }
            setCropRect({
                x: (img.naturalWidth - w) / 2,
                y: (img.naturalHeight - h) / 2,
                w,
                h
            });
            setCropAsset(item);
        };
        img.src = item.url;
    };

    const framePointerDown = (mode) => (event) => {
        event.preventDefault();
        event.stopPropagation();
        const container = event.currentTarget.closest('[data-crop-stage]');
        const imgEl = container?.querySelector('img');
        if (!imgEl || !cropImg) return;
        const bounds = imgEl.getBoundingClientRect();
        const scale = cropImg.naturalWidth / bounds.width;
        dragState.current = {
            mode,
            startX: event.clientX,
            startY: event.clientY,
            rect: { ...cropRect },
            scale,
            bounds,
            aspect: sizeContract.width / sizeContract.height
        };
    };

    useEffect(() => {
        if (!cropAsset) return undefined;
        const minSize = 16;
        const onMove = (event) => {
            const st = dragState.current;
            if (!st) return;
            const dx = (event.clientX - st.startX) * st.scale;
            const dy = (event.clientY - st.startY) * st.scale;
            const natW = st.bounds.width * st.scale;
            const natH = st.bounds.height * st.scale;
            setCropRect(() => {
                const r = { ...st.rect };
                if (st.mode === 'move') {
                    r.x = Math.min(Math.max(0, r.x + dx), natW - r.w);
                    r.y = Math.min(Math.max(0, r.y + dy), natH - r.h);
                    return r;
                }
                // Corner resize, aspect locked to the contract.
                const goRight = st.mode.includes('e');
                const goDown = st.mode.includes('s');
                let w = r.w + (goRight ? dx : -dx);
                w = Math.max(minSize, Math.min(w, goRight ? natW - r.x : r.x + r.w));
                let h = w / st.aspect;
                if (r.y + h > natH) {
                    h = natH - r.y;
                    w = h * st.aspect;
                }
                if (h < minSize) {
                    h = minSize;
                    w = h * st.aspect;
                }
                return {
                    x: goRight ? r.x : r.x + r.w - w,
                    y: goDown ? r.y : r.y + r.h - h,
                    w,
                    h
                };
            });
        };
        const onUp = () => { dragState.current = null; };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
        };
    }, [cropAsset]);

    // The frame is stored in natural pixels but positioned over the scaled
    // <img>; measure the rendered element after layout so the overlay matches
    // the picture even when the browser scaled it down.
    useLayoutEffect(() => {
        if (!cropAsset) return undefined;
        const measure = () => {
            const el = stageImgRef.current;
            if (el && cropImg) setCropScale(el.getBoundingClientRect().width / cropImg.naturalWidth);
        };
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, [cropAsset, cropImg]);

    const applyCrop = async () => {
        if (!cropAsset || !cropImg || !cropRect) return;
        setCropBusy(true);
        try {
            const canvas = document.createElement('canvas');
            canvas.width = sizeContract.width;
            canvas.height = sizeContract.height;
            const ctx = canvas.getContext('2d');
            // Rendered straight at the contract size: a cropped asset always
            // satisfies the contract exactly, whatever the frame's pixel size.
            ctx.drawImage(cropImg, cropRect.x, cropRect.y, cropRect.w, cropRect.h, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            const base = (cropAsset.orig_name || 'image').replace(/\.[^.]+$/, '');
            const file = new File([blob], `${base}-${sizeContract.width}x${sizeContract.height}.png`, { type: 'image/png' });
            const fd = new FormData();
            fd.append('files[]', file);
            if (folderId !== 'all') fd.append('folder_id', folderId);
            const res = await axios.post(`${API_URL}?action=media_upload`, fd, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            const data = res.data?.status === 'success' ? (res.data.data || {}) : null;
            if (!data) throw new Error(res.data?.message || 'failed');
            const created = (data.items || [])[0];
            setCropAsset(null);
            setCropImg(null);
            setCropRect(null);
            await loadItems({ page: 1 });
            if (created) {
                if (multiple) togglePicked(created.id); else setSelected(created.id);
            }
        } catch (err) {
            setError(apiError(err, tr));
        } finally {
            setCropBusy(false);
        }
    };

    if (!open) return null;

    const selectedBadge = (() => {
        const item = items.find(i => i.id === selected);
        return item ? fitBadge(item) : null;
    })();

    return (
        <div className="modal-overlay" style={{ zIndex: 2000 }}>
            <div className="modal-content flex flex-col" style={{ maxWidth: '880px', width: '94%', minHeight: '420px', maxHeight: '86vh', overflow: 'visible' }}>
                <div className="flex items-center justify-between gap-3 pb-3" style={{ borderBottom: '1px solid var(--color-border)' }}>
                    <h3 className="page-title m-0">{t('media.pickerTitle', 'Choose an image')}</h3>
                    <div className="flex items-center gap-2">
                        {sizeContract && (
                            <span className="text-xs px-2 py-1 rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                                {sizeContract.label || `${sizeContract.width}×${sizeContract.height}`}
                            </span>
                        )}
                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => fileInputRef.current?.click()} disabled={uploading > 0}>
                            {uploading > 0 ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <ImagePlus className="h-3.5 w-3.5" />}
                            {uploading > 0 ? tr('media.uploadingCount', 'Uploading ({n})…', { n: uploading }) : t('media.upload', 'Upload images')}
                        </button>
                        <button type="button" className="btn btn-secondary btn-sm" onClick={onClose} aria-label={t('common.close')}>
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <input
                    ref={fileInputRef}
                    type="file"
                    accept={accept}
                    multiple={multiple}
                    className="hidden"
                    onChange={(e) => uploadFiles(e.target.files)}
                />

                <div className="flex flex-wrap items-center gap-2 py-3">
                    <div className="flex flex-wrap items-center gap-1.5 flex-1 min-w-0">
                        <button
                            type="button"
                            onClick={() => { setFolderId('all'); loadItems({ folder: 'all', page: 1 }); }}
                            className="btn btn-sm"
                            style={folderId === 'all'
                                ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                                : { backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}
                        >
                            {t('media.allFiles', 'All files')}
                        </button>
                        {folders.map(folder => (
                            <button
                                key={folder.id}
                                type="button"
                                onClick={() => { setFolderId(folder.id); loadItems({ folder: folder.id, page: 1 }); }}
                                className="btn btn-sm"
                                style={folderId === folder.id
                                    ? { backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }
                                    : { backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}
                            >
                                {folder.name} ({folder.asset_count})
                            </button>
                        ))}
                    </div>
                    <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5" style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            className="form-input pl-8 py-1.5 text-sm"
                            style={{ paddingLeft: '2rem' }}
                            placeholder={t('media.searchPlaceholder', 'Search by file name…')}
                            value={q}
                            onChange={(e) => {
                                setQ(e.target.value);
                                loadItems({ search: e.target.value, page: 1 });
                            }}
                        />
                    </div>
                </div>

                {error && <div className="alert alert-danger text-sm mb-2">{error}</div>}

                {cropAsset ? (
                    <div className="flex min-h-0 flex-1 flex-col gap-3">
                        <div
                            data-crop-stage
                            className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-xl"
                            style={{ backgroundColor: 'var(--color-bg-soft)' }}
                        >
                            <div className="relative select-none" style={{ maxWidth: '100%', maxHeight: '52vh' }}>
                                <img
                                    ref={stageImgRef}
                                    src={cropAsset.url}
                                    alt={cropAsset.orig_name}
                                    className="block max-w-full"
                                    style={{ maxHeight: '52vh' }}
                                    draggable={false}
                                />
                                {cropRect && cropImg && (
                                    <div
                                        className="absolute cursor-move"
                                        onPointerDown={framePointerDown('move')}
                                        style={{
                                            left: cropRect.x * cropScale,
                                            top: cropRect.y * cropScale,
                                            width: cropRect.w * cropScale,
                                            height: cropRect.h * cropScale,
                                            outline: '2px solid var(--color-primary)',
                                            boxShadow: '0 0 0 9999px rgba(0,0,0,0.55)',
                                            cursor: 'move'
                                        }}
                                    >
                                        {['nw', 'ne', 'sw', 'se'].map(corner => (
                                            <div
                                                key={corner}
                                                onPointerDown={framePointerDown(corner)}
                                                className="absolute h-3 w-3 rounded-sm"
                                                style={{
                                                    backgroundColor: 'var(--color-primary)',
                                                    cursor: `${corner}-resize`,
                                                    ...(corner.includes('n') ? { top: -6 } : { bottom: -6 }),
                                                    ...(corner.includes('w') ? { left: -6 } : { right: -6 })
                                                }}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                {t('media.cropHint', 'Drag the frame, resize from the corners — result is exactly {w}×{h}.', { w: sizeContract.width, h: sizeContract.height })}
                            </span>
                            <div className="flex items-center gap-2">
                                <button type="button" className="btn btn-secondary btn-sm" onClick={() => { setCropAsset(null); setCropImg(null); setCropRect(null); }} disabled={cropBusy}>
                                    {t('media.cropSkip', 'Skip')}
                                </button>
                                <button type="button" className="btn btn-primary btn-sm" onClick={applyCrop} disabled={cropBusy}>
                                    {cropBusy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Scissors className="h-3.5 w-3.5" />}
                                    {t('media.cropApply', 'Apply and upload')}
                                </button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <>
                        <div ref={gridRef} className="min-h-0 flex-1 overflow-y-auto">
                            {loading && !items.length ? (
                                <div className="py-10 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('common.loading')}</div>
                            ) : !items.length ? (
                                <div className="py-10 text-center" style={{ color: 'var(--color-text-muted)' }}>{t('media.empty', 'No images yet — upload the first one')}</div>
                            ) : (
                                <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))' }}>
                                    {items.map(item => {
                                        const badge = fitBadge(item);
                                        const isSelected = multiple ? selectedMany.has(item.id) : selected === item.id;
                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => (multiple ? togglePicked(item.id) : setSelected(isSelected ? null : item.id))}
                                                className="relative flex flex-col rounded-xl border p-1.5 text-left transition"
                                                style={{
                                                    borderColor: isSelected ? 'var(--color-primary)' : 'var(--color-border)',
                                                    backgroundColor: 'var(--color-bg-card)',
                                                    opacity: badge?.level === 'small' ? 0.55 : 1,
                                                    cursor: badge?.level === 'small' ? 'not-allowed' : 'pointer'
                                                }}
                                                title={badge?.title || item.orig_name}
                                            >
                                                <div className="relative mb-1 flex h-20 items-center justify-center overflow-hidden rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                                    <img src={item.url} alt={item.orig_name} className="max-h-20 max-w-full object-contain" loading="lazy" />
                                                    {isSelected && (
                                                        <span className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full" style={{ backgroundColor: 'var(--color-primary)', color: 'var(--color-text-inverse)' }}>
                                                            <Check className="h-3 w-3" />
                                                        </span>
                                                    )}
                                                    {badge && (
                                                        <span
                                                            className="absolute left-1 top-1 flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-bold"
                                                            style={{
                                                                backgroundColor: badge.level === 'ok' ? 'rgba(34,197,94,0.9)' : badge.level === 'crop' ? 'rgba(234,179,8,0.9)' : 'rgba(239,68,68,0.9)',
                                                                color: '#fff'
                                                            }}
                                                        >
                                                            {badge.level === 'ok' ? '✓' : badge.level === 'crop' ? '↓' : '!'}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="truncate text-[11px] font-medium" style={{ color: 'var(--color-text-primary)' }} title={item.orig_name}>{item.orig_name}</div>
                                                <div className="flex items-center justify-between gap-1 text-[10px]" style={{ color: 'var(--color-text-muted)' }}>
                                                    <span>{item.width}×{item.height}</span>
                                                    <span
                                                        role="button"
                                                        tabIndex={-1}
                                                        className="hover:underline"
                                                        onClick={async (e) => {
                                                            e.stopPropagation();
                                                            await copyToClipboard(item.url);
                                                        }}
                                                        title={t('media.copyUrl', 'Copy URL')}
                                                    >
                                                        URL
                                                    </span>
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                        <div className="flex items-center justify-between gap-2 pt-3" style={{ borderTop: '1px solid var(--color-border)' }}>
                            <div className="flex items-center gap-2">
                                {selected && (
                                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => {
                                        const item = items.find(i => i.id === selected);
                                        if (sizeContract?.crop && item) openCrop(item);
                                    }}>
                                        <Scissors className="h-3.5 w-3.5" />
                                        {t('media.cropButton', 'Crop')}
                                    </button>
                                )}
                                {page < pages && (
                                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => loadItems({ append: true, page: page + 1 })} disabled={loading}>
                                        {t('media.loadMore', 'Show more')}
                                    </button>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                    {selectedBadge?.level === 'small'
                                        ? tr('media.tooSmall', 'Too small for {w}×{h}', { w: sizeContract.width, h: sizeContract.height })
                                        : `${items.length}`}
                                </span>
                                <button type="button" className="btn btn-secondary btn-sm" onClick={onClose}>
                                    {t('common.cancel')}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    onClick={confirmSelect}
                                    disabled={multiple ? selectedMany.size === 0 : (!selected || selectedBadge?.level === 'small')}
                                >
                                    <FolderOpen className="h-3.5 w-3.5" />
                                    {multiple && selectedMany.size > 0
                                        ? tr('media.selectCount', 'Select ({n})', { n: selectedMany.size })
                                        : t('media.selectButton', 'Select')}
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
};

export default MediaPicker;
