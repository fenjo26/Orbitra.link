import React, { useEffect, useRef, useState } from 'react';
import { RefreshCw, X } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

/**
 * Registers the panel service worker (sw.js at the domain root) and surfaces
 * a "new version available — reload" prompt when a freshly deployed worker is
 * waiting. The new bundle is never swapped under the user mid-session: the
 * waiting worker takes over only after the user taps Reload (SKIP_WAITING →
 * controllerchange → reload).
 */
const UpdateToast = () => {
    const { t } = useLanguage();
    const [waitingWorker, setWaitingWorker] = useState(null);
    const [dismissed, setDismissed] = useState(false);
    const reloadingRef = useRef(false);

    useEffect(() => {
        if (!('serviceWorker' in navigator)) return undefined;
        // Service workers (and PWA install) require a secure context. Panels
        // on plain-HTTP IPs quietly skip this — the app works, just uncached.
        if (!window.isSecureContext
            && !['localhost', '127.0.0.1'].includes(window.location.hostname)) {
            return undefined;
        }

        let registration = null;

        // First activation (clients.claim) also fires controllerchange — only
        // reload when we asked a waiting worker to take over.
        const onControllerChange = () => {
            if (reloadingRef.current) window.location.reload();
        };
        navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);

        const watchWaiting = (reg) => {
            if (reg.waiting && navigator.serviceWorker.controller) {
                setWaitingWorker(reg.waiting);
            }
        };

        // ?panel= tells the worker which URL is the panel shell (see sw.js):
        // /admin.php or the secret admin path.
        const panelParam = encodeURIComponent(window.location.pathname || '/admin.php');
        navigator.serviceWorker.register(`/sw.js?panel=${panelParam}`, { scope: '/' })
            .then((reg) => {
                registration = reg;
                watchWaiting(reg);
                reg.addEventListener('updatefound', () => {
                    const worker = reg.installing;
                    if (!worker) return;
                    worker.addEventListener('statechange', () => {
                        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                            setWaitingWorker(worker);
                        }
                    });
                });
            })
            .catch(() => {
                // Registration is best-effort — the panel works without it.
            });

        // Look for updates when the app comes back to the foreground, so a
        // deploy that happened while the PWA was backgrounded is noticed.
        const onVisible = () => {
            if (document.visibilityState === 'visible' && registration) {
                registration.update().catch(() => {});
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, []);

    const applyUpdate = () => {
        if (!waitingWorker) return;
        reloadingRef.current = true;
        waitingWorker.postMessage('SKIP_WAITING');
    };

    if (!waitingWorker || dismissed) return null;

    return (
        <div
            role="status"
            className="fixed bottom-4 left-4 right-4 sm:left-auto sm:right-6 sm:bottom-6 z-[2500] flex items-center gap-3 rounded-2xl border shadow-2xl px-4 py-3"
            style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}
        >
            <RefreshCw className="w-5 h-5 flex-shrink-0" style={{ color: 'var(--color-primary)' }} />
            <span className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>
                {t('pwa.updateAvailable')}
            </span>
            <div className="flex items-center gap-2 ml-auto flex-shrink-0">
                <button
                    type="button"
                    onClick={applyUpdate}
                    className="btn btn-primary text-xs py-2.5 px-4 rounded-xl font-semibold"
                    style={{ minHeight: 44 }}
                >
                    {t('pwa.reload')}
                </button>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    className="p-2 rounded-lg"
                    style={{ color: 'var(--color-text-muted)', minHeight: 44, minWidth: 44 }}
                    aria-label={t('common.close')}
                >
                    <X className="w-4 h-4" />
                </button>
            </div>
        </div>
    );
};

export default UpdateToast;
