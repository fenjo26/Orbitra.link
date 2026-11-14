import React, { useState, useRef, useEffect, useLayoutEffect, useCallback } from 'react';
import { Home, LayoutDashboard, Globe, Users, DollarSign, Activity, PieChart, Tag, Bell, Search, Settings, Link, FileText, Mail, ChevronDown, UserCog, Palette, Map, Plug, BarChart3, FileStack, Archive, Upload, Trash2, Database, ArrowRightLeft, RefreshCw, Server, LogOut, Palette as BrandIcon, TrendingUp, Sun, Moon, Menu, X, MessageSquare, Sparkles, Zap, Images, Download, LayoutTemplate } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { canAccessTab, isAdminUser } from '../utils/permissions';

// Gaps between the nav links, px. The icons row grows its gap up to the max
// when there is room; past that the icons would read as unrelated buttons.
// The full row does the opposite first: it tightens from its normal gap down
// to the minimum before giving up its labels, so a screen the row only just
// misses (a 15" laptop at 1710px with Russian labels) keeps them.
const NAV_GAP_FULL = 6;
const NAV_GAP_FULL_MIN = 2;
const NAV_GAP_ICONS = 4;
const NAV_GAP_ICONS_MAX = 44;
// Smallest space left between the links and the gear/theme/profile group.
const NAV_GROUP_GAP = 12;

