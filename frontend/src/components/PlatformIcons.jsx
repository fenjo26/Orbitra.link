import React from 'react';
import { Bot, Globe } from 'lucide-react';
import BRAND_ICONS from './brandIcons';
import { describeOs, describeBrowser } from './uaLabels';

// OS / browser logos for click tables. Input is the `ua` object the API
// attaches to each traffic-log row (core/UserAgent.php → orbitraParseUserAgent),
// with the stored coarse os / browser columns as a fallback.

const SIZE = 16;

const BrandSvg = ({ slug, label, size = SIZE }) => {
    const icon = BRAND_ICONS[slug];
    if (!icon) return null;
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" role="img" aria-label={label}
            style={{ color: icon.color || 'var(--color-text-secondary)', flexShrink: 0 }}>
            <path fill="currentColor" d={icon.d} />
        </svg>
    );
};

// Four panes — a generic window glyph for Windows (Simple Icons carries no
// Microsoft marks).
const WindowsSvg = ({ label, size = SIZE }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" role="img" aria-label={label} style={{ color: '#00A4EF', flexShrink: 0 }}>
        <path fill="currentColor" d="M1 1h10.5v10.5H1zM12.5 1H23v10.5H12.5zM1 12.5h10.5V23H1zM12.5 12.5H23V23H12.5z" />
    </svg>
);

// Lettered disc for browsers without a CC0 mark (Edge, Yandex, UC, IE).
const LetterBadge = ({ letter, color, label, size = SIZE }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" role="img" aria-label={label} style={{ flexShrink: 0 }}>
        <circle cx="12" cy="12" r="11" fill={color} />
        <text x="12" y="16.5" textAnchor="middle" fontSize={letter.length > 1 ? 9 : 13} fontWeight="700"
            fontFamily="system-ui, sans-serif" fill="#fff">{letter}</text>
    </svg>
);

const Wrap = ({ title, children }) => (
    <span className="inline-flex items-center justify-center" title={title}>{children}</span>
);

const Unknown = ({ title }) => (
    <Wrap title={title}><Globe size={SIZE} className="text-[var(--color-text-muted)]" aria-label={title} /></Wrap>
);

export const OsIcon = ({ ua, fallbackOs, unknownLabel = '' }) => {
    const u = ua || {};
    const name = (u.os_name || u.os || fallbackOs || '').toLowerCase();
    const title = describeOs(u, fallbackOs) || unknownLabel;
    let icon = null;
    if (name.startsWith('windows')) icon = <WindowsSvg label={title} />;
    else if (name === 'ios' || name === 'ipados' || name === 'macos') icon = <BrandSvg slug="apple" label={title} />;
    else if (name === 'android') icon = <BrandSvg slug="android" label={title} />;
    else if (name === 'harmonyos') icon = <BrandSvg slug="harmonyos" label={title} />;
    else if (name === 'chromeos') icon = <BrandSvg slug="googlechrome" label={title} />;
    else if (name.startsWith('linux')) icon = <BrandSvg slug="linux" label={title} />;
    return icon ? <Wrap title={title}>{icon}</Wrap> : <Unknown title={title} />;
};

const IN_APP_SLUGS = {
    Facebook: 'facebook', Messenger: 'messenger', Instagram: 'instagram', TikTok: 'tiktok',
    Snapchat: 'snapchat', Telegram: 'telegram', WeChat: 'wechat', LINE: 'line',
    Pinterest: 'pinterest', 'X (Twitter)': 'x',
};

export const BrowserIcon = ({ ua, fallbackBrowser, unknownLabel = '' }) => {
    const u = ua || {};
    const title = describeBrowser(u, fallbackBrowser) || unknownLabel;
    if (u.bot || u.browser_name === 'Chrome Headless') {
        return <Wrap title={title}><Bot size={SIZE} style={{ color: 'var(--color-warning, #f59e0b)' }} aria-label={title} /></Wrap>;
    }
    if (u.in_app && IN_APP_SLUGS[u.in_app]) {
        return <Wrap title={title}><BrandSvg slug={IN_APP_SLUGS[u.in_app]} label={title} /></Wrap>;
    }
    const name = u.browser_name || u.browser || fallbackBrowser || '';
    let icon = null;
    if (name.startsWith('Edge')) icon = <LetterBadge letter="e" color="#0C8CE9" label={title} />;
    else if (name.startsWith('Chrome')) icon = <BrandSvg slug="googlechrome" label={title} />;
    else if (name.startsWith('Safari')) icon = <BrandSvg slug="safari" label={title} />;
    else if (name.startsWith('Firefox')) icon = <BrandSvg slug="firefoxbrowser" label={title} />;
    else if (name.startsWith('Opera')) icon = <BrandSvg slug="opera" label={title} />;
    // The Samsung mark is a wordmark, unreadable at 16px — a lettered disc instead.
    else if (name.startsWith('Samsung')) icon = <LetterBadge letter="S" color="#6E5BF5" label={title} />;
    else if (name === 'Yandex Browser') icon = <LetterBadge letter="Y" color="#FC3F1D" label={title} />;
    else if (name === 'UC Browser') icon = <LetterBadge letter="UC" color="#FF7A00" label={title} />;
    else if (name === 'Mi Browser') icon = <BrandSvg slug="xiaomi" label={title} />;
    else if (name === 'Huawei Browser') icon = <BrandSvg slug="huawei" label={title} />;
    else if (name === 'DuckDuckGo') icon = <BrandSvg slug="duckduckgo" label={title} />;
    else if (name === 'Vivaldi') icon = <BrandSvg slug="vivaldi" label={title} />;
    else if (name === 'Brave') icon = <BrandSvg slug="brave" label={title} />;
    else if (name === 'Internet Explorer') icon = <LetterBadge letter="e" color="#1EBBEE" label={title} />;
    return icon ? <Wrap title={title}>{icon}</Wrap> : <Unknown title={title} />;
};
