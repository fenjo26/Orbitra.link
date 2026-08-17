import React, { forwardRef, useCallback, useImperativeHandle, useRef } from 'react';

const INDENT = '  ';
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

/**
 * Lightweight dependency-free editor used for landing assets. It deliberately
 * keeps a real textarea (native selection, IME and accessibility) while adding
 * a synchronized gutter, indentation helpers and an imperative insertion API
 * for the landing macro toolbar.
 */
export const CodeEditor = forwardRef(function CodeEditor({
    value = '',
    onChange,
    onSave,
    language = 'text',
    ariaLabel = 'Code editor'
}, forwardedRef) {
    const textareaRef = useRef(null);
    const gutterContentRef = useRef(null);
    const text = String(value ?? '');
    const lineCount = Math.max(1, text.split('\n').length);

    const restoreSelection = useCallback((start, end = start) => {
        window.requestAnimationFrame(() => {
            const textarea = textareaRef.current;
            if (!textarea) return;
            textarea.focus();
            textarea.setSelectionRange(start, end);
        });
    }, []);

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
        setSelection: restoreSelection
    }), [replaceRange, restoreSelection]);

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
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            onSave?.();
            return;
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

    return (
        <div
            className="flex h-full min-h-0 w-full flex-col overflow-hidden rounded-xl border font-mono text-[13px]"
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
