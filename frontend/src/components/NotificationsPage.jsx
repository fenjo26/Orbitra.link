import React, { useEffect, useState } from 'react';
import { Bell, Send } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { canAccessTab } from '../utils/permissions';
import SegmentedControl from './common/SegmentedControl';
import PanelPushCard from './PanelPushCard';
import TelegramNotificationsPanel from './TelegramNotificationsPanel';

// Notifications hub. Two audiences, two subtabs:
//  - "Push на телефон" — the operator's own devices (web push), personal,
//    every signed-in user gets it;
//  - "Telegram для команды" — the admin bot's chats and digest schedule,
//    team-wide, admin-only (hidden, not disabled, for everyone else).
// The bot CONNECTION (token, webhook/polling) stays in Integrations: that is
// an external service; this page is where "what do I get notified about"
// lives.

export default function NotificationsPage({ user, subTabHint }) {
    const { t } = useLanguage();
    const isAdmin = user?.role === 'admin';
    const [subTab, setSubTab] = useState(isAdmin ? 'push' : 'push');

    // Deep links (?sub=telegram) and the cross-page navigation hint both
    // land here; telegram is admin-only so a non-admin deep link stays on
    // push.
    useEffect(() => {
        if (subTabHint === 'telegram' && isAdmin) setSubTab('telegram');
        if (subTabHint === 'push') setSubTab('push');
    }, [subTabHint, isAdmin]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const sub = params.get('sub');
        if (sub === 'telegram' && isAdmin) setSubTab('telegram');
    }, [isAdmin]);

    if (user && !canAccessTab(user, 'notifications')) {
        return <div className="page-card"><div className="empty-state">{t('push.noAccess')}</div></div>;
    }

    return (
        <div className="page-card">
            <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0, flexDirection: 'column', alignItems: 'flex-start', gap: 10 }}>
                <div style={{ maxWidth: 420, width: '100%' }}>
                    <SegmentedControl
                        value={subTab}
                        onChange={(v) => setSubTab(v)}
                        ariaLabel={t('notifications.navLabel')}
                        options={[
                            { value: 'push', label: t('notifications.tabPush'), icon: Bell },
                            ...(isAdmin ? [{ value: 'telegram', label: t('notifications.tabTelegram'), icon: Send }] : []),
                        ]}
                    />
                </div>
                <p style={{ fontSize: '13px', color: 'var(--color-text-muted)', margin: 0 }}>
                    {subTab === 'push' ? t('notifications.pushIntro') : t('notifications.tgIntro')}
                </p>
            </div>

            <div style={{ marginTop: 16 }}>
                {subTab === 'push' ? (
                    <PanelPushCard embedded user={user} />
                ) : (
                    isAdmin ? <TelegramNotificationsPanel /> : null
                )}
            </div>
        </div>
    );
}
