import React, { useState, useEffect } from 'react';
import { Database, CheckCircle, Clock, RotateCcw, Play, Terminal, Download, AlertCircle, Check } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

// Creates the settings-only dump ON the Keitaro server (run there, as root,
// over SSH). Kept identical to docs/keitaro-migration.md: credentials come
// from Keitaro's own inventory.env, works with a host MariaDB client or the
// one inside Keitaro's container, dumps settings tables only (no click logs)
// and refuses to run when none are found. 8d3bac8 dropped this block from the
// panel together with the old steps, leaving scp commands that fetch a file
// nothing on the server creates.
const KEITARO_DUMP_CMD = `bash <<'ORBITRA_DUMP'
set -eo pipefail
source /etc/keitaro/env/inventory.env
set -u
export MYSQL_PWD="$MARIADB_KEITARO_PASSWORD"
DB="$MARIADB_KEITARO_DATABASE"
if CLI=$(command -v mariadb || command -v mysql) && DUMP=$(command -v mariadb-dump || command -v mysqldump); then
  sql()  { "$CLI"  -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
  dump() { "$DUMP" -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
else
  CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i -m1 -E 'mariadb|mysql' || true)
  if [ -z "$CT" ]; then
    echo "ERROR: no MariaDB client on this server and no MariaDB container found" >&2
    exit 1
  fi
  echo "Using MariaDB inside container: $CT"
  sql()  { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb || command -v mysql) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
  dump() { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb-dump || command -v mysqldump) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
fi
TABLES=$(sql -N "$DB" -e "
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'keitaro_affiliate_networks', 'keitaro_groups', 'keitaro_offers',
    'keitaro_domains', 'keitaro_campaigns', 'keitaro_campaign_postbacks',
    'keitaro_landings', 'keitaro_streams', 'keitaro_stream_filters',
    'keitaro_stream_offer_associations', 'keitaro_stream_landing_associations',
    'keitaro_traffic_sources', 'keitaro_ref_sources')
ORDER BY table_name" | tr '\\n' ' ')
if [ -z "$(echo $TABLES)" ]; then
  echo "ERROR: no Keitaro tables found in database $DB" >&2
  exit 1
fi
OUT=/root/keitaro_orbitra_full.sql.gz
echo "Dumping tables: $TABLES"
dump --single-transaction --quick --skip-lock-tables "$DB" $TABLES | gzip -1 > "$OUT"
ls -lah "$OUT"
echo "DONE: $OUT"
ORBITRA_DUMP`;

