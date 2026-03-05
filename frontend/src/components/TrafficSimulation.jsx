import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Play, Activity } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const TrafficSimulation = () => {
    const { t } = useLanguage();
    const [campaigns, setCampaigns] = useState([]);
    const [formData, setFormData] = useState({
        campaign_id: '',
        ip: '192.168.1.1',
        user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        country: 'US',
        device_type: 'desktop'
    });
    const [trace, setTrace] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        axios.get(`${API_URL}?action=campaigns`)
            .then(res => {
                if (res.data.status === 'success') {
                    setCampaigns(res.data.data);
                    if (res.data.data.length > 0) {
                        setFormData(prev => ({ ...prev, campaign_id: res.data.data[0].id }));
                    }
                }
            });
    }, []);

    const handleSimulate = async (e) => {
        e.preventDefault();
        setLoading(true);
        setTrace(null);
        try {
            const res = await axios.post(`${API_URL}?action=simulate_traffic`, formData);
            if (res.data.status === 'success') {
                setTrace(res.data.trace);
            } else {
                alert(res.data.message);
            }
        } catch (err) {
            alert(t('common.networkError') + ': ' + err.message);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="page-card">
            <div className="page-header">
                <div className="flex items-center gap-2">
                    <Activity className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                    <h2 className="page-title">{t('simulation.title')}</h2>
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '32px' }}>
                {/* Form */}
                <div style={{ maxWidth: '400px' }}>
                    <form onSubmit={handleSimulate} className="form-section">
                        <div>
                            <label className="form-label">{t('simulation.campaign')}</label>
                            <select
                                value={formData.campaign_id}
                                onChange={e => setFormData({ ...formData, campaign_id: e.target.value })}
                                className="form-select"
                                required
                            >
                                {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="form-label">{t('simulation.ipAddress')}</label>
                            <input
                                type="text"
                                value={formData.ip}
                                onChange={e => setFormData({ ...formData, ip: e.target.value })}
                                className="form-input"
                                required
                            />
                        </div>
                        <div>
                            <label className="form-label">{t('simulation.userAgent')}</label>
                            <input
                                type="text"
                                value={formData.user_agent}
                                onChange={e => setFormData({ ...formData, user_agent: e.target.value })}
                                className="form-input"
                                required
                            />
                        </div>
                        <div>
                            <label className="form-label">{t('simulation.geoCode')}</label>
                            <input
                                type="text"
                                value={formData.country}
                                onChange={e => setFormData({ ...formData, country: e.target.value })}
                                className="form-input"
                                required
                            />
                        </div>
                        <div>
                            <label className="form-label">{t('simulation.deviceType')}</label>
                            <select
                                value={formData.device_type}
                                onChange={e => setFormData({ ...formData, device_type: e.target.value })}
                                className="form-select"
                            >
                                <option value="desktop">{t('simulation.desktop')}</option>
                                <option value="mobile">{t('simulation.mobile')}</option>
                                <option value="tablet">{t('simulation.tablet')}</option>
                            </select>
                        </div>
                        <button type="submit" disabled={loading} className="btn btn-primary" style={{ width: '100%' }}>
                            <Play className="w-4 h-4" />
                            {loading ? t('simulation.simulating') : t('simulation.runTest')}
                        </button>
                    </form>
                </div>

                {/* Result Trace */}
                <div style={{ flex: 1 }}>
                    <h3 style={{
                        fontSize: '14px',
                        fontWeight: 600,
                        color: 'var(--color-text-primary)',
                        textTransform: 'uppercase',
                        marginBottom: '12px',
                        paddingBottom: '8px',
                        borderBottom: '1px solid var(--color-border)'
                    }}>
                        {t('simulation.resultTitle')}
                    </h3>
                    <div style={{
                        background: '#1a1a1a',
                        borderRadius: '16px',
                        padding: '16px',
                        height: '384px',
                        overflowY: 'auto',
                        fontFamily: 'monospace',
                        fontSize: '13px',
                        color: '#4ade80'
                    }}>
                        {trace === null ? (
                            <div style={{
                                color: 'var(--color-text-muted)',
                                display: 'flex',
                                height: '100%',
                                alignItems: 'center',
                                justifyContent: 'center'
                            }}>
                                {t('simulation.runToSee')}
                            </div>
                        ) : (
                            trace.map((line, idx) => (
                                <div
                                    key={idx}
                                    style={{
                                        color: line.includes('=> MATCHED') ? '#fbbf24' : (line.includes('[Filter Failed]') ? '#f87171' : '#4ade80'),
                                        fontWeight: line.includes('=> MATCHED') ? 600 : 400,
                                        padding: '2px 0'
                                    }}
                                >
                                    {line}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default TrafficSimulation;