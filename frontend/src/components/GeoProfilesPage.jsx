import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Globe, Plus, Edit2, Trash2, X, Check, Search, MapPin } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { localizedCountryName } from '../utils/countryNames';

const API_URL = '/api.php';

const GeoProfilesPage = () => {
    const { language, t } = useLanguage();
    const [profiles, setProfiles] = useState([]);
    const [countries, setCountries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [editingProfile, setEditingProfile] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [formData, setFormData] = useState({
        name: '',
        countries: []
    });
    const [countrySearch, setCountrySearch] = useState('');

    useEffect(() => {
        loadData();
    }, []);

    const loadData = async () => {
        try {
            const [profilesRes, countriesRes] = await Promise.all([
                axios.get(`${API_URL}?action=geo_profiles`),
                axios.get(`${API_URL}?action=countries_list`)
            ]);
            setProfiles(profilesRes.data.data || []);
            setCountries(countriesRes.data.data || []);
            setLoading(false);
        } catch (err) {
            console.error('Failed to load data:', err);
            setLoading(false);
        }
    };

    const openModal = (profile = null) => {
        if (profile) {
            setEditingProfile(profile);
            setFormData({
                name: profile.name,
                countries: profile.countries || []
            });
        } else {
            setEditingProfile(null);
            setFormData({ name: '', countries: [] });
        }
        setCountrySearch('');
        setShowModal(true);
    };

    const closeModal = () => {
        setShowModal(false);
        setEditingProfile(null);
        setFormData({ name: '', countries: [] });
    };

    const handleSave = async () => {
        if (!formData.name.trim()) {
            alert(t('geoProfiles.enterProfileName'));
            return;
        }
        if (formData.countries.length === 0) {
            alert(t('geoProfiles.selectAtLeastOne'));
            return;
        }

        try {
            const payload = {
                name: formData.name,
                countries: formData.countries
            };
            if (editingProfile) {
                payload.id = editingProfile.id;
            }

            await axios.post(`${API_URL}?action=save_geo_profile`, payload);
            closeModal();
            loadData();
        } catch (err) {
            alert(t('geoProfiles.saveError') + ' ' + (err.response?.data?.message || err.message));
        }
    };

    const handleDelete = async (id) => {
        if (!confirm(t('geoProfiles.deleteConfirm'))) return;

        try {
            await axios.post(`${API_URL}?action=delete_geo_profile`, { id });
            loadData();
        } catch (err) {
            alert(t('geoProfiles.deleteError') + ' ' + (err.response?.data?.message || err.message));
        }
    };

    const toggleCountry = (code) => {
        setFormData(prev => ({
            ...prev,
            countries: prev.countries.includes(code)
                ? prev.countries.filter(c => c !== code)
                : [...prev.countries, code]
        }));
    };

    const selectAllVisible = () => {
        const visibleCodes = filteredCountries.map(c => c.code);
        const newCountries = [...new Set([...formData.countries, ...visibleCodes])];
        setFormData(prev => ({ ...prev, countries: newCountries }));
    };

    const deselectAll = () => {
        setFormData(prev => ({ ...prev, countries: [] }));
    };

    const getCountryName = (code) => {
        const country = countries.find(c => c.code === code);
        return localizedCountryName(code, language, country ? country.name : undefined);
    };

    const filteredCountries = countries.filter(c =>
        localizedCountryName(c.code, language, c.name).toLowerCase().includes(countrySearch.toLowerCase()) ||
        c.code.toLowerCase().includes(countrySearch.toLowerCase())
    );

    const filteredProfiles = profiles.filter(p =>
        p.name.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const getCountryFlag = (code) => {
        return code.toUpperCase().replace(/./g, char =>
            String.fromCodePoint(127397 + char.charCodeAt())
        );
    };

    if (loading) {
        return <div className="text-center py-10">{t('geoProfiles.loading')}</div>;
    }

    return (
        <div className="space-y-6 fade-in">
            {/* Header */}
            <div className="flex justify-between items-center">
                <div className="flex items-center gap-4 flex-1">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2" size={18} style={{ color: 'var(--color-text-muted)' }} />
                        <input
                            type="text"
                            placeholder={t('geoProfiles.searchPlaceholder')}
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="form-input pl-12"
                        />
                    </div>
                </div>
                <button
                    onClick={() => openModal()}
                    className="btn-primary flex items-center gap-2"
                >
                    <Plus size={18} />
                    {t('geoProfiles.createProfile')}
                </button>
            </div>

            {/* Profiles List */}
            <div className="card overflow-hidden">
                <table className="w-full">
                    <thead>
                        <tr>
                            <th>{t('geoProfiles.profileName')}</th>
                            <th>{t('geoProfiles.countriesSelected').replace(':', '')}</th>
                            <th className="w-24">{t('geoDb.colActions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredProfiles.length === 0 ? (
                            <tr>
                                <td colSpan="3" className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                    {profiles.length === 0
                                        ? t('geoProfiles.noProfiles')
                                        : t('geoProfiles.notFound')}
                                </td>
                            </tr>
                        ) : (
                            filteredProfiles.map(profile => (
                                <tr key={profile.id}>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <Globe size={18} style={{ color: 'var(--color-primary)' }} />
                                            <span className="font-medium">{profile.name}</span>
                                            {profile.is_template && (
                                                <span className="text-xs px-2 py-0.5 rounded" style={{ backgroundColor: 'var(--color-primary-light)', color: 'var(--color-primary)' }}>
                                                    {t('geoProfiles.template')}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap gap-1 max-w-lg">
                                            {(profile.countries || []).slice(0, 10).map(code => (
                                                <span
                                                    key={code}
                                                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium"
                                                    style={{
                                                        backgroundColor: 'var(--color-bg-soft)',
                                                        color: 'var(--color-text-primary)',
                                                        border: '1px solid var(--color-border)'
                                                    }}
                                                    title={getCountryName(code)}
                                                >
                                                    <span className="text-sm leading-none">{getCountryFlag(code)}</span>
                                                    <span>{code}</span>
                                                </span>
                                            ))}
                                            {(profile.countries || []).length > 10 && (
                                                <span className="text-xs font-medium self-center" style={{ color: 'var(--color-text-muted)' }}>
                                                    +{(profile.countries || []).length - 10} {t('geoProfiles.more')}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td>
                                        <div className="flex gap-2">
                                            <button
                                                onClick={() => openModal(profile)}
                                                className="p-1 rounded transition-colors hover:bg-[var(--color-bg-hover)]"
                                                style={{ color: 'var(--color-text-secondary)' }}
                                                title={t('geoProfiles.edit')}
                                            >
                                                <Edit2 size={16} />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(profile.id)}
                                                className="p-1 rounded transition-colors text-[var(--color-danger)] hover:bg-[var(--color-danger-bg)]"
                                                title={t('geoProfiles.delete')}
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Modal */}
            {showModal && (
                <div className="modal-overlay">
                    <div className="modal-content" style={{ maxWidth: '800px', backgroundColor: 'var(--color-bg-card)', color: 'var(--color-text-primary)' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <MapPin size={20} style={{ color: 'var(--color-primary)' }} />
                                {editingProfile ? t('geoProfiles.editProfile') : t('geoProfiles.createProfile')}
                            </h3>
                            <button onClick={closeModal} className="action-btn">
                                <X size={20} />
                            </button>
                        </div>

                        <div>
                            <div className="mb-4">
                                <label className="form-label">{t('geoProfiles.profileName')}</label>
                                <input
                                    type="text"
                                    value={formData.name}
                                    onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                                    className="form-input"
                                    placeholder={t('geoProfiles.namePlaceholder')}
                                />
                            </div>

                            <div className="mb-2 flex justify-between items-center">
                                <label className="text-sm font-medium">
                                    {t('geoProfiles.countriesSelected')} <span style={{ color: 'var(--color-primary)' }}>{formData.countries.length}</span>
                                </label>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={selectAllVisible}
                                        className="text-sm hover:underline"
                                        style={{ color: 'var(--color-primary)' }}
                                    >
                                        {t('geoProfiles.selectAll')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={deselectAll}
                                        className="text-sm hover:underline"
                                        style={{ color: 'var(--color-danger)' }}
                                    >
                                        {t('geoProfiles.clear')}
                                    </button>
                                </div>
                            </div>

                            <div className="mb-4">
                                <div className="relative mb-3">
                                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2" size={16} style={{ color: 'var(--color-text-muted)' }} />
                                    <input
                                        type="text"
                                        placeholder={t('geoProfiles.countrySearch')}
                                        value={countrySearch}
                                        onChange={(e) => setCountrySearch(e.target.value)}
                                        className="form-input pl-12"
                                    />
                                </div>

                                <div className="border rounded-lg max-h-64 overflow-y-auto" style={{ borderColor: 'var(--color-border)' }}>
                                    <div className="grid grid-cols-3 md:grid-cols-4 gap-1 p-2">
                                        {filteredCountries.map(country => (
                                            <button
                                                key={country.code}
                                                onClick={() => toggleCountry(country.code)}
                                                className={`flex items-center gap-1 px-2 py-1 rounded text-sm text-left transition-colors border ${formData.countries.includes(country.code)
                                                    ? 'bg-[var(--color-primary-light)] text-[var(--color-primary)] font-semibold border-[var(--color-primary)]'
                                                    : 'hover:bg-[var(--color-bg-hover)] text-[var(--color-text-primary)] border-transparent'
                                                    }`}
                                            >
                                                <span className="text-base">{getCountryFlag(country.code)}</span>
                                                <span className="truncate">{getCountryName(country.code)}</span>
                                                {formData.countries.includes(country.code) && (
                                                    <Check size={14} className="ml-auto flex-shrink-0" style={{ color: 'var(--color-primary)' }} />
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {/* Selected countries preview */}
                            {formData.countries.length > 0 && (
                                <div className="border rounded-xl p-3" style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}>
                                    <div className="text-sm font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('geoProfiles.selectedCountries')}
                                    </div>
                                    <div className="flex flex-wrap gap-1 max-h-32 overflow-y-auto">
                                        {formData.countries.map(code => (
                                            <span
                                                key={code}
                                                className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium"
                                                style={{
                                                    backgroundColor: 'var(--color-bg-card)',
                                                    color: 'var(--color-text-primary)',
                                                    border: '1px solid var(--color-border)'
                                                }}
                                            >
                                                <span>{getCountryFlag(code)}</span>
                                                <span>{code}</span>
                                                <button
                                                    onClick={() => toggleCountry(code)}
                                                    className="ml-1 hover:text-[var(--color-danger)] transition-colors"
                                                    title="Remove"
                                                >
                                                    <X size={12} />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="modal-footer">
                            <button
                                onClick={closeModal}
                                className="btn btn-secondary"
                            >
                                {t('geoProfiles.cancel')}
                            </button>
                            <button
                                onClick={handleSave}
                                className="btn-primary flex items-center gap-2"
                            >
                                <Check size={18} />
                                {editingProfile ? t('geoProfiles.save') : t('geoProfiles.createProfile')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default GeoProfilesPage;