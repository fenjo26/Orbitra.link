import React, { useState, useEffect, useRef } from 'react';
import { Save, X, Upload, FileText, Code, Check } from 'lucide-react';
import axios from 'axios';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const LandingEditor = ({ landingId, onClose }) => {
    const { t } = useLanguage();
    const [landing, setLanding] = useState({
        name: '',
        group_id: '',
        type: 'local',
        url: '',
        action_payload: '',
        state: 'active'
    });
    const [groups, setGroups] = useState([]);
    const [loading, setLoading] = useState(false);

    // Local Landing File Management State
    const [files, setFiles] = useState([]);
    const [selectedFile, setSelectedFile] = useState(null);
    const [fileContent, setFileContent] = useState('');
    const [savingFile, setSavingFile] = useState(false);
    const [uploadingZip, setUploadingZip] = useState(false);
    const fileInputRef = useRef(null);

    useEffect(() => {
        const fetchInitialData = async () => {
            setLoading(true);
            try {
                const [groupsRes] = await Promise.all([
                    axios.get(`${API_URL}?action=landing_groups`)
                ]);
                if (groupsRes.data.status === 'success') {
                    setGroups(groupsRes.data.data);
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
                                <select
                                    value={landing.type}
                                    onChange={e => setLanding({ ...landing, type: e.target.value })}
                                    className="form-select font-medium"
                                    style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}
                                >
                                    <option value="local">{t('landingEditor.localZip')}</option>
                                    <option value="redirect">{t('landingEditor.redirect')}</option>
                                    <option value="preload">{t('landingEditor.preload')}</option>
                                    <option value="action">{t('landingEditor.action')}</option>
                                </select>
                            </div>

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
                                <div>
                                    <label className="form-label">{t('landingEditor.actionPayloadLabel')}</label>
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
                                    {files.length === 0 ? (
                                        <div className="p-4 text-sm text-center italic" style={{ color: 'var(--color-text-muted)' }}>{t('landingEditor.selectFile')}</div>
                                    ) : (
                                        <ul className="py-2">
                                            {files.map(file => (
                                                <li key={file}>
                                                    <button
                                                        onClick={() => loadFileContent(file)}
                                                        className={`w-full text-left px-4 py-2 text-sm flex items-center transition ${selectedFile === file ? 'font-medium' : ''}`}
                                                        style={{
                                                            backgroundColor: selectedFile === file ? 'var(--color-primary-light)' : 'transparent',
                                                            color: selectedFile === file ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                                            borderRight: selectedFile === file ? '2px solid var(--color-primary)' : 'none'
                                                        }}
                                                    >
                                                        <FileText className="w-3.5 h-3.5 mr-2" style={{ color: 'var(--color-text-muted)' }} />
                                                        <span className="truncate" title={file}>{file}</span>
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