import React, { useState, useEffect } from 'react';
import { useLanguage } from '../../contexts/LanguageContext';

/**
 * Dual-mode proxy entry. Vendors hand out proxies either as a ready URL
 * (http://user:pass@host:port) or as columns / ip:port:user:pass — so the
 * component keeps one canonical URL string in the form state (what the
 * engines' parse_url()-based applyProxy expects) and lets the operator work
 * in whichever shape they received. Pasting a colon-format string converts
 * on the fly; the separate fields stay in sync for free.
 */
export const ProxyInput = ({ value, onChange, label }) => {
    const { t } = useLanguage();
    const [mode, setMode] = useState('string'); // 'string' | 'blocks'
    const [parts, setParts] = useState({ protocol: 'http', host: '', port: '', user: '', pass: '' });

    // Parse the canonical string into the separate fields (best effort — a
    // half-typed URL simply leaves them as they were).
    useEffect(() => {
        if (!value) {
            setParts(prev => (prev.host === '' && prev.port === '' ? prev : { protocol: prev.protocol, host: '', port: '', user: '', pass: '' }));
            return;
        }
        try {
            if (value.includes('://')) {
                const url = new URL(value);
                setParts({
                    protocol: url.protocol.replace(':', '') || 'http',
                    host: url.hostname || '',
                    port: url.port || '',
                    user: decodeURIComponent(url.username || ''),
                    pass: decodeURIComponent(url.password || '')
                });
                return;
            }
            const segments = value.split(':');
            if (segments.length === 4) {
                // ip:port:user:pass
                setParts({ protocol: 'http', host: segments[0], port: segments[1], user: segments[2], pass: segments[3] });
            }
        } catch (e) {
            // Incomplete input while typing — keep the fields as they are.
        }
    }, [value]);

    const handlePartChange = (key, val) => {
        const next = { ...parts, [key]: val };
        setParts(next);

        if (!next.host) {
            onChange('');
            return;
        }
        let full = `${next.protocol || 'http'}://`;
        if (next.user || next.pass) {
            full += `${encodeURIComponent(next.user || '')}:${encodeURIComponent(next.pass || '')}@`;
        }
        full += next.host;
        if (next.port) {
            full += `:${next.port}`;
        }
        onChange(full);
    };

    const handleStringChange = (str) => {
        let clean = str.trim();
        const segments = clean.split(':');
        if (segments.length === 4 && !clean.includes('://')) {
            // ip:port:user:pass → canonical URL, so the engines' parse_url() sees it
            clean = `http://${segments[2]}:${segments[3]}@${segments[0]}:${segments[1]}`;
        }
        onChange(clean);
    };

    return (
        <div className="space-y-2">
            <div className="flex justify-between items-center gap-2">
                {label !== undefined && <label className="form-label mb-0">{label}</label>}
                <div className="flex p-0.5 rounded-lg border text-[11px]" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                    <button
                        type="button"
                        onClick={() => setMode('string')}
                        className="px-2 py-0.5 rounded-md font-medium transition"
                        style={mode === 'string'
                            ? { backgroundColor: 'var(--color-primary)', color: '#ffffff' }
                            : { color: 'var(--color-text-muted)' }}
                    >
                        {t('proxy.singleString', 'Single string')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setMode('blocks')}
                        className="px-2 py-0.5 rounded-md font-medium transition"
                        style={mode === 'blocks'
                            ? { backgroundColor: 'var(--color-primary)', color: '#ffffff' }
                            : { color: 'var(--color-text-muted)' }}
                    >
                        {t('proxy.separateFields', 'Separate fields')}
                    </button>
                </div>
            </div>

            {mode === 'string' ? (
                <div>
                    <input
                        type="text"
                        value={value || ''}
                        onChange={(e) => handleStringChange(e.target.value)}
                        className="form-input font-mono text-xs"
                        placeholder="http://user:pass@1.2.3.4:8080"
                        spellCheck="false"
                    />
                    <p className="text-[11px] mt-1" style={{ color: 'var(--color-text-muted)' }}>
                        {t('proxy.hint', 'Supports http://user:pass@ip:port and ip:port:user:pass formats')}
                    </p>
                </div>
            ) : (
                <div className="grid grid-cols-12 gap-2">
                    <div className="col-span-3 sm:col-span-2">
                        <select
                            value={parts.protocol}
                            onChange={(e) => handlePartChange('protocol', e.target.value)}
                            className="form-select text-xs"
                            aria-label="Protocol"
                        >
                            <option value="http">HTTP</option>
                            <option value="https">HTTPS</option>
                            <option value="socks5">SOCKS5</option>
                        </select>
                    </div>
                    <div className="col-span-9 sm:col-span-4">
                        <input
                            type="text"
                            placeholder={t('proxy.host', 'Host / IP')}
                            value={parts.host}
                            onChange={(e) => handlePartChange('host', e.target.value)}
                            className="form-input text-xs font-mono"
                            spellCheck="false"
                        />
                    </div>
                    <div className="col-span-4 sm:col-span-2">
                        <input
                            type="text"
                            placeholder={t('proxy.port', 'Port')}
                            value={parts.port}
                            onChange={(e) => handlePartChange('port', e.target.value.replace(/[^0-9]/g, ''))}
                            className="form-input text-xs font-mono"
                            inputMode="numeric"
                        />
                    </div>
                    <div className="col-span-4 sm:col-span-2">
                        <input
                            type="text"
                            placeholder={t('proxy.username', 'User')}
                            value={parts.user}
                            onChange={(e) => handlePartChange('user', e.target.value)}
                            className="form-input text-xs font-mono"
                            spellCheck="false"
                            autoComplete="off"
                        />
                    </div>
                    <div className="col-span-4 sm:col-span-2">
                        <input
                            type="password"
                            placeholder={t('proxy.password', 'Pass')}
                            value={parts.pass}
                            onChange={(e) => handlePartChange('pass', e.target.value)}
                            className="form-input text-xs font-mono"
                            autoComplete="new-password"
                        />
                    </div>
                </div>
            )}
        </div>
    );
};

export default ProxyInput;
