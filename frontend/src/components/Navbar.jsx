import React, { useState, useRef, useEffect } from 'react';
import { Home, LayoutDashboard, Globe, Users, DollarSign, Activity, PieChart, Tag, Bell, Search, Settings, Link, FileText, Mail, ChevronDown, UserCog, Palette, Map, Globe2, Plug, BarChart3, FileStack, Archive, Upload, Trash2, Database, ArrowRightLeft, RefreshCw, Server, LogOut, Palette as BrandIcon, TrendingUp, Sun, Moon, Menu, X, MessageSquare } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const Navbar = ({ activeTab, setActiveTab, user, onLogout }) => {
    const { t } = useLanguage();
    const [adminMenuOpen, setAdminMenuOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const adminMenuRef = useRef(null);
    const userMenuRef = useRef(null);

    // Close menu when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (adminMenuRef.current && !adminMenuRef.current.contains(event.target)) {
                setAdminMenuOpen(false);
            }
            if (userMenuRef.current && !userMenuRef.current.contains(event.target)) {
                setUserMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const [theme, setTheme] = useState(localStorage.getItem('orbitra_mode') || 'light');

    useEffect(() => {
        const handleStorageChange = () => {
            setTheme(localStorage.getItem('orbitra_mode') || 'light');
        };
        window.addEventListener('storage', handleStorageChange);
        window.addEventListener('themeChanged', handleStorageChange);
        return () => {
            window.removeEventListener('storage', handleStorageChange);
            window.removeEventListener('themeChanged', handleStorageChange);
        };
    }, []);

    const toggleTheme = () => {
        const newMode = theme === 'light' ? 'dark' : 'light';
        localStorage.setItem('orbitra_mode', newMode);
        setTheme(newMode);

        // Dispatch event for App.jsx and BrandingPage.jsx to catch
        window.dispatchEvent(new Event('themeChanged'));
    };

    const adminMenuItems = [
        { icon: <UserCog size={16} />, label: t('adminMenu.users'), tab: 'admin_users' },
        { icon: <Palette size={16} />, label: t('adminMenu.branding'), tab: 'admin_branding' },
        { icon: <Map size={16} />, label: t('adminMenu.geoProfiles'), tab: 'admin_geo_profiles' },
        { icon: <Link size={16} />, label: t('adminMenu.postback'), tab: 'postback' },
        { icon: <Settings size={16} />, label: t('adminMenu.settings'), tab: 'admin_settings' },
        { icon: <Plug size={16} />, label: t('adminMenu.integrations'), tab: 'admin_integrations' },
        { icon: <Database size={16} />, label: t('adminMenu.aggregator'), tab: 'admin_aggregator' },
        { divider: true },
        { icon: <FileText size={16} />, label: t('adminMenu.conversions'), tab: 'conversions' },
        { icon: <Server size={16} />, label: t('adminMenu.status'), tab: 'admin_status' },
        { icon: <FileStack size={16} />, label: t('adminMenu.logs'), tab: 'admin_logs' },
        { icon: <Archive size={16} />, label: t('adminMenu.archive'), tab: 'admin_archive' },
        { icon: <Upload size={16} />, label: t('adminMenu.import'), tab: 'admin_import' },
        { icon: <Activity size={16} />, label: t('adminMenu.simulation'), tab: 'simulation' },
        { icon: <Trash2 size={16} />, label: t('adminMenu.cleanup'), tab: 'admin_cleanup' },
        { divider: true },
        { icon: <Database size={16} />, label: t('adminMenu.geoDbs'), tab: 'admin_geo_dbs' },
        { icon: <ArrowRightLeft size={16} />, label: t('adminMenu.migrations'), tab: 'admin_migrations' },
        { icon: <RefreshCw size={16} />, label: t('adminMenu.update'), tab: 'admin_update' },
        { icon: <MessageSquare size={16} />, label: t('adminMenu.feedback') || 'Feedback & Support', tab: 'admin_feedback' },
    ];

    const handleMenuClick = (tab) => {
        setActiveTab(tab);
        setAdminMenuOpen(false);
        setMobileMenuOpen(false);
    };

    const mobileNavItems = [
        { icon: <LayoutDashboard size={18} />, label: t('nav.dashboard'), tab: 'dashboard' },
        { icon: <Tag size={18} />, label: t('nav.campaigns'), tab: 'campaigns' },
        { icon: <Globe size={18} />, label: t('nav.landings'), tab: 'landings' },
        { icon: <DollarSign size={18} />, label: t('nav.offers'), tab: 'offers' },
        { icon: <Users size={18} />, label: t('nav.networks'), tab: 'networks' },
        { icon: <Link size={18} />, label: t('nav.sources'), tab: 'sources' },
        { icon: <TrendingUp size={18} />, label: t('nav.trends'), tab: 'trends' },
        { icon: <Globe size={18} />, label: t('nav.domains'), tab: 'domains' },
    ];

    return (
        <div className="w-full fixed top-0 z-[1000] px-4 pt-4 md:px-6 md:pt-6 transition-all">
            <nav className="navbar-header h-[72px] flex items-center justify-between px-6 md:px-10 shadow-[var(--shadow-main)] bg-[var(--color-bg-card)] rounded-[24px] w-full mx-auto border-none transition-colors duration-300">
                <div className="flex items-center space-x-3 md:space-x-6 h-full">
                    {/* Logo / Brand */}
                    <div className="font-semibold text-xl mr-4 flex items-center cursor-pointer" onClick={() => setActiveTab('dashboard')} style={{ color: 'var(--color-text-primary)' }}>
                        Orbitra<span style={{ color: 'var(--color-primary)' }}>.link</span>
                    </div>

                    {/* Navigation Links */}
                    <div className="hidden md:flex space-x-2 h-full items-center">
                        <NavItem icon={<LayoutDashboard size={18} />} label={t('nav.dashboard')} active={activeTab === 'dashboard'} onClick={() => setActiveTab('dashboard')} />
                        <NavItem icon={<Tag size={18} />} label={t('nav.campaigns')} active={activeTab === 'campaigns'} onClick={() => setActiveTab('campaigns')} />
                        <NavItem icon={<Globe size={18} />} label={t('nav.landings')} active={activeTab === 'landings'} onClick={() => setActiveTab('landings')} />
                        <NavItem icon={<DollarSign size={18} />} label={t('nav.offers')} active={activeTab === 'offers'} onClick={() => setActiveTab('offers')} />
                        <NavItem icon={<Users size={18} />} label={t('nav.networks')} active={activeTab === 'networks'} onClick={() => setActiveTab('networks')} />
                        <NavItem icon={<Link size={18} />} label={t('nav.sources')} active={activeTab === 'sources'} onClick={() => setActiveTab('sources')} />
                        <NavItem icon={<TrendingUp size={18} />} label={t('nav.trends')} active={activeTab === 'trends'} onClick={() => setActiveTab('trends')} />
                        <NavItem icon={<Globe size={18} />} label={t('nav.domains')} active={activeTab === 'domains'} onClick={() => setActiveTab('domains')} />
                    </div>
                </div>

                <div className="flex items-center space-x-2 md:space-x-4">
                    {/* Desktop icons */}
                    <div className="hidden md:flex items-center space-x-4">
                        {/* Admin Menu */}
                        <div className="relative" ref={adminMenuRef}>
                            <div
                                onClick={() => setAdminMenuOpen(!adminMenuOpen)}
                                className={`flex items-center justify-center w-10 h-10 rounded-2xl cursor-pointer transition-all
                                ${adminMenuOpen
                                        ? 'bg-[var(--color-primary-light)] text-[var(--color-primary)]'
                                        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-hover)] hover:text-[var(--color-text-primary)]'
                                    }`}
                                title={t('navbar.adminTitle')}
                            >
                                <Settings size={18} />
                            </div>

                            {adminMenuOpen && (
                                <div className="absolute right-0 top-full mt-1 w-56 rounded-lg shadow-xl py-1 z-[100] border" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                    {adminMenuItems.map((item, idx) => (
                                        item.divider ? (
                                            <div key={`div-${idx}`} className="border-t my-1" style={{ borderColor: 'var(--color-border)' }} />
                                        ) : (
                                            <button
                                                key={item.tab}
                                                onClick={() => handleMenuClick(item.tab)}
                                                className={`w-full flex items-center space-x-3 px-4 py-2 text-sm transition border-l-2 ${activeTab === item.tab
                                                    ? 'bg-[var(--color-primary-light)] border-[var(--color-primary)] text-[var(--color-primary)]'
                                                    : 'border-transparent hover:border-[var(--color-primary)] hover:bg-[var(--color-bg-hover)]'
                                                    }`}
                                                style={activeTab !== item.tab ? { color: 'var(--color-text-primary)' } : {}}
                                            >
                                                <span style={{ color: 'var(--color-text-muted)' }}>{item.icon}</span>
                                                <span>{item.label}</span>
                                            </button>
                                        )
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Theme Toggle */}
                        <div>
                            <button
                                onClick={toggleTheme}
                                className="p-2 rounded-xl text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--color-bg-hover)] transition flex items-center justify-center"
                                title={theme === 'light' ? t('navbar.enableDark') : t('navbar.enableLight')}
                            >
                                {theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}
                            </button>
                        </div>

                        {/* User Profile */}
                        <div className="relative" ref={userMenuRef}>
                            <button
                                onClick={() => setUserMenuOpen(!userMenuOpen)}
                                className="flex items-center space-x-2 cursor-pointer ml-2 px-2 py-1 rounded-full hover:bg-[var(--color-bg-hover)] transition"
                                style={{ color: 'var(--color-text-primary)' }}
                            >
                                <div
                                    className="w-9 h-9 rounded-full flex justify-center items-center font-medium text-sm transition"
                                    style={{ backgroundColor: userMenuOpen ? 'var(--color-primary-light)' : 'var(--color-bg-hover)', color: userMenuOpen ? 'var(--color-primary)' : 'var(--color-text-primary)' }}
                                >
                                    {user?.username?.charAt(0)?.toUpperCase() || 'A'}
                                </div>
                                <span className="text-sm font-medium hidden lg:block" style={{ color: 'var(--color-text-secondary)' }}>{user?.username || 'Admin'}</span>
                                <ChevronDown size={14} className={`transition text-[var(--color-text-secondary)] ${userMenuOpen ? 'rotate-180 text-[var(--color-primary)]' : ''}`} />
                            </button>

                            {userMenuOpen && (
                                <div className="absolute right-0 top-full mt-1 w-48 rounded-lg shadow-xl py-1 z-[100] border" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                    <div className="px-4 py-2 border-b" style={{ borderColor: 'var(--color-border)' }}>
                                        <p className="text-sm font-medium" style={{ color: 'var(--color-text-primary)' }}>{user?.username || 'Admin'}</p>
                                        <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{user?.role === 'admin' ? 'Admin' : 'User'}</p>
                                    </div>
                                    <button
                                        onClick={() => {
                                            setUserMenuOpen(false);
                                            onLogout();
                                        }}
                                        className="w-full flex items-center space-x-2 px-4 py-2 text-sm text-red-600 transition hover:bg-red-50/10"
                                    >
                                        <LogOut size={16} />
                                        <span>{t('nav.logout')}</span>
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Burger button (mobile only) */}
                    <button
                        className="md:hidden p-2 -mr-2 rounded-xl transition"
                        style={{ color: 'var(--color-text-secondary)' }}
                        onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                    >
                        {mobileMenuOpen ? <X size={22} /> : <Menu size={22} />}
                    </button>
                </div>
            </nav>

            {/* Mobile Drawer */}
            {mobileMenuOpen && (
                <>
                    <div
                        className="md:hidden fixed inset-0 bg-black/40 z-[999]"
                        onClick={() => setMobileMenuOpen(false)}
                        style={{ top: 0 }}
                    />
                    <div
                        className="md:hidden fixed right-0 top-0 bottom-0 z-[1001] overflow-y-auto"
                        style={{
                            width: '280px',
                            background: 'var(--color-bg-card)',
                            boxShadow: '-4px 0 20px rgba(0,0,0,0.15)',
                            padding: '20px 16px',
                            animation: 'slideInRight 0.25s ease-out'
                        }}
                    >
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                            <span className="font-semibold text-lg" style={{ color: 'var(--color-text-primary)' }}>
                                Orbitra<span style={{ color: 'var(--color-primary)' }}>.link</span>
                            </span>
                            <button onClick={() => setMobileMenuOpen(false)} style={{ color: 'var(--color-text-secondary)', padding: '4px' }}>
                                <X size={20} />
                            </button>
                        </div>

                        {/* Nav items */}
                        <div style={{ marginBottom: '16px' }}>
                            <p style={{ fontSize: '11px', fontWeight: 600, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: '8px', paddingLeft: '12px' }}>{t('nav.navigation') || 'Navigation'}</p>
                            {mobileNavItems.map(item => (
                                <button
                                    key={item.tab}
                                    onClick={() => handleMenuClick(item.tab)}
                                    style={{
                                        display: 'flex', alignItems: 'center', gap: '12px', width: '100%',
                                        padding: '10px 12px', borderRadius: '14px', border: 'none',
                                        cursor: 'pointer', fontSize: '14px', fontWeight: 500, textAlign: 'left',
                                        background: activeTab === item.tab ? 'var(--color-primary-light)' : 'transparent',
                                        color: activeTab === item.tab ? 'var(--color-primary)' : 'var(--color-text-primary)',
                                        marginBottom: '2px', transition: 'all 0.2s ease'
                                    }}
                                >
                                    <span style={{ color: activeTab === item.tab ? 'var(--color-primary)' : 'var(--color-text-muted)' }}>{item.icon}</span>
                                    {item.label}
                                </button>
                            ))}
                        </div>

                        <div style={{ height: '1px', background: 'var(--color-border)', margin: '12px 0' }} />

                        {/* Admin items */}
                        <p style={{ fontSize: '11px', fontWeight: 600, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: '8px', paddingLeft: '12px' }}>{t('navbar.adminTitle')}</p>
                        {adminMenuItems.filter(i => !i.divider).map(item => (
                            <button
                                key={item.tab}
                                onClick={() => handleMenuClick(item.tab)}
                                style={{
                                    display: 'flex', alignItems: 'center', gap: '12px', width: '100%',
                                    padding: '10px 12px', borderRadius: '14px', border: 'none',
                                    cursor: 'pointer', fontSize: '13px', fontWeight: 400, textAlign: 'left',
                                    background: activeTab === item.tab ? 'var(--color-primary-light)' : 'transparent',
                                    color: activeTab === item.tab ? 'var(--color-primary)' : 'var(--color-text-secondary)',
                                    marginBottom: '2px', transition: 'all 0.2s ease'
                                }}
                            >
                                <span style={{ color: 'var(--color-text-muted)' }}>{item.icon}</span>
                                {item.label}
                            </button>
                        ))}

                        <div style={{ height: '1px', background: 'var(--color-border)', margin: '12px 0' }} />

                        {/* Theme + Logout */}
                        <button
                            onClick={() => { toggleTheme(); }}
                            style={{
                                display: 'flex', alignItems: 'center', gap: '12px', width: '100%',
                                padding: '10px 12px', borderRadius: '14px', border: 'none', cursor: 'pointer',
                                fontSize: '14px', background: 'transparent', color: 'var(--color-text-primary)'
                            }}
                        >
                            {theme === 'light' ? <Moon size={18} /> : <Sun size={18} />}
                            {theme === 'light' ? (t('navbar.enableDark') || 'Dark Mode') : (t('navbar.enableLight') || 'Light Mode')}
                        </button>
                        <button
                            onClick={() => { setMobileMenuOpen(false); onLogout(); }}
                            style={{
                                display: 'flex', alignItems: 'center', gap: '12px', width: '100%',
                                padding: '10px 12px', borderRadius: '14px', border: 'none', cursor: 'pointer',
                                fontSize: '14px', background: 'transparent', color: '#ef4444'
                            }}
                        >
                            <LogOut size={18} /> {t('nav.logout')}
                        </button>
                    </div>
                </>
            )}
        </div>
    );
};

const NavItem = ({ icon, label, active, onClick }) => {
    return (
        <div
            onClick={onClick}
            className={`flex items-center space-x-2 px-4 py-2 m-1 rounded-2xl cursor-pointer transition-all text-sm font-medium
                ${active
                    ? 'bg-[var(--color-primary-light)] text-[var(--color-primary)]'
                    : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-hover)] hover:text-[var(--color-text-primary)]'
                }`}
        >
            {icon}
            <span>{label}</span>
        </div>
    );
};

export default Navbar;
