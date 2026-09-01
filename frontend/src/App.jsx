import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { AlertCircle, X } from 'lucide-react';
import { useLanguage } from './contexts/LanguageContext';
import Navbar from './components/Navbar';
import Login from './components/Login';
import SetupWizard from './components/SetupWizard';
import StatCards from './components/StatCards';
import MainChart from './components/MainChart';
import DataTables from './components/DataTables';
import RecentClicks from './components/RecentClicks';
import Domains from './components/Domains';
import BackorderDomains from './components/BackorderDomains';
import Campaigns from './components/Campaigns';
import TrafficSimulation from './components/TrafficSimulation';
import Landings from './components/Landings';
import Offers from './components/Offers';
import TrafficSources from './components/TrafficSources';
import ConversionsLog from './components/ConversionsLog';
import PostbackSettings from './components/PostbackSettings';
import AffiliateNetworks from './components/AffiliateNetworks';
import AdminPage from './components/AdminPage';
import TrendsPage from './components/TrendsPage';
import CampaignEditor from './components/CampaignEditor';
import DashboardHeader from './components/DashboardHeader';
import DashboardSettingsModal from './components/DashboardSettingsModal';
import LeadForgePage from './components/LeadForgePage';
import CRMPage from './components/CRMPage';
import GalleryPage from './components/GalleryPage';
import PushBasePage from './components/PushBasePage';
import { canAccessTab, firstAllowedTab } from './utils/permissions';
import { applyCustomThemeVars, clearInverseText } from './utils/themeContrast';
import { useTimezone } from './utils/useTimezone';

// In development, Vite runs on port 5173 and the API on 8080.
// In production they are served from the same domain.
const API_URL = '/api.php';

const DEFAULT_ACTIVE_METRICS = [
  'clicks',
  'unique_clicks',
  'conversions',
  'cost',
  'revenue',
  'profit',
  'roi'
];

const CHART_METRICS = new Set([
  ...DEFAULT_ACTIVE_METRICS,
  'real_revenue',
  'real_roi',
  'ctr'
]);

const getActiveMetricsStorageKey = (user) => {
  const userKey = user?.id ?? user?.username;
  return userKey ? `orbitra_dashboard_active_metrics_${userKey}` : null;
};

const loadActiveMetrics = (user) => {
  const storageKey = getActiveMetricsStorageKey(user);
  if (!storageKey) return [...DEFAULT_ACTIVE_METRICS];

  try {
    const saved = localStorage.getItem(storageKey);
    if (saved === null) return [...DEFAULT_ACTIVE_METRICS];

    const parsed = JSON.parse(saved);
    if (!Array.isArray(parsed)) return [...DEFAULT_ACTIVE_METRICS];

    const validMetrics = [...new Set(parsed.filter(metric => CHART_METRICS.has(metric)))];
    return parsed.length > 0 && validMetrics.length === 0
      ? [...DEFAULT_ACTIVE_METRICS]
      : validMetrics;
  } catch {
    return [...DEFAULT_ACTIVE_METRICS];
  }
};

