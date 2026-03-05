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

    const handleAddIps = async () => {
        if (!newIps.trim()) return;
        try {
            const items = newIps.split('\n').map(s => s.trim()).filter(Boolean);
            const res = await fetch(`${API_URL}?action=bot_ips`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setNewIps('');
                fetchData();
                alert(`${t('botSettings.addedCount')} ${data.count || items.length}`);
            }
        } catch (e) {
            alert(t('botSettings.networkError'));
        }
    };

    const handleAddSigs = async () => {
        if (!newSigs.trim()) return;
        try {
            const items = newSigs.split('\n').map(s => s.trim()).filter(Boolean);
            const res = await fetch(`${API_URL}?action=bot_signatures`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items })
            });
            const data = await res.json();
            if (data.status === 'success') {
                setNewSigs('');
                fetchData();
                alert(`${t('botSettings.addedCount')} ${data.count || items.length}`);
            }
        } catch (e) {
            alert(t('botSettings.networkError'));
        }
    };

    const handleDelete = async (type, id) => {
        try {
            const action = type === 'ip' ? 'bot_ips' : 'bot_signatures';
            await fetch(`${API_URL}?action=${action}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            fetchData();
        } catch (e) {
            alert(t('botSettings.deleteError'));
        }
    };

    const handleClear = async (type) => {
        if (!window.confirm(t('botSettings.confirmClear'))) return;
        try {
            const action = type === 'ip' ? 'bot_ips' : 'bot_signatures';
            const res = await fetch(`${API_URL}?action=${action}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ clear_all: true })
            });
            const data = await res.json();
            if (data.status === 'success') {
                alert(t('botSettings.cleared'));
                fetchData();
            }
        } catch (e) {
            alert(t('botSettings.clearError'));
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