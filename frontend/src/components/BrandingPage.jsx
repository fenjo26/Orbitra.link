import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Sun, Moon, Palette, Check, Monitor, Droplet, RefreshCw, Save } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const DEFAULT_CUSTOM_COLORS = {
    '--color-primary': '#f05a3e',
    '--color-bg-main': '#f4f5f7',
    '--color-bg-card': '#ffffff',
    '--color-text-primary': '#111111'
};

const BrandingPage = () => {
    const { t } = useLanguage();
    const [mode, setMode] = useState('light');
    const [saved, setSaved] = useState(false);
    const [customColors, setCustomColors] = useState(DEFAULT_CUSTOM_COLORS);

    useEffect(() => {
        const savedMode = localStorage.getItem('orbitra_mode') || 'light';
        setMode(savedMode);

        const savedColors = localStorage.getItem('orbitra_custom_colors');
        if (savedColors) {
            try {
                setCustomColors(JSON.parse(savedColors));
            } catch (e) { }
        }
    }, []);

    const applyLiveCustomColors = (colors) => {
        const root = document.documentElement;
        Object.entries(colors).forEach(([key, value]) => {
            root.style.setProperty(key, value);
        });
    };

    const handleModeChange = (newMode) => {
        setMode(newMode);
        localStorage.setItem('orbitra_mode', newMode);

        if (newMode === 'custom') {
            applyLiveCustomColors(customColors);
        }

        window.dispatchEvent(new Event('themeChanged'));
    };

    const handleColorChange = (key, value) => {
        const newColors = { ...customColors, [key]: value };
        setCustomColors(newColors);
        if (mode === 'custom') {
            applyLiveCustomColors(newColors);
        }
    };

    const resetCustomColors = () => {
        setCustomColors(DEFAULT_CUSTOM_COLORS);
        if (mode === 'custom') {
            applyLiveCustomColors(DEFAULT_CUSTOM_COLORS);
        }
    };

    const handleSave = async () => {
        try {
            if (mode === 'custom') {
                localStorage.setItem('orbitra_custom_colors', JSON.stringify(customColors));
            }

            await axios.post(`${API_URL}?action=save_settings`, {
                mode,
                custom_colors: mode === 'custom' ? customColors : null,
                theme: 'default'
            });
            setSaved(true);
            setTimeout(() => setSaved(false), 2000);
            window.dispatchEvent(new Event('themeChanged'));
        } catch (err) {
            console.error('Failed to save settings');
        }
    };

    const handleReset = () => {
        handleModeChange('light');
    };

    return (
        <div className="space-y-6 fade-in">
            {/* Theme Toggle */}
            <div className="card shadow-sm p-6">
                <h3 className="text-lg font-semibold mb-4" style={{ color: 'var(--color-text-primary)' }}>{t('branding.themeTitle')}</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div
                        onClick={() => handleModeChange('light')}
                        className={`border-2 rounded-xl p-4 cursor-pointer transition-all flex items-center gap-3 ${mode === 'light' ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]' : 'border-[var(--color-border)] hover:border-gray-300'
                            }`}
                        style={mode !== 'light' ? { backgroundColor: 'var(--color-bg-card)' } : {}}
                    >
                        <div className={`p-2 rounded-full ${mode === 'light' ? 'bg-[var(--color-primary)] text-white' : 'bg-gray-100 text-gray-500'}`}>
                            <Sun size={20} />
                        </div>
                        <div>
                            <p className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('branding.light')}</p>
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('branding.lightDesc')}</p>
                        </div>
                    </div>
                    <div
                        onClick={() => handleModeChange('dark')}
                        className={`border-2 rounded-xl p-4 cursor-pointer transition-all flex items-center gap-3 ${mode === 'dark' ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]' : 'border-[var(--color-border)] hover:border-gray-600'
                            }`}
                        style={mode !== 'dark' ? { backgroundColor: 'var(--color-bg-card)' } : {}}
                    >
                        <div className={`p-2 rounded-full ${mode === 'dark' ? 'bg-[var(--color-primary)] text-white' : 'bg-gray-800 text-gray-400'}`}>
                            <Moon size={20} />
                        </div>
                        <div>
                            <p className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('branding.dark')}</p>
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('branding.darkDesc')}</p>
                        </div>
                    </div>
                    <div
                        onClick={() => handleModeChange('green')}
                        className={`border-2 rounded-xl p-4 cursor-pointer transition-all flex items-center gap-3 ${mode === 'green' ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]' : 'border-[var(--color-border)] hover:border-green-600'
                            }`}
                        style={mode !== 'green' ? { backgroundColor: 'var(--color-bg-card)' } : {}}
                    >
                        <div className={`p-2 rounded-full ${mode === 'green' ? 'bg-[var(--color-primary)] text-white' : 'bg-green-100 text-green-600'}`}>
                            <Droplet size={20} />
                        </div>
                        <div>
                            <p className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('branding.green')}</p>
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('branding.greenDesc')}</p>
                        </div>
                    </div>
                    <div
                        onClick={() => handleModeChange('neon')}
                        className={`border-2 rounded-xl p-4 cursor-pointer transition-all flex items-center gap-3 ${mode === 'neon' ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]' : 'border-[var(--color-border)] hover:border-[#a3e635]'
                            }`}
                        style={mode !== 'neon' ? { backgroundColor: 'var(--color-bg-card)' } : {}}
                    >
                        <div className={`p-2 rounded-full ${mode === 'neon' ? 'bg-[var(--color-primary)] text-black' : 'bg-gray-800 text-[#a3e635]'}`}>
                            <Monitor size={20} />
                        </div>
                        <div>
                            <p className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('branding.neon')}</p>
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('branding.neonDesc')}</p>
                        </div>
                    </div>
                    <div
                        onClick={() => handleModeChange('custom')}
                        className={`border-2 rounded-xl p-4 cursor-pointer transition-all flex items-center gap-3 md:col-span-2 ${mode === 'custom' ? 'border-[var(--color-primary)] bg-[var(--color-primary-light)]' : 'border-[var(--color-border)] hover:border-blue-500'
                            }`}
                        style={mode !== 'custom' ? { backgroundColor: 'var(--color-bg-card)' } : {}}
                    >
                        <div className={`p-2 rounded-full ${mode === 'custom' ? 'bg-[var(--color-primary)] text-white' : 'bg-blue-100 text-blue-600'}`}>
                            <Palette size={20} />
                        </div>
                        <div>
                            <p className="font-medium" style={{ color: 'var(--color-text-primary)' }}>{t('branding.custom') || 'Custom Theme'}</p>
                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{t('branding.customDesc') || 'Customize colors to match your brand exactly.'}</p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Custom Colors Editor */}
            {mode === 'custom' && (
                <div className="card shadow-sm p-6 fade-in border-t-4 border-[var(--color-primary)]">
                    <div className="flex justify-between items-center mb-6">
                        <div>
                            <h3 className="text-lg font-bold" style={{ color: 'var(--color-text-primary)' }}>{t('branding.customColorsTitle') || 'Custom Color Palette'}</h3>
                            <p className="text-sm mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('branding.customColorsDesc') || 'Click on the color circles to pick your brand colors.'}</p>
                        </div>
                        <button onClick={resetCustomColors} className="text-sm flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[var(--color-bg-soft)] text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-hover)] hover:text-[var(--color-text-primary)] transition">
                            <RefreshCw size={14} />
                            {t('branding.resetColors') || 'Reset Colors'}
                        </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {/* Primary Accent */}
                        <div className="flex items-center gap-3 p-4 bg-[var(--color-bg-soft)] rounded-xl border border-[var(--color-border)] hover:border-[var(--color-primary)] transition-colors group">
                            <div className="relative w-12 h-12 rounded-full overflow-hidden border-4 border-white dark:border-gray-800 shadow-md shrink-0">
                                <input
                                    type="color"
                                    className="absolute -top-4 -left-4 w-24 h-24 cursor-pointer"
                                    value={customColors['--color-primary']}
                                    onChange={(e) => handleColorChange('--color-primary', e.target.value)}
                                />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('branding.primaryAccent') || 'Primary Accent'}</span>
                                <span className="text-xs uppercase font-mono mt-0.5" style={{ color: 'var(--color-text-muted)' }}>{customColors['--color-primary']}</span>
                            </div>
                        </div>

                        {/* Main Background */}
                        <div className="flex items-center gap-3 p-4 bg-[var(--color-bg-soft)] rounded-xl border border-[var(--color-border)] hover:border-[var(--color-primary)] transition-colors group">
                            <div className="relative w-12 h-12 rounded-full overflow-hidden border-4 border-white dark:border-gray-800 shadow-md shrink-0">
                                <input
                                    type="color"
                                    className="absolute -top-4 -left-4 w-24 h-24 cursor-pointer"
                                    value={customColors['--color-bg-main']}
                                    onChange={(e) => handleColorChange('--color-bg-main', e.target.value)}
                                />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('branding.mainBg') || 'Main Background'}</span>
                                <span className="text-xs uppercase font-mono mt-0.5" style={{ color: 'var(--color-text-muted)' }}>{customColors['--color-bg-main']}</span>
                            </div>
                        </div>

                        {/* Card Background */}
                        <div className="flex items-center gap-3 p-4 bg-[var(--color-bg-soft)] rounded-xl border border-[var(--color-border)] hover:border-[var(--color-primary)] transition-colors group">
                            <div className="relative w-12 h-12 rounded-full overflow-hidden border-4 border-white dark:border-gray-800 shadow-md shrink-0">
                                <input
                                    type="color"
                                    className="absolute -top-4 -left-4 w-24 h-24 cursor-pointer"
                                    value={customColors['--color-bg-card']}
                                    onChange={(e) => handleColorChange('--color-bg-card', e.target.value)}
                                />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('branding.cardBg') || 'Card Background'}</span>
                                <span className="text-xs uppercase font-mono mt-0.5" style={{ color: 'var(--color-text-muted)' }}>{customColors['--color-bg-card']}</span>
                            </div>
                        </div>

                        {/* Primary Text */}
                        <div className="flex items-center gap-3 p-4 bg-[var(--color-bg-soft)] rounded-xl border border-[var(--color-border)] hover:border-[var(--color-primary)] transition-colors group">
                            <div className="relative w-12 h-12 rounded-full overflow-hidden border-4 border-white dark:border-gray-800 shadow-md shrink-0">
                                <input
                                    type="color"
                                    className="absolute -top-4 -left-4 w-24 h-24 cursor-pointer"
                                    value={customColors['--color-text-primary']}
                                    onChange={(e) => handleColorChange('--color-text-primary', e.target.value)}
                                />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-sm font-semibold" style={{ color: 'var(--color-text-primary)' }}>{t('branding.textPrimary') || 'Primary Text'}</span>
                                <span className="text-xs uppercase font-mono mt-0.5" style={{ color: 'var(--color-text-muted)' }}>{customColors['--color-text-primary']}</span>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Preview */}
            <div className="card p-6">
                <h3 className="text-lg font-semibold mb-4">{t('branding.preview')}</h3>
                <div
                    className="rounded-lg p-6 border"
                    style={{
                        backgroundColor: 'var(--color-bg-card)',
                        borderColor: 'var(--color-border)'
                    }}
                >
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <h4 className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('branding.buttons')}</h4>
                            <div className="flex gap-2">
                                <button className="btn-primary">Primary</button>
                                <button className="btn-secondary">Secondary</button>
                            </div>
                        </div>
                        <div>
                            <h4 className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('branding.badges')}</h4>
                            <div className="flex gap-2">
                                <span className="badge badge-success">Success</span>
                                <span className="badge badge-warning">Warning</span>
                                <span className="badge badge-danger">Danger</span>
                            </div>
                        </div>
                        <div>
                            <h4 className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('branding.forms')}</h4>
                            <input type="text" placeholder="Input example" className="w-full" />
                        </div>
                        <div>
                            <h4 className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>{t('branding.links')}</h4>
                            <a href="#">{t('branding.linkExample')}</a>
                        </div>
                    </div>
                </div>
            </div>

            {/* Actions */}
            <div className="flex justify-between items-center">
                <button
                    onClick={handleReset}
                    className="flex items-center gap-2 px-4 py-2 border rounded hover:bg-[var(--color-bg-hover)] transition-colors"
                >
                    <RefreshCw size={18} />
                    {t('branding.reset')}
                </button>

                <button
                    onClick={handleSave}
                    className={`flex items-center gap-2 px-6 py-2 rounded text-white transition-all ${saved ? 'bg-green-500' : 'btn-primary'
                        }`}
                >
                    {saved ? (
                        <>
                            <Check size={18} />
                            {t('branding.saved')}
                        </>
                    ) : (
                        <>
                            <Save size={18} />
                            {t('branding.saveSettings')}
                        </>
                    )}
                </button>
            </div>
        </div>
    );
};

export default BrandingPage;