import React, { useState, useRef, useCallback } from 'react';
import { X, Upload, FileText, Check, AlertCircle, Loader2 } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';
import axios from 'axios';

const API_URL = '/api.php';

/**
 * Universal CSV parser with header row detection
 * Supports both header-based and position-based formats
 */
const parseCSVFile = (content, entityType) => {
    // Normalize line endings
    const lines = content.split(/\r\n|\n|\r/).filter(line => line.trim());

    if (lines.length === 0) {
        return { items: [], headers: null, error: null };
    }

    // Detect if first line is a header (contains common column names)
    const firstLine = lines[0];
    const headerPattern = /^(name|url|payout|type|alias|group)(\s*,\s*|\s*\|\s*)/i;
    const hasHeaders = headerPattern.test(firstLine);

    let headers = null;
    let startIndex = 0;

    if (hasHeaders) {
        // Parse headers from first line
        headers = parseLine(firstLine).map(h => h.toLowerCase().trim());
        startIndex = 1;
    }

    const items = [];
    const errors = [];

    for (let i = startIndex; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line) continue;

        try {
            const values = parseLine(line);
            const item = { raw: line };

            if (hasHeaders && headers) {
                // Map values to headers
                headers.forEach((header, idx) => {
                    item[header] = values[idx] || '';
                });
            } else {
                // Position-based fallback
                item.name = values[0] || '';
                item.url = values[1] || '';
                item.payout = values[2] || '';
                item.type = values[3] || '';
                item.alias = values[2] || ''; // For campaigns
                item.group = values[3] || '';
            }

            if (item.name) {
                items.push(item);
            } else {
                errors.push({ line: i + 1, error: 'Missing required field: name' });
            }
        } catch (e) {
            errors.push({ line: i + 1, error: e.message });
        }
    }

    return { items, headers, errors };
};

/**
 * Parse a single line handling both CSV (comma/semicolon) and pipe-delimited formats
 */
