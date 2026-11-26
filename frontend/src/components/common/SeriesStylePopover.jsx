import React, { useEffect, useRef } from 'react';
import { RotateCcw, Check } from 'lucide-react';
import { CHART_STYLES, COLOR_SWATCHES } from '../../utils/chartMetrics';
import { useLanguage } from '../../contexts/LanguageContext';

// Tiny visual icon representing a line style or bars, coloured in the series' tint.
export function StyleSwatch({ style = 'solid', color = 'currentColor', width = 22, height = 10 }) {
    if (style === 'bars') {
        return (
            <svg width={width} height={height} viewBox="0 0 22 10" aria-hidden="true" className="shrink-0">
                <rect x="2" y="4" width="4" height="6" rx="1" fill={color} opacity="0.85" />
                <rect x="9" y="1" width="4" height="9" rx="1" fill={color} />
                <rect x="16" y="3" width="4" height="7" rx="1" fill={color} opacity="0.85" />
            </svg>
        );
    }
    const dash = style === 'dashed' ? '5 3' : style === 'dotted' ? '1.5 3' : style === 'dashdot' ? '6 2.5 1.5 2.5' : undefined;
    return (
        <svg width={width} height={height} viewBox="0 0 22 10" aria-hidden="true" className="shrink-0">
            <line
                x1="1" y1="5" x2="21" y2="5"
                stroke={color}
                strokeWidth="2.4"
                strokeLinecap={style === 'dotted' ? 'round' : 'butt'}
                strokeDasharray={dash}
            />
        </svg>
    );
}

/**
 * Compact popover anchored below a metric/series chip to customize its colour,
 * line style (solid / dashed / dotted / dashdot / bars) and optional area fill.
 */
const SeriesStylePopover = ({
    color,
    resolvedHex,
    style = 'solid',
    fill = null,
    allowBars = true,
    allowFill = true,
    onChangeColor,
    onChangeStyle,
    onChangeFill,
    onReset,
    onClose,
}) => {
    const { t } = useLanguage();
    const ref = useRef(null);

    useEffect(() => {
        const onDown = (e) => {
            if (ref.current && !ref.current.contains(e.target)) onClose?.();
        };
        const onKey = (e) => {
            if (e.key === 'Escape') onClose?.();
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    const styles = allowBars ? CHART_STYLES : CHART_STYLES.filter((s) => s !== 'bars');
    const styleLabels = {
        solid: t('analyticsCompare.styleSolid', 'Solid'),
        dashed: t('analyticsCompare.styleDashed', 'Dashed'),
        dotted: t('analyticsCompare.styleDotted', 'Dotted'),
        dashdot: t('analyticsCompare.styleDashdot', 'Dash-dot'),
        bars: t('analyticsCompare.styleBars', 'Bars'),
    };
    const hexValue = /^#[0-9a-f]{6}$/i.test(color || '') ? color : (resolvedHex || '#3b82f6');

    return (
        <div
            ref={ref}
            onClick={(e) => e.stopPropagation()}
            className="absolute left-0 top-full mt-2 z-50 w-64 rounded-xl p-3 shadow-xl border"
            style={{
                backgroundColor: 'var(--color-bg-card)',
                borderColor: 'var(--color-border)',
                color: 'var(--color-text-primary)',
            }}
        >
            <div className="flex items-center justify-between mb-2">
                <span className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: 'var(--color-text-muted)' }}>
                    {t('analyticsCompare.seriesColor', 'Color')}
                </span>
                {onReset && (
                    <button
                        type="button"
                        onClick={onReset}
                        className="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded hover:opacity-80"
                        style={{ color: 'var(--color-text-secondary)' }}
                        title={t('analyticsCompare.resetStyle', 'Reset to theme default')}
                    >
                        <RotateCcw className="w-3 h-3" />
                        {t('analyticsCompare.reset', 'Reset')}
                    </button>
                )}
            </div>

            <div className="grid grid-cols-6 gap-1.5 mb-2.5">
                {COLOR_SWATCHES.map((swatch) => {
                    const active = hexValue.toLowerCase() === swatch.toLowerCase();
                    return (
                        <button
                            key={swatch}
                            type="button"
                            onClick={() => onChangeColor?.(swatch)}
                            className="w-7 h-7 rounded-lg flex items-center justify-center transition-transform hover:scale-105"
                            style={{
                                backgroundColor: swatch,
                                boxShadow: active ? '0 0 0 2px var(--color-bg-card), 0 0 0 4px var(--color-primary)' : 'inset 0 0 0 1px rgba(0,0,0,0.12)',
                            }}
                            title={swatch}
                        >
                            {active && <Check className="w-3.5 h-3.5 text-white drop-shadow" />}
                        </button>
                    );
                })}
            </div>

            <label
                className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg border text-xs mb-3 cursor-pointer"
                style={{ borderColor: 'var(--color-border)', backgroundColor: 'var(--color-bg-soft)' }}
            >
                <span style={{ color: 'var(--color-text-secondary)' }}>
                    {t('analyticsCompare.customColor', 'Custom HEX')}
                </span>
                <div className="flex items-center gap-1.5">
                    <span className="font-mono text-[11px] uppercase" style={{ color: 'var(--color-text-muted)' }}>
                        {hexValue}
                    </span>
                    <input
                        type="color"
                        value={hexValue}
                        onChange={(e) => onChangeColor?.(e.target.value)}
                        className="w-6 h-5 rounded cursor-pointer border-0 p-0 bg-transparent"
                    />
                </div>
            </label>

            {onChangeStyle && (
                <div className="mb-2.5">
                    <div className="text-[11px] font-semibold uppercase tracking-wide mb-1.5" style={{ color: 'var(--color-text-muted)' }}>
                        {t('analyticsCompare.lineStyle', 'Line style')}
                    </div>
                    <div className="grid grid-cols-2 gap-1.5">
                        {styles.map((s) => {
                            const active = style === s;
                            return (
                                <button
                                    key={s}
                                    type="button"
                                    onClick={() => onChangeStyle(s)}
                                    className="flex items-center gap-2 px-2 py-1.5 rounded-lg border text-xs transition-colors"
                                    style={{
                                        borderColor: active ? 'var(--color-primary)' : 'var(--color-border)',
                                        backgroundColor: active ? 'var(--color-primary-light)' : 'transparent',
                                        color: active ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                        fontWeight: active ? 600 : 400,
                                    }}
                                >
                                    <StyleSwatch style={s} color={hexValue} />
                                    <span className="truncate">{styleLabels[s]}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {allowFill && onChangeFill && style !== 'bars' && (
                <label className="flex items-center justify-between gap-2 pt-2 border-t text-xs cursor-pointer"
                    style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-secondary)' }}>
                    <span>{t('analyticsCompare.areaFill', 'Area gradient fill')}</span>
                    <input
                        type="checkbox"
                        checked={Boolean(fill)}
                        onChange={(e) => onChangeFill(e.target.checked)}
                        className="accent-[var(--color-primary)]"
                    />
                </label>
            )}
        </div>
    );
};

export default SeriesStylePopover;
