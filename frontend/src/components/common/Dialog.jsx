import React, { useEffect } from 'react';
import { AlertTriangle, Info } from 'lucide-react';

/**
 * Themed replacement for window.confirm / window.alert on the flows that
 * had them (Check DNS, Issue SSL, the campaign editor's unsaved-changes
 * guard). The native dialogs are browser chrome: unthemeable, white against
 * the dark themes, and they block the main thread while the theme they
 * ignore is doing its work. This renders in the panel's own modal layer
 * (z 2000 on the overlay ladder) with the panel's variables.
 *
 * tone 'confirm' → two buttons, onConfirm / onClose (cancel);
 * tone 'alert'   → one button, onClose.
 */
const Dialog = ({
    open,
    tone = 'confirm',
    title,
    message,
    confirmLabel,
    cancelLabel,
    busy = false,
    danger = false,
    onConfirm,
    onClose,
}) => {
    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => {
            if (e.key === 'Escape' && !busy) onClose?.();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, busy, onClose]);

    if (!open) return null;

    const isAlert = tone === 'alert';

    return (
        <div
            className="fixed inset-0 z-[2000] bg-black/50 flex items-center justify-center p-4"
            onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onClose?.(); }}
        >
            <div
                role={isAlert ? 'alertdialog' : 'dialog'}
                aria-modal="true"
                className="bg-[var(--color-bg-card)] border border-[var(--color-border)] rounded-xl shadow-2xl w-full max-w-md p-6"
            >
                <div className="flex items-start gap-3">
                    <div
                        className="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                        style={{
                            backgroundColor: danger ? 'var(--color-danger-bg)' : 'var(--color-primary-light)',
                            color: danger ? 'var(--color-danger)' : 'var(--color-primary)',
                        }}
                    >
                        {isAlert ? <Info size={20} /> : <AlertTriangle size={20} />}
                    </div>
                    <div className="min-w-0">
                        {title && (
                            <h3 className="text-lg font-semibold mb-1" style={{ color: 'var(--color-text-primary)' }}>
                                {title}
                            </h3>
                        )}
                        <p className="text-sm whitespace-pre-line" style={{ color: 'var(--color-text-secondary)' }}>
                            {message}
                        </p>
                    </div>
                </div>

                <div className="flex justify-end gap-3 mt-6">
                    {!isAlert && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => onClose?.()}
                            className="px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50"
                            style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-primary)' }}
                        >
                            {cancelLabel}
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => (isAlert ? onClose?.() : onConfirm?.())}
                        className={`px-4 py-2 rounded-lg text-sm font-medium transition disabled:opacity-50 ${danger ? '' : 'btn-primary'}`}
                        style={danger
                            ? { backgroundColor: 'var(--color-danger)', color: 'var(--color-text-inverse)' }
                            : undefined}
                    >
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default Dialog;