const parseLine = (line) => {
    // First check for pipe delimiter (simple format)
    if (line.includes('|')) {
        return line.split('|').map(v => v.trim().replace(/^["']|["']$/g, ''));
    }

    // CSV parsing with quote handling
    const values = [];
    let current = '';
    let inQuotes = false;

    for (let i = 0; i < line.length; i++) {
        const char = line[i];

        if (char === '"' || char === "'") {
            inQuotes = !inQuotes;
        } else if ((char === ',' || char === ';') && !inQuotes) {
            values.push(current.trim());
            current = '';
        } else {
            current += char;
        }
    }

    if (current) {
        values.push(current.trim());
    }

    return values.map(v => v.replace(/^["']|["']$/g, ''));
};

/**
 * Calculate dynamic chunk size based on item count
 */
const calculateChunkSize = (itemCount) => {
    if (itemCount < 1000) return itemCount; // Single request
    if (itemCount < 5000) return 500;      // 500-1000 per chunk
    return 1000;                            // 1000-2000 per chunk
};

/**
 * Entity type to API action mapping
 */
const ENTITY_ACTIONS = {
    offers: 'bulk_import_offers',
    landings: 'bulk_import_landings',
    campaigns: 'bulk_import_campaigns',
    sources: 'bulk_import_sources',
    traffic_sources: 'bulk_import_sources'
};

/**
 * Entity display names
 */
const ENTITY_NAMES = {
    offers: 'Offers',
    landings: 'Landings',
    campaigns: 'Campaigns',
    sources: 'Traffic Sources',
    traffic_sources: 'Traffic Sources'
};

const BulkFileUpload = ({ entityType, onSuccess, onClose }) => {
    const { t } = useLanguage();
    const fileInputRef = useRef(null);

    const [file, setFile] = useState(null);
    const [parsing, setParsing] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState({ current: 0, total: 0 });
    const [result, setResult] = useState(null);
    const [parseErrors, setParseErrors] = useState([]);
    const [detectedHeaders, setDetectedHeaders] = useState(null);

    const entityName = ENTITY_NAMES[entityType] || entityType;
    const apiAction = ENTITY_ACTIONS[entityType] || `bulk_import_${entityType}`;

    const handleFileSelect = useCallback((selectedFile) => {
        if (!selectedFile) return;

        const validExtensions = ['.txt', '.csv'];
        const fileName = selectedFile.name.toLowerCase();
        const isValid = validExtensions.some(ext => fileName.endsWith(ext));

        if (!isValid) {
            alert(t('bulkFileUpload.invalidFormat'));
            return;
        }

        setFile(selectedFile);
        setResult(null);
        setParseErrors([]);
        setDetectedHeaders(null);

        // Parse the file
        setParsing(true);
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const content = e.target.result;
                const { items, headers, errors } = parseCSVFile(content, entityType);

                if (items.length === 0 && errors.length === 0) {
                    alert(t('bulkFileUpload.emptyFile'));
                    setFile(null);
                    setParsing(false);
                    return;
                }

                setDetectedHeaders(headers);
                setParseErrors(errors);

                if (items.length > 0) {
                    uploadItems(items);
                } else {
                    setParsing(false);
                }
            } catch (error) {
                console.error('Parse error:', error);
                alert(t('bulkFileUpload.error', { error: error.message }));
                setParsing(false);
            }
        };

        reader.onerror = () => {
            alert(t('bulkFileUpload.error', { error: 'Failed to read file' }));
            setParsing(false);
        };

        reader.readAsText(selectedFile);
    }, [entityType, t]);

    const uploadItems = async (items) => {
        setUploading(true);
        setParsing(false);

        const total = items.length;
        const chunkSize = calculateChunkSize(total);

        let added = 0;
        let skipped = 0;
        let errors = [];

        try {
            for (let i = 0; i < items.length; i += chunkSize) {
                const chunk = items.slice(i, i + chunkSize);
                const currentEnd = Math.min(i + chunkSize, items.length);

                setProgress({ current: currentEnd, total });

                const res = await axios.post(`${API_URL}?action=${apiAction}`, {
                    items: chunk
                });

                if (res.data.status === 'success') {
                    added += res.data.data.added || 0;
                    skipped += res.data.data.skipped || res.data.data.duplicates || 0;
                    if (res.data.data.errors) {
                        errors = [...errors, ...res.data.data.errors];
                    }
                } else {
                    throw new Error(res.data.message || 'Import failed');
                }
            }

            setResult({ added, skipped, errors });

            if (added > 0 || skipped > 0) {
                const message = skipped > 0
                    ? t('bulkFileUpload.successWithSkipped', { added, skipped })
                    : t('bulkFileUpload.success', { added });
                alert(message);
                onSuccess && onSuccess();
            }
        } catch (error) {
            console.error('Upload error:', error);
            alert(t('bulkFileUpload.error', { error: error.message }));
        } finally {
            setUploading(false);
        }
    };

    const handleButtonClick = () => {
        fileInputRef.current?.click();
    };

    const handleClose = () => {
        if (result && result.added > 0) {
            onSuccess && onSuccess();
        }
        onClose();
    };

    const isProcessing = parsing || uploading;

    return (
        <div className="modal-overlay" onClick={handleClose}>
            <div className="modal-content" style={{ maxWidth: '600px' }} onClick={(e) => e.stopPropagation()}>
                {/* Header */}
                <div className="modal-header">
                    <h2 className="modal-title">{t('bulkFileUpload.title', { entity: entityName })}</h2>
                    <button onClick={handleClose} className="action-btn" disabled={isProcessing}>
                        <X size={20} />
                    </button>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto p-6">
                    <div className="space-y-4">
                        {/* Upload Area */}
                        {!file && !result && (
                            <div
                                onClick={!isProcessing ? handleButtonClick : undefined}
                                className="border-2 border-dashed rounded-2xl p-8 text-center transition-all hover:border-[var(--color-primary)] flex flex-col items-center justify-center gap-3 cursor-pointer"
                                style={{
                                    opacity: isProcessing ? 0.65 : 1,
                                    cursor: isProcessing ? 'wait' : 'pointer',
                                    backgroundColor: 'var(--color-bg-soft)',
                                            borderColor: 'var(--color-border)'
                                }}
                            >
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".txt,.csv"
                                    className="hidden"
                                    disabled={isProcessing}
                                    onChange={(e) => {
                                        handleFileSelect(e.target.files?.[0]);
                                        e.target.value = ''; // Reset for same file re-upload
                                    }}
                                />
                                <div
                                    className="w-16 h-16 rounded-2xl flex items-center justify-center"
                                    style={{
                                        backgroundColor: 'var(--color-bg-card)',
                                        color: 'var(--color-primary)'
                                    }}
                                >
                                    <Upload className="w-8 h-8" />
                                </div>
                                <div>
                                    <div className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('bulkFileUpload.uploadBtn')}
                                    </div>
                                    <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('bulkFileUpload.formatHelp')}
                                    </div>
                                    <div className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('bulkFileUpload.formatHelpSimple')}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Processing State */}
                        {isProcessing && (
                            <div className="flex flex-col items-center gap-3 py-8">
                                <Loader2 className="w-8 h-8 animate-spin" style={{ color: 'var(--color-primary)' }} />
                                <div className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                    {parsing ? t('bulkFileUpload.processing') : t('bulkFileUpload.uploading', {
                                        current: progress.current,
                                        total: progress.total
                                    })}
                                </div>
                                {uploading && progress.total > 0 && (
                                    <div className="w-full max-w-xs h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--color-bg-soft)' }}>
                                        <div
                                            className="h-full transition-all duration-300"
                                            style={{
                                                width: `${(progress.current / progress.total) * 100}%`,
                                                backgroundColor: 'var(--color-primary)'
                                            }}
                                        />
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Results */}
                        {result && !isProcessing && (
                            <div className="space-y-3">
                                {/* Summary */}
                                <div className="p-4 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                    <div className="flex items-center gap-2 mb-3">
                                        <Check className="w-5 h-5" style={{ color: 'var(--color-success)' }} />
                                        <span className="font-medium text-sm">{t('bulkFileUpload.addedLabel')}: {result.added}</span>
                                    </div>
                                    {result.skipped > 0 && (
                                        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                                            <AlertCircle className="w-4 h-4" style={{ color: 'var(--color-warning)' }} />
                                            <span>{t('bulkFileUpload.skippedLabel')}: {result.skipped}</span>
                                        </div>
                                    )}
                                </div>

                                {/* Detected Headers */}
                                {detectedHeaders && (
                                    <div className="text-xs p-2 rounded-lg" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>
                                        {t('bulkFileUpload.detectedHeaders', { columns: detectedHeaders.join(', ') })}
                                    </div>
                                )}

                                {/* Errors */}
                                {(result.errors.length > 0 || parseErrors.length > 0) && (
                                    <div className="p-3 rounded-xl" style={{ backgroundColor: 'rgba(239, 68, 68, 0.1)', border: '1px solid var(--color-error)' }}>
                                        <p className="font-medium text-sm mb-2" style={{ color: 'var(--color-error)' }}>
                                            {t('bulkFileUpload.errorsLabel')}: {result.errors.length + parseErrors.length}
                                        </p>
                                        <div className="max-h-32 overflow-y-auto space-y-1">
                                            {[...parseErrors.slice(0, 5), ...result.errors.slice(0, 5)].map((err, i) => (
                                                <p key={i} className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                    Line {err.line || err.row || '?'}: {err.error}
                                                </p>
                                            ))}
                                            {(result.errors.length + parseErrors.length) > 10 && (
                                                <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>
                                                    ...and {(result.errors.length + parseErrors.length) - 10} more
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Upload Another */}
                                <button
                                    onClick={() => {
                                        setFile(null);
                                        setResult(null);
                                        setParseErrors([]);
                                        setDetectedHeaders(null);
                                    }}
                                    className="btn btn-ghost btn-sm w-full"
                                >
                                    <Upload className="w-4 h-4" />
                                    Upload another file
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Footer */}
                <div className="modal-footer">
                    <button onClick={handleClose} className="btn btn-secondary" disabled={isProcessing}>
                        {result && result.added > 0 ? t('common.close') : t('common.cancel')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default BulkFileUpload;
