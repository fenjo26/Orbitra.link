import React, { useState, useEffect, useRef } from 'react';
import { Calendar, ChevronLeft, ChevronRight, Globe, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

export const TIMEZONES = [
    { value: 'UTC', label: 'UTC (UTC+00:00)' },
    { value: 'Europe/London', label: 'London (UTC+01:00 / +00:00)' },
    { value: 'Europe/Berlin', label: 'Berlin / Paris / Rome (CET, UTC+01:00 / +02:00)' },
    { value: 'Europe/Kyiv', label: 'Kyiv (UTC+03:00 / +02:00)' },
    { value: 'Europe/Moscow', label: 'Moscow (UTC+03:00)' },
    { value: 'Asia/Dubai', label: 'Dubai (UTC+04:00)' },
    { value: 'Asia/Kolkata', label: 'India / Kolkata (IST, UTC+05:30)' },
    { value: 'Asia/Bangkok', label: 'Bangkok (UTC+07:00)' },
    { value: 'Asia/Singapore', label: 'Singapore (UTC+08:00)' },
    { value: 'Asia/Tokyo', label: 'Tokyo (UTC+09:00)' },
    { value: 'America/New_York', label: 'New York (UTC-04:00 / -05:00)' },
    { value: 'America/Chicago', label: 'Chicago (UTC-05:00 / -06:00)' },
    { value: 'America/Los_Angeles', label: 'Los Angeles (UTC-07:00 / -08:00)' },
    { value: 'America/Sao_Paulo', label: 'São Paulo (UTC-03:00)' }
];

export const formatDate = (d) => {
    if (!d) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

export const parseDate = (str) => {
    if (!str) return null;
    const parts = str.split('-');
    if (parts.length !== 3) return null;
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
};

export const getPresetDates = (presetKey) => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    switch (presetKey) {
        case 'today':
            return { from: formatDate(today), to: formatDate(today) };
        case 'yesterday': {
            const y = new Date(today);
            y.setDate(y.getDate() - 1);
            return { from: formatDate(y), to: formatDate(y) };
        }
        case 'thisWeek': {
            const dayOfWeek = today.getDay(); // 0 is Sun
            const startOffset = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
            const startOfWeek = new Date(today);
            startOfWeek.setDate(today.getDate() + startOffset);
            return { from: formatDate(startOfWeek), to: formatDate(today) };
        }
        case 'last7Days': {
            const start = new Date(today);
            start.setDate(today.getDate() - 6);
            return { from: formatDate(start), to: formatDate(today) };
        }
        case 'thisMonth': {
            const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
            return { from: formatDate(startOfMonth), to: formatDate(today) };
        }
        case 'last30Days': {
            const start = new Date(today);
            start.setDate(today.getDate() - 29);
            return { from: formatDate(start), to: formatDate(today) };
        }
        case 'previousMonth': {
            const startOfPrevMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const endOfPrevMonth = new Date(today.getFullYear(), today.getMonth(), 0);
            return { from: formatDate(startOfPrevMonth), to: formatDate(endOfPrevMonth) };
        }
        default:
            return null;
    }
};

const DateRangePicker = ({
    dateFrom,
    dateTo,
    onChange,
    selectedTimezone,
    onTimezoneChange,
    className = '',
    compact = false
}) => {
    const { t } = useLanguage();
    const [isOpen, setIsOpen] = useState(false);
    const [activePreset, setActivePreset] = useState('today');

    // Calendar navigation state
    const [viewDate, setViewDate] = useState(() => {
        const initial = parseDate(dateTo) || new Date();
        return new Date(initial.getFullYear(), initial.getMonth(), 1);
    });

    // Temporary selection inside modal
    const [tempFrom, setTempFrom] = useState(dateFrom || formatDate(new Date()));
    const [tempTo, setTempTo] = useState(dateTo || formatDate(new Date()));
    const [hoverDate, setHoverDate] = useState(null);
    const [selectionStep, setSelectionStep] = useState(0); // 0 = ready for start, 1 = picking end

    const [timezone, setTimezone] = useState(selectedTimezone || localStorage.getItem('orbitra_tz') || 'UTC');
    const containerRef = useRef(null);

    // Sync when props change
    useEffect(() => {
        if (dateFrom) setTempFrom(dateFrom);
        if (dateTo) setTempTo(dateTo);
    }, [dateFrom, dateTo]);

    // Close on outside click
    useEffect(() => {
        const handleOutsideClick = (e) => {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setIsOpen(false);
            }
        };
        if (isOpen) {
            document.addEventListener('mousedown', handleOutsideClick);
        }
        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, [isOpen]);

    const presets = [
        { id: 'today', label: t('dateRangePicker.today') },
        { id: 'yesterday', label: t('dateRangePicker.yesterday') },
        { id: 'thisWeek', label: t('dateRangePicker.thisWeek') },
        { id: 'last7Days', label: t('dateRangePicker.last7Days') },
        { id: 'thisMonth', label: t('dateRangePicker.thisMonth') },
        { id: 'last30Days', label: t('dateRangePicker.last30Days') },
        { id: 'previousMonth', label: t('dateRangePicker.previousMonth') },
        { id: 'custom', label: t('dateRangePicker.custom') },
    ];

    const handleSelectPreset = (pId) => {
        setActivePreset(pId);
        if (pId !== 'custom') {
            const range = getPresetDates(pId);
            if (range) {
                setTempFrom(range.from);
                setTempTo(range.to);
                const d = parseDate(range.to);
                if (d) setViewDate(new Date(d.getFullYear(), d.getMonth(), 1));
            }
        }
    };

    const handleDayClick = (dayStr) => {
        setActivePreset('custom');
        if (selectionStep === 0 || !tempFrom || (tempFrom && tempTo && tempFrom !== tempTo)) {
            // First click - set start date
            setTempFrom(dayStr);
            setTempTo(dayStr);
            setSelectionStep(1);
        } else {
            // Second click - set end date
            if (dayStr < tempFrom) {
                setTempTo(tempFrom);
                setTempFrom(dayStr);
            } else {
                setTempTo(dayStr);
            }
            setSelectionStep(0);
        }
    };

    const handleApply = () => {
        onChange(tempFrom, tempTo);
        if (onTimezoneChange) {
            onTimezoneChange(timezone);
            localStorage.setItem('orbitra_tz', timezone);
        }
        setIsOpen(false);
    };

    const handlePrevMonth = () => {
        setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1));
    };

    const handleNextMonth = () => {
        setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1));
    };

    // Calendar grid generation
    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const firstDayIndex = new Date(year, month, 1).getDay(); // 0 is Sun
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();

    const monthNames = [
        t('dateRangePicker.months.0', 'January'),
        t('dateRangePicker.months.1', 'February'),
        t('dateRangePicker.months.2', 'March'),
        t('dateRangePicker.months.3', 'April'),
        t('dateRangePicker.months.4', 'May'),
        t('dateRangePicker.months.5', 'June'),
        t('dateRangePicker.months.6', 'July'),
        t('dateRangePicker.months.7', 'August'),
        t('dateRangePicker.months.8', 'September'),
        t('dateRangePicker.months.9', 'October'),
        t('dateRangePicker.months.10', 'November'),
        t('dateRangePicker.months.11', 'December')
    ];

    const weekDayHeaders = [
        t('dateRangePicker.days.0', 'Su'),
        t('dateRangePicker.days.1', 'Mo'),
        t('dateRangePicker.days.2', 'Tu'),
        t('dateRangePicker.days.3', 'We'),
        t('dateRangePicker.days.4', 'Th'),
        t('dateRangePicker.days.5', 'Fr'),
        t('dateRangePicker.days.6', 'Sa')
    ];

    const calendarCells = [];
    // Previous month filler
    for (let i = firstDayIndex - 1; i >= 0; i--) {
        const d = daysInPrevMonth - i;
        const prevMonth = month === 0 ? 11 : month - 1;
        const prevYear = month === 0 ? year - 1 : year;
        const str = formatDate(new Date(prevYear, prevMonth, d));
        calendarCells.push({ dayNumber: d, dateStr: str, isCurrentMonth: false });
    }
    // Current month days
    for (let d = 1; d <= daysInMonth; d++) {
        const str = formatDate(new Date(year, month, d));
        calendarCells.push({ dayNumber: d, dateStr: str, isCurrentMonth: true });
    }
    // Next month filler to complete 35 or 42 grid
    const totalCells = calendarCells.length <= 35 ? 35 : 42;
    const remaining = totalCells - calendarCells.length;
    for (let d = 1; d <= remaining; d++) {
        const nextMonth = month === 11 ? 0 : month + 1;
        const nextYear = month === 11 ? year + 1 : year;
        const str = formatDate(new Date(nextYear, nextMonth, d));
        calendarCells.push({ dayNumber: d, dateStr: str, isCurrentMonth: false });
    }

    // Format display range for trigger button
    const formatDisplay = () => {
        if (!dateFrom && !dateTo) return t('dateRangePicker.today');
        if (dateFrom === dateTo) return dateFrom;
        return `${dateFrom} - ${dateTo}`;
    };

    return (
        <div className={`relative inline-block ${className}`} ref={containerRef}>
            {/* Trigger Button */}
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className="btn btn-secondary flex items-center gap-2 text-xs py-1.5 px-3 rounded-xl"
                style={{
                    backgroundColor: 'var(--color-bg-card)',
                    border: '1px solid var(--color-border)',
                    color: 'var(--color-text-primary)'
                }}
            >
                <Calendar className="w-3.5 h-3.5" style={{ color: 'var(--color-primary)' }} />
                <span className="font-medium">{formatDisplay()}</span>
                <span className="text-xs px-1.5 py-0.5 rounded" style={{ backgroundColor: 'var(--color-bg-soft)', color: 'var(--color-text-muted)' }}>
                    {timezone.split('/')[1] || timezone}
                </span>
                <span className="text-xs" style={{ color: 'var(--color-text-muted)' }}>▾</span>
            </button>

            {/* Dropdown Calendar Panel */}
            {isOpen && (
                <div
                    className="absolute right-0 top-full mt-2 z-50 rounded-2xl shadow-2xl p-4 flex flex-col gap-4 animate-in fade-in zoom-in-95 duration-150"
                    style={{
                        backgroundColor: 'var(--color-bg-card)',
                        border: '1px solid var(--color-border)',
                        color: 'var(--color-text-primary)',
                        minWidth: '540px'
                    }}
                >
                    <div className="flex gap-4">
                        {/* Left Side: Presets Menu */}
                        <div
                            className="flex flex-col gap-1 pr-3"
                            style={{
                                width: '160px',
                                borderRight: '1px solid var(--color-border)'
                            }}
                        >
                            <span className="text-xs font-semibold uppercase px-2 mb-1" style={{ color: 'var(--color-text-muted)' }}>
                                {t('reportCustomizer.presets')}
                            </span>
                            {presets.map((p) => {
                                const isSelected = activePreset === p.id;
                                return (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => handleSelectPreset(p.id)}
                                        className="text-left text-xs px-2.5 py-1.5 rounded-lg transition-colors flex items-center justify-between"
                                        style={{
                                            backgroundColor: isSelected ? 'var(--color-primary-light)' : 'transparent',
                                            color: isSelected ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                            fontWeight: isSelected ? 600 : 400
                                        }}
                                    >
                                        <span>{p.label}</span>
                                        {isSelected && <Check className="w-3.5 h-3.5" />}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Right Side: Interactive Calendar */}
                        <div className="flex-1 flex flex-col gap-3">
                            {/* Month Header Navigation */}
                            <div className="flex items-center justify-between">
                                <button
                                    type="button"
                                    onClick={handlePrevMonth}
                                    className="p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                                    style={{ color: 'var(--color-text-secondary)' }}
                                >
                                    <ChevronLeft className="w-4 h-4" />
                                </button>
                                <span className="text-sm font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                    {monthNames[month]} {year}
                                </span>
                                <button
                                    type="button"
                                    onClick={handleNextMonth}
                                    className="p-1 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                                    style={{ color: 'var(--color-text-secondary)' }}
                                >
                                    <ChevronRight className="w-4 h-4" />
                                </button>
                            </div>

                            {/* Weekday headers */}
                            <div className="grid grid-cols-7 gap-1 text-center">
                                {weekDayHeaders.map((dh, idx) => (
                                    <span key={idx} className="text-xs font-semibold" style={{ color: 'var(--color-text-muted)' }}>
                                        {dh}
                                    </span>
                                ))}
                            </div>

                            {/* Calendar Days Grid */}
                            <div className="grid grid-cols-7 gap-1">
                                {calendarCells.map((cell, idx) => {
                                    const isStart = cell.dateStr === tempFrom;
                                    const isEnd = cell.dateStr === tempTo;
                                    const effectiveEnd = selectionStep === 1 && hoverDate ? hoverDate : tempTo;
                                    const isInRange = tempFrom && effectiveEnd && (
                                        (cell.dateStr >= tempFrom && cell.dateStr <= effectiveEnd) ||
                                        (cell.dateStr >= effectiveEnd && cell.dateStr <= tempFrom)
                                    );

                                    let bg = 'transparent';
                                    let textColor = cell.isCurrentMonth ? 'var(--color-text-primary)' : 'var(--color-text-muted)';
                                    let borderRadius = '6px';

                                    if (isStart || isEnd) {
                                        bg = 'var(--color-primary)';
                                        textColor = '#ffffff';
                                    } else if (isInRange) {
                                        bg = 'var(--color-primary-light)';
                                        textColor = 'var(--color-primary)';
                                    }

                                    return (
                                        <button
                                            key={idx}
                                            type="button"
                                            onClick={() => handleDayClick(cell.dateStr)}
                                            onMouseEnter={() => {
                                                if (selectionStep === 1) setHoverDate(cell.dateStr);
                                            }}
                                            className="h-8 text-xs font-medium flex items-center justify-center transition-all"
                                            style={{
                                                backgroundColor: bg,
                                                color: textColor,
                                                borderRadius,
                                                opacity: cell.isCurrentMonth ? 1 : 0.4
                                            }}
                                        >
                                            {cell.dayNumber}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Date Inputs summary */}
                            <div className="flex items-center gap-2 pt-2" style={{ borderTop: '1px solid var(--color-border)' }}>
                                <input
                                    type="date"
                                    value={tempFrom}
                                    onChange={(e) => {
                                        setTempFrom(e.target.value);
                                        setActivePreset('custom');
                                    }}
                                    className="form-input text-xs py-1 px-2 flex-1 rounded-lg"
                                />
                                <span style={{ color: 'var(--color-text-muted)' }}>—</span>
                                <input
                                    type="date"
                                    value={tempTo}
                                    onChange={(e) => {
                                        setTempTo(e.target.value);
                                        setActivePreset('custom');
                                    }}
                                    className="form-input text-xs py-1 px-2 flex-1 rounded-lg"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Bottom Toolbar: Timezone and Action Buttons */}
                    <div
                        className="flex items-center justify-between pt-3"
                        style={{ borderTop: '1px solid var(--color-border)' }}
                    >
                        {/* Timezone picker */}
                        <div className="flex items-center gap-2">
                            <Globe className="w-3.5 h-3.5" style={{ color: 'var(--color-text-muted)' }} />
                            <select
                                value={timezone}
                                onChange={(e) => setTimezone(e.target.value)}
                                className="form-select text-xs py-1 px-2 rounded-lg"
                                style={{ width: '200px' }}
                            >
                                {TIMEZONES.map((tz) => (
                                    <option key={tz.value} value={tz.value}>
                                        {tz.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Buttons */}
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setIsOpen(false)}
                                className="btn btn-ghost text-xs py-1.5 px-3 rounded-xl"
                            >
                                {t('dateRangePicker.cancel')}
                            </button>
                            <button
                                type="button"
                                onClick={handleApply}
                                className="btn btn-primary text-xs py-1.5 px-4 rounded-xl font-medium"
                            >
                                {t('dateRangePicker.apply')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DateRangePicker;
