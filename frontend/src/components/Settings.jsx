import React, { useEffect, useState } from 'react';
import { Settings as SettingsIcon, User, ShieldBan, RefreshCw, BarChart2, HardDrive, Shield, Clock } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

import GeneralSettings from './GeneralSettings';
import ProfileSettings from './ProfileSettings';
import BotSettings from './BotSettings';
import ConversionTypesSettings from './ConversionTypesSettings';
import CustomMetricsSettings from './CustomMetricsSettings';
import AutomationSettings from './AutomationSettings';
import SystemSettings from './SystemSettings';
import PrivacySettings from './PrivacySettings';

class TabErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }
    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }
    componentDidCatch(error, info) {
        console.error('[Settings tab crash]', error, info);
    }
    componentDidUpdate(prevProps) {
        if (prevProps.tabKey !== this.props.tabKey) {
            this.setState({ hasError: false, error: null });
        }
    }
    render() {
        if (this.state.hasError) {
            return (
                <div className="page-card" style={{ color: '#b91c1c' }}>
                    <h3 style={{ margin: '0 0 8px' }}>⚠ Render Error (v3)</h3>
                    <pre style={{ whiteSpace: 'pre-wrap', fontSize: 13, background: '#fef2f2', padding: 12, borderRadius: 6 }}>
                        {String(this.state.error)}
                    </pre>
                    <button className="btn btn-secondary" style={{ marginTop: 12 }}
                        onClick={() => this.setState({ hasError: false, error: null })}>
                        Retry
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}

const Settings = () => {
    const { t } = useLanguage();
    const [activeTab, setActiveTab] = useState('general');

    const tabs = [
        { id: 'general', title: t('settings.general'), icon: SettingsIcon, component: GeneralSettings },
        { id: 'profile', title: t('settings.profile'), icon: User, component: ProfileSettings },
        { id: 'bots', title: t('settings.bots'), icon: ShieldBan, component: BotSettings },
        { id: 'conversions', title: t('settings.conversionTypes'), icon: RefreshCw, component: ConversionTypesSettings },
        { id: 'metrics', title: t('settings.customMetrics'), icon: BarChart2, component: CustomMetricsSettings },
        { id: 'automation', title: t('settings.automation'), icon: Clock, component: AutomationSettings },
        { id: 'system', title: t('settings.system'), icon: HardDrive, component: SystemSettings },
        { id: 'privacy', title: t('settings.privacy'), icon: Shield, component: PrivacySettings },
    ];

    const activeTabObj = tabs.find(t => t.id === activeTab) || tabs[0];
    const ActiveComponent = activeTabObj.component;

    useEffect(() => {
        // Allow other pages to request a specific settings tab (e.g. Backorder -> Automation).
        const requested = localStorage.getItem('orbitra_settings_tab') || '';
        if (!requested) return;
        if (tabs.some(x => x.id === requested)) {
            setActiveTab(requested);
        }
        localStorage.removeItem('orbitra_settings_tab');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div style={{ display: 'flex', flexDirection: 'row', gap: '24px' }}>
            {/* Sidebar */}
            <div style={{ width: '240px', flexShrink: 0 }}>
                <div className="page-card" style={{ padding: 0 }}>
                    <nav style={{ display: 'flex', flexDirection: 'column' }}>
                        {tabs.map(tab => {
                            const Icon = tab.icon;
                            const isActive = activeTab === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: '12px',
                                        padding: '14px 16px',
                                        textAlign: 'left',
                                        background: isActive ? 'var(--color-primary-light)' : 'transparent',
                                        color: isActive ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                        fontWeight: isActive ? 500 : 400,
                                        border: 'none',
                                        borderLeft: isActive ? '3px solid var(--color-primary)' : '3px solid transparent',
                                        cursor: 'pointer',
                                        transition: 'all 0.2s ease',
                                        fontSize: '14px'
                                    }}
                                >
                                    <Icon size={18} style={{ color: isActive ? 'var(--color-primary)' : 'var(--color-text-muted)' }} />
                                    <span>{tab.title}</span>
                                </button>
                            );
                        })}
                    </nav>
                </div>
            </div>

            {/* Content Area */}
            <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ marginBottom: '16px' }}>
                    <h2 style={{ fontSize: '18px', fontWeight: 600, color: 'var(--color-text-primary)' }}>
                        {activeTabObj.title}
                    </h2>
                </div>
                <TabErrorBoundary tabKey={activeTab}>
                    <ActiveComponent />
                </TabErrorBoundary>
            </div>
        </div>
    );
};

export default Settings;
