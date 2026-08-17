import React, { forwardRef, useCallback, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, ChevronUp, X } from 'lucide-react';
import { useLanguage } from '../../contexts/LanguageContext';

const INDENT = '  ';
// Fixed by the textarea style below. The find widget uses it to scroll a
// match into view — wrap="off" keeps every logical line exactly one visual
// line, so row × height is the exact pixel offset.
const LINE_HEIGHT = 21;
const FIND_MATCH_CAP = 9999;
const VOID_HTML_TAGS = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr'
]);

const shouldIndentNextLine = (line, language) => {
    const trimmed = line.trim();
    if (!trimmed) return false;
    if (/[{[(]$/.test(trimmed)) return true;
    if (language !== 'html') return false;

    const match = trimmed.match(/<([a-z][\w:-]*)(?:\s[^>]*)?>$/i);
    if (!match || trimmed.endsWith('/>')) return false;
    return !VOID_HTML_TAGS.has(match[1].toLowerCase());
};

// RegExp source for the find widget. Literal queries are escaped; whole-word
// wraps the source in a non-capturing \b pair so top-level alternation
// ("a|b") cannot leak outside the word boundary. Invalid user regexes are
// reported, not thrown.
const buildFindRegex = (query, { matchCase, wholeWord, useRegex }) => {
    if (!query) return null;
    try {
        let source = query;
        if (!useRegex) source = source.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (wholeWord) source = `\\b(?:${source})\\b`;
        return { source, flags: matchCase ? 'g' : 'gi' };
    } catch (e) {
        return { invalid: true };
    }
};

// Outside regex mode the replacement is literal: "$&" typed by the user must
// not resolve to the matched text.
const escapeReplacement = (replacement, useRegex) => useRegex ? replacement : replacement.replace(/\$/g, '$$$$');

// Literal replace-all built from the match list itself: the number of edited
// spots equals matches.length by construction, and a pattern that can also
// match empty (regex "a*") never smears the replacement between characters.
// Regex mode still uses String.replace so $1-style backreferences keep working.
const replaceAllLiteral = (text, matches, replacement) => {
    let result = '';
    let last = 0;
    for (const match of matches) {
        result += text.slice(last, match.start) + replacement;
        last = match.end;
    }
    return result + text.slice(last);
};

/**
 * Lightweight dependency-free editor used for landing assets. It deliberately
 * keeps a real textarea (native selection, IME and accessibility) while adding
 * a synchronized gutter, indentation helpers, a VS Code-style find & replace
 * widget and an imperative insertion API for the landing macro toolbar.
 */
export const CodeEditor = forwardRef(function CodeEditor({
    value = '',
    onChange,
    onSave,
    language = 'text',
    ariaLabel = 'Code editor'
}, forwardedRef) {
    const { t } = useLanguage();
    const textareaRef = useRef(null);
    const gutterContentRef = useRef(null);
    const findInputRef = useRef(null);
    const replaceInputRef = useRef(null);
    // Set by replaceOne so the post-render effect knows to move the selection
    // onto the match that follows the one just replaced.
    const revealNextMatch = useRef(false);
    const text = String(value ?? '');
    const lineCount = Math.max(1, text.split('\n').length);

    // Find & Replace state. The query intentionally survives file switches —
    // the CodeEditor instance stays mounted across them, and re-running the
    // same replacement over a landing's other files is the main workflow.
    const [findOpen, setFindOpen] = useState(false);
    const [showReplace, setShowReplace] = useState(false);
    const [findQuery, setFindQuery] = useState('');
    const [replaceQuery, setReplaceQuery] = useState('');
    const [matchCase, setMatchCase] = useState(false);
    const [wholeWord, setWholeWord] = useState(false);
    const [useRegex, setUseRegex] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [findNotice, setFindNotice] = useState('');

    // t() has no interpolation, so {count}-style placeholders are filled here.
    const interpolate = useCallback((key, fallback, params) => {
        let str = t(key, fallback);
        Object.entries(params || {}).forEach(([name, val]) => {
            str = str.split(`{${name}}`).join(String(val));
        });
        return str;
    }, [t]);

    const regexInfo = useMemo(
        () => buildFindRegex(findQuery, { matchCase, wholeWord, useRegex }),
        [findQuery, matchCase, wholeWord, useRegex]
    );

    const matches = useMemo(() => {
        if (!findQuery || !regexInfo || regexInfo.invalid) return [];
        try {
            const regex = new RegExp(regexInfo.source, regexInfo.flags);
            const found = [];
            let m;
            while ((m = regex.exec(text)) !== null && found.length < FIND_MATCH_CAP) {
                // Zero-length matches ("a*", "\\b") are skipped, not listed —
                // they would otherwise multiply between every character.
                if (m[0]) found.push({ start: m.index, end: m.index + m[0].length });
                else regex.lastIndex++;
            }
            return found;
        } catch (e) {
            return [];
        }
    }, [text, findQuery, regexInfo]);

    useEffect(() => {
        setActiveIndex(index => Math.min(index, Math.max(0, matches.length - 1)));
    }, [matches.length]);

    useEffect(() => {
        if (!findNotice) return undefined;
        const timer = window.setTimeout(() => setFindNotice(''), 2500);
        return () => window.clearTimeout(timer);
    }, [findNotice]);

    const restoreSelection = useCallback((start, end = start) => {
        window.requestAnimationFrame(() => {
            const textarea = textareaRef.current;
            if (!textarea) return;
            textarea.focus();
            textarea.setSelectionRange(start, end);
        });
    }, []);

    // Scrolls a character offset into view without stealing focus from the
    // find input, so Enter / Shift+Enter keep navigating. Vertical math is
    // exact (fixed line height, no soft wrap); horizontal is measured for the
    // monospace font so minified one-liners also reveal the match.
    const revealOffset = useCallback((start, end = start) => {
        const textarea = textareaRef.current;
        if (!textarea) return;
        textarea.setSelectionRange(start, end);
        const before = text.slice(0, start).split('\n');
        const rowNum = before.length - 1;
        textarea.scrollTop = Math.max(0, rowNum * LINE_HEIGHT - Math.max(0, (textarea.clientHeight - LINE_HEIGHT) / 2));

        const col = before[before.length - 1].replace(/\t/g, '  ').length;
        const styles = window.getComputedStyle(textarea);
        const probe = document.createElement('span');
        probe.style.visibility = 'hidden';
        probe.style.position = 'absolute';
        probe.style.whiteSpace = 'pre';
        probe.style.fontFamily = styles.fontFamily;
        probe.style.fontSize = styles.fontSize;
        probe.style.fontWeight = styles.fontWeight;
        probe.style.letterSpacing = styles.letterSpacing;
        probe.textContent = 'M'.repeat(col);
        document.body.appendChild(probe);
        const offsetLeft = probe.getBoundingClientRect().width;
        probe.remove();
        if (offsetLeft < textarea.scrollLeft || offsetLeft > textarea.scrollLeft + textarea.clientWidth - 80) {
            textarea.scrollLeft = Math.max(0, offsetLeft - textarea.clientWidth / 2);
        }
    }, [text]);

    const gotoMatch = useCallback((index) => {
        if (!matches.length) return;
        const next = ((index % matches.length) + matches.length) % matches.length;
        setActiveIndex(next);
        revealOffset(matches[next].start, matches[next].end);
    }, [matches, revealOffset]);

    const openFind = useCallback((opts = {}) => {
        setFindOpen(true);
        if (opts.replace) setShowReplace(true);
        const textarea = textareaRef.current;
        // Seed the query from the editor selection, VS Code style — the
        // "select a link, Ctrl+F, replace with {offer}" flow starts pre-filled.
        if (textarea && textarea.selectionStart !== textarea.selectionEnd) {
            const chosen = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
            if (chosen && chosen.length <= 200 && !chosen.includes('\n')) {
                setFindQuery(chosen);
                setActiveIndex(0);
            }
        }
        window.requestAnimationFrame(() => {
            const input = opts.replace ? replaceInputRef.current : findInputRef.current;
            if (input) {
                input.focus();
                input.select();
            }
        });
    }, []);

    const closeFind = useCallback(() => {
        setFindOpen(false);
        setFindNotice('');
        textareaRef.current?.focus();
    }, []);

    const replaceOne = useCallback(() => {
        const match = matches[activeIndex];
        if (!match) return;
        const replacement = escapeReplacement(replaceQuery, useRegex);
        const nextText = text.slice(0, match.start) + replacement + text.slice(match.end);
        revealNextMatch.current = true;
        onChange?.(nextText);
    }, [matches, activeIndex, replaceQuery, useRegex, text, onChange]);

    const replaceAll = useCallback(() => {
        if (!matches.length || !regexInfo || regexInfo.invalid) return;
        const count = matches.length;
        const nextText = useRegex
            ? text.replace(new RegExp(regexInfo.source, regexInfo.flags), replaceQuery)
            : replaceAllLiteral(text, matches, replaceQuery);
        onChange?.(nextText);
        setActiveIndex(0);
        setFindNotice(interpolate('landingEditor.replacedCount', 'Replaced: {count}', { count }));
    }, [matches, regexInfo, replaceQuery, useRegex, text, onChange, interpolate]);

    // After replaceOne the matches recompute against the new text; move the
    // selection onto whatever match now sits at the same index (the next one).
    useEffect(() => {
        if (!revealNextMatch.current) return;
        revealNextMatch.current = false;
        if (!matches.length) return;
        const index = Math.min(activeIndex, matches.length - 1);
        setActiveIndex(index);
        revealOffset(matches[index].start, matches[index].end);
    }, [matches, activeIndex, revealOffset]);

    const replaceRange = useCallback((replacement, start, end) => {
        const textarea = textareaRef.current;
        const rangeStart = Number.isInteger(start) ? start : (textarea?.selectionStart ?? text.length);
        const rangeEnd = Number.isInteger(end) ? end : (textarea?.selectionEnd ?? rangeStart);
        const nextValue = text.slice(0, rangeStart) + replacement + text.slice(rangeEnd);
        onChange?.(nextValue);
        restoreSelection(rangeStart + replacement.length);
        return nextValue;
    }, [onChange, restoreSelection, text]);

    useImperativeHandle(forwardedRef, () => ({
        focus: () => textareaRef.current?.focus(),
        insertText: (snippet) => replaceRange(String(snippet ?? '')),
        replaceRange: (snippet, start, end) => replaceRange(String(snippet ?? ''), start, end),
        getSelection: () => ({
            start: textareaRef.current?.selectionStart ?? 0,
            end: textareaRef.current?.selectionEnd ?? 0
        }),
        setSelection: restoreSelection,
        openFind,
        closeFind
    }), [replaceRange, restoreSelection, openFind, closeFind]);

    const handleScroll = () => {
        const textarea = textareaRef.current;
        const gutter = gutterContentRef.current;
        if (textarea && gutter) gutter.style.transform = `translateY(-${textarea.scrollTop}px)`;
    };

    const handleTab = (event) => {
        const textarea = event.currentTarget;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = text.slice(start, end);

        if (!selected.includes('\n')) {
            if (event.shiftKey) {
                const lineStart = text.lastIndexOf('\n', start - 1) + 1;
                const removable = text.slice(lineStart, lineStart + INDENT.length).match(/^ {1,2}/)?.[0] || '';
                if (!removable) return;
                onChange?.(text.slice(0, lineStart) + text.slice(lineStart + removable.length));
                restoreSelection(Math.max(lineStart, start - removable.length), Math.max(lineStart, end - removable.length));
                return;
            }
            replaceRange(INDENT, start, end);
            return;
        }

        const blockStart = text.lastIndexOf('\n', start - 1) + 1;
        const blockEndBreak = text.indexOf('\n', end);
        const blockEnd = blockEndBreak === -1 ? text.length : blockEndBreak;
        const block = text.slice(blockStart, blockEnd);
        const nextBlock = event.shiftKey
            ? block.replace(/^ {1,2}/gm, '')
            : block.replace(/^/gm, INDENT);
        const nextValue = text.slice(0, blockStart) + nextBlock + text.slice(blockEnd);
        onChange?.(nextValue);
        restoreSelection(blockStart, blockStart + nextBlock.length);
    };

    const handleKeyDown = (event) => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey) {
            const key = event.key.toLowerCase();
            if (key === 'f') {
                event.preventDefault();
                openFind();
                return;
            }
            if (key === 'h') {
                event.preventDefault();
                openFind({ replace: true });
                return;
            }
            if (key === 's') {
                event.preventDefault();
                onSave?.();
                return;
            }
        }

        if (event.key === 'Tab') {
            event.preventDefault();
            handleTab(event);
            return;
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            const start = event.currentTarget.selectionStart;
            const end = event.currentTarget.selectionEnd;
            const lineStart = text.lastIndexOf('\n', start - 1) + 1;
            const currentLine = text.slice(lineStart, start);
            const baseIndent = currentLine.match(/^\s*/)?.[0] || '';
            const nextIndent = baseIndent + (shouldIndentNextLine(currentLine, language) ? INDENT : '');
            replaceRange(`\n${nextIndent}`, start, end);
        }
    };

    const handleWidgetKeyDown = (event) => {
        // Enter confirms an IME composition — it must not jump to the next match.
        if (event.nativeEvent.isComposing) return;
        const meta = event.ctrlKey || event.metaKey;
        if (event.key === 'Escape') {
            event.preventDefault();
            // Without this the editor-fullscreen Esc handler (a window-level
            // listener in LandingEditor) would also fire and collapse the panel.
            event.stopPropagation();
            closeFind();
            return;
        }
        if (meta && event.key.toLowerCase() === 'f') {
            event.preventDefault();
            findInputRef.current?.focus();
            findInputRef.current?.select();
            return;
        }
        if (meta && event.key.toLowerCase() === 'h') {
            event.preventDefault();
            setShowReplace(true);
            window.requestAnimationFrame(() => replaceInputRef.current?.focus());
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (event.shiftKey) {
                gotoMatch(activeIndex - 1);
            } else if (event.target === replaceInputRef.current) {
                replaceOne();
            } else {
                gotoMatch(activeIndex + 1);
            }
        }
    };

    const renderModifierToggle = (active, label, title, onToggle) => (
        <button
            type="button"
            onClick={onToggle}
            title={title}
            aria-pressed={active}
            className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md font-mono text-[10px] font-semibold transition"
            style={{
                backgroundColor: active ? 'rgba(56, 139, 253, 0.18)' : 'transparent',
                border: `1px solid ${active ? '#58a6ff' : 'transparent'}`,
                color: active ? '#79c0ff' : '#8b949e'
            }}
        >
            {label}
        </button>
    );

    const renderIconButton = (Icon, title, onClick, disabled = false) => (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-md transition"
            style={{ color: disabled ? '#484f58' : '#8b949e' }}
        >
            <Icon className="h-3.5 w-3.5" />
        </button>
    );

    const counter = !findQuery
        ? { text: '', color: '#8b949e' }
        : regexInfo?.invalid
            ? { text: t('landingEditor.invalidRegex', 'Invalid regex'), color: '#f85149' }
            : !matches.length
                ? { text: t('landingEditor.noMatches', 'No matches'), color: '#f85149' }
                : {
                    text: interpolate('landingEditor.matchCount', '{current} of {total}', {
                        current: Math.min(activeIndex + 1, matches.length),
                        total: matches.length
                    }),
                    color: '#8b949e'
                };

    return (
        <div
            className="relative flex h-full min-h-0 w-full flex-col overflow-hidden rounded-xl border font-mono text-[13px]"
            style={{ backgroundColor: '#141619', borderColor: '#30363d', color: '#e6edf3' }}
            data-language={language}
        >
            <div className="flex min-h-0 flex-1 overflow-hidden">
                <div
                    className="w-12 flex-shrink-0 select-none overflow-hidden border-r py-3 pr-3 text-right"
                    style={{ backgroundColor: '#0d1117', borderColor: '#30363d', color: '#6e7681', lineHeight: '21px' }}
                    aria-hidden="true"
                >
                    <div ref={gutterContentRef}>
                        {Array.from({ length: lineCount }, (_, index) => (
                            <div key={index + 1}>{index + 1}</div>
                        ))}
                    </div>
                </div>

                <textarea
                    ref={textareaRef}
                    value={text}
                    onChange={(event) => onChange?.(event.target.value)}
                    onScroll={handleScroll}
                    onKeyDown={handleKeyDown}
                    className="min-h-0 flex-1 resize-none overflow-auto whitespace-pre border-none bg-transparent p-3 outline-none"
                    style={{ color: '#e6edf3', caretColor: '#ffffff', lineHeight: '21px', tabSize: 2 }}
                    spellCheck={false}
                    wrap="off"
                    aria-label={ariaLabel}
                />
            </div>

            {findOpen && (
                <div
                    className="absolute right-2 top-2 z-20 flex w-[min(440px,calc(100%-70px))] flex-col gap-1 rounded-lg p-1.5 shadow-2xl"
                    style={{ backgroundColor: '#161b22', border: '1px solid #30363d' }}
                    onKeyDown={handleWidgetKeyDown}
                >
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => setShowReplace(v => !v)}
                            title={t('landingEditor.toggleReplace', 'Toggle replace')}
                            aria-expanded={showReplace}
                            className="flex h-6 w-5 flex-shrink-0 items-center justify-center rounded transition"
                            style={{ color: '#8b949e' }}
                        >
                            {showReplace ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                        </button>
                        <input
                            ref={findInputRef}
                            type="text"
                            value={findQuery}
                            onChange={(event) => {
                                setFindQuery(event.target.value);
                                setActiveIndex(0);
                            }}
                            placeholder={t('landingEditor.findPlaceholder', 'Find text or regex…')}
                            spellCheck={false}
                            aria-label={t('landingEditor.findPlaceholder', 'Find text or regex…')}
                            className="h-7 min-w-0 flex-1 rounded-md px-2 text-xs outline-none"
                            style={{ backgroundColor: '#0d1117', border: '1px solid #30363d', color: '#e6edf3' }}
                        />
                        {renderModifierToggle(matchCase, 'Aa', t('landingEditor.matchCase', 'Match case'), () => setMatchCase(v => !v))}
                        {renderModifierToggle(wholeWord, '\\b', t('landingEditor.wholeWord', 'Match whole word'), () => setWholeWord(v => !v))}
                        {renderModifierToggle(useRegex, '.*', t('landingEditor.useRegex', 'Use regular expression'), () => setUseRegex(v => !v))}
                        <span className="min-w-[54px] flex-shrink-0 text-right text-[10px]" style={{ color: counter.color }}>
                            {counter.text}
                        </span>
                        {renderIconButton(ChevronUp, t('landingEditor.findPrev', 'Previous match (Shift+Enter)'), () => gotoMatch(activeIndex - 1), !matches.length)}
                        {renderIconButton(ChevronDown, t('landingEditor.findNext', 'Next match (Enter)'), () => gotoMatch(activeIndex + 1), !matches.length)}
                        {renderIconButton(X, t('landingEditor.closeFind', 'Close (Esc)'), closeFind)}
                    </div>

                    {showReplace && (
                        <div className="flex items-center gap-1">
                            <span className="w-5 flex-shrink-0" />
                            <input
                                ref={replaceInputRef}
                                type="text"
                                value={replaceQuery}
                                onChange={(event) => setReplaceQuery(event.target.value)}
                                placeholder={t('landingEditor.replacePlaceholder', 'Replace with (e.g. {offer})…')}
                                spellCheck={false}
                                aria-label={t('landingEditor.replacePlaceholder', 'Replace with (e.g. {offer})…')}
                                className="h-7 min-w-0 flex-1 rounded-md px-2 text-xs outline-none"
                                style={{ backgroundColor: '#0d1117', border: '1px solid #30363d', color: '#e6edf3' }}
                            />
                            <button
                                type="button"
                                onClick={replaceOne}
                                disabled={!matches.length}
                                className="h-7 flex-shrink-0 rounded-md px-2 text-[10px] font-semibold transition"
                                style={{
                                    backgroundColor: '#21262d',
                                    border: '1px solid #30363d',
                                    color: matches.length ? '#e6edf3' : '#484f58'
                                }}
                            >
                                {t('landingEditor.replaceBtn', 'Replace')}
                            </button>
                            <button
                                type="button"
                                onClick={replaceAll}
                                disabled={!matches.length}
                                className="h-7 flex-shrink-0 rounded-md px-2 text-[10px] font-semibold transition"
                                style={{
                                    backgroundColor: matches.length ? '#1f6feb' : '#21262d',
                                    border: '1px solid',
                                    borderColor: matches.length ? '#1f6feb' : '#30363d',
                                    color: matches.length ? '#ffffff' : '#484f58'
                                }}
                            >
                                {t('landingEditor.replaceAllBtn', 'Replace all')}
                            </button>
                        </div>
                    )}

                    {findNotice && (
                        <div className="px-1 text-[10px]" style={{ color: '#3fb950' }}>
                            {findNotice}
                        </div>
                    )}
                </div>
            )}

            <div
                className="flex flex-shrink-0 items-center justify-between gap-3 border-t px-3 py-1.5 text-[10px]"
                style={{ backgroundColor: '#0d1117', borderColor: '#30363d', color: '#8b949e' }}
            >
                <span>UTF-8 · {lineCount} lines · {text.length.toLocaleString()} chars</span>
                <span>{language.toUpperCase()} · Ctrl/Cmd+S</span>
            </div>
        </div>
    );
});

export default CodeEditor;
