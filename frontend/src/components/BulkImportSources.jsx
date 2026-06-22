import React, { useState } from 'react';
import axios from 'axios';
import { X, Upload, Info } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const BulkImportSources = ({ onClose, onSave }) => {
    const { t } = useLanguage();
    const [lines, setLines] = useState('');
    const [importing, setImporting] = useState(false);
    const [result, setResult] = useState(null);

    const handleImport = async () => {
        if (!lines.trim()) {
            alert(t('bulkImport.enterUrl'));
            return;
        }

        setImporting(true);
        setResult(null);

        try {
            const linesArray = lines.split('\n').map(l => l.trim()).filter(l => l);
            const res = await axios.post(`${API_URL}?action=bulk_import_sources`, { lines: linesArray });
            if (res.data.status === 'success') {
                setResult(res.data.data);
            }
        } catch (error) {
            console.error('Error importing sources:', error);
            setResult({ imported: 0, errors: [{ error: error.message }] });
        } finally {
            setImporting(false);
        }
    };

    const handleClose = () => {
        if (result && result.imported > 0) {
            onSave();
        } else {
            onClose();
        }
    };

    return (
        <div className="modal-overlay">
            <div className="modal-content" style={{ maxWidth: '600px' }}>
                {/* Header */}
                <div className="modal-header">
                    <h2 className="modal-title">{t('bulkImport.title')}</h2>
                    <button onClick={handleClose} className="action-btn">
                        <X size={20} />
                    </button>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto p-6">
                    <div className="space-y-4">
                        {/* Info */}
                        <div className="p-3 rounded-xl" style={{ backgroundColor: 'var(--color-primary-light)', border: '1px solid var(--color-primary)' }}>
                            <div className="flex items-start gap-2">
                                <Info size={16} style={{ color: 'var(--color-primary)', marginTop: '2px' }} />
                                <div className="text-sm">
                                    <p className="font-medium mb-1" style={{ color: 'var(--color-primary)' }}>{t('bulkImport.formatTitle')}</p>
                                    <ul className="space-y-1 text-xs" style={{ color: 'var(--color-text-secondary)' }}>
                                        <li>• {t('bulkImport.urlOnly')}: <code>https://example.com</code></li>
                                        <li>• {t('bulkImport.nameAndUrl')}: <code>{t('bulkImport.mySource')}|https://example.com</code></li>
                                        <li>• {t('bulkImport.onePerLine')}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        {/* Textarea */}
                        <div>
                            <label className="form-label">{t('bulkImport.listLabel')}</label>
                            <textarea
                                value={lines}
                                onChange={(e) => setLines(e.target.value)}
                                className="form-input resize-none font-mono text-sm"
                                rows={10}
                                placeholder={`https://example1.com\nhttps://example2.com\n${t('bulkImport.mySource')}|https://example3.com`}
                                disabled={importing}
                            />
                            <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>
                                {lines.split('\n').filter(l => l.trim()).length} {t('bulkImport.linesSuffix')}
                            </p>
                        </div>

                        {/* Result */}
                        {result && (
                            <div className="p-3 rounded-xl" style={{ backgroundColor: 'var(--color-bg-soft)', border: '1px solid var(--color-border)' }}>
                                <p className="font-medium text-sm mb-2">{t('bulkImport.resultTitle')}</p>
                                <div className="space-y-1 text-sm">
                                    <p className="text-green-600">✓ {t('bulkImport.added')}: {result.imported}</p>
                                    {result.duplicates > 0 && (
                                        <p className="text-yellow-600">⚠ {t('bulkImport.duplicates')}: {result.duplicates}</p>
                                    )}
                                    {result.errors && result.errors.length > 0 && (
                                        <div>
                                            <p className="text-red-600">✗ {t('bulkImport.errors')}: {result.errors.length}</p>
                                            <div className="max-h-24 overflow-y-auto mt-1 text-xs">
                                                {result.errors.slice(0, 10).map((err, i) => (
                                                    <p key={i} style={{ color: 'var(--color-text-muted)' }}>
                                                        {err.line || ''}: {err.error}
                                                    </p>
                                                ))}
                                                {result.errors.length > 10 && (
                                                    <p style={{ color: 'var(--color-text-muted)' }}>
                                                        ...{t('bulkImport.andMore')} {result.errors.length - 10}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Footer */}
                <div className="modal-footer">
                    <button onClick={handleClose} className="btn btn-secondary" disabled={importing}>
                        {result && result.imported > 0 ? t('common.close') : t('common.cancel')}
                    </button>
                    <button onClick={handleImport} className="btn btn-primary" disabled={importing || !lines.trim()}>
                        <Upload size={18} />
                        <span>{importing ? t('bulkImport.importing') : t('bulkImport.importBtn')}</span>
                    </button>
                </div>
            </div>
        </div>
    );
};

export default BulkImportSources;