const MigrationsPage = () => {
    const { t } = useLanguage();
    const [migrations, setMigrations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(null);
    const [kFile, setKFile] = useState(null);
    // Default to real import (more intuitive). Preview is available via a separate button.
    const [kDryRun, setKDryRun] = useState(false);
    const [kImportDomains, setKImportDomains] = useState(true);
    const [kImportOffers, setKImportOffers] = useState(true);
    const [kImportCompanies, setKImportCompanies] = useState(true);
    const [kImportTrafficSources, setKImportTrafficSources] = useState(false);
    const [kImportLandings, setKImportLandings] = useState(false);
    const [kImportCampaigns, setKImportCampaigns] = useState(true);
    const [kImportStreams, setKImportStreams] = useState(false);
    const [kImportCampaignPostbacks, setKImportCampaignPostbacks] = useState(false);
    const [kPreserveCampaignIds, setKPreserveCampaignIds] = useState(false);
    const [kLoading, setKLoading] = useState(false);
    const [kError, setKError] = useState('');
    const [kResult, setKResult] = useState(null);

    const [purgeConfirm, setPurgeConfirm] = useState('');
    const [purgeLoading, setPurgeLoading] = useState(false);
    const [purgeError, setPurgeError] = useState('');
    const [purgeResult, setPurgeResult] = useState(null);

    // Binom import (v2 Public API): the tracker URL and the API key travel
    // with each request and are never stored — same no-persist rule as the
    // Keitaro dump upload.
    const [bUrl, setBUrl] = useState('');
    const [bApiKey, setBApiKey] = useState('');
    const [bTesting, setBTesting] = useState(false);
    const [bTestReport, setBTestReport] = useState(null);
    const [bLoading, setBLoading] = useState(false);
    const [bError, setBError] = useState('');
    const [bResult, setBResult] = useState(null);
    const [bImportDomains, setBImportDomains] = useState(true);
    const [bImportNetworks, setBImportNetworks] = useState(true);
    const [bImportOffers, setBImportOffers] = useState(true);
    const [bImportLandings, setBImportLandings] = useState(true);
    const [bImportTrafficSources, setBImportTrafficSources] = useState(false);
    const [bImportCampaigns, setBImportCampaigns] = useState(false);

    // Which copy button last succeeded ('dump', 'scp-unix', 'scp-win'), so only
    // that one flips to "Copied" instead of every button on the page.
    const [copySuccess, setCopySuccess] = useState(null);
    const [kServerIp, setKServerIp] = useState('');

    const copyToClipboard = async (text, key = 'dump') => {
        try {
            // Try modern Clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                setCopySuccess(key);
                setTimeout(() => setCopySuccess(null), 2000);
                return;
            }
        } catch (err) {
            console.warn('Clipboard API failed, falling back to execCommand', err);
        }

        // Fallback: use textarea + execCommand
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, 99999); // For mobile devices
            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (successful) {
                setCopySuccess(key);
                setTimeout(() => setCopySuccess(null), 2000);
            } else {
                throw new Error('execCommand failed');
            }
        } catch (err) {
            console.error('Failed to copy:', err);
            alert('Failed to copy. Please select and copy the command manually.');
        }
    };

    const fetchMigrations = () => {
        setLoading(true);
        fetch(`${API_URL}?action=migrations`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    setMigrations(data.data);
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => {
        fetchMigrations();
    }, []);

    const handleRunMigration = async (version) => {
        setActionLoading(version);
        try {
            const res = await fetch(`${API_URL}?action=run_migration`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version })
            });
            const data = await res.json();
            if (data.status === 'success') {
                fetchMigrations();
            } else {
                alert(t('common.error') + ': ' + (data.message || t('migrations.runError')));
            }
        } catch (e) {
            alert((e?.message ? String(e.message) : t('common.networkError')) + ': ' + e.message);
        } finally {
            setActionLoading(null);
        }
    };

    const handleKeitaroImport = async ({ dryRunOverride = null } = {}) => {
        if (!kFile) {
            setKError(t('migrations.keitaroNoFile'));
            return;
        }
        setKLoading(true);
        setKError('');
        setKResult(null);
        try {
            const fd = new FormData();
            fd.append('sql_file', kFile);
            const dryRun = dryRunOverride === null ? kDryRun : !!dryRunOverride;
            fd.append('dry_run', dryRun ? '1' : '0');
            fd.append('import_domains', kImportDomains ? '1' : '0');
            fd.append('import_offers', kImportOffers ? '1' : '0');
            fd.append('import_companies', kImportCompanies ? '1' : '0');
            fd.append('import_traffic_sources', kImportTrafficSources ? '1' : '0');
            fd.append('import_landings', kImportLandings ? '1' : '0');
            fd.append('import_campaigns', kImportCampaigns ? '1' : '0');
            fd.append('import_streams', kImportStreams ? '1' : '0');
            fd.append('import_campaign_postbacks', kImportCampaignPostbacks ? '1' : '0');
            fd.append('preserve_campaign_ids', (kPreserveCampaignIds && kImportCampaigns) ? '1' : '0');

            const res = await fetch(`${API_URL}?action=keitaro_import_sql`, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.status !== 'success') {
                setKError(data.message || t('common.error'));
                return;
            }
            setKResult(data.data || null);
        } catch (e) {
            setKError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setKLoading(false);
        }
    };

    const binomPost = async (action, payload) => {
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return res.json();
    };

    const requireBinomCreds = () => {
        if (!bUrl.trim() || !bApiKey.trim()) {
            setBError(t('migrations.binomNoCreds'));
            return false;
        }
        return true;
    };

    const handleBinomTest = async () => {
        if (!requireBinomCreds()) return;
        setBTesting(true);
        setBError('');
        setBTestReport(null);
        setBResult(null);
        try {
            const data = await binomPost('binom_test_connection', { url: bUrl, api_key: bApiKey });
            if (data.status !== 'success') {
                setBError(data.message || t('common.error'));
                return;
            }
            setBTestReport(data.data || null);
        } catch (e) {
            setBError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setBTesting(false);
        }
    };

    const handleBinomImport = async ({ dryRunOverride = null } = {}) => {
        if (!requireBinomCreds()) return;
        setBLoading(true);
        setBError('');
        setBResult(null);
        try {
            const data = await binomPost('binom_import', {
                url: bUrl,
                api_key: bApiKey,
                dry_run: dryRunOverride === null ? false : !!dryRunOverride,
                import_domains: bImportDomains,
                import_networks: bImportNetworks,
                import_offers: bImportOffers,
                import_landings: bImportLandings,
                import_traffic_sources: bImportTrafficSources,
                import_campaigns: bImportCampaigns,
            });
            if (data.status !== 'success') {
                setBError(data.message || t('common.error'));
                return;
            }
            setBResult(data.data || null);
        } catch (e) {
            setBError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setBLoading(false);
        }
    };

    const handlePurgeMetadata = async () => {
        setPurgeLoading(true);
        setPurgeError('');
        setPurgeResult(null);
        try {
            const res = await fetch(`${API_URL}?action=purge_metadata`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm: purgeConfirm,
                    purge: {
                        companies: 1,
                        offers: 1,
                        domains: 1,
                        campaigns: 1,
                        streams: 1,
                        campaign_postbacks: 1,
                        campaign_pixels: 1,
                        traffic_sources: 1,
                        landings: 1,
                        groups: 1,
                    }
                })
            });
            const data = await res.json();
            if (data.status !== 'success') {
                setPurgeError(data.message || t('common.error'));
                return;
            }
            setPurgeResult(data.data || null);
        } catch (e) {
            setPurgeError(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setPurgeLoading(false);
        }
    };

    if (loading && migrations.length === 0) {
        return (
            <div className="page-card">
                <div className="empty-state">
                    <p className="empty-state-title">{t('migrations.loadingMigrations')}</p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="page-card">
                {/* Header */}
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.title')}</h2>
                    </div>
                </div>

                {/* Info Block */}
                <div style={{
                    background: 'var(--color-info-bg)',
                    border: '1px solid var(--color-info-border, var(--color-info))',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '24px'
                }}>
                    <p style={{ fontSize: '13.5px', lineHeight: 1.55, margin: 0, color: 'var(--color-text-primary)' }}>
                        {t('migrations.infoText')}
                    </p>
                </div>

                {/* Table */}
                <div className="overflow-x-auto">
                    <table className="page-table">
                        <thead>
                            <tr>
                                <th style={{ width: '100px' }}>{t('migrations.colVersion')}</th>
                                <th>{t('migrations.colDescription')}</th>
                                <th style={{ width: '160px' }}>{t('migrations.colStatus')}</th>
                                <th style={{ width: '140px', textAlign: 'right' }}>{t('migrations.colActions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {migrations.map(m => {
                                const isCompleted = m.status === 'completed';
                                return (
                                    <tr key={m.version}>
                                        <td style={{
                                            fontFamily: 'monospace',
                                            fontSize: '14px',
                                            color: 'var(--color-text-muted)'
                                        }}>
                                            #{m.version}
                                        </td>
                                        <td style={{ fontWeight: 500 }}>{t(`migrations.descriptions.v${m.version}`)}</td>
                                        <td>
                                            {isCompleted ? (
                                                <span className="status-badge status-active">
                                                    <CheckCircle className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                    {t('migrations.completed')}
                                                </span>
                                            ) : (
                                                <span className="status-badge status-pending">
                                                    <Clock className="w-3.5 h-3.5" style={{ marginRight: '4px' }} />
                                                    {t('migrations.pending')}
                                                </span>
                                            )}
                                            {m.executed_at && (
                                                <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '4px' }}>
                                                    {m.executed_at}
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            <div className="action-buttons">
                                                <button
                                                    onClick={() => handleRunMigration(m.version)}
                                                    disabled={actionLoading === m.version}
                                                    className={`btn btn-sm ${isCompleted ? 'btn-secondary' : 'btn-primary'}`}
                                                >
                                                    {actionLoading === m.version ? (
                                                        <RotateCcw className="w-3.5 h-3.5 animate-spin" />
                                                    ) : isCompleted ? (
                                                        <RotateCcw className="w-3.5 h-3.5" />
                                                    ) : (
                                                        <Play className="w-3.5 h-3.5" />
                                                    )}
                                                    {actionLoading === m.version ? t('migrations.running') : isCompleted ? t('migrations.repeat') : t('migrations.execute')}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {migrations.length === 0 && !loading && (
                    <div className="empty-state">
                        <p className="empty-state-title">{t('migrations.noMigrations')}</p>
                    </div>
                )}
            </div>

            {/* Keitaro import */}
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.keitaroTitle')}</h2>
                    </div>
                </div>

                {/* Backup Instruction */}
                <div style={{
                    background: 'var(--color-bg-soft)',
                    borderRadius: '16px',
                    padding: '20px',
                    marginBottom: '20px',
                    border: '1px solid var(--color-border)'
                }}>
                    <div className="flex items-center gap-2 mb-4">
                        <Terminal className="w-5 h-5" style={{ color: 'var(--color-primary)' }} />
                        <h3 className="font-semibold" style={{ fontSize: '16px', color: 'var(--color-text-primary)' }}>
                            {t('migrations.keitaroBackupTitle')}
                        </h3>
                    </div>

                    <div className="space-y-4">
                        {/* Step 1 */}
                        <div style={{ paddingLeft: '12px', borderLeft: '3px solid var(--color-primary)' }}>
                            <div className="flex items-start gap-3">
                                <div style={{
                                    width: '24px',
                                    height: '24px',
                                    borderRadius: '50%',
                                    background: 'var(--color-primary)',
                                    color: 'white',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    fontSize: '13px',
                                    fontWeight: 600,
                                    flexShrink: 0
                                }}>1</div>
                                <div style={{ flex: 1 }}>
                                    <p className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('migrations.backupStep1Title')}
                                    </p>
                                    <p className="text-sm mb-2" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('migrations.backupStep1Desc')}
                                    </p>
                                    {/* The dump command runs ON the Keitaro server (SSH, root) and
                                        creates the file the scp commands below then fetch. */}
                                    <div className="text-xs mb-1" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('migrations.dumpOnServerTitle')}
                                    </div>
                                    <p className="text-xs mb-2" style={{ color: 'var(--color-text-muted)' }}>
                                        {t('migrations.dumpOnServerHint')}
                                    </p>
                                    <div className="flex items-stretch gap-2 mb-3">
                                        <code style={{
                                            flex: 1,
                                            minWidth: 0,
                                            background: '#1e1e1e',
                                            color: '#d4d4d4',
                                            borderRadius: '8px',
                                            padding: '10px 12px',
                                            fontSize: '11px',
                                            fontFamily: 'monospace',
                                            overflowX: 'auto',
                                            maxHeight: '120px',
                                            overflowY: 'auto',
                                            whiteSpace: 'pre'
                                        }}>{KEITARO_DUMP_CMD}</code>
                                        <button
                                            type="button"
                                            onClick={() => copyToClipboard(KEITARO_DUMP_CMD, 'dump')}
                                            className="text-xs px-3 rounded-lg flex items-center gap-1 transition-colors shrink-0"
                                            style={{
                                                background: copySuccess === 'dump' ? 'var(--color-success-bg)' : 'var(--color-bg-hover)',
                                                color: copySuccess === 'dump' ? 'var(--color-success)' : 'var(--color-primary)',
                                                border: '1px solid var(--color-border)',
                                                cursor: 'pointer'
                                            }}
                                            title={copySuccess === 'dump' ? t('common.copied') : t('migrations.copyCommand')}
                                        >
                                            {copySuccess === 'dump' ? <Check size={12} /> : <Terminal size={12} />}
                                            {copySuccess === 'dump' ? t('common.copied') : t('migrations.copyCommand')}
                                        </button>
                                    </div>
                                    {/* The server IP is typed once and substituted into ready commands:
                                        the old static block made people swap YOUR_KEITARO_SERVER_IP by
                                        hand, and its first variant ended in a lone " ." that got lost in
                                        copying — scp then failed with a bare usage message. */}
                                    <input
                                        type="text"
                                        className="form-input mb-3"
                                        style={{ maxWidth: '280px' }}
                                        value={kServerIp}
                                        onChange={(e) => setKServerIp(e.target.value)}
                                        placeholder={t('migrations.serverIpPlaceholder')}
                                        aria-label={t('migrations.serverIpLabel')}
                                        spellCheck={false}
                                        autoComplete="off"
                                    />
                                    {(() => {
                                        const cleaned = kServerIp.trim().replace(/^[a-z0-9_-]+@/i, '');
                                        const host = /^[A-Za-z0-9.:[\]-]+$/.test(cleaned) ? cleaned : 'YOUR_KEITARO_SERVER_IP';
                                        const src = `root@${host}:/root/keitaro_orbitra_full.sql.gz`;
                                        const rows = [
                                            { key: 'scp-unix', label: t('migrations.scpUnix'), cmd: `scp ${src} ~/Downloads/` },
                                            { key: 'scp-win', label: t('migrations.scpWindows'), cmd: `scp ${src} $env:USERPROFILE\\Downloads\\` },
                                        ];
                                        return rows.map(r => (
                                            <div key={r.key} className="mb-2">
                                                <div className="text-xs mb-1" style={{ color: 'var(--color-text-muted)' }}>{r.label}</div>
                                                <div className="flex items-stretch gap-2">
                                                    <code style={{
                                                        flex: 1,
                                                        minWidth: 0,
                                                        background: '#1e1e1e',
                                                        color: '#d4d4d4',
                                                        borderRadius: '8px',
                                                        padding: '10px 12px',
                                                        fontSize: '12px',
                                                        fontFamily: 'monospace',
                                                        overflowX: 'auto',
                                                        whiteSpace: 'nowrap'
                                                    }}>{r.cmd}</code>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyToClipboard(r.cmd, r.key)}
                                                        className="text-xs px-3 rounded-lg flex items-center gap-1 transition-colors shrink-0"
                                                        style={{
                                                            background: copySuccess === r.key ? 'var(--color-success-bg)' : 'var(--color-bg-hover)',
                                                            color: copySuccess === r.key ? 'var(--color-success)' : 'var(--color-primary)',
                                                            border: '1px solid var(--color-border)',
                                                            cursor: 'pointer'
                                                        }}
                                                        title={copySuccess === r.key ? t('common.copied') : t('migrations.copyCommand')}
                                                    >
                                                        {copySuccess === r.key ? <Check size={12} /> : <Terminal size={12} />}
                                                        {copySuccess === r.key ? t('common.copied') : t('migrations.copyCommand')}
                                                    </button>
                                                </div>
                                            </div>
                                        ));
                                    })()}
                                </div>
                            </div>
                        </div>

                        {/* Tip */}
                        <div style={{
                            background: 'var(--color-warning-bg)',
                            borderRadius: '8px',
                            padding: '12px',
                            display: 'flex',
                            gap: '10px',
                            alignItems: 'start'
                        }}>
                            <AlertCircle size={18} style={{ color: 'var(--color-warning)', flexShrink: 0, marginTop: '2px' }} />
                            <div style={{ fontSize: '13px', color: 'var(--color-text-secondary)' }}>
                                <span className="font-medium" style={{ color: 'var(--color-warning)' }}>
                                    {t('migrations.backupTipTitle')}:
                                </span> {t('migrations.backupTipText')}
                            </div>
                        </div>
                    </div>
                </div>

                <div style={{
                    background: 'var(--color-info-bg)',
                    border: '1px solid var(--color-info-border, var(--color-info))',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '16px'
                }}>
                    <p style={{ fontSize: '13.5px', lineHeight: 1.55, margin: 0, color: 'var(--color-text-primary)' }}>
                        {t('migrations.keitaroInfo')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.keitaroFile')}</label>
                        <input
                            type="file"
                            accept=".sql,.gz,.sql.gz,application/gzip,application/x-gzip,application/sql,text/plain"
                            className="form-input"
                            onChange={(e) => setKFile(e.target.files?.[0] || null)}
                        />
                        <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '8px' }}>
                            {t('migrations.keitaroFileHint')}
                        </div>
                    </div>

                    <div>
                        <label className="form-label">{t('migrations.keitaroOptions')}</label>
                        <div className="space-y-2">
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kDryRun} onChange={(e) => setKDryRun(e.target.checked)} />
                                <span>{t('migrations.keitaroDryRun')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCompanies} onChange={(e) => setKImportCompanies(e.target.checked)} />
                                <span>{t('migrations.keitaroCompanies')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportOffers} onChange={(e) => setKImportOffers(e.target.checked)} />
                                <span>{t('migrations.keitaroOffers')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportDomains} onChange={(e) => setKImportDomains(e.target.checked)} />
                                <span>{t('migrations.keitaroDomains')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportTrafficSources} onChange={(e) => setKImportTrafficSources(e.target.checked)} />
                                <span>{t('migrations.keitaroTrafficSources')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportLandings} onChange={(e) => setKImportLandings(e.target.checked)} />
                                <span>{t('migrations.keitaroLandings')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCampaigns} onChange={(e) => setKImportCampaigns(e.target.checked)} />
                                <span>{t('migrations.keitaroCampaigns')}</span>
                            </label>
                            {kImportCampaigns && (
                                <label className="flex items-center gap-2" title={t('migrations.keitaroPreserveCampaignIdsHint')}>
                                    <input type="checkbox" checked={kPreserveCampaignIds} onChange={(e) => setKPreserveCampaignIds(e.target.checked)} />
                                    <span>{t('migrations.keitaroPreserveCampaignIds')}</span>
                                </label>
                            )}
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportStreams} onChange={(e) => setKImportStreams(e.target.checked)} />
                                <span>{t('migrations.keitaroStreams')}</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={kImportCampaignPostbacks} onChange={(e) => setKImportCampaignPostbacks(e.target.checked)} />
                                <span>{t('migrations.keitaroCampaignPostbacks')}</span>
                            </label>
                        </div>
                    </div>
                </div>

                {kError && (
                    <div className="alert alert-danger mt-4">
                        {kError}
                    </div>
                )}

                {kResult && (
                    <>
                        {kResult?.dry_run ? (
                            <div className="alert alert-warning mt-4">
                                {t('migrations.keitaroDryRun')} (dry_run=1). {t('migrations.dryRunNote1')} "{t('migrations.keitaroImportBtn')}" {t('migrations.dryRunNote2')}
                            </div>
                        ) : null}
                        <div className="alert alert-success mt-4" style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                            {JSON.stringify(kResult, null, 2)}
                        </div>
                    </>
                )}

                <div className="mt-4 flex items-center justify-end gap-2 flex-wrap">
                    <button
                        className="btn btn-secondary"
                        onClick={() => handleKeitaroImport({ dryRunOverride: true })}
                        disabled={kLoading}
                        title={t('migrations.keitaroPreviewBtn')}
                    >
                        {kLoading ? t('migrations.keitaroRunning') : t('migrations.keitaroPreviewBtn')}
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={() => handleKeitaroImport({ dryRunOverride: false })}
                        disabled={kLoading}
                    >
                        {kLoading ? t('migrations.keitaroRunning') : t('migrations.keitaroImportBtn')}
                    </button>
                </div>
            </div>

            {/* Binom import (v2 Public API — no dump file, the panel pulls) */}
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.binomTitle')}</h2>
                    </div>
                </div>

                <div style={{
                    background: 'var(--color-bg-soft)',
                    borderRadius: '16px',
                    padding: '16px 20px',
                    marginBottom: '20px',
                    border: '1px solid var(--color-border)'
                }}>
                    <p className="text-sm" style={{ color: 'var(--color-text-secondary)' }}>
                        {t('migrations.binomInfo')}
                    </p>
                    <p className="text-sm mt-2" style={{ color: 'var(--color-text-secondary)' }}>
                        {t('migrations.binomKeyHow')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.binomUrlLabel')}</label>
                        <input
                            type="text"
                            className="form-input"
                            value={bUrl}
                            onChange={(e) => setBUrl(e.target.value)}
                            placeholder={t('migrations.binomUrlPlaceholder')}
                            autoComplete="off"
                        />
                    </div>
                    <div>
                        <label className="form-label">{t('migrations.binomKeyLabel')}</label>
                        <input
                            type="password"
                            className="form-input"
                            value={bApiKey}
                            onChange={(e) => setBApiKey(e.target.value)}
                            placeholder="••••••••"
                            autoComplete="new-password"
                        />
                    </div>
                </div>

                <div className="mt-3 flex items-center gap-2 flex-wrap">
                    <button
                        className="btn btn-secondary"
                        onClick={handleBinomTest}
                        disabled={bTesting || bLoading}
                    >
                        {bTesting ? t('migrations.binomTesting') : t('migrations.binomTestBtn')}
                    </button>
                </div>

                {bTestReport && (
                    <div className={`alert mt-4 ${bTestReport.ok ? 'alert-success' : 'alert-danger'}`} style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                        {bTestReport.ok
                            ? t('migrations.binomTestOk') + "\n" + JSON.stringify({ timezone: bTestReport.timezone, counts: bTestReport.counts, issues: bTestReport.issues }, null, 2)
                            : (bTestReport.error || t('common.error'))}
                    </div>
                )}

                <div className="mt-4">
                    <label className="form-label">{t('migrations.binomOptions')}</label>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={bImportNetworks} onChange={(e) => setBImportNetworks(e.target.checked)} />
                            <span>{t('migrations.binomNetworks')}</span>
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={bImportOffers} onChange={(e) => setBImportOffers(e.target.checked)} />
                            <span>{t('migrations.binomOffers')}</span>
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={bImportLandings} onChange={(e) => setBImportLandings(e.target.checked)} />
                            <span>{t('migrations.binomLandings')}</span>
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={bImportDomains} onChange={(e) => setBImportDomains(e.target.checked)} />
                            <span>{t('migrations.binomDomains')}</span>
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={bImportTrafficSources} onChange={(e) => setBImportTrafficSources(e.target.checked)} />
                            <span>{t('migrations.keitaroTrafficSources')}</span>
                        </label>
                        <label className="flex items-center gap-2" title={t('migrations.binomCampaignsHint')}>
                            <input type="checkbox" checked={bImportCampaigns} onChange={(e) => setBImportCampaigns(e.target.checked)} />
                            <span>{t('migrations.binomCampaigns')}</span>
                        </label>
                    </div>
                </div>

                {bError && (
                    <div className="alert alert-danger mt-4">
                        {bError}
                    </div>
                )}

                {bResult && (
                    <>
                        {bResult?.dry_run ? (
                            <div className="alert alert-warning mt-4">
                                {t('migrations.dryRunNote1')} "{t('migrations.binomImportBtn')}" {t('migrations.dryRunNote2')}
                            </div>
                        ) : null}
                        <div className="alert alert-success mt-4" style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                            {JSON.stringify(bResult, null, 2)}
                        </div>
                    </>
                )}

                <div className="mt-4 flex items-center justify-end gap-2 flex-wrap">
                    <button
                        className="btn btn-secondary"
                        onClick={() => handleBinomImport({ dryRunOverride: true })}
                        disabled={bLoading || bTesting}
                        title={t('migrations.keitaroPreviewBtn')}
                    >
                        {bLoading ? t('migrations.binomRunning') : t('migrations.keitaroPreviewBtn')}
                    </button>
                    <button
                        className="btn btn-primary"
                        onClick={() => handleBinomImport({ dryRunOverride: false })}
                        disabled={bLoading || bTesting}
                    >
                        {bLoading ? t('migrations.binomRunning') : t('migrations.binomImportBtn')}
                    </button>
                </div>
            </div>

            {/* Purge / reset metadata */}
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Database className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('migrations.purgeTitle')}</h2>
                    </div>
                </div>

                <div style={{
                    background: 'var(--color-warning-bg)',
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '16px',
                    border: '1px solid var(--color-border)'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-warning)' }}>
                        {t('migrations.purgeInfo')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.purgeConfirmLabel')}</label>
                        <input
                            type="text"
                            className="form-input"
                            value={purgeConfirm}
                            onChange={(e) => setPurgeConfirm(e.target.value)}
                            placeholder="DELETE"
                        />
                        <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '8px' }}>
                            {t('migrations.purgeConfirmHint')}
                        </div>
                    </div>
                    <div>
                        <label className="form-label">{t('migrations.purgeWhat')}</label>
                        <div style={{ fontSize: '13px', color: 'var(--color-text-muted)', lineHeight: 1.5 }}>
                            {t('migrations.purgeWhatHint')}
                        </div>
                    </div>
                </div>

                {purgeError && (
                    <div className="alert alert-danger mt-4">
                        {purgeError}
                    </div>
                )}

                {purgeResult && (
                    <div className="alert alert-success mt-4" style={{ whiteSpace: 'pre-wrap', fontFamily: 'monospace', fontSize: '12px' }}>
                        {JSON.stringify(purgeResult, null, 2)}
                    </div>
                )}

                <div className="mt-4 flex items-center justify-end">
                    <button
                        className="btn btn-danger"
                        onClick={handlePurgeMetadata}
                        disabled={purgeLoading || String(purgeConfirm || '').trim().toUpperCase() !== 'DELETE'}
                        title={String(purgeConfirm || '').trim().toUpperCase() !== 'DELETE' ? t('migrations.purgeDisabledHint') : ''}
                    >
                        {purgeLoading ? t('migrations.purgeRunning') : t('migrations.purgeBtn')}
                    </button>
                </div>
            </div>
        </div>
    );
};

export default MigrationsPage;
