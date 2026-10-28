import React, { useEffect, useState } from 'react';
import { ShieldAlert, ChevronDown, ChevronUp, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';
// Dismissal is scoped to advisory + level: a future advisory (or a level
// change after the operator upgraded) re-opens the banner instead of
// inheriting an old "closed" verdict.
const dismissKey = (info) => `orbitra_sys_digest_${info.advisory}_${info.level}`;

// October 2026 system-package advisory banner. The tracker's own vhost
// dropped the affected alias directive in this release (ORB-016) — what
// remains is the OPERATING SYSTEM half: the nginx package, the kernel and
// the CA trust store, which the panel cannot update for the operator. The
// level comes from core/security_digest.php via action=security_digest;
// distro builds hide the backported patch inside an older version number,
// which is why the banner is dismissible rather than permanent. Mirrors
// PhpSecurityBanner: dashboard notification stack, hides itself once the
// server reports a fixed nginx.
const SecurityDigestBanner = () => {
    const { t } = useLanguage();
    const [info, setInfo] = useState(null);
    const [expanded, setExpanded] = useState(false);

    useEffect(() => {
        fetch(`${API_URL}?action=security_digest`)
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success' || !d.data || d.data.level === 'ok') return;
                try {
                    if (localStorage.getItem(dismissKey(d.data)) === '1') return;
                } catch { /* private mode — show the banner */ }
                setInfo(d.data);
            })
            .catch(() => { /* unknown state — render nothing */ });
    }, []);

    if (!info) return null;

    const close = () => {
        setInfo(null);
        try { localStorage.setItem(dismissKey(info), '1'); } catch { /* private mode */ }
    };

    return (
        <div className="mb-4 bg-[var(--color-danger-bg)] border border-[var(--color-danger-border)] rounded-lg p-4 flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
                <ShieldAlert className="w-6 h-6 text-[var(--color-danger)] flex-shrink-0" />
                <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('securityDigest.title')}</span>
                    <div className="mt-1 text-[var(--color-text-secondary)] text-sm">
                        {info.level === 'unknown'
                            ? t('securityDigest.textUnknown')
                            : t('securityDigest.text', { version: info.nginx_version, fixed_in: info.fixed_in || '' })}
                    </div>
                    <button
                        type="button"
                        onClick={() => setExpanded(v => !v)}
                        className="mt-3 btn btn-secondary flex items-center gap-2 text-xs"
                    >
                        {expanded ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
                        {t('securityDigest.howToFix')}
                    </button>
                    {expanded && (
                        <div className="mt-3">
                            <div className="text-sm text-[var(--color-text-secondary)]">{t('securityDigest.aptNote')}</div>
                            {/* Paste-safe block, same discipline as the PHP banner:
                                -y removes the Continue? prompt, NEEDRESTART_MODE=a
                                silences the "which services to restart" dialog that
                                would swallow the next line as its answer. A full
                                upgrade (not --only-upgrade) is deliberate: the
                                kernel and ca-certificates ride in the same pass. */}
                            <code className="block mt-2 px-3 py-2 rounded-lg text-xs font-mono whitespace-pre-wrap"
                                style={{ backgroundColor: 'var(--color-bg-hover)', color: 'var(--color-text-primary)' }}>
{`apt update
NEEDRESTART_MODE=a apt upgrade -y
systemctl reload nginx
nginx -v`}
                            </code>
                            <div className="mt-2 text-sm text-[var(--color-text-secondary)]">{t('securityDigest.rebootNote')}</div>
                            <div className="mt-1 text-sm text-[var(--color-text-secondary)]">{t('securityDigest.distroNote')}</div>
                        </div>
                    )}
                </div>
            </div>
            <button
                onClick={close}
                aria-label={t('common.close')}
                className="p-1 hover:bg-[var(--color-bg-hover)] rounded flex-shrink-0"
            >
                <X className="w-5 h-5 text-[var(--color-danger)]" />
            </button>
        </div>
    );
};

export default SecurityDigestBanner;
