import React, { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { Plus, Edit2, Trash2, Key, Copy, Check, Shield, User, Globe, Lock, Link2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { ROLE_TEMPLATES, templatePermissions, detectTemplate } from '../utils/roleTemplates';
import { copyToClipboard } from '../utils/clipboard';

const API_URL = '/api.php';

// Full-access defaults in the real permission structure; templates overwrite
// these. 'finance' rides inside permissions_json — the backend masking reads
// it from there.
const DEFAULT_PERMISSIONS = () => ({
    campaigns: { access: 'full', items: [] },
    offers: { access: 'full', items: [] },
    landings: { access: 'full', items: [] },
    sources: { access: 'full', items: [] },
    networks: { access: 'full', items: [] },
    domains: { access: 'full', items: [] },
    logs: { access: 'full', items: [] },
    finance: { show_costs: true, show_revenue: true, show_payout: true },
});

const UsersPage = () => {
    const { t, setLanguage: setContextLanguage, language: currentLanguage } = useLanguage();
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [showPermissionsModal, setShowPermissionsModal] = useState(false);
    const [showApiKeysModal, setShowApiKeysModal] = useState(false);
    const [campaignOptions, setCampaignOptions] = useState([]);
    const [currentUser, setCurrentUser] = useState(null);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [credentialFieldReady, setCredentialFieldReady] = useState({ username: false, password: false });
    const usernameInputRef = useRef(null);
    const passwordInputRef = useRef(null);

    const [formData, setFormData] = useState({
        username: '',
        password: '',
        role: 'user',
        language: currentLanguage,
        is_active: 1
    });

    const [permissions, setPermissions] = useState(DEFAULT_PERMISSIONS());
    const [selectedTemplate, setSelectedTemplate] = useState('custom');

    useEffect(() => {
        fetchUsers();
    }, []);

    useEffect(() => {
        if (!showModal) return;

        const syncAutofill = () => {
            const domUsername = usernameInputRef.current?.value || '';
            const domPassword = passwordInputRef.current?.value || '';

            if (domUsername && domUsername !== formData.username) {
                setFormData(prev => ({ ...prev, username: domUsername }));
            }
            if (domPassword && domPassword !== formData.password) {
                setFormData(prev => ({ ...prev, password: domPassword }));
            }
            if (domUsername || domPassword) {
                setCredentialFieldReady(prev => {
                    const next = {
                        username: prev.username || !!domUsername,
                        password: prev.password || !!domPassword
                    };
                    if (next.username === prev.username && next.password === prev.password) {
                        return prev;
                    }
                    return next;
                });
            }
        };

        const t1 = setTimeout(syncAutofill, 80);
        const t2 = setTimeout(syncAutofill, 400);

        return () => {
            clearTimeout(t1);
            clearTimeout(t2);
        };
    }, [showModal, formData.username, formData.password]);

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
            language: currentLanguage,
            is_active: 1
        });
        setCredentialFieldReady({ username: false, password: false });
        setError('');
        setSelectedTemplate('custom');
        setPermissions(DEFAULT_PERMISSIONS());
        setShowModal(true);
    };

    const openEditModal = (user) => {
        setCurrentUser(user);
        setFormData({
            username: user.username,
            password: '',
            role: user.role,
            language: user.language || 'en',
            is_active: user.is_active
        });
        setCredentialFieldReady({ username: true, password: false });
        setError('');
        setSelectedTemplate(detectTemplate(user.role, user.permissions));
        setPermissions(
            user.permissions && typeof user.permissions === 'object' && !Array.isArray(user.permissions)
                ? user.permissions
                : DEFAULT_PERMISSIONS()
        );
        setShowModal(true);
    };

    // Templates fill role + the permission matrix in one click; 'custom' keeps
    // whatever is currently set for manual tuning via the permissions dialog.
    const handleTemplateChange = (templateId) => {
        setSelectedTemplate(templateId);
        const template = ROLE_TEMPLATES[templateId];
        if (!template) return;
        setFormData(prev => ({ ...prev, role: template.role }));
        setPermissions(template.permissions ? templatePermissions(templateId) : DEFAULT_PERMISSIONS());
    };

    const openPermissionsModal = (user) => {
        setCurrentUser(user);
        const stored = user.permissions && typeof user.permissions === 'object' && !Array.isArray(user.permissions)
            ? { ...DEFAULT_PERMISSIONS(), ...user.permissions }
            : DEFAULT_PERMISSIONS();
        // 'selected'/'own' are real levels only for campaigns (owner +
        // assigned items). On the other resources they were never enforced
        // server-side and behave like 'full' — surface them as such.
        Object.keys(stored).forEach((key) => {
            if (key === 'campaigns') return;
            const access = stored[key]?.access;
            if (access === 'selected' || access === 'own') {
                stored[key] = { ...stored[key], access: 'full' };
            }
        });
        setPermissions(stored);
        setShowPermissionsModal(true);
        // The items picker lists every campaign an admin can assign.
        axios.get(`${API_URL}?action=campaigns_simple`).then((res) => {
            if (res.data?.status === 'success') setCampaignOptions(res.data.data || []);
        }).catch(() => setCampaignOptions([]));
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
            // Always send the permission matrix: templates apply it on save,
            // custom edits round-trip it. The backend ignores it for admins.
            data.permissions = data.role === 'admin' ? {} : permissions;
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

    const generateApiKey = async (permissions = 'read') => {
        try {
            const label = permissions === 'write' ? 'MCP Write' : 'MCP Read';
            const res = await axios.post(`${API_URL}?action=generate_api_key`, {
                user_id: currentUser.id,
                key_name: `${label} ${(currentUser.api_keys?.length || 0) + 1}`,
                permissions
            });
            if (res.data.status === 'success') {
                notifyModal(t('common.success'));
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
            notifyModal(t('common.success'));
            openApiKeysModal(currentUser);
        } catch (err) {
            setError(t('common.error'));
        }
    };

    // Ready-to-paste Claude Desktop config for the Orbitra MCP server.
    const buildMcpConfig = () => {
        const origin = (typeof window !== 'undefined' && window.location && window.location.origin)
            ? window.location.origin
            : 'https://tracker.example.com';
        return JSON.stringify({
            mcpServers: {
                orbitra: {
                    command: 'node',
                    args: ['/absolute/path/to/Orbitra/mcp/src/index.js'],
                    env: {
                        ORBITRA_URL: origin,
                        ORBITRA_API_KEY: '<your-api-key>'
                    }
                }
            }
        }, null, 2);
    };

    // The util falls back to execCommand on plain-HTTP origins where the
    // Clipboard API is blocked, so these work outside HTTPS/localhost too.
    // Page-level alerts render behind the API-keys modal overlay, so feedback
    // for actions inside that modal must live in the modal itself.
    const [copiedKeyTarget, setCopiedKeyTarget] = useState(null);
    const [modalNotice, setModalNotice] = useState('');
    const notifyModal = (msg) => {
        setModalNotice(msg);
        setTimeout(() => setModalNotice(''), 2000);
    };
    const handleCopyKeyItem = async (text, targetId, noticeText) => {
        if (await copyToClipboard(text)) {
            setCopiedKeyTarget(targetId);
            notifyModal(noticeText);
            // Guarded so a second copy within 2s isn't cleared by the first timer.
            setTimeout(() => setCopiedKeyTarget(current => (current === targetId ? null : current)), 2000);
        }
    };

    const [copiedMcp, setCopiedMcp] = useState(false);
    const handleCopyMcp = async () => {
        if (await copyToClipboard(buildMcpConfig())) {
            setCopiedMcp(true);
            setTimeout(() => setCopiedMcp(false), 2000);
        }
    };

    const resources = [
        { key: 'campaigns', label: t('nav.campaigns') },
        { key: 'offers', label: t('nav.offers') },
        { key: 'landings', label: t('nav.landings') },
        { key: 'media', label: t('nav.mediaGallery') },
        { key: 'sources', label: t('nav.sources') },
        { key: 'networks', label: t('nav.networks') },
        { key: 'domains', label: t('nav.domains') },
        { key: 'logs', label: t('users.resourceLogs') }
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
                                            {{
                                                ru: 'Русский',
                                                en: 'English',
                                                uk: 'Українська',
                                                es: 'Español',
                                                zh: '中文 (简体)',
                                                fr: 'Français',
                                                de: 'Deutsch'
                                            }[user.language] || user.language}
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
                                            ref={usernameInputRef}
                                            type="text"
                                            name="username"
                                            id={currentUser ? 'edit-username' : 'new-username'}
                                            autoComplete="username"
                                            autoCapitalize="none"
                                            autoCorrect="off"
                                            spellCheck={false}
                                            readOnly={!credentialFieldReady.username}
                                            value={formData.username}
                                            onFocus={() => setCredentialFieldReady(prev => ({ ...prev, username: true }))}
                                            onMouseDown={() => setCredentialFieldReady(prev => ({ ...prev, username: true }))}
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
                                            ref={passwordInputRef}
                                            type="password"
                                            name="password"
                                            id="user-password"
                                            autoComplete="new-password"
                                            autoCapitalize="none"
                                            autoCorrect="off"
                                            spellCheck={false}
                                            readOnly={!credentialFieldReady.password}
                                            value={formData.password}
                                            onFocus={() => setCredentialFieldReady(prev => ({ ...prev, password: true }))}
                                            onMouseDown={() => setCredentialFieldReady(prev => ({ ...prev, password: true }))}
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
                                            <option value="uk">Українська</option>
                                            <option value="es">Español</option>
                                            <option value="zh">中文 (简体)</option>
                                            <option value="fr">Français</option>
                                            <option value="de">Deutsch</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label className="form-label">{t('users.roleTemplate')}</label>
                                    <select
                                        value={selectedTemplate}
                                        onChange={(e) => handleTemplateChange(e.target.value)}
                                        className="form-select"
                                    >
                                        <option value="admin">{t('users.templateAdmin')}</option>
                                        <option value="media_buyer">{t('users.templateMediaBuyer')}</option>
                                        <option value="video_editor">{t('users.templateVideoEditor')}</option>
                                        <option value="developer">{t('users.templateDeveloper')}</option>
                                        <option value="custom">{t('users.templateCustom')}</option>
                                    </select>
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
                                            <React.Fragment key={res.key}>
                                                <div className="flex items-center justify-between" style={{ padding: '8px 0', borderBottom: '1px solid var(--color-border)' }}>
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
                                                        {res.key === 'campaigns' && <option value="own">Own + Selected</option>}
                                                        {res.key === 'campaigns' && <option value="selected">Selected</option>}
                                                        <option value="none">None</option>
                                                    </select>
                                                </div>
                                                {res.key === 'campaigns' && (permissions.campaigns?.access === 'own' || permissions.campaigns?.access === 'selected') && (
                                                    <div style={{ padding: '8px 0', borderBottom: '1px solid var(--color-border)' }}>
                                                        <div style={{ fontSize: 12, color: 'var(--color-text-muted)', marginBottom: 8 }}>{t('users.campaignItems')}</div>
                                                        <div style={{ maxHeight: 170, overflowY: 'auto', display: 'flex', flexWrap: 'wrap', gap: '6px 14px' }}>
                                                            {campaignOptions.map((c) => (
                                                                <label key={c.id} className="flex items-center gap-1.5" style={{ fontSize: 12, cursor: 'pointer' }}>
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={(permissions.campaigns?.items || []).includes(c.id)}
                                                                        onChange={(e) => setPermissions((prev) => {
                                                                            const cur = prev.campaigns || { access: 'own', items: [] };
                                                                            const items = e.target.checked
                                                                                ? [...(cur.items || []), c.id]
                                                                                : (cur.items || []).filter((idv) => idv !== c.id);
                                                                            return { ...prev, campaigns: { ...cur, items } };
                                                                        })}
                                                                    />
                                                                    {c.name}
                                                                </label>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                            </React.Fragment>
                                        ))}
                                    </div>
                                </div>

                                {/* Financial data visibility — the backend masks
                                    the hidden families in every report endpoint. */}
                                <div>
                                    <h4 style={{ fontWeight: 500, marginBottom: '12px' }}>{t('users.financeData')}</h4>
                                    <div className="space-y-3">
                                        {[
                                            { key: 'show_costs', label: t('users.financeCosts') },
                                            { key: 'show_revenue', label: t('users.financeRevenue') },
                                            { key: 'show_payout', label: t('users.financePayout') }
                                        ].map(f => (
                                            <div key={f.key} className="flex items-center justify-between" style={{ padding: '8px 0', borderBottom: '1px solid var(--color-border)' }}>
                                                <span>{f.label}</span>
                                                <input
                                                    type="checkbox"
                                                    checked={permissions.finance?.[f.key] !== false}
                                                    onChange={(e) => setPermissions(prev => ({
                                                        ...prev,
                                                        finance: { ...prev.finance, [f.key]: e.target.checked }
                                                    }))}
                                                />
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
                                {modalNotice && (
                                    <div className="alert alert-success flex items-center gap-2" style={{ padding: '8px 12px', fontSize: '13px' }}>
                                        <Check size={14} className="text-emerald-500" />
                                        <span>{modalNotice}</span>
                                    </div>
                                )}
                                {(currentUser.api_keys || []).map((key) => (
                                    <div key={key.id} className="flex items-center justify-between" style={{ padding: '12px', background: 'var(--color-bg-soft)', borderRadius: '12px' }}>
                                        <div>
                                            <div style={{ fontWeight: 500, fontSize: '14px' }}>
                                                {key.key_name}
                                                <span style={{
                                                    marginLeft: '8px', fontSize: '11px', padding: '2px 8px', borderRadius: '10px',
                                                    background: key.permissions === 'write' ? 'var(--color-red-soft, #fee2e2)' : 'var(--color-bg-soft)',
                                                    color: key.permissions === 'write' ? 'var(--color-red, #b91c1c)' : 'var(--color-text-muted)'
                                                }}>
                                                    {key.permissions === 'write' ? 'write' : 'read'}
                                                </span>
                                            </div>
                                            <code style={{ fontSize: '12px' }}>{key.api_key.substring(0, 16)}...</code>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {/* The whole credential for a remote connector is the URL, so hand
                                                it over ready-made — the connector dialog has no key field and
                                                assembling this by hand is where people get stuck. */}
                                            <button
                                                onClick={() => handleCopyKeyItem(
                                                    `${window.location.origin}/mcp.php?k=${key.api_key}`,
                                                    `url-${key.id}`,
                                                    t('users.urlCopied', 'MCP Connector URL copied to clipboard!')
                                                )}
                                                className="action-btn text-blue"
                                                title={t('users.copyMcpUrl', 'Copy connector URL (for Claude → Add custom connector)')}
                                            >
                                                {copiedKeyTarget === `url-${key.id}` ? (
                                                    <Check size={16} className="text-emerald-500" />
                                                ) : (
                                                    <Link2 size={16} />
                                                )}
                                            </button>
                                            <button
                                                onClick={() => handleCopyKeyItem(
                                                    key.api_key,
                                                    `key-${key.id}`,
                                                    t('users.keyCopied', 'API Key copied to clipboard!')
                                                )}
                                                className="action-btn text-blue"
                                                title={t('common.copy')}
                                            >
                                                {copiedKeyTarget === `key-${key.id}` ? (
                                                    <Check size={16} className="text-emerald-500" />
                                                ) : (
                                                    <Copy size={16} />
                                                )}
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

                            <div style={{
                                fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '10px', lineHeight: 1.5
                            }}>
                                {t('users.apiKeyHint', 'Read keys allow analytics only. Write keys also allow managing campaigns/offers/domains — used by the Orbitra MCP server for AI assistants (see mcp/README.md).')}
                            </div>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => generateApiKey('read')}
                                    className="btn btn-secondary"
                                    style={{ flex: 1, borderStyle: 'dashed' }}
                                >
                                    <Plus size={16} />
                                    {t('users.newReadKey', 'Read key')}
                                </button>
                                <button
                                    onClick={() => generateApiKey('write')}
                                    className="btn btn-secondary"
                                    style={{ flex: 1, borderStyle: 'dashed' }}
                                >
                                    <Plus size={16} />
                                    {t('users.newWriteKey', 'Write key')}
                                </button>
                            </div>

                            {/* MCP (AI assistant) connection guide */}
                            <div style={{
                                marginTop: '18px', paddingTop: '16px', borderTop: '1px solid var(--color-border)'
                            }}>
                                <div className="flex items-center justify-between" style={{ marginBottom: '8px' }}>
                                    <div style={{ fontWeight: 600, fontSize: '14px' }}>
                                        {t('users.mcpTitle', 'Connect an AI assistant (MCP)')}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={handleCopyMcp}
                                        className="btn btn-secondary"
                                        style={{ padding: '4px 10px', fontSize: '12px' }}
                                    >
                                        {copiedMcp ? (
                                            <>
                                                <Check size={14} className="text-emerald-500" />
                                                <span className="text-emerald-500 font-semibold">{t('common.copied')}</span>
                                            </>
                                        ) : (
                                            <>
                                                <Copy size={14} />
                                                <span>{t('users.mcpCopyConfig', 'Copy config')}</span>
                                            </>
                                        )}
                                    </button>
                                </div>
                                <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginBottom: '10px', lineHeight: 1.5 }}>
                                    {t('users.mcpDesc', 'Generate a key above, then paste this into your Claude Desktop config. Replace the path with the absolute path to mcp/src/index.js and ORBITRA_API_KEY with your key. Full guide: mcp/README.md.')}
                                </div>
                                <pre style={{
                                    background: 'var(--color-bg-soft)', borderRadius: '10px', padding: '12px',
                                    fontSize: '12px', overflowX: 'auto', margin: 0, lineHeight: 1.45,
                                    color: 'var(--color-text)'
                                }}>
                                    <code>{buildMcpConfig()}</code>
                                </pre>
                            </div>

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
