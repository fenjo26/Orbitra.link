import { useRef, useState } from 'react';
import { Check, FileArchive, UploadCloud } from 'lucide-react';

const acceptsFile = (file, accept) => {
    if (!accept) return true;
    const rules = accept.split(',').map(rule => rule.trim().toLowerCase()).filter(Boolean);
    return rules.some(rule => (
        rule.startsWith('.')
            ? file.name.toLowerCase().endsWith(rule)
            : file.type.toLowerCase() === rule
    ));
};

export const FileDropzone = ({
    file,
    onFileSelect,
    accept = '.zip',
    label = 'Upload ZIP Archive',
    emptyHint = 'Drag & drop .zip here or click to browse files',
    replaceHint = 'Click or drop another file to replace',
    disabled = false
}) => {
    const inputRef = useRef(null);
    const [isDragging, setIsDragging] = useState(false);

    const selectFile = (nextFile) => {
        if (!nextFile || disabled || !acceptsFile(nextFile, accept)) return;
        onFileSelect(nextFile);
    };

    const openPicker = () => {
        if (!disabled) inputRef.current?.click();
    };

    return (
        <div
            role="button"
            tabIndex={disabled ? -1 : 0}
            aria-disabled={disabled}
            onClick={openPicker}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openPicker();
                }
            }}
            onDragEnter={(event) => {
                event.preventDefault();
                if (!disabled) setIsDragging(true);
            }}
            onDragOver={(event) => event.preventDefault()}
            onDragLeave={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) setIsDragging(false);
            }}
            onDrop={(event) => {
                event.preventDefault();
                setIsDragging(false);
                selectFile(event.dataTransfer.files?.[0]);
            }}
            className="border-2 border-dashed rounded-2xl p-6 text-center transition-all hover:border-[var(--color-primary)] flex flex-col items-center justify-center gap-2 group"
            style={{
                cursor: disabled ? 'wait' : 'pointer',
                opacity: disabled ? 0.65 : 1,
                backgroundColor: isDragging ? 'var(--color-primary-light)' : 'var(--color-bg-soft)',
                borderColor: file ? 'var(--color-success)' : isDragging ? 'var(--color-primary)' : 'var(--color-border)'
            }}
        >
            <input
                ref={inputRef}
                type="file"
                accept={accept}
                className="hidden"
                disabled={disabled}
                onChange={(event) => {
                    selectFile(event.target.files?.[0]);
                    event.target.value = '';
                }}
            />
            <div
                className="w-12 h-12 rounded-2xl flex items-center justify-center transition-transform group-hover:scale-110"
                style={{
                    backgroundColor: file ? 'rgba(34, 197, 94, 0.12)' : 'var(--color-bg-card)',
                    color: file ? 'var(--color-success)' : 'var(--color-primary)'
                }}
            >
                {file ? <Check className="w-6 h-6" /> : isDragging ? <FileArchive className="w-6 h-6" /> : <UploadCloud className="w-6 h-6" />}
            </div>
            <div>
                <div className="text-xs font-semibold" style={{ color: 'var(--color-text-primary)' }}>
                    {file ? file.name : label}
                </div>
                <div className="text-[11px] mt-0.5" style={{ color: 'var(--color-text-muted)' }}>
                    {file
                        ? `${(file.size / 1024 / 1024).toFixed(2)} MB · ${replaceHint}`
                        : emptyHint}
                </div>
            </div>
        </div>
    );
};

export default FileDropzone;
