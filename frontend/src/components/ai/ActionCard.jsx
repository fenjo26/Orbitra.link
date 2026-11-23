import React from 'react';

const MUTED = { color: 'var(--color-text-muted)' };
const SOFT = { background: 'var(--color-bg-soft)' };

// One held action as the confirmation block shows it: the entity it concerns,
// plain lines and "was → will be" rows, built by describeCall() in
// actionCards.js. The title (the tool's name) is rendered by the chat above it.
export default function ActionCard({ card, picks, onPicks, skipped, onSkip, showSkip, t }) {
    const rows = card.rows ?? [];
    const notes = card.notes ?? [];
    const items = card.variants?.items ?? [];
    const isPicked = (i) => !picks || picks[i] !== false;
    return (
        <div className="flex items-start gap-2" data-kind={card.kind} data-testid="ai-action-card">
            {showSkip && (
                <input
                    type="checkbox"
                    className="mt-0.5"
                    checked={!skipped}
                    onChange={(e) => onSkip(!e.target.checked)}
                    title={t('ai.action.runThis')}
                    aria-label={t('ai.action.runThis')}
                />
            )}
            <div className="flex-1 min-w-0 space-y-1">
                {card.subject && <div className="truncate" title={card.subject}>{card.subject}</div>}
                {notes.map((n, i) => <div key={i}>{n}</div>)}
                {rows.length > 0 && (
                    <div className="rounded-lg px-2 py-1.5 space-y-1" style={SOFT}>
                        {rows.map((r) => (
                            <div key={r.key} className="flex items-center gap-1.5">
                                <span className="flex-1 truncate" title={r.label}>{r.label}</span>
                                {r.from !== undefined && <><span style={MUTED}>{r.from}</span><span style={MUTED}>→</span></>}
                                <span style={{ color: 'var(--color-text-primary)', fontWeight: 600 }}>{r.to}</span>
                            </div>
                        ))}
                    </div>
                )}
                {card.variants && (
                    <div className="rounded-lg px-2 py-1.5 space-y-1.5" style={SOFT}>
                        <div style={MUTED}>{t('ai.action.original')}: <span style={{ color: 'var(--color-text-primary)' }}>{card.variants.original}</span></div>
                        {items.map((v, i) => (
                            <label key={i} className="flex items-start gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={isPicked(i)}
                                    onChange={(e) => onPicks(items.map((_, j) => (j === i ? e.target.checked : isPicked(j))))}
                                />
                                <span className="break-words">{v}</span>
                            </label>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
