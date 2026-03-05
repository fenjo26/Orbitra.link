import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Plus, Edit2, Trash2, Key, Copy, Shield, User, Globe, Lock } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

const UsersPage = () => {
    const { t, setLanguage: setContextLanguage } = useLanguage();
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [showPermissionsModal, setShowPermissionsModal] = useState(false);
    const [showApiKeysModal, setShowApiKeysModal] = useState(false);
    const [currentUser, setCurrentUser] = useState(null);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const [formData, setFormData] = useState({
        username: '',
        password: '',
        role: 'user',
        language: 'ru',
        is_active: 1
    });

    const [permissions, setPermissions] = useState({
        campaigns: { access: 'full', items: [] },
        offers: { access: 'full', items: [] },
        landings: { access: 'full', items: [] },
        sources: { access: 'full', items: [] },
        networks: { access: 'full', items: [] },
        reports: { metrics: true, costs: false, conversions: true }
    });

    useEffect(() => {
        fetchUsers();
    }, []);

    const fetchUsers = async () => {
        try {
            const res = await axios.get(`${API_URL}?action=users`);
            if (res.data.status === 'success') {
                setUsers(res.data.data);
            }
        } catch (err) {
            setError(t('common.error'));
        } finally {
            setLoading(false);
        }
    };

    const showSuccess = (msg) => {
        setSuccess(msg);
        setTimeout(() => setSuccess(''), 3000);
    };

    const openCreateModal = () => {
        setCurrentUser(null);
        setFormData({
            username: '',
            password: '',
            role: 'user',
            language: 'ru',
            is_active: 1
        });
        setError('');
        setShowModal(true);
    };

    const openEditModal = (user) => {
        setCurrentUser(user);
        setFormData({
            username: user.username,
            password: '',
            role: user.role,
            language: user.language || 'ru',
            is_active: user.is_active
        });
        setError('');
        setShowModal(true);
    };

    const openPermissionsModal = (user) => {
        setCurrentUser(user);
        setPermissions(user.permissions || {
            campaigns: { access: 'full', items: [] },
            offers: { access: 'full', items: [] },
            landings: { access: 'full', items: [] },
            sources: { access: 'full', items: [] },
            networks: { access: 'full', items: [] },
            reports: { metrics: true, costs: false, conversions: true }
        });
        setShowPermissionsModal(true);
    };

    const openApiKeysModal = async (user) => {
        try {
            const res = await axios.get(`${API_URL}?action=get_user&id=${user.id}`);
            if (res.data.status === 'success') {
                setCurrentUser({ ...user, api_keys: res.data.data.api_keys || [] });
                setShowApiKeysModal(true);
            }
        } catch (err) {
            setError(t('common.error'));
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!formData.username) {
            setError(t('users.usernamePlaceholder'));
            return;
        }
        if (!currentUser && !formData.password) {
            setError(t('users.passwordPlaceholderNew'));
            return;
        }

        try {
            const data = { ...formData };
            if (currentUser) {
                data.id = currentUser.id;
            }
            const res = await axios.post(`${API_URL}?action=save_user`, data);
            if (res.data.status === 'success') {
                showSuccess(currentUser ? t('common.success') : t('common.success'));
                // If editing current user's language, update the context
                if (data.language) {
                    setContextLanguage(data.language);
                    try {
                        const user = JSON.parse(localStorage.getItem('orbitra_user') || '{}');
                        user.language = data.language;
                        localStorage.setItem('orbitra_user', JSON.stringify(user));
                        window.dispatchEvent(new Event('userUpdated'));
                    } catch (e) { }
                }
                setShowModal(false);
                fetchUsers();
            } else {
                setError(res.data.message);
            }
        } catch (err) {
            setError(err.response?.data?.message || t('common.error'));
        }
    };

    const handleDelete = async (user) => {
        if (!window.confirm(t('users.deleteConfirm'))) return;

        try {
            const res = await axios.post(`${API_URL}?action=delete_user`, { id: user.id });
            if (res.data.status === 'success') {
                showSuccess(t('common.success'));
                fetchUsers();
            } else {
                setError(res.data.message);
            }
        } catch (err) {
            setError(err.response?.data?.message || t('common.error'));
        }
    };

    const handleSavePermissions = async () => {
        try {
            const res = await axios.post(`${API_URL}?action=save_user`, {
                id: currentUser.id,
                username: currentUser.username,
                permissions: permissions
            });
            if (res.data.status === 'success') {
                showSuccess(t('common.success'));
                setShowPermissionsModal(false);
                fetchUsers();
            }
        } catch (err) {
            setError(t('common.error'));
        }
    };

    const generateApiKey = async () => {
        try {
            const res = await axios.post(`${API_URL}?action=generate_api_key`, {
                user_id: currentUser.id,
                key_name: `Key ${(currentUser.api_keys?.length || 0) + 1}`
            });
            if (res.data.status === 'success') {
                showSuccess(t('common.success'));
                openApiKeysModal(currentUser);
            }
        } catch (err) {
            setError(t('common.error'));
        }
    };

    const deleteApiKey = async (keyId) => {
        if (!window.confirm(t('common.deleteConfirm'))) return;
        try {
            await axios.post(`${API_URL}?action=delete_api_key`, { id: keyId });
            showSuccess(t('common.success'));
            openApiKeysModal(currentUser);
        } catch (err) {
            setError(t('common.error'));
        }
    };

    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text);
        showSuccess(t('common.copy'));
    };

    const resources = [
        { key: 'campaigns', label: t('nav.campaigns') },
        { key: 'offers', label: t('nav.offers') },
        { key: 'landings', label: t('nav.landings') },
        { key: 'sources', label: t('nav.sources') },
        { key: 'networks', label: t('nav.networks') }
    ];

    if (loading) {
        return <div className="empty-state"><p style={{ color: 'var(--color-text-muted)' }}>{t('users.loading')}</p></div>;
    }

    return (
        <div className="space-y-4">
            {success && (
                <div className="alert alert-success">{success}</div>
            )}
            {error && (
                <div className="alert alert-danger">{error}</div>
            )}

            {/* Header */}
            <div className="page-card">
                <div className="page-header" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: 0 }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-text-secondary)' }}>
                        {t('users.title')}
                    </p>
                    <button onClick={openCreateModal} className="btn btn-primary btn-sm">
                        <Plus size={18} />
                        <span>{t('common.create')}</span>
                    </button>
                </div>
            </div>

            {/* Users Table */}
            <div className="page-card" style={{ padding: 0 }}>
                <div className="overflow-x-auto">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th>{t('users.username')}</th>
                                <th>{t('users.language') || 'Language'}</th>
                                <th>{t('users.role')}</th>
                                <th>{t('components.status')}</th>
                                <th>{t('users.createdAt')}</th>
                                <th>API {t('common.actions')}</th>
                                <th className="text-right">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td>
                                        <button
                                            onClick={() => openEditModal(user)}
                                            style={{ color: 'var(--color-primary)', fontWeight: 500 }}
                                        >
                                            {user.username}
                                        </button>
                                    </td>
                                    <td>
                                        <span className="status-badge" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' }}>
                                            {user.language === 'en' ? 'English' : 'Русский'}
                                        </span>
                                    </td>
                                    <td>
                                        <span className={`status-badge ${user.role === 'admin' ? 'status-pending' : ''}`}
                                            style={user.role !== 'admin' ? { background: 'var(--color-bg-soft)', color: 'var(--color-text-secondary)' } : {}}>
                                            {user.role === 'admin' ? 'Admin' : 'User'}
                                        </span>
                                    </td>
                                    <td>
                                        <span className={`status-badge ${user.is_active ? 'status-active' : 'status-inactive'}`}>
                                            {user.is_active ? t('components.active') : t('components.paused')}
                                        </span>
                                    </td>
                                    <td style={{ color: 'var(--color-text-secondary)', fontSize: '14px' }}>
                                        {user.last_login || '-'}
                                    </td>
                                    <td>
                                        <button
                                            onClick={() => openApiKeysModal(user)}
                                            style={{ color: 'var(--color-primary)', fontSize: '14px' }}
                                        >
                                            {user.api_keys_count || 0}
                                        </button>
                                    </td>
                                    <td>
                                        <div className="action-buttons">
                                            {user.role !== 'admin' && (
                                                <button
                                                    onClick={() => openPermissionsModal(user)}
                                                    className="action-btn text-blue"
                                                    title={t('users.permissions')}
                                                >
                                                    <Shield size={16} />
                                                </button>
                                            )}
                                            <button
                                                onClick={() => openApiKeysModal(user)}
                                                className="action-btn text-blue"
                                                title="API"
                                            >
                                                <Key size={16} />
                                            </button>
                                            <button
                                                onClick={() => openEditModal(user)}
                                                className="action-btn text-blue"
                                                title={t('users.edit')}
                                            >
                                                <Edit2 size={16} />
                                            </button>
                                            {user.id !== 1 && (
                                                <button
                                                    onClick={() => handleDelete(user)}
                                                    className="action-btn text-red"
                                                    title={t('users.delete')}
                                                >
                                                    <Trash2 size={16} />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {users.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center" style={{ padding: '32px' }}>
                                        <div className="empty-state">
                                            <p className="empty-state-title">{t('users.noUsers')}</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Create/Edit User Modal */}
            {
                showModal && (
                    <div className="modal-overlay">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h3 className="modal-title">
                                    {currentUser ? t('users.edit') : t('users.addUser')}
                                </h3>
                            </div>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="form-label">{t('users.username')} *</label>
                                    <div className="relative">
                                        <User className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type="text"
                                            value={formData.username}
                                            onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                                            className="form-input pl-12"
                                            placeholder={t('users.usernamePlaceholder')}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">{t('profile.newPassword')} {!currentUser && '*'}</label>
                                    <div className="relative">
                                        <Lock className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
                                        <input
                                            type="password"
                                            value={formData.password}
                                            onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                            className="form-input pl-12"
                                            placeholder={currentUser ? t('users.passwordPlaceholder') : t('users.passwordPlaceholderNew')}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">{t('users.language') || 'Language'}</label>
                                    <div className="relative">
                                        <Globe className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
                                        <select
                                            value={formData.language}
                                            onChange={(e) => setFormData({ ...formData, language: e.target.value })}
                                            className="form-select pl-12"
                                        >
                                            <option value="ru">Русский</option>
                                            <option value="en">English</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">{t('users.role')}</label>
                                    <select
                                        value={formData.role}
                                        onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                                        className="form-select"
                                    >
                                        <option value="user">User</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="is_active"
                                        checked={formData.is_active}
                                        onChange={(e) => setFormData({ ...formData, is_active: e.target.checked ? 1 : 0 })}
                                    />
                                    <label htmlFor="is_active" className="form-label" style={{ margin: 0 }}>{t('components.active')}</label>
                                </div>
                                {error && (
                                    <div className="alert alert-danger">{error}</div>
                                )}
                                <div className="modal-footer">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="btn btn-secondary"
                                    >
                                        {t('common.cancel')}
                                    </button>
                                    <button type="submit" className="btn btn-primary">
                                        {currentUser ? t('common.save') : t('common.create')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )
            }

            {/* Permissions Modal */}
            {
                showPermissionsModal && currentUser && (
                    <div className="modal-overlay">
                        <div className="modal-content" style={{ maxWidth: '640px' }}>
                            <div className="modal-header">
                                <h3 className="modal-title">{t('users.permissions')}: {currentUser.username}</h3>
                            </div>

                            <div className="space-y-6">
                                {/* Resources Access */}
                                <div>
                                    <h4 style={{ fontWeight: 500, marginBottom: '12px' }}>{t('users.permissions')}</h4>
                                    <div className="space-y-3">
                                        {resources.map((res) => (
                                            <div key={res.key} className="flex items-center justify-between" style={{ padding: '8px 0', borderBottom: '1px solid var(--color-border)' }}>
                                                <span>{res.label}</span>
                                                <select
                                                    value={permissions[res.key]?.access || 'full'}
                                                    onChange={(e) => setPermissions({
                                                        ...permissions,
                                                        [res.key]: { ...permissions[res.key], access: e.target.value }
                                                    })}
                                                    className="form-select"
                                                    style={{ width: 'auto' }}
                                                >
                                                    <option value="full">Full</option>
                                                    <option value="read">Read only</option>
                                                    <option value="selected">Selected</option>
                                                    <option value="own">Own + Selected</option>
                                                    <option value="none">None</option>
                                                </select>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="modal-footer">
                                <button
                                    onClick={() => setShowPermissionsModal(false)}
                                    className="btn btn-secondary"
                                >
                                    {t('common.cancel')}
                                </button>
                                <button onClick={handleSavePermissions} className="btn btn-primary">
                                    {t('common.save')}
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }

            {/* API Keys Modal */}
            {
                showApiKeysModal && currentUser && (
                    <div className="modal-overlay">
                        <div className="modal-content">
                            <div className="modal-header">
                                <h3 className="modal-title">API: {currentUser.username}</h3>
                            </div>

                            <div className="space-y-3" style={{ marginBottom: '16px' }}>
                                {(currentUser.api_keys || []).map((key) => (
                                    <div key={key.id} className="flex items-center justify-between" style={{ padding: '12px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                                        <div>
                                            <div style={{ fontWeight: 500, fontSize: '14px' }}>{key.key_name}</div>
                                            <code style={{ fontSize: '12px' }}>{key.api_key.substring(0, 16)}...</code>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => copyToClipboard(key.api_key)}
                                                className="action-btn text-blue"
                                                title={t('common.copy')}
                                            >
                                                <Copy size={16} />
                                            </button>
                                            <button
                                                onClick={() => deleteApiKey(key.id)}
                                                className="action-btn text-red"
                                                title={t('common.delete')}
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                                {(!currentUser.api_keys || currentUser.api_keys.length === 0) && (
                                    <div className="empty-state">
                                        <p style={{ color: 'var(--color-text-muted)' }}>-</p>
                                    </div>
                                )}
                            </div>

                            <button
                                onClick={generateApiKey}
                                className="btn btn-secondary"
                                style={{ width: '100%', borderStyle: 'dashed' }}
                            >
                                <Plus size={16} />
                                {t('common.create')}
                            </button>

                            <div className="modal-footer">
                                <button onClick={() => setShowApiKeysModal(false)} className="btn btn-secondary">
                                    {t('common.close')}
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }
        </div >
    );
};

export default UsersPage;