const Navbar = ({ activeTab, setActiveTab, user, onLogout }) => {
    const { t, language } = useLanguage();
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
    const [activeSuiteModule, setActiveSuiteModule] = useState(() => localStorage.getItem('orbitra_suite_module') || 'tracker');

    // The browser's install offer, stashed by App.jsx's module-level
    // listener (it can fire long before the navbar mounts). Re-announced
    // through a custom event so this stays reactive without polling.
    const [installPrompt, setInstallPrompt] = useState(() => window.__orbitraInstallPrompt || null);
    useEffect(() => {
        const sync = () => setInstallPrompt(window.__orbitraInstallPrompt || null);
        window.addEventListener('orbitra:install-available', sync);
        return () => window.removeEventListener('orbitra:install-available', sync);
    }, []);
    const installApp = async () => {
        const evt = window.__orbitraInstallPrompt;
        if (!evt) return;
        evt.prompt();
        await evt.userChoice;
        window.__orbitraInstallPrompt = null;
        setInstallPrompt(null);
    };

    useEffect(() => {
        if (activeTab === 'leadforge' && isAdminUser(user)) {
            setActiveSuiteModule('leadforge');
            localStorage.setItem('orbitra_suite_module', 'leadforge');
        } else if (activeTab === 'crm' && isAdminUser(user)) {
            setActiveSuiteModule('crm');
            localStorage.setItem('orbitra_suite_module', 'crm');
        } else {
            setActiveSuiteModule('tracker');
            localStorage.setItem('orbitra_suite_module', 'tracker');
        }
    }, [activeTab]);

    const handleSwitchModule = (mod) => {
        setActiveSuiteModule(mod);
        localStorage.setItem('orbitra_suite_module', mod);
        if (mod === 'tracker') {
            setActiveTab('dashboard');
        } else if (mod === 'leadforge') {
            setActiveTab('leadforge');
        } else if (mod === 'crm') {
            setActiveTab('crm');
        }
    };

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
        { icon: <UserCog size={16} />, label: t('users.usersAndProfile'), tab: 'admin_users' },
        { icon: <Palette size={16} />, label: t('adminMenu.branding'), tab: 'admin_branding' },
        { icon: <Map size={16} />, label: t('adminMenu.geoProfiles'), tab: 'admin_geo_profiles' },
        { icon: <Link size={16} />, label: t('adminMenu.postback'), tab: 'postback' },
        { icon: <Settings size={16} />, label: t('adminMenu.settings'), tab: 'admin_settings' },
        { icon: <Plug size={16} />, label: t('adminMenu.integrations'), tab: 'admin_integrations' },
        { icon: <Sparkles size={16} />, label: t('adminMenu.mcp', 'AI / MCP'), tab: 'admin_mcp' },
        { icon: <Database size={16} />, label: t('adminMenu.aggregator'), tab: 'admin_aggregator' },
        { divider: true },
        { icon: <Search size={16} />, label: t('nav.backorder'), tab: 'backorder' },
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

    // LeadForge and CRM read/write leadforge_*/crm_* API actions, which the
    // backend serves to admins only — other roles get the tracker capsule.
    const suiteModules = isAdminUser(user)
        ? [
              { id: 'leadforge', label: t('suite.leadforge', 'LeadForge'), icon: <Zap size={14} /> },
              { id: 'tracker', label: t('suite.tracker', 'Tracker'), icon: <BarChart3 size={14} /> },
              { id: 'crm', label: t('suite.crm', 'CRM'), icon: <Users size={14} /> }
          ]
        : [{ id: 'tracker', label: t('suite.tracker', 'Tracker'), icon: <BarChart3 size={14} /> }];

    const handleMenuClick = (tab) => {
        setActiveTab(tab);
        setAdminMenuOpen(false);
        setMobileMenuOpen(false);
    };

    // Gear-menu entries this user may open, with orphaned dividers dropped
    // (leading/trailing or left between two removed items).
    const allowedMenuEntries = adminMenuItems.filter(item => item.divider || canAccessTab(user, item.tab));
    const visibleAdminMenuItems = allowedMenuEntries.filter((item, idx, arr) => {
        if (!item.divider) return true;
        if (idx === 0 || idx === arr.length - 1) return false;
        return !arr[idx - 1].divider;
    });

    const mobileNavItems = [
        { icon: <LayoutDashboard size={18} />, label: t('nav.dashboard'), tab: 'dashboard' },
        { icon: <Tag size={18} />, label: t('nav.campaigns'), tab: 'campaigns' },
        { icon: <LayoutTemplate size={18} />, label: t('nav.landings'), tab: 'landings' },
        { icon: <DollarSign size={18} />, label: t('nav.offers'), tab: 'offers' },
        { icon: <Images size={18} />, label: t('nav.mediaGallery'), tab: 'media' },
        { icon: <Bell size={18} />, label: t('push.navLabel'), tab: 'push' },
        { icon: <Users size={18} />, label: t('nav.networks'), tab: 'networks' },
        { icon: <Link size={18} />, label: t('nav.sources'), tab: 'sources' },
        { icon: <TrendingUp size={18} />, label: t('nav.analytics'), tab: 'trends' },
        { icon: <Globe size={18} />, label: t('nav.domains'), tab: 'domains' },
    ];

    // Three layouts, chosen by MEASURING rather than by a fixed breakpoint:
    //   full   — icons with labels;
    //   icons  — icons only (label on the active tab, the rest in tooltips);
    //   burger — everything in the drawer.
    // How wide a row is depends on the locale (Russian/German labels are far
    // longer than English), on how many tabs this user may open and on the
    // username — the old fixed `xl` (1280px) cut left Domains, the gear, the
    // theme toggle and the profile off-screen on 1280–1700px displays.
    // Whichever row is in the DOM gets measured; the number is kept so a
    // layout that is not rendered can still be judged. 0 means "not measured
    // yet — render it once and find out".
    const navRef = useRef(null);
    const navLeftRef = useRef(null);
    const navRightRef = useRef(null);
    const navNeededRef = useRef({ full: 0, icons: 0 });
    const [navMode, setNavMode] = useState('full');
    // Gap between the links, fitted to the free space: the icons row
    // stretches so the icons do not huddle next to the active tab, the full
    // row tightens when it is a few pixels short.
    const [navGap, setNavGap] = useState(NAV_GAP_FULL);
    const navCollapsed = navMode === 'burger';
    const navCompact = navMode === 'icons';

    const fitNav = useCallback(() => {
        const nav = navRef.current, left = navLeftRef.current, right = navRightRef.current;
        if (!nav || !left || !right) return;
        const cs = window.getComputedStyle(nav);
        const avail = nav.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const needed = navNeededRef.current;
        // data-mode mirrors what is in the DOM right now.
        const shown = nav.dataset.mode;
        const links = left.querySelector('[data-nav-links]');
        const linkCount = links ? links.children.length : 0;
        if (shown === 'full' || shown === 'icons') {
            // Width at the SMALLEST gap: the icons row is drawn stretched,
            // so its links are re-added from their own widths rather than
            // read off the (wider) container.
            // Fractional widths: offsetWidth rounds each of a dozen boxes,
            // which adds up to ~10px of error.
            const w = (el) => el.getBoundingClientRect().width;
            let leftWidth = w(left);
            if (links) {
                const base = shown === 'icons' ? NAV_GAP_ICONS : NAV_GAP_FULL_MIN;
                let natural = Math.max(0, linkCount - 1) * base;
                for (const item of links.children) natural += w(item);
                leftWidth += natural - w(links);
            }
            needed[shown] = Math.ceil(leftWidth + w(right)) + NAV_GROUP_GAP;
        }
        let next = 'burger';
        // Phones always get the drawer, whatever happens to fit.
        if (window.innerWidth >= 768) {
            if (needed.full <= avail) next = 'full';
            else if (needed.icons <= avail) next = 'icons';
        }
        setNavMode(next);
        if (next !== 'burger' && needed[next] > 0 && linkCount > 1) {
            const [min, max] = next === 'icons' ? [NAV_GAP_ICONS, NAV_GAP_ICONS_MAX] : [NAV_GAP_FULL_MIN, NAV_GAP_FULL];
            const spare = Math.floor((avail - needed[next]) / (linkCount - 1));
            setNavGap(Math.max(min, Math.min(max, min + spare)));
        }
    }, []);

    // Runs before paint, so the bar never flashes in the wrong mode. A switch
    // re-runs it: the newly rendered row is measured and may step down again.
    // Layout measurement feeding state is the one case this rule allows for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useLayoutEffect(() => { fitNav(); }, [navMode, fitNav]);

    useLayoutEffect(() => {
        const nav = navRef.current;
        if (!nav || typeof ResizeObserver === 'undefined') return;
        const ro = new ResizeObserver(fitNav);
        ro.observe(nav);
        return () => ro.disconnect();
    }, [fitNav]);

    // The rows' own widths changed (language, module, tabs, username, web
    // font arriving): forget both numbers and measure again from the top.
    const remeasureNav = useCallback(() => {
        navNeededRef.current = { full: 0, icons: 0 };
        fitNav();
    }, [fitNav]);
    const navTabsKey = mobileNavItems.filter(item => canAccessTab(user, item.tab)).length + ':' + visibleAdminMenuItems.length;
    // Layout measurement feeding state is the one case this rule allows for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useLayoutEffect(() => { remeasureNav(); }, [language, activeSuiteModule, user?.username, navTabsKey, remeasureNav]);
    // The icons row carries the active tab's label, so its width moves with
    // the tab. Only that number is stale; the full row is unaffected.
    useLayoutEffect(() => {
        navNeededRef.current.icons = 0;
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fitNav();
    }, [activeTab, fitNav]);
    useEffect(() => {
        let alive = true;
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => { if (alive) remeasureNav(); });
        }
        return () => { alive = false; };
    }, [remeasureNav]);

    const drawerOpen = mobileMenuOpen && navCollapsed;

    return (
        <div className="w-full fixed top-0 z-[1500] px-4 pt-4 md:px-6 md:pt-6 transition-all">
            <nav ref={navRef} data-mode={navMode} className="navbar-header h-[72px] flex items-center justify-between px-6 md:px-10 shadow-[var(--shadow-main)] bg-[var(--color-bg-card)] rounded-[24px] w-full mx-auto border-none transition-colors duration-300">
                <div ref={navLeftRef} className="flex items-center space-x-3 md:space-x-5 h-full shrink-0">
                    {/* Logo / Brand */}
                    <div className="font-semibold text-xl mr-2 flex items-center cursor-pointer" onClick={() => setActiveTab('dashboard')} style={{ color: 'var(--color-text-primary)' }}>
                        Orbitra<span style={{ color: 'var(--color-primary)' }}>.link</span>
                    </div>

                    {/* Suite Switcher — icon-only capsule (~95px): the labels
                        live in tooltips/aria, one click per switch stays. */}
                    <div
                        className="inline-flex p-0.5 rounded-full border items-center gap-0.5 shrink-0"
                        role="radiogroup"
                        aria-label="Suite module"
                        style={{ backgroundColor: 'var(--color-bg-soft)', borderColor: 'var(--color-border)' }}
                    >
                        {suiteModules.map((mod) => {
                            const isActive = activeSuiteModule === mod.id;
                            return (
                                <button
                                    key={mod.id}
                                    type="button"
                                    role="radio"
                                    aria-checked={isActive}
                                    onClick={() => handleSwitchModule(mod.id)}
                                    aria-label={mod.label}
                                    className="group relative w-7 h-7 rounded-full flex items-center justify-center transition-all cursor-pointer"
                                    style={{
                                        backgroundColor: isActive ? 'var(--color-primary)' : 'transparent',
                                        color: isActive ? 'var(--color-text-inverse)' : 'var(--color-text-muted)',
                                        boxShadow: isActive ? '0 2px 8px rgba(0,0,0,0.18)' : 'none'
                                    }}
                                >
                                    {mod.icon}
                                    {/* Hover label — the icon-only capsule still
                                        names its modules, theme-safe */}
                                    <span
                                        className="pointer-events-none absolute left-1/2 -translate-x-1/2 top-full mt-2 px-2 py-1 rounded-lg text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-150 group-hover:opacity-100"
                                        style={{
                                            backgroundColor: 'var(--color-bg-card)',
                                            border: '1px solid var(--color-border)',
                                            color: 'var(--color-text-primary)',
                                            boxShadow: 'var(--shadow-main)',
                                            zIndex: 60
                                        }}
                                    >
                                        {mod.label}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {/* Navigation Links (Tracker Module) */}
                    {activeSuiteModule === 'tracker' && !navCollapsed && (
                        // key: remount the row whenever its geometry changes. index.css
                        // animates every property on every element (`* { transition }`),
                        // so reused nodes would be measured mid-animation — padding and
                        // label widths still on their old values — and the row would be
                        // judged ~55px wider than it really is.
                        <div key={navCompact ? `icons:${activeTab}` : 'full'} data-nav-links className="flex h-full items-center" style={{ columnGap: navGap, transition: 'none' }}>
                            <NavItem compact={navCompact} icon={<LayoutDashboard size={18} />} label={t('nav.dashboard')} active={activeTab === 'dashboard'} onClick={() => setActiveTab('dashboard')} />
                            {canAccessTab(user, 'campaigns') && (
                                <NavItem compact={navCompact} icon={<Tag size={18} />} label={t('nav.campaigns')} active={activeTab === 'campaigns'} onClick={() => setActiveTab('campaigns')} />
                            )}
                            {canAccessTab(user, 'landings') && (
                                <NavItem compact={navCompact} icon={<LayoutTemplate size={18} />} label={t('nav.landings')} active={activeTab === 'landings'} onClick={() => setActiveTab('landings')} />
                            )}
                            {canAccessTab(user, 'offers') && (
                                <NavItem compact={navCompact} icon={<DollarSign size={18} />} label={t('nav.offers')} active={activeTab === 'offers'} onClick={() => setActiveTab('offers')} />
                            )}
                            {canAccessTab(user, 'media') && (
                                <NavItem compact={navCompact} icon={<Images size={18} />} label={t('nav.mediaGallery')} active={activeTab === 'media'} onClick={() => setActiveTab('media')} />
                            )}
                            {canAccessTab(user, 'push') && (
                                <NavItem compact={navCompact} icon={<Bell size={18} />} label={t('push.navLabel')} active={activeTab === 'push'} onClick={() => setActiveTab('push')} />
                            )}
                            {canAccessTab(user, 'networks') && (
                                <NavItem compact={navCompact} icon={<Users size={18} />} label={t('nav.networks')} active={activeTab === 'networks'} onClick={() => setActiveTab('networks')} />
                            )}
                            {canAccessTab(user, 'sources') && (
                                <NavItem compact={navCompact} icon={<Link size={18} />} label={t('nav.sources')} active={activeTab === 'sources'} onClick={() => setActiveTab('sources')} />
                            )}
                            <NavItem compact={navCompact} icon={<TrendingUp size={18} />} label={t('nav.analytics')} active={activeTab === 'trends'} onClick={() => setActiveTab('trends')} />
                            {canAccessTab(user, 'domains') && (
                                <NavItem compact={navCompact} icon={<Globe size={18} />} label={t('nav.domains')} active={activeTab === 'domains'} onClick={() => setActiveTab('domains')} />
                            )}
                        </div>
                    )}
                </div>

                <div ref={navRightRef} className="flex items-center space-x-2 md:space-x-4 shrink-0">
                    {/* Desktop icons */}
                    {!navCollapsed && (
                    <div className="flex items-center space-x-4">
                        {/* Settings Menu (shown only when the user may open at least one entry) */}
                        {visibleAdminMenuItems.length > 0 && (
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
                                <div className="absolute right-0 top-full mt-1 w-max min-w-[14rem] max-w-[min(20rem,calc(100vw-1.5rem))] rounded-lg shadow-xl py-1 z-[100] border" style={{ backgroundColor: 'var(--color-bg-card)', borderColor: 'var(--color-border)' }}>
                                    {/* Width follows the longest label of the active locale (w-max) instead of a
                                    fixed w-56 that wrapped "Пользователи и профиль" onto two centred lines;
                                    capped so a long translation truncates rather than leaving the screen. */}
                                    {visibleAdminMenuItems.map((item, idx) => (
                                        item.divider ? (
                                            <div key={`div-${idx}`} className="border-t my-1" style={{ borderColor: 'var(--color-border)' }} />
                                        ) : (
                                            <button
                                                key={item.tab}
                                                onClick={() => handleMenuClick(item.tab)}
                                                title={item.label}
                                                className={`w-full flex items-center gap-3 px-4 py-2 text-sm text-left transition border-l-2 ${activeTab === item.tab
                                                    ? 'bg-[var(--color-primary-light)] border-[var(--color-primary)] text-[var(--color-primary)]'
                                                    : 'border-transparent hover:border-[var(--color-primary)] hover:bg-[var(--color-bg-hover)]'
                                                    }`}
                                                style={activeTab !== item.tab ? { color: 'var(--color-text-primary)' } : {}}
                                            >
                                                <span className="shrink-0 flex" style={{ color: 'var(--color-text-muted)' }}>{item.icon}</span>
                                                <span className="flex-1 min-w-0 truncate whitespace-nowrap">{item.label}</span>
                                            </button>
                                        )
                                    ))}
                                </div>
                            )}
                            </div>
                        )}

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
                                            window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'admin_users', sub: 'profile' } }));
                                        }}
                                        className="w-full flex items-center space-x-2 px-4 py-2 text-sm transition hover:bg-red-50/10"
                                        style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <UserCog size={16} />
                                        <span>{t('nav.profile')}</span>
                                    </button>
                                    <button
                                        onClick={() => {
                                            setUserMenuOpen(false);
                                            window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'notifications', sub: 'push' } }));
                                        }}
                                        className="w-full flex items-center space-x-2 px-4 py-2 text-sm transition hover:bg-red-50/10"
                                        style={{ color: 'var(--color-text-primary)' }}
                                    >
                                        <Bell size={16} />
                                        <span>{t('notifications.navLabel')}</span>
                                    </button>
                                    {/* Install as app: only when the browser offers it
                                        (Chrome/Edge/Android). Kept out of the top bar on
                                        purpose; iOS is guided from Notifications → Push. */}
                                    {installPrompt && (
                                        <button
                                            onClick={() => {
                                                setUserMenuOpen(false);
                                                installApp();
                                            }}
                                            className="w-full flex items-center space-x-2 px-4 py-2 text-sm transition hover:bg-red-50/10"
                                            style={{ color: 'var(--color-text-primary)' }}
                                            title={t('navbar.installAppHint')}
                                        >
                                            <Download size={16} />
                                            <span>{t('navbar.installApp')}</span>
                                        </button>
                                    )}
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
                    )}

                    {/* Burger button (whenever the full row does not fit) */}
                    {navCollapsed && (
                    <button
                        type="button"
                        className="p-2 -mr-2 rounded-xl transition flex items-center justify-center"
                        style={{ color: 'var(--color-text-secondary)' }}
                        onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                        aria-label="Toggle navigation menu"
                    >
                        {drawerOpen ? <X size={22} /> : <Menu size={22} />}
                    </button>
                    )}
                </div>
            </nav>

            {/* Mobile Drawer */}
            {drawerOpen && (
                <>
                    <div
                        className="fixed inset-0 bg-black/40 z-[1499]"
                        onClick={() => setMobileMenuOpen(false)}
                        style={{ top: 0 }}
                    />
                    <div
                        className="fixed right-0 top-0 bottom-0 z-[1501] overflow-y-auto"
                        style={{
                            width: '280px',
                            background: 'var(--color-bg-card)',
                            boxShadow: '-4px 0 20px rgba(0,0,0,0.15)',
                            padding: '20px 16px',
                            animation: 'slideInRight 0.25s ease-out'
                        }}
                    >
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                            <span className="font-semibold text-lg" style={{ color: 'var(--color-text-primary)' }}>
                                Orbitra<span style={{ color: 'var(--color-primary)' }}>.link</span>
                            </span>
                            <button onClick={() => setMobileMenuOpen(false)} style={{ color: 'var(--color-text-secondary)', padding: '4px' }}>
                                <X size={20} />
                            </button>
                        </div>

                        {/* Account: the avatar menu lives in the desktop-only bar, so the
                            drawer carries it too — otherwise an installed (standalone,
                            narrow) panel has no way into Notifications or Profile. */}
                        {(() => {
                            const go = (tab, sub) => {
                                setMobileMenuOpen(false);
                                window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab, sub } }));
                            };
                            const row = (active) => ({
                                display: 'flex', alignItems: 'center', gap: '12px', width: '100%',
                                padding: '10px 12px', borderRadius: '14px', border: 'none', cursor: 'pointer',
                                fontSize: '14px', textAlign: 'left', marginBottom: '2px',
                                background: active ? 'var(--color-primary-light)' : 'transparent',
                                color: active ? 'var(--color-primary)' : 'var(--color-text-primary)',
                            });
                            return (
                                <div style={{ marginBottom: '16px' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '4px 12px 10px' }}>
                                        <div className="w-9 h-9 rounded-full flex justify-center items-center font-medium text-sm shrink-0" style={{ backgroundColor: 'var(--color-bg-hover)', color: 'var(--color-text-primary)' }}>
                                            {user?.username?.charAt(0)?.toUpperCase() || 'A'}
                                        </div>
                                        <div style={{ minWidth: 0 }}>
                                            <p className="text-sm font-medium truncate" style={{ color: 'var(--color-text-primary)' }}>{user?.username || 'Admin'}</p>
                                            <p className="text-xs" style={{ color: 'var(--color-text-muted)' }}>{user?.role === 'admin' ? 'Admin' : 'User'}</p>
                                        </div>
                                    </div>
                                    <button type="button" onClick={() => go('notifications', 'push')} style={row(activeTab === 'notifications')}>
                                        <Bell size={18} /> {t('notifications.navLabel')}
                                    </button>
                                    <button type="button" onClick={() => go('admin_users', 'profile')} style={row(false)}>
                                        <UserCog size={18} /> {t('nav.profile')}
                                    </button>
                                    {installPrompt && (
                                        <button type="button" onClick={() => { setMobileMenuOpen(false); installApp(); }} style={row(false)} title={t('navbar.installAppHint')}>
                                            <Download size={18} /> {t('navbar.installApp')}
                                        </button>
                                    )}
                                    <div style={{ height: '1px', background: 'var(--color-border)', margin: '12px 0 0' }} />
                                </div>
                            );
                        })()}

                        {/* Mobile Suite Switcher */}
                        <div style={{ marginBottom: '16px' }}>
                            <div
                                className="flex p-1 rounded-full border items-center justify-between gap-1 w-full"
                                style={{ backgroundColor: 'var(--color-bg-main)', borderColor: 'var(--color-border)' }}
                            >
                                {suiteModules.map((mod) => {
                                    const isActive = activeSuiteModule === mod.id;
                                    const label = mod.id === 'leadforge' ? 'LeadForge' : mod.id === 'crm' ? 'CRM' : 'Tracker';
                                    return (
                                        <button
                                            key={mod.id}
                                            type="button"
                                            onClick={() => {
                                                handleSwitchModule(mod.id);
                                                setMobileMenuOpen(false);
                                            }}
                                            className="flex-1 py-1.5 rounded-full text-xs font-semibold transition-all text-center cursor-pointer"
                                            style={{
                                                backgroundColor: isActive ? 'var(--color-primary)' : 'transparent',
                                                color: isActive ? 'var(--color-text-inverse)' : 'var(--color-text-secondary)',
                                                boxShadow: isActive ? '0 2px 8px rgba(0,0,0,0.18)' : 'none'
                                            }}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Nav items */}
                        {activeSuiteModule === 'tracker' && (
                            <div style={{ marginBottom: '16px' }}>
                                <p style={{ fontSize: '11px', fontWeight: 600, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: '8px', paddingLeft: '12px' }}>{t('nav.navigation') || 'Navigation'}</p>
                                {mobileNavItems.filter(item => canAccessTab(user, item.tab)).map(item => (
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
                        )}

                        <div style={{ height: '1px', background: 'var(--color-border)', margin: '12px 0' }} />

                        {visibleAdminMenuItems.length > 0 && (
                            <>
                                {/* Settings items */}
                                <p style={{ fontSize: '11px', fontWeight: 600, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: '8px', paddingLeft: '12px' }}>{t('navbar.adminTitle')}</p>
                                {visibleAdminMenuItems.filter(i => !i.divider).map(item => (
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
                            </>
                        )}

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

const NavItem = ({ icon, label, active, onClick, compact = false }) => {
    // Compact row: inactive tabs are icon-only and name themselves in a
    // hover label (same look as the suite capsule's); the active tab keeps
    // its label so the bar still says where you are.
    const iconOnly = compact && !active;
    return (
        <div
            onClick={onClick}
            role="button"
            aria-label={label}
            className={`group relative flex items-center gap-1.5 py-1.5 rounded-2xl cursor-pointer transition-all text-xs font-semibold whitespace-nowrap leading-none
                ${iconOnly ? 'px-2' : 'px-2.5'}
                ${active
                    ? 'bg-[var(--color-primary-light)] text-[var(--color-primary)]'
                    : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-bg-hover)] hover:text-[var(--color-text-primary)]'
                }`}
        >
            <span className="shrink-0">{icon}</span>
            {iconOnly ? (
                <span
                    className="pointer-events-none absolute left-1/2 -translate-x-1/2 top-full mt-2 px-2 py-1 rounded-lg text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-150 group-hover:opacity-100"
                    style={{
                        backgroundColor: 'var(--color-bg-card)',
                        border: '1px solid var(--color-border)',
                        color: 'var(--color-text-primary)',
                        boxShadow: 'var(--shadow-main)',
                        zIndex: 60
                    }}
                >
                    {label}
                </span>
            ) : (
                <span className="whitespace-nowrap">{label}</span>
            )}
        </div>
    );
};

export default Navbar;
