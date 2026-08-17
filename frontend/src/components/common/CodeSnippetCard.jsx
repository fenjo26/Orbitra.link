import { Check, Copy } from 'lucide-react';

export const CodeSnippetCard = ({
    title,
    description,
    code,
    copyId,
    onCopy,
    copied,
    copyLabel = 'Copy',
    copiedLabel = 'Copied',
    actions,
    muted = false
}) => (
    <div
        className="p-4 rounded-2xl border flex flex-col gap-2.5"
        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
    >
        <div className="flex justify-between items-start gap-3">
            <div className="min-w-0">
                <div className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--color-text-primary)' }}>
                    {title}
                </div>
                {description && (
                    <div className="text-xs mt-0.5" style={{ color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                        {description}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
                {actions}
                <button
                    type="button"
                    onClick={() => onCopy(code, copyId)}
                    disabled={!code}
                    className="btn btn-secondary text-[11px] py-1 px-3 rounded-lg flex items-center gap-1.5"
                >
                    {copied === copyId
                        ? <Check size={13} style={{ color: 'var(--color-success, #16a34a)' }} />
                        : <Copy size={13} />}
                    <span style={copied === copyId ? { color: 'var(--color-success, #16a34a)' } : undefined}>
                        {copied === copyId ? copiedLabel : copyLabel}
                    </span>
                </button>
            </div>
        </div>
        <pre
            className="p-3 rounded-xl font-mono text-xs overflow-x-auto border"
            style={{
                backgroundColor: 'var(--color-bg-card)',
                borderColor: 'var(--color-border)',
                color: muted ? 'var(--color-text-muted)' : 'var(--color-text-primary)',
                margin: 0,
                whiteSpace: 'pre-wrap',
                overflowWrap: 'anywhere'
            }}
        >
            <code>{code}</code>
        </pre>
    </div>
);

export default CodeSnippetCard;
