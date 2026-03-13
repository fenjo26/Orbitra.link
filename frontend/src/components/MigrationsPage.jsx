import React, { useState, useEffect } from 'react';
import { Database, CheckCircle, Clock, RotateCcw, Play, Terminal, Download, AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const API_URL = '/api.php';

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
            alert(t('common.networkError') + ': ' + e.message);
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
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '24px'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-info)' }}>
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
                                    <div style={{
                                        background: '#1e1e1e',
                                        borderRadius: '8px',
                                        padding: '12px',
                                        overflow: 'auto'
                                    }}>
                                        <code style={{
                                            fontSize: '12px',
                                            color: '#d4d4d4',
                                            whiteSpace: 'pre-wrap',
                                            fontFamily: 'monospace'
                                        }}>
{`ssh root@YOUR_KEITARO_SERVER_IP`}
                                        </code>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Step 2 */}
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
                                }}>2</div>
                                <div style={{ flex: 1 }}>
                                    <p className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('migrations.backupStep2Title')}
                                    </p>
                                    <p className="text-sm mb-2" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('migrations.backupStep2Desc')}
                                    </p>
                                    <button
                                        onClick={() => {
                                            const command = `bash -lc '
set -euo pipefail

source /etc/keitaro/env/inventory.env

# Конфиг чтобы не светить пароль в командной строке
cat > /root/keitaro-mariadb.cnf <<EOF
[client]
user=$MARIADB_KEITARO_USER
password=$MARIADB_KEITARO_PASSWORD
host=127.0.0.1
port=3306
protocol=tcp
EOF
chmod 600 /root/keitaro-mariadb.cnf

# Список "настроечных" таблиц, которые нужны для миграции (без логов/кликов/рефов и т.п.)
SQL_LIST="
SELECT table_name
FROM information_schema.tables
WHERE table_schema = \\'\\'$MARIADB_KEITARO_DATABASE\\'\\'
AND table_name IN (
  \\'\\'keitaro_affiliate_networks\\'\\',
  \\'\\'keitaro_groups\\'\\',
  \\'\\'keitaro_offers\\'\\',
  \\'\\'keitaro_domains\\'\\',
  \\'\\'keitaro_campaigns\\'\\',
  \\'\\'keitaro_campaign_postbacks\\'\\',
  \\'\\'keitaro_landings\\'\\',
  \\'\\'keitaro_streams\\'\\',
  \\'\\'keitaro_stream_filters\\'\\',
  \\'\\'keitaro_stream_offer_associations\\'\\',
  \\'\\'keitaro_stream_landing_associations\\'\\',
  \\'\\'keitaro_traffic_sources\\'\\',
  \\'\\'keitaro_ref_sources\\'\\'
)
ORDER BY table_name;
"

TABLES=$(mariadb --defaults-extra-file=/root/keitaro-mariadb.cnf -N -e "$SQL_LIST" "$MARIADB_KEITARO_DATABASE" | tr "\\n" " ")

OUT="/root/keitaro_orbitra_full.sql.gz"
echo "Dumping tables: $TABLES"
mysqldump --defaults-extra-file=/root/keitaro-mariadb.cnf \\
  --single-transaction --quick --skip-lock-tables \\
  "$MARIADB_KEITARO_DATABASE" $TABLES \\
  | gzip -1 > "$OUT"

ls -lah "$OUT"
echo "DONE: $OUT"
'`;
                                            navigator.clipboard.writeText(command);
                                        }}
                                        className="text-xs px-3 py-1.5 rounded-lg flex items-center gap-1 transition-colors"
                                        style={{
                                            background: 'var(--color-bg-hover)',
                                            color: 'var(--color-primary)',
                                            border: '1px solid var(--color-border)',
                                            cursor: 'pointer'
                                        }}
                                        title={t('migrations.copyCommand')}
                                    >
                                        <Terminal size={12} /> {t('migrations.copyCommand')}
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Step 3 */}
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
                                }}>3</div>
                                <div style={{ flex: 1 }}>
                                    <p className="font-medium mb-2" style={{ color: 'var(--color-text-primary)' }}>
                                        {t('migrations.backupStep3Title')}
                                    </p>
                                    <p className="text-sm mb-2" style={{ color: 'var(--color-text-secondary)' }}>
                                        {t('migrations.backupStep3Desc')}
                                    </p>
                                    <div style={{
                                        background: '#1e1e1e',
                                        borderRadius: '8px',
                                        padding: '12px',
                                        overflow: 'auto'
                                    }}>
                                        <code style={{
                                            fontSize: '12px',
                                            color: '#d4d4d4',
                                            whiteSpace: 'pre-wrap',
                                            fontFamily: 'monospace'
                                        }}>
{`# Скачать в текущую папку:
scp root@YOUR_KEITARO_SERVER_IP:/root/keitaro_orbitra_full.sql.gz .

# Скачать в Downloads (macOS/Linux):
scp root@YOUR_KEITARO_SERVER_IP:/root/keitaro_orbitra_full.sql.gz ~/Downloads/

# Скачать в Downloads (Windows PowerShell):
scp root@YOUR_KEITARO_SERVER_IP:/root/keitaro_orbitra_full.sql.gz $env:USERPROFILE\\Downloads\\`}
                                        </code>
                                    </div>
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
                    borderRadius: '16px',
                    padding: '16px',
                    marginBottom: '16px'
                }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-info)' }}>
                        {t('migrations.keitaroInfo')}
                    </p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label className="form-label">{t('migrations.keitaroFile')}</label>
                        <input
                            type="file"
                            accept=".sql,.sql.gz"
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
                                {t('migrations.keitaroDryRun')} (dry_run=1). Данные не были записаны в Orbitra. Нажми "{t('migrations.keitaroImportBtn')}" для реального импорта.
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