function App() {
  const { t } = useLanguage();
  const ACTIVE_TAB_STORAGE_KEY = 'orbitra_active_tab';

  const normalizeActiveTab = (tab) => {
    if (!tab || typeof tab !== 'string') return 'dashboard';

    // Avoid restoring the editor on reload: it depends on transient state (editingCampaignId).
    if (tab === 'campaign_editor') return 'campaigns';

    const baseTabs = new Set([
      'dashboard',
      'domains',
      'backorder',
      'campaigns',
      'landings',
      'offers',
      'sources',
      'networks',
      'conversions',
      'trends',
      'postback',
      'simulation',
      'logs',
      'leadforge',
      'crm'
    ]);

    if (baseTabs.has(tab)) return tab;
    if (tab.startsWith('admin_')) return tab;

    return 'dashboard';
  };

  const [user, setUser] = useState(() => {
    const saved = localStorage.getItem('orbitra_user');
    return saved ? JSON.parse(saved) : null;
  });
  const [needsSetup, setNeedsSetup] = useState(null); // null = checking, true = needs setup, false = has users
  const [activeTab, setActiveTab] = useState(() => {
    const saved = localStorage.getItem(ACTIVE_TAB_STORAGE_KEY);
    return normalizeActiveTab(saved);
  });
  const [metrics, setMetrics] = useState(null);
  const [chartData, setChartData] = useState(null);
  const [globalSettings, setGlobalSettings] = useState({ currency: 'USD' });
  const [campaigns, setCampaigns] = useState([]);
  const [offers, setOffers] = useState([]);
  const [landings, setLandings] = useState([]);
  const [sources, setSources] = useState([]);
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [updateAvailable, setUpdateAvailable] = useState(null);
  const [dismissUpdate, setDismissUpdate] = useState(false);
  // Background workers that fail by being silent: the queue that delivers every
  // S2S postback and CAPI event, and the cost aggregator. Nothing else in the panel
  // says when they are not running.
  const [workerHealth, setWorkerHealth] = useState(null);
  const [dismissWorkerHealth, setDismissWorkerHealth] = useState(false);
  const [editingCampaignId, setEditingCampaignId] = useState(null);

  const [serverTime, setServerTime] = useState('');
  const [activeMetrics, setActiveMetrics] = useState(() => loadActiveMetrics(user));
  const [dashboardFilters, setDashboardFilters] = useState({
    campaign_id: '',
    date_range: 'today',
    custom_from: null,
    custom_to: null
  });

  // Shared with every list view, so applying a timezone anywhere moves the
  // dashboard too instead of leaving it on the period it mounted with.
  const [dashboardTimezone] = useTimezone();

  // Handle API Session Expiration (401 Unauthorized) globally
  useEffect(() => {
    // CSRF Token Request Interceptor
    const getCsrfToken = () => {
      // Priority: current-session token from HTML meta > localStorage fallback.
      const metaToken = document.querySelector('meta[name="csrf-token"]')?.content;
      if (metaToken && metaToken !== '{{ csrf_token }}') return metaToken;
      const storedToken = localStorage.getItem('orbitra_csrf_token');
      return storedToken || '';
    };

    const reqIntercept = axios.interceptors.request.use((config) => {
      const csrfToken = getCsrfToken();
      if (csrfToken && csrfToken !== '{{ csrf_token }}') {
        config.headers['X-CSRF-TOKEN'] = csrfToken;
      }
      return config;
    });

    const mintercept = axios.interceptors.response.use(
      response => response,
      error => {
        if (error.response && error.response.status === 401) {
          localStorage.removeItem('orbitra_user');
          localStorage.removeItem('orbitra_csrf_token');
          setUser(null);
          // Optional: Handle token refresh or show message
        }
        return Promise.reject(error);
      }
    );
    // Add interceptor for native fetch as well
    const originalFetch = window.fetch;
    window.fetch = async (input, init = {}) => {
      const requestInit = { ...init };
      const method = (requestInit.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const requestUrl = typeof input === 'string' ? input : input?.url || '';

      const isApiRequest = requestUrl.includes('/api.php') || requestUrl.startsWith('/api.php');
      const isMutating = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);

      if (isApiRequest) {
        requestInit.credentials = requestInit.credentials || 'same-origin';

        if (isMutating) {
          const csrfToken = getCsrfToken();
          if (csrfToken && csrfToken !== '{{ csrf_token }}') {
            const headers = new Headers(requestInit.headers || (input instanceof Request ? input.headers : undefined));
            if (!headers.has('X-CSRF-TOKEN')) {
              headers.set('X-CSRF-TOKEN', csrfToken);
            }
            requestInit.headers = headers;
          }
        }
      }

      const response = await originalFetch(input, requestInit);
      if (response.status === 401) {
        localStorage.removeItem('orbitra_user');
        localStorage.removeItem('orbitra_csrf_token');
        setUser(null);
      }
      return response;
    };

    return () => {
      axios.interceptors.request.eject(reqIntercept);
      axios.interceptors.response.eject(mintercept);
      window.fetch = originalFetch; // restore
    };
  }, []);

  // Cross-page navigation for components that don't receive setActiveTab as a
  // prop (Integrations lives inside AdminPage): they dispatch
  // 'orbitra:navigate' with { tab } instead.
  useEffect(() => {
    const onNavigate = (e) => {
      const tab = e?.detail?.tab;
      if (typeof tab === 'string' && tab) setActiveTab(tab);
    };
    window.addEventListener('orbitra:navigate', onNavigate);
    return () => window.removeEventListener('orbitra:navigate', onNavigate);
  }, []);

  // Global Theme Manager
  useEffect(() => {
    const applyTheme = () => {
      const savedMode = localStorage.getItem('orbitra_mode') || 'light';
      const root = document.documentElement;

      if (['dark', 'green', 'neon', 'cobalt', 'cobalt-dark', 'canary', 'canary-dark', 'parchment', 'parchment-dark', 'indigo', 'indigo-dark', 'custom'].includes(savedMode)) {
        root.setAttribute('data-theme', savedMode);
        root.setAttribute('data-mode', savedMode);
      } else {
        root.removeAttribute('data-theme');
        root.removeAttribute('data-mode');
      }

      if (savedMode === 'custom') {
        const customColorsStr = localStorage.getItem('orbitra_custom_colors');
        let applied = false;
        if (customColorsStr) {
          try {
            applyCustomThemeVars(JSON.parse(customColorsStr), root);
            applied = true;
          } catch (e) { }
        }
        // A broken/missing palette must not leave a stale inline inverse
        // overriding the theme's own value.
        if (!applied) clearInverseText(root);
      } else {
        root.style.removeProperty('--color-primary');
        root.style.removeProperty('--color-bg-main');
        root.style.removeProperty('--color-bg-card');
        root.style.removeProperty('--color-text-primary');
        root.style.removeProperty('--color-bg-header');
        root.style.removeProperty('--color-text-header');
        clearInverseText(root);
      }
    };

    applyTheme(); // Run on mount

    window.addEventListener('storage', applyTheme);
    window.addEventListener('themeChanged', applyTheme);
    return () => {
      window.removeEventListener('storage', applyTheme);
      window.removeEventListener('themeChanged', applyTheme);
    };
  }, []);

  const [dashboardPreferences, setDashboardPreferences] = useState(() => {
    const saved = localStorage.getItem('ltt_dash_prefs');
    return saved ? JSON.parse(saved) : {
      visible_metrics: ['clicks', 'unique_clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi', 'cpc', 'cpa'],
      visible_blocks: ['campaigns', 'offers', 'landings', 'sources'],
      click_columns: ['created_at', 'campaign_name', 'device_type', 'ip', 'user_agent', 'redirect_url']
    };
  });
  const [showSettingsModal, setShowSettingsModal] = useState(false);

  useEffect(() => {
    localStorage.setItem('ltt_dash_prefs', JSON.stringify(dashboardPreferences));
  }, [dashboardPreferences]);

  // Chart selection is independent from card visibility settings and belongs to
  // the signed-in user. An empty array is valid: the user may disable all series.
  useEffect(() => {
    const storageKey = getActiveMetricsStorageKey(user);
    if (!storageKey) return;

    try {
      localStorage.setItem(storageKey, JSON.stringify(activeMetrics));
    } catch {
      // Ignore storage issues (private mode, quota, etc.).
    }
  }, [activeMetrics, user]);

  const fetchData = async () => {
    try {
      // Build query string for dashboard filters
      const params = new URLSearchParams();
      if (dashboardFilters.campaign_id) params.append('campaign_id', dashboardFilters.campaign_id);
      if (dashboardFilters.date_range) params.append('date_range', dashboardFilters.date_range);
      if (dashboardFilters.date_range === 'custom' && dashboardFilters.custom_from && dashboardFilters.custom_to) {
        // format as YYYY-MM-DD
        const fDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        params.append('custom_from', fDate(dashboardFilters.custom_from));
        params.append('custom_to', fDate(dashboardFilters.custom_to));
      }
      // The date-range picker persists its timezone here; the API shifts every
      // date condition by it, so without this param the selector was decorative.
      if (dashboardTimezone) params.append('timezone', dashboardTimezone);

      const pStr = params.toString() ? `&${params.toString()}` : '';

      const results = await Promise.all([
        axios.get(`${API_URL}?action=metrics${pStr}`),
        axios.get(`${API_URL}?action=chart${pStr}`),
        axios.get(`${API_URL}?action=campaigns${pStr}`).catch(err => ({ ok: false, error: err })), // Removed limit=10 to show all
        axios.get(`${API_URL}?action=offers${pStr}`).catch(err => ({ ok: false, error: err })),
        axios.get(`${API_URL}?action=logs${pStr}&dashboard=true&per_page=20`).catch(err => ({ ok: false, error: err })),
        axios.get(`${API_URL}?action=landings${pStr}`).catch(err => ({ ok: false, error: err })),
        axios.get(`${API_URL}?action=traffic_sources${pStr}`).catch(err => ({ ok: false, error: err }))
      ]);
      const [resMetrics, resChart, resCampaigns, resOffers, resLogs, resLandings, resSources] = results;

      if (resMetrics.data.status === 'success') {
        setMetrics(resMetrics.data.data);
        if (resMetrics.data.server_time) {
          setServerTime(resMetrics.data.server_time);
        }
      }
      if (resChart.data.status === 'success') setChartData(resChart.data.data);
      // A 403 means the signed-in user's resource scope is 'none' (the tab is
      // hidden too) — degrade to an empty list instead of failing the whole
      // dashboard load.
      if (resCampaigns.data?.status === 'success') setCampaigns(resCampaigns.data.data || []);
      if (resOffers.data?.status === 'success') setOffers(resOffers.data.data || []);
      if (resLogs.data?.status === 'success') setLogs(resLogs.data.data || []);
      if (resLandings.data?.status === 'success') setLandings(resLandings.data.data || []);
      if (resSources.data?.status === 'success') setSources(resSources.data.data || []);
    } catch (error) {
      if (error?.response?.status !== 401) {
        console.error("Error fetching data:", error);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!user) return;
    fetchData();
    // Only the dashboard renders this data. Polling on every tab fired seven
    // requests per 10s at the single SQLite writer (~60k/day, queued behind
    // the crons — the real cause of "reports are slow"). The interval is
    // dashboard-scoped, 15s, and silent while the tab is hidden; the initial
    // fetch still runs per tab change — a burst, not a stream.
    if (activeTab !== 'dashboard') return;
    const interval = setInterval(() => {
      if (!document.hidden) fetchData();
    }, 15000);
    return () => clearInterval(interval);
    // dashboardTimezone: the dashboard's own numbers are bucketed by it server-side,
    // so a timezone change has to refetch, not just relabel.
  }, [user, dashboardFilters, dashboardTimezone, activeTab]);

  // Fetch global settings (e.g., default currency) once per session.
  useEffect(() => {
    const fetchGlobalSettings = async () => {
      try {
        const res = await axios.get(`${API_URL}?action=global_settings`);
        if (res?.data?.status === 'success' && res?.data?.data) {
          setGlobalSettings(prev => ({ ...prev, ...res.data.data }));
        }
      } catch (e) {
        // ignore
      }
    };
    if (user) {
      fetchGlobalSettings();
    }
  }, [user]);

  // Check for updates on mount
  useEffect(() => {
    const checkUpdate = async () => {
      try {
        const res = await axios.get(`${API_URL}?action=check_update`);
        if (res.data.status === 'success' && res.data.data.update_available) {
          // Version-scoped dismissal: silencing 1.3.x must not silence 1.3.y
          setDismissUpdate(localStorage.getItem('orbitra_update_dismissed') === res.data.data.latest_version);
          setUpdateAvailable(res.data.data);
        }
      } catch (e) {
        // Silently fail
      }
    };
    if (user) {
      checkUpdate();
    }
  }, [user]);

  // Worker health. Polled slowly — this answers "is the cron installed", which does
  // not change minute to minute.
  useEffect(() => {
    const checkWorkers = async () => {
      try {
        const res = await axios.get(`${API_URL}?action=worker_health`);
        if (res.data.status === 'success') {
          setWorkerHealth(res.data.data);
        }
      } catch (e) {
        // Silently fail — a health check must never block the panel.
      }
    };
    if (user) {
      checkWorkers();
      const interval = setInterval(checkWorkers, 300000);
      return () => clearInterval(interval);
    }
  }, [user]);

  // Check if setup is needed on mount
  useEffect(() => {
    const checkSetup = async () => {
      try {
        const res = await axios.get(`${API_URL}?action=check_setup`);
        if (res.data.status === 'success') {
          setNeedsSetup(res.data.needs_setup);
        } else {
          setNeedsSetup(false);
        }
      } catch (e) {
        setNeedsSetup(false);
      }
    };
    checkSetup();
  }, []);

  const handleLogin = (userData) => {
    localStorage.setItem('orbitra_user', JSON.stringify(userData));
    setActiveMetrics(loadActiveMetrics(userData));
    setUser(userData);
  };

  const handleLogout = () => {
    localStorage.removeItem('orbitra_user');
    localStorage.removeItem(ACTIVE_TAB_STORAGE_KEY);
    setUser(null);
  };

  // Persist the current tab so a full page refresh returns the user to the same place.
  useEffect(() => {
    if (!user) return;
    if (activeTab === 'campaign_editor') return;
    try {
      localStorage.setItem(ACTIVE_TAB_STORAGE_KEY, normalizeActiveTab(activeTab));
    } catch (e) {
      // Ignore storage issues (private mode, quota, etc.)
    }
  }, [activeTab, user]);

  // Route guard: keep the user away from tabs they cannot open — admin-only
  // gear sections, gear entries tied to a revoked resource, or a permission
  // key set to 'none' (restored from localStorage or a stale link).
  useEffect(() => {
    if (!user) return;
    if (!canAccessTab(user, activeTab)) {
      setActiveTab(firstAllowedTab(user));
    }
  }, [activeTab, user]);

  // Show loading while checking setup
  if (needsSetup === null) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--color-primary)]"></div>
      </div>
    );
  }

  // Show setup wizard if no users exist
  if (needsSetup === true) {
    return <SetupWizard onComplete={() => setNeedsSetup(false)} />;
  }

  if (!user) {
    return <Login onLogin={handleLogin} />;
  }

  return (
    <div className="min-h-screen relative pb-10">
      <Navbar activeTab={activeTab} setActiveTab={setActiveTab} user={user} onLogout={handleLogout} />
      {/* 84px of top padding below 480px: the navbar shrinks to 48px there,
          pt-32 (128px) strands the page title in empty space. overflow-x clip
          (not hidden): hidden would make <main> a scroll container and break
          sticky table headers — clip only stops one wide child from dragging
          the viewport sideways. */}
      <main className="pt-32 max-[480px]:pt-[84px] px-4 md:px-6 w-full mx-auto [overflow-x:clip]">
        {loading ? (
          <div className="flex items-center justify-center h-64">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--color-primary)]"></div>
          </div>
        ) : (
          <>
            {/* CRM and LeadForge carry their own hero headers with actions —
                rendering this generic h1 on top duplicated the title twice. */}
            {!['leadforge', 'crm'].includes(activeTab) && (
            <div className="flex justify-between items-center mb-2">
              <h1 className="text-2xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                {activeTab === 'dashboard' && t('app.dashboard')}
                {activeTab === 'domains' && t('app.domains')}
                {activeTab === 'backorder' && t('app.backorder')}
                {activeTab === 'campaigns' && t('app.campaigns')}
                {activeTab === 'offers' && t('app.offers')}
                {activeTab === 'sources' && t('app.sources')}
                {activeTab === 'networks' && t('app.networks')}
                {activeTab === 'conversions' && t('app.conversions')}
                {activeTab === 'postback' && t('app.postback')}
                {activeTab === 'landings' && t('app.landings')}
                {activeTab === 'simulation' && t('app.simulation')}
                {activeTab === 'leadforge' && (t('leadforge.title') || 'LeadForge')}
                {activeTab === 'crm' && (t('crm.title') || 'CRM')}
              </h1>
              {activeTab === 'dashboard' && (
                <div className="text-sm hidden md:block" style={{ color: 'var(--color-text-secondary)' }}>
                  {t('app.updated')} {serverTime ? serverTime : new Date().toLocaleTimeString()}
                </div>
              )}
            </div>
            )}

            {/* Update Available Banner */}
            {updateAvailable && !dismissUpdate && (
              <div className="mb-4 bg-[var(--color-warning-bg)] border border-[var(--color-warning-border)] rounded-lg p-4 flex items-center justify-between">
                <div
                  role="button"
                  tabIndex={0}
                  onClick={() => setActiveTab('admin_update')}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      setActiveTab('admin_update');
                    }
                  }}
                  className="flex items-center gap-3 cursor-pointer"
                >
                  <AlertCircle className="w-6 h-6 text-[var(--color-warning)]" />
                  <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('app.updateAvailable')}</span>
                    <span className="text-[var(--color-text-secondary)] ml-2">{t('app.updateDesc').replace('{version}', updateAvailable.latest_version)}</span>
                  </div>
                </div>
                <button
                  onClick={() => {
                    setDismissUpdate(true);
                    localStorage.setItem('orbitra_update_dismissed', updateAvailable.latest_version || '');
                  }}
                  aria-label={t('common.close')}
                  className="p-1 hover:bg-[var(--color-bg-hover)] rounded"
                >
                  <X className="w-5 h-5 text-[var(--color-warning)]" />
                </button>
              </div>
            )}

            {/* Background worker warnings — see worker_health in api.php */}
            {workerHealth && !workerHealth.healthy && !dismissWorkerHealth && (
              <div className="mb-4 bg-[var(--color-warning-bg)] border border-[var(--color-warning-border)] rounded-lg p-4 flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <AlertCircle className="w-6 h-6 text-[var(--color-warning)] flex-shrink-0" />
                  <div>
                    <span className="font-medium text-[var(--color-text-primary)]">{t('workerHealth.title')}</span>
                    <ul className="mt-1 space-y-0.5">
                      {workerHealth.issues.map((issue, i) => (
                        <li key={i} className="text-[var(--color-text-secondary)] text-sm">
                          {t(`workerHealth.${issue.key}`)
                            .replace('{count}', issue.count ?? 0)
                            .replace('{minutes}', issue.minutes ?? 0)
                            .replace('{statuses}', (issue.statuses || []).join(', '))}
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
                <button
                  onClick={() => setDismissWorkerHealth(true)}
                  aria-label={t('common.close')}
                  className="p-1 hover:bg-[var(--color-bg-hover)] rounded flex-shrink-0"
                >
                  <X className="w-5 h-5 text-[var(--color-warning)]" />
                </button>
              </div>
            )}

            {activeTab === 'dashboard' && (
              <>
                <DashboardHeader
                  filters={dashboardFilters}
                  setFilters={setDashboardFilters}
                  campaigns={campaigns}
                  onOpenSettings={() => setShowSettingsModal(true)}
                />
                <StatCards metrics={metrics} preferences={dashboardPreferences} activeMetrics={activeMetrics} setActiveMetrics={setActiveMetrics} user={user} />
                <MainChart chartData={chartData} activeMetrics={activeMetrics} currency={globalSettings.currency || 'USD'} />
                <DataTables
                  campaigns={campaigns.slice(0, 10)}
                  offers={offers.slice(0, 10)}
                  landings={landings.slice(0, 10)}
                  sources={sources.slice(0, 10)}
                  preferences={dashboardPreferences}
                />
                <RecentClicks
                  logs={logs}
                  preferences={dashboardPreferences}
                  onShowAll={() => setActiveTab('logs')}
                />
              </>
            )}

            {activeTab === 'domains' && (
              <Domains campaigns={campaigns} user={user} />
            )}

            {activeTab === 'backorder' && (
              <BackorderDomains
                onOpenAutomation={() => {
                  localStorage.setItem('orbitra_settings_tab', 'automation');
                  setActiveTab('admin_settings');
                }}
              />
            )}

            {activeTab === 'campaigns' && (
              <Campaigns
                campaigns={campaigns}
                refreshData={fetchData}
                setActiveTab={setActiveTab}
                setEditingCampaignId={setEditingCampaignId}
                user={user}
              />
            )}

            {activeTab === 'landings' && (
              <Landings landings={landings} refreshData={fetchData} user={user} />
            )}

            {activeTab === 'offers' && (
              <Offers offers={offers} refreshData={fetchData} user={user} />
            )}

            {activeTab === 'media' && (
              <GalleryPage user={user} />
            )}

            {activeTab === 'push' && (
              <PushBasePage user={user} />
            )}

            {activeTab === 'sources' && (
              <TrafficSources refreshData={fetchData} user={user} />
            )}

            {activeTab === 'networks' && (
              <AffiliateNetworks user={user} />
            )}

            {activeTab === 'conversions' && (
              <ConversionsLog />
            )}

            {activeTab === 'trends' && (
              <TrendsPage />
            )}

            {activeTab === 'postback' && (
              <PostbackSettings />
            )}

            {activeTab === 'simulation' && (
              <TrafficSimulation />
            )}

            {activeTab === 'leadforge' && (
              <LeadForgePage
                setActiveTab={setActiveTab}
                refreshData={fetchData}
              />
            )}

            {activeTab === 'crm' && (
              <CRMPage
                setActiveTab={setActiveTab}
                user={user}
              />
            )}

            {activeTab === 'campaign_editor' && (
              <CampaignEditor
                campaignId={editingCampaignId}
                onClose={(saved) => {
                  setActiveTab('campaigns');
                  if (saved) fetchData();
                }}
              />
            )}

            {/* Admin Pages */}
            {activeTab === 'logs' ? (
              <AdminPage page="admin_logs" />
            ) : activeTab.startsWith('admin_') && (
              <AdminPage page={activeTab} />
            )}

            {showSettingsModal && (
              <DashboardSettingsModal
                preferences={dashboardPreferences}
                setPreferences={setDashboardPreferences}
                onClose={() => setShowSettingsModal(false)}
              />
            )}
          </>
        )}
      </main>
    </div>
  );
}

export default App;
