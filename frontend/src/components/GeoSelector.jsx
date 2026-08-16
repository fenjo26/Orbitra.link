import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { X, ChevronDown, Check, Type, Plus, MapPin, Globe } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const getCountryFlag = (code) => {
    if (!code || code === 'Unknown' || code === '??') return '🏳️';
    if (code.length !== 2) return '📍';
    return code.toUpperCase().replace(/./g, char =>
        String.fromCodePoint(127397 + char.charCodeAt())
    );
};

const GeoSelector = ({ value = '', onChange, placeholder }) => {
    const { t } = useLanguage();
    const [mode, setMode] = useState('select');
    const [isOpen, setIsOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [countries, setCountries] = useState([]);
    const [profiles, setProfiles] = useState([]);
    const [showProfiles, setShowProfiles] = useState(false);
    const [textareaValue, setTextareaValue] = useState('');

    const wrapperRef = useRef(null);
    const inputRef = useRef(null);

    const selectedCodes = typeof value === 'string' && value.trim() ? value.split(',').map(c => c.trim()).filter(Boolean) : [];

    useEffect(() => {
        const loadData = async () => {
            try {
                const [cRes, pRes] = await Promise.all([
                    axios.get(`${API_URL}?action=countries_list`),
                    axios.get(`${API_URL}?action=geo_profiles`)
                ]);
                if (cRes.data?.data) setCountries(cRes.data.data);
                if (pRes.data?.data) setProfiles(pRes.data.data);
            } catch (err) {
                console.error('Failed to load geo data', err);
            }
        };
        loadData();
    }, []);

    useEffect(() => {
        const handleClickOutside = (event) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target)) {
                setIsOpen(false);
                setShowProfiles(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (mode === 'textarea') {
            setTextareaValue(selectedCodes.join(' '));
        }
    }, [mode, value]);

    const handleTextareaChange = (e) => {
        setTextareaValue(e.target.value);
    };

    const handleTextareaBlur = () => {
        parseTextarea(textareaValue);
    };

    const parseTextarea = (text) => {
        const codes = text.split(/[\s,]+/).map(c => c.trim().toUpperCase()).filter(Boolean);
        const uniqueCodes = [...new Set(codes)];
        onChange(uniqueCodes.join(','));
    };

    const toggleMode = () => {
        if (mode === 'textarea') {
            parseTextarea(textareaValue);
        }
        setMode(mode === 'select' ? 'textarea' : 'select');
    };

    const addCode = (code) => {
        if (!selectedCodes.includes(code)) {
            const newCodes = [...selectedCodes, code];
            onChange(newCodes.join(','));
        }
        setSearch('');
        if (inputRef.current) inputRef.current.focus();
    };

    const removeCode = (code) => {
        const newCodes = selectedCodes.filter(c => c !== code);
        onChange(newCodes.join(','));
    };

    const addUnknown = () => {
        addCode('Unknown');
    };

    const handleProfileSelect = (profile) => {
        const newCodes = [...new Set([...selectedCodes, ...(profile.countries || [])])];
        onChange(newCodes.join(','));
        setShowProfiles(false);
        setIsOpen(false);
    };

    const getCountryName = (code) => {
        if (code === 'Unknown') return t('geoSelector.unknown');
        const c = countries.find(x => x.code === code);
        return c ? c.name : code;
    };

    const filteredCountries = countries.filter(c =>
        c.name.toLowerCase().includes(search.toLowerCase()) ||
        c.code.toLowerCase().includes(search.toLowerCase())
    );

    return (
        <div className="w-full relative" ref={wrapperRef}>
            {/* Toolbar — kept above the input so the opening dropdown never covers it */}
            <div className="flex items-center justify-between mb-2 flex-wrap gap-y-1">
                <button
                    type="button"
                    onClick={toggleMode}
                    className="text-xs text-[var(--color-primary)] hover:underline flex items-center gap-1 font-medium transition-colors"
                >
                    <Type size={12} />
                    {mode === 'select' ? t('geoSelector.switchToTextarea') : t('geoSelector.switchToSelect')}
                </button>

                {mode === 'select' && (
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                setIsOpen(false);
                                setShowProfiles(!showProfiles);
                            }}
                            className="text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:underline flex items-center gap-1 transition-colors"
                        >
                            <MapPin size={12} />
                            {t('geoSelector.insertFromProfile')}
                        </button>
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                addUnknown();
                            }}
                            className="text-xs text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:underline flex items-center gap-1 transition-colors"
                        >
                            <Plus size={12} />
                            {t('geoSelector.addUnknown')}
                        </button>
                    </div>
                )}
            </div>

            {mode === 'select' ? (
                <div
                    className={`min-h-[42px] border rounded-[4px] p-1.5 flex flex-wrap items-center gap-1.5 cursor-text transition-colors bg-[var(--color-bg-card)] ${isOpen
                        ? 'border-[var(--color-primary)] ring-1 ring-[var(--color-primary)]'
                        : 'border-[var(--color-border)] hover:border-[var(--color-text-muted)]'}`}
                    onClick={() => {
                        setIsOpen(true);
                        if (inputRef.current) inputRef.current.focus();
                    }}
                >
                    {selectedCodes.map(code => (
                        <div key={code} className="flex items-center gap-1 bg-[var(--color-bg-soft)] border border-[var(--color-border)] text-[var(--color-text-primary)] px-2 py-0.5 rounded-[4px] text-[13px]">
                            <span>{getCountryFlag(code)}</span>
                            <span className="font-medium">{code}</span>
                            <span className="text-[var(--color-text-muted)] max-w-[100px] truncate" title={getCountryName(code)}>
                                {getCountryName(code) !== code ? `(${getCountryName(code)})` : ''}
                            </span>
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    removeCode(code);
                                }}
                                className="ml-0.5 text-[var(--color-text-muted)] hover:text-[var(--color-danger)] hover:bg-[var(--color-danger-bg)] rounded-full p-0.5 transition-colors"
                            >
                                <X size={12} />
                            </button>
                        </div>
                    ))}
                    <div className="flex-1 min-w-[120px]">
                        <input
                            ref={inputRef}
                            type="text"
                            value={search}
                            onChange={e => {
                                setSearch(e.target.value);
                                setIsOpen(true);
                                setShowProfiles(false);
                            }}
                            className="w-full bg-transparent border-none outline-none text-sm p-1 text-[var(--color-text-primary)] placeholder:text-[var(--color-text-muted)]"
                            placeholder={selectedCodes.length === 0 ? (placeholder || t('geoSelector.placeholder')) : ''}
                        />
                    </div>
                    <div className="absolute right-2 top-1/2 transform -translate-y-1/2 text-[var(--color-text-muted)] pointer-events-none">
                        <ChevronDown size={16} />
                    </div>
                </div>
            ) : (
                <textarea
                    value={textareaValue}
                    onChange={handleTextareaChange}
                    onBlur={handleTextareaBlur}
                    className="form-input text-sm uppercase min-h-[84px] resize-y"
                    placeholder={t('geoSelector.placeholder')}
                />
            )}

            {/* Dropdown for smart search */}
            {mode === 'select' && isOpen && !showProfiles && (
                <div className="absolute top-full left-0 w-full mt-1 bg-[var(--color-bg-dropdown)] border border-[var(--color-border)] rounded-[4px] shadow-lg z-50 max-h-60 overflow-y-auto">
                    {filteredCountries.length > 0 ? (
                        filteredCountries.map(country => (
                            <button
                                key={country.code}
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    addCode(country.code);
                                }}
                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-bg-hover)] flex items-center justify-between group transition-colors"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="text-lg leading-none">{getCountryFlag(country.code)}</span>
                                    <span className="text-sm font-medium text-[var(--color-text-primary)] group-hover:text-[var(--color-primary)]">{country.name}</span>
                                    <span className="text-xs text-[var(--color-text-muted)]">{country.code}</span>
                                </div>
                                {selectedCodes.includes(country.code) && (
                                    <Check size={16} className="text-[var(--color-primary)]" />
                                )}
                            </button>
                        ))
                    ) : (
                        <div className="px-3 py-3 text-sm text-[var(--color-text-muted)] text-center">
                            {t('geoSelector.nothingFound')}
                        </div>
                    )}
                </div>
            )}

            {/* Profiles Dropdown */}
            {mode === 'select' && showProfiles && (
                <div className="absolute top-full left-0 w-full mt-1 bg-[var(--color-bg-dropdown)] border border-[var(--color-border)] rounded-[4px] shadow-lg z-50 max-h-60 overflow-y-auto">
                    <div className="px-3 py-2 border-b border-[var(--color-border)] bg-[var(--color-bg-soft)] text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wider">
                        {t('geoSelector.geoProfiles')}
                    </div>
                    {profiles.length > 0 ? (
                        profiles.map(profile => (
                            <button
                                key={profile.id}
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    handleProfileSelect(profile);
                                }}
                                className="w-full text-left px-3 py-2 hover:bg-[var(--color-primary-light)] flex items-center gap-2 transition-colors border-b border-[var(--color-border)] last:border-0 group"
                            >
                                <Globe size={14} className="text-[var(--color-primary)]" />
                                <div>
                                    <div className="text-sm font-medium text-[var(--color-text-primary)] group-hover:text-[var(--color-primary)]">{profile.name}</div>
                                    <div className="text-xs text-[var(--color-text-muted)] truncate max-w-[300px]">
                                        {(profile.countries || []).join(', ')}
                                    </div>
                                </div>
                            </button>
                        ))
                    ) : (
                        <div className="px-3 py-3 text-sm text-[var(--color-text-muted)] text-center">
                            {t('geoSelector.noProfiles')}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default GeoSelector;
