import React, { useState, useEffect } from 'react';
import { ShieldBan, Plus, Trash2, RotateCcw } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const BotSettings = () => {
    const { t } = useLanguage();

    const [ipList, setIpList] = useState([]);
    const [sigList, setSigList] = useState([]);
    const [loading, setLoading] = useState(true);
    const [newIps, setNewIps] = useState('');
    const [newSigs, setNewSigs] = useState('');

    const fetchData = async () => {
        setLoading(true);
        try {
            const [ipRes, sigRes] = await Promise.all([
                fetch(`${API_URL}?action=bot_ips`).then(r => r.json()),
                fetch(`${API_URL}?action=bot_signatures`).then(r => r.json()),
            ]);
            if (ipRes.status === 'success') setIpList(ipRes.data || []);
            if (sigRes.status === 'success') setSigList(sigRes.data || []);
        } catch (e) {
            alert(t('botSettings.loadError'));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, []);

    // One place that speaks the API's contract, so a mismatch cannot creep back
    // into three separate handlers. Anything but an explicit success is surfaced:
    // these operations used to fail silently while still reporting success.
    const mutate = async (type, payload) => {
        const action = type === 'ip' ? 'bot_ips' : 'bot_signatures';
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || data?.status !== 'success') {
            throw new Error(data?.message || `HTTP ${res.status}`);
        }
        return data;
    };

    const handleAdd = async (type) => {
        const source = type === 'ip' ? newIps : newSigs;
        if (!source.trim()) return;
        const items = source.split('\n').map(s => s.trim()).filter(Boolean);
        try {
            const data = await mutate(type, { items });
            (type === 'ip' ? setNewIps : setNewSigs)('');
            fetchData();
            const skipped = data.skipped || 0;
            alert(`${t('botSettings.addedCount')} ${data.added ?? 0}`
                + (skipped ? ` (${t('botSettings.skippedDuplicates')} ${skipped})` : ''));
        } catch (e) {
            alert(`${t('botSettings.networkError')}: ${e.message}`);
        }
    };

    const handleAddIps = () => handleAdd('ip');
    const handleAddSigs = () => handleAdd('sig');

    const handleDelete = async (type, id) => {
        try {
            await mutate(type, { action: 'delete', id });
            fetchData();
        } catch (e) {
            alert(`${t('botSettings.deleteError')}: ${e.message}`);
        }
    };

    const handleClear = async (type) => {
        if (!window.confirm(t('botSettings.confirmClear'))) return;
        try {
            await mutate(type, { action: 'clear_all' });
            alert(t('botSettings.cleared'));
            fetchData();
        } catch (e) {
            alert(`${t('botSettings.clearError')}: ${e.message}`);
        }
    };

    if (loading) {
        return (
            <div className="page-card">
                <p style={{ color: 'var(--color-text-muted)' }}>{t('botSettings.loading')}</p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* IP Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <ShieldBan size={18} style={{ color: 'var(--color-primary)' }} />
                        <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.ipTitle')}</h3>
                    </div>
                    <button onClick={() => handleClear('ip')} className="btn btn-ghost btn-sm">
                        <RotateCcw size={14} />
                        {t('botSettings.clearAll')}
                    </button>
                </div>

                <div style={{ marginTop: '16px' }}>
                    <textarea
                        value={newIps}
                        onChange={(e) => setNewIps(e.target.value)}
                        placeholder={t('botSettings.ipPlaceholder')}
                        rows={4}
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    <button onClick={handleAddIps} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                        <Plus size={14} />
                        {t('botSettings.addIp')}
                    </button>
                </div>

                <div style={{ marginTop: '16px', maxHeight: '300px', overflowY: 'auto' }}>
                    {ipList.length === 0 ? (
                        <p style={{ color: 'var(--color-text-muted)', fontSize: '14px' }}>{t('botSettings.noRecords')}</p>
                    ) : (
                        <table className="page-table">
                            <tbody>
                                {ipList.map(item => (
                                    <tr key={item.id}>
                                        <td style={{ fontFamily: 'monospace', fontSize: '13px' }}>{item.value || item.ip}</td>
                                        <td style={{ width: '40px', textAlign: 'right' }}>
                                            <button onClick={() => handleDelete('ip', item.id)} className="btn btn-ghost btn-sm" style={{ color: 'var(--color-danger)' }}>
                                                <Trash2 size={14} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {/* Signatures Section */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <h3 className="page-title" style={{ margin: 0 }}>{t('botSettings.signaturesTitle')}</h3>
                    <button onClick={() => handleClear('sig')} className="btn btn-ghost btn-sm">
                        <RotateCcw size={14} />
                        {t('botSettings.clearAll')}
                    </button>
                </div>

                <div style={{ marginTop: '16px' }}>
                    <textarea
                        value={newSigs}
                        onChange={(e) => setNewSigs(e.target.value)}
                        placeholder={t('botSettings.signaturePlaceholder')}
                        rows={4}
                        className="form-input"
                        style={{ fontFamily: 'monospace', fontSize: '13px' }}
                    />
                    <button onClick={handleAddSigs} className="btn btn-primary btn-sm" style={{ marginTop: '8px' }}>
                        <Plus size={14} />
                        {t('botSettings.addSignature')}
                    </button>
                </div>

                <div style={{ marginTop: '16px', maxHeight: '300px', overflowY: 'auto' }}>
                    {sigList.length === 0 ? (
                        <p style={{ color: 'var(--color-text-muted)', fontSize: '14px' }}>{t('botSettings.noRecords')}</p>
                    ) : (
                        <table className="page-table">
                            <tbody>
                                {sigList.map(item => (
                                    <tr key={item.id}>
                                        <td style={{ fontFamily: 'monospace', fontSize: '13px' }}>{item.value || item.signature}</td>
                                        <td style={{ width: '40px', textAlign: 'right' }}>
                                            <button onClick={() => handleDelete('sig', item.id)} className="btn btn-ghost btn-sm" style={{ color: 'var(--color-danger)' }}>
                                                <Trash2 size={14} />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
};

export default BotSettings;