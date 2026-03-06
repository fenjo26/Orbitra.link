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

// В режиме разработки Vite запущен на порту 5173, а API на 8080.
// В проде они будут на одном домене.
const API_URL = '/api.php';

function App() {
  const { t } = useLanguage();
  const [user, setUser] = useState(() => {
    const saved = localStorage.getItem('orbitra_user');
    return saved ? JSON.parse(saved) : null;
  });
  const [needsSetup, setNeedsSetup] = useState(null); // null = checking, true = needs setup, false = has users
  const [activeTab, setActiveTab] = useState('dashboard');
  const [metrics, setMetrics] = useState(null);
  const [chartData, setChartData] = useState(null);
  const [campaigns, setCampaigns] = useState([]);
  const [offers, setOffers] = useState([]);
  const [landings, setLandings] = useState([]);
  const [sources, setSources] = useState([]);
  const [logs, setLogs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [updateAvailable, setUpdateAvailable] = useState(null);
  const [dismissUpdate, setDismissUpdate] = useState(false);
  const [editingCampaignId, setEditingCampaignId] = useState(null);

  const [serverTime, setServerTime] = useState('');
  const [activeMetrics, setActiveMetrics] = useState(['clicks']);
  const [dashboardFilters, setDashboardFilters] = useState({
    campaign_id: '',
    date_range: 'today',
    custom_from: null,
    custom_to: null
  });

  // Handle API Session Expiration (401 Unauthorized) globally
  useEffect(() => {
    // CSRF Token Request Interceptor
    const getCsrfToken = () => {
      // Priority: localStorage (from login) > meta tag
      const storedToken = localStorage.getItem('orbitra_csrf_token');
      if (storedToken) return storedToken;
      return document.querySelector('meta[name="csrf-token"]')?.content;
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
          setUser(null);
          // Optional: Handle token refresh or show message
        }
        return Promise.reject(error);
      }
    );
    // Add interceptor for native fetch as well
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
      const response = await originalFetch(...args);
      if (response.status === 401) {
        localStorage.removeItem('orbitra_user');
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

  // Global Theme Manager
  useEffect(() => {
    const applyTheme = () => {
      const savedMode = localStorage.getItem('orbitra_mode') || 'light';
      const root = document.documentElement;

      if (['dark', 'green', 'neon', 'custom'].includes(savedMode)) {
        root.setAttribute('data-theme', savedMode);
        root.setAttribute('data-mode', savedMode);
      } else {
        root.removeAttribute('data-theme');
        root.removeAttribute('data-mode');
      }

      if (savedMode === 'custom') {
        const customColorsStr = localStorage.getItem('orbitra_custom_colors');
        if (customColorsStr) {
          try {
            const customColors = JSON.parse(customColorsStr);
            Object.keys(customColors).forEach(key => {
              root.style.setProperty(key, customColors[key]);
            });
          } catch (e) { }
        }
      } else {
        root.style.removeProperty('--color-primary');
        root.style.removeProperty('--color-bg-main');
        root.style.removeProperty('--color-bg-card');
        root.style.removeProperty('--color-text-primary');
        root.style.removeProperty('--color-bg-header');
        root.style.removeProperty('--color-text-header');
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

      const pStr = params.toString() ? `&${params.toString()}` : '';

      const [resMetrics, resChart, resCampaigns, resOffers, resLogs, resLandings, resSources] = await Promise.all([
        axios.get(`${API_URL}?action=metrics${pStr}`),
        axios.get(`${API_URL}?action=chart${pStr}`),
        axios.get(`${API_URL}?action=campaigns${pStr}`), // Removed limit=10 to show all
        axios.get(`${API_URL}?action=offers${pStr}`),
        axios.get(`${API_URL}?action=logs${pStr}&dashboard=true&per_page=20`),
        axios.get(`${API_URL}?action=landings${pStr}`),
        axios.get(`${API_URL}?action=traffic_sources${pStr}`)
      ]);

      if (resMetrics.data.status === 'success') {
        setMetrics(resMetrics.data.data);
        if (resMetrics.data.server_time) {
          setServerTime(resMetrics.data.server_time);
        }
      }
      if (resChart.data.status === 'success') setChartData(resChart.data.data);
      if (resCampaigns.data.status === 'success') setCampaigns(resCampaigns.data.data || []);
      if (resOffers.data.status === 'success') setOffers(resOffers.data.data || []);
      if (resLogs.data.status === 'success') setLogs(resLogs.data.data || []);
      if (resLandings.data.status === 'success') setLandings(resLandings.data.data || []);
      if (resSources.data.status === 'success') setSources(resSources.data.data || []);
    } catch (error) {
      console.error("Error fetching data:", error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (user) {
      fetchData();
      const interval = setInterval(fetchData, 10000);
      return () => clearInterval(interval);
    }
  }, [user, dashboardFilters]);

  // Check for updates on mount
  useEffect(() => {
    const checkUpdate = async () => {
      try {
        const res = await axios.get(`${API_URL}?action=check_update`);
        if (res.data.status === 'success' && res.data.data.update_available) {
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
    setUser(userData);
  };

  const handleLogout = () => {
    localStorage.removeItem('orbitra_user');
    setUser(null);
  };

  // Show loading while checking setup
  if (needsSetup === null) {
    return (
      <div className="min-h-screen bg-gray-900 flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
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
      <main className="pt-32 px-4 md:px-6 w-full mx-auto">
        {loading ? (
          <div className="flex items-center justify-center h-64">
            <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
          </div>
        ) : (
          <>
            <div className="flex justify-between items-center mb-2">
              <h1 className="text-2xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                {activeTab === 'dashboard' && t('app.dashboard')}
                {activeTab === 'domains' && t('app.domains')}
                {activeTab === 'campaigns' && t('app.campaigns')}
                {activeTab === 'offers' && t('app.offers')}
                {activeTab === 'sources' && t('app.sources')}
                {activeTab === 'networks' && t('app.networks')}
                {activeTab === 'conversions' && t('app.conversions')}
                {activeTab === 'postback' && t('app.postback')}
                {activeTab === 'landings' && t('app.landings')}
                {activeTab === 'simulation' && t('app.simulation')}
              </h1>
              {activeTab === 'dashboard' && (
                <div className="text-sm hidden md:block" style={{ color: 'var(--color-text-secondary)' }}>
                  {t('app.updated')} {serverTime ? serverTime : new Date().toLocaleTimeString()}
                </div>
              )}
            </div>

            {/* Update Available Banner */}
            {updateAvailable && !dismissUpdate && (
              <div className="mb-4 bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-center justify-between">
                <div className="flex items-center gap-3 cursor-pointer" onClick={() => setActiveTab('admin_update')}>
                  <AlertCircle className="w-6 h-6 text-amber-600" />
                  <div>
                    <span className="font-medium text-amber-800">{t('app.updateAvailable')}</span>
                    <span className="text-amber-700 ml-2">{t('app.updateDesc').replace('{version}', updateAvailable.latest_version)}</span>
                  </div>
                </div>
                <button
                  onClick={() => setDismissUpdate(true)}
                  className="p-1 hover:bg-amber-100 rounded"
                >
                  <X className="w-5 h-5 text-amber-600" />
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
                <StatCards metrics={metrics} preferences={dashboardPreferences} activeMetrics={activeMetrics} setActiveMetrics={setActiveMetrics} />
                <MainChart chartData={chartData} activeMetrics={activeMetrics} />
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
              <Domains campaigns={campaigns} />
            )}

            {activeTab === 'campaigns' && (
              <Campaigns
                campaigns={campaigns}
                refreshData={fetchData}
                setActiveTab={setActiveTab}
                setEditingCampaignId={setEditingCampaignId}
              />
            )}

            {activeTab === 'landings' && (
              <Landings landings={landings} refreshData={fetchData} />
            )}

            {activeTab === 'offers' && (
              <Offers offers={offers} refreshData={fetchData} />
            )}

            {activeTab === 'sources' && (
              <TrafficSources refreshData={fetchData} />
            )}

            {activeTab === 'networks' && (
              <AffiliateNetworks />
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
