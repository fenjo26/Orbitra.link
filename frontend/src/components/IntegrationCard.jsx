import React from 'react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';

const IntegrationCard = ({ item, onSelect, onClick }) => {
    const handleAction = () => {
        if (typeof onSelect === 'function') {
            onSelect(item?.id);
        } else if (typeof onClick === 'function') {
            onClick(item?.id);
        }
    };

    return (
        <div
            onClick={handleAction}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleAction();
                }
            }}
            className="group relative flex flex-col justify-between p-5 rounded-2xl border transition-all duration-200 cursor-pointer hover:shadow-lg hover:-translate-y-1 select-none"
            style={{
                background: 'var(--color-bg-card)',
                borderColor: 'var(--color-border)',
            }}
        >
            <div>
                {/* Top Row: Category Badge & Status Indicator */}
                <div className="flex items-center justify-between gap-2 mb-4">
                    <span
                        className="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full shrink-0"
                        style={{
                            background: item.badgeBg || 'rgba(99, 102, 241, 0.12)',
                            color: item.badgeColor || 'var(--color-primary)',
                        }}
                    >
                        {item.badge}
                    </span>

                    {item.isConnected ? (
                        <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-emerald-500 shrink-0">
                            <span className="relative flex h-2 w-2">
                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            Active
                        </span>
                    ) : (
                        <span className="text-[11px] font-medium text-slate-400 dark:text-slate-500 shrink-0">
                            Available
                        </span>
                    )}
                </div>

                {/* Logo & Title */}
                <div className="flex items-start gap-3.5 mb-2.5">
                    <div
                        className="w-11 h-11 rounded-xl flex items-center justify-center text-xl shrink-0 transition-transform duration-200 group-hover:scale-105"
                        style={{
                            background: item.iconBg || 'var(--color-bg-soft)',
                            color: item.iconColor || 'var(--color-primary)',
                            border: '1px solid var(--color-border)',
                        }}
                    >
                        {item.icon}
                    </div>
                    <div className="min-w-0 flex-1">
                        <h4
                            className="font-semibold text-[15px] leading-snug truncate group-hover:text-[var(--color-primary)] transition-colors"
                            style={{ color: 'var(--color-text-primary)' }}
                        >
                            {item.title}
                        </h4>
                        <p
                            className="text-xs truncate mt-0.5"
                            style={{ color: 'var(--color-text-muted)' }}
                        >
                            {item.subtitle}
                        </p>
                    </div>
                </div>

                {/* Description */}
                <p
                    className="text-xs line-clamp-2 mt-2 leading-relaxed"
                    style={{ color: 'var(--color-text-secondary)' }}
                >
                    {item.description}
                </p>
            </div>

            {/* Bottom Row / Stats & CTA */}
            <div
                className="mt-5 pt-3.5 border-t flex items-center justify-between gap-2 text-xs"
                style={{ borderColor: 'var(--color-border)' }}
            >
                <div className="flex items-center gap-1.5 min-w-0 flex-1">
                    {item.isConnected && (
                        <CheckCircle2 size={13} className="text-emerald-500 shrink-0" />
                    )}
                    <span
                        className="font-medium truncate"
                        style={{
                            color: item.isConnected ? 'var(--color-text-primary)' : 'var(--color-text-muted)',
                        }}
                    >
                        {item.statText || 'Click to configure'}
                    </span>
                </div>

                <div
                    className="flex items-center gap-1 font-semibold shrink-0 transition-transform duration-200 group-hover:translate-x-1"
                    style={{ color: 'var(--color-primary)' }}
                >
                    <span>{item.ctaText || 'Configure'}</span>
                    <ArrowRight size={13} />
                </div>
            </div>
        </div>
    );
};

export default IntegrationCard;
