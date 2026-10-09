import React, { useState, useEffect } from 'react';
import { ShoppingCart, Download, Check, X, AlertCircle, RefreshCw, Copy } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost } from '../utils/apiCache';
import { copyToClipboard } from '../utils/clipboard';

// Dynadot toolbar buttons + the Register and Import dialogs for the Domains
// page. Self-contained on purpose: the Namecheap dialogs in Domains.jsx stay
// untouched, and this one renders nothing until a Dynadot account exists.
//
// Dynadot allows one API call per second per key, and parking a domain takes
// three of them (domain_info, get_dns, set_dns2), so the import sends
// save_domain in batches small enough to finish well inside one request.
const IMPORT_BATCH = 10;
const INTENT_KEY = 'orbitra_dd_intent';

const DynadotDomainTools = ({ domains, onChanged, pageLoading }) => {
    const { t } = useLanguage();
    const [accounts, setAccounts] = useState([]);
    const [accountId, setAccountId] = useState(null);
    const [clientIp, setClientIp] = useState('');
    const [intent, setIntent] = useState(null);

    const [showRegister, setShowRegister] = useState(false);
    const [regDomain, setRegDomain] = useState('');
    const [regChecking, setRegChecking] = useState(false);
    const [regResult, setRegResult] = useState(null);
    const [regBuying, setRegBuying] = useState(false);
    const [regMessage, setRegMessage] = useState('');

    const [showImport, setShowImport] = useState(false);
    const [imp, setImp] = useState({ loading: false, domains: [], selected: {}, importing: false, progress: '', message: '', ip: '' });
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        cachedGet('dynadot_status', {}, 0)
            .then(({ data }) => {
                if (data.status !== 'success') return;
                const list = data.data.accounts || [];
                setAccounts(list);
                setClientIp(data.data.client_ip || '');
                if (list.length) setAccountId(a => a || list[0].id);
            })
            .catch(() => {});
        try {
            const raw = localStorage.getItem(INTENT_KEY);
            if (raw) {
                localStorage.removeItem(INTENT_KEY);
                setIntent(JSON.parse(raw));
            }
        } catch { /* malformed intent — ignore */ }
    }, []);

    const active = accounts.find(a => a.id === accountId) || accounts[0] || null;

    const openImport = async (accId) => {
        const id = typeof accId === 'number' ? accId : active?.id;
        setShowImport(true);
        setImp({ loading: true, domains: [], selected: {}, importing: false, progress: '', message: '', ip: '' });
        try {
            const { data } = await cachedPost('dynadot_domains', id ? { account_id: id } : {});
            if (data.status !== 'success') {
                setImp(s => ({ ...s, loading: false, message: data.message || t('common.error'), ip: data.detail?.ip || '' }));
                return;
            }
            const have = new Set((domains || []).map(d => String(d.name).toLowerCase()));
            const list = data.data.domains || [];
            const selected = {};
            list.filter(d => !have.has(d)).forEach(d => { selected[d] = true; });
            setImp({ loading: false, domains: list, selected, importing: false, progress: '', message: '', ip: '' });
        } catch (e) {
            setImp(s => ({ ...s, loading: false, message: e?.message ? String(e.message) : t('common.networkError') }));
        }
    };

    // Deep-link from the Integrations account card, once the page has its list.
    useEffect(() => {
        if (!intent || pageLoading || !accounts.length) return;
        const target = accounts.find(a => a.id === intent.account_id);
        setIntent(null);
        if (!target) return;
        setAccountId(target.id);
        if (intent.mode === 'import') {
            openImport(target.id);
        } else {
            setShowRegister(true); setRegResult(null); setRegMessage('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [intent, pageLoading, accounts]);

    if (!accounts.length) return null;

    const priceOk = Number.isFinite(Number(regResult?.price)) && Number(regResult?.price) > 0
        && /^[A-Z]{3}$/.test(regResult?.currency || '');
    const priceLabel = priceOk ? `${regResult.currency} ${Number(regResult.price).toFixed(2)}` : '';
    const currentResult = regResult && regResult.account_id === active?.id && regResult.domain === regDomain.trim().toLowerCase();

    const checkDomain = async () => {
        const domain = regDomain.trim().toLowerCase();
        if (!domain || regChecking || regBuying) return;
        setRegChecking(true); setRegResult(null); setRegMessage('');
        try {
            const { data } = await cachedPost('dynadot_check_domain', { domain, account_id: active?.id });
            if (data.status === 'success') setRegResult({ ...data.data, account_id: active?.id });
            else setRegMessage(data.message || t('common.error'));
        } catch (e) {
            setRegMessage(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setRegChecking(false);
        }
    };

    const buy = async () => {
        if (!currentResult || !regResult.available || !priceOk || regBuying) return;
        const domain = regResult.domain;
        if (!window.confirm(`${t('dynadot.buyConfirm')}: ${domain} (${priceLabel})?\n${t('dynadot.priceEstimate')}`)) return;
        setRegBuying(true); setRegMessage('');
        try {
            const { data } = await cachedPost('dynadot_register_domain', { domain, account_id: active?.id, premium: !!regResult.is_premium });
            if (data.status === 'success') {
                setShowRegister(false); setRegDomain(''); setRegResult(null);
                onChanged && onChanged();
                const lines = [`${t('dynadot.registeredOk')}: ${data.data.domain}`];
                if (data.data.dynadot) lines.push(`✓ ${t('dynadot.parkedOk')}: ${data.data.dynadot}`);
                if (data.data.dynadot_error) lines.push(`⚠ ${t('dynadot.parkFailed')}: ${data.data.dynadot_error}`);
                lines.push(t('domains.sslQueued'));
                alert(lines.join('\n'));
            } else if (data.message === 'dynadot_domain_taken') {
                setRegMessage(`${domain} — ${t('dynadot.taken')}`);
            } else {
                setRegMessage(data.message || t('common.error'));
            }
        } catch (e) {
            setRegMessage(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setRegBuying(false);
        }
    };

    const importSelected = async () => {
        const names = Object.keys(imp.selected).filter(k => imp.selected[k]);
        if (!names.length || imp.importing) return;
        setImp(s => ({ ...s, importing: true, message: '' }));
        const added = [];
        const parked = [];
        const warnings = [];
        try {
            for (let i = 0; i < names.length; i += IMPORT_BATCH) {
                const batch = names.slice(i, i + IMPORT_BATCH);
                setImp(s => ({ ...s, progress: `${Math.min(i + batch.length, names.length)} / ${names.length}` }));
                const { data } = await cachedPost('save_domain', {
                    name: batch.join(', '),
                    dns_provider: 'dynadot',
                    dns_account_id: active?.id,
                });
                if (data.status !== 'success') {
                    warnings.push(data.message || t('common.error'));
                    continue;
                }
                (data.domains || []).forEach(d => {
                    added.push(d.name);
                    if (d.dynadot) parked.push(`${d.name}: ${d.dynadot}`);
                });
                (data.warnings || []).forEach(w => warnings.push(w));
            }
            setShowImport(false);
            onChanged && onChanged();
            const lines = [`${t('dynadot.importedCount')}: ${added.length}`];
            if (parked.length) lines.push('', `✓ ${t('dynadot.parkedOk')}:`, ...parked);
            if (warnings.length) lines.push('', '⚠', ...warnings);
            alert(lines.join('\n'));
        } catch (e) {
            setImp(s => ({ ...s, importing: false, progress: '', message: e?.message ? String(e.message) : t('common.networkError') }));
        }
    };

    const accountSelect = (onPick, disabled) => accounts.length > 1 && (
        <div>
            <label style={{ fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' }}>
                {t('dynadot.chooseAccount')}
            </label>
            <select className="form-select" style={{ width: '100%' }} value={active?.id ?? ''} disabled={disabled}
                onChange={e => onPick(Number(e.target.value))}>
                {accounts.map(a => <option key={a.id} value={a.id}>{a.name} ({a.last_balance || '—'})</option>)}
            </select>
        </div>
    );

    return (
        <>
            <button
                onClick={() => { setShowRegister(true); setRegResult(null); setRegMessage(''); }}
                className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                title={t('dynadot.registerHint')}
            >
                <ShoppingCart size={16} /> {t('dynadot.registerBtn')}
            </button>
            <button
                onClick={() => openImport()}
                className="btn btn-secondary flex items-center gap-2 whitespace-nowrap"
                title={t('dynadot.importHint')}
            >
                <Download size={16} /> {t('dynadot.importBtn')}
            </button>

            {showRegister && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2"><ShoppingCart size={18} /> {t('dynadot.registerTitle')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setShowRegister(false)}><X size={20} /></button>
                        </div>
                        <div className="space-y-4">
                            <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{t('dynadot.registerHintLong')}</p>
                            {accountSelect(id => { setAccountId(id); setRegResult(null); setRegMessage(''); }, regChecking || regBuying)}
                            <div className="flex gap-2">
                                <input
                                    type="text" className="form-input" style={{ flex: 1 }} placeholder="my-new-domain.com"
                                    value={regDomain} disabled={regChecking || regBuying}
                                    onChange={e => { setRegDomain(e.target.value.toLowerCase()); setRegResult(null); }}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); checkDomain(); } }}
                                />
                                <button type="button" className="btn btn-secondary" disabled={regChecking || regBuying || !regDomain.trim()} onClick={checkDomain}>
                                    {regChecking ? t('domains.checkingShort') : t('dynadot.checkBtn')}
                                </button>
                            </div>
                            {currentResult && (
                                <div className="rounded-2xl p-4" style={{ border: '1px solid var(--color-border)', background: 'var(--color-bg-soft)' }}>
                                    {regResult.available ? (
                                        <>
                                            <div className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                                <Check size={16} className="text-green-500" />
                                                <span className="font-medium">{regResult.domain}</span>
                                                <span className="text-xs">— {t('dynadot.available')}</span>
                                            </div>
                                            {priceOk ? (
                                                <div className="text-sm mt-1" style={{ color: 'var(--color-text-primary)' }}>
                                                    {regResult.is_premium ? t('dynadot.premium') : t('dynadot.price')}: <span className="font-semibold">{priceLabel}</span>
                                                    <p className="text-xs mt-1" style={{ color: 'var(--color-text-secondary)' }}>{t('dynadot.priceEstimate')}</p>
                                                </div>
                                            ) : (
                                                <p className="text-sm mt-1" role="status">{t('dynadot.priceUnavailable')}</p>
                                            )}
                                            <button type="button" className="btn btn-primary mt-3 w-full" disabled={regBuying || regChecking || !priceOk} onClick={buy}>
                                                <ShoppingCart size={16} /> {regBuying ? t('dynadot.buying') : t('dynadot.buyPark')}
                                            </button>
                                        </>
                                    ) : (
                                        <div className="flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                                            <X size={16} className="text-red-500" />
                                            <span className="font-medium">{regResult.domain}</span>
                                            <span className="text-xs">— {t('dynadot.taken')}</span>
                                        </div>
                                    )}
                                </div>
                            )}
                            {regMessage && <div className="alert alert-danger flex items-center gap-2"><AlertCircle size={16} />{regMessage}</div>}
                        </div>
                    </div>
                </div>
            )}

            {showImport && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-lg" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2"><Download size={18} /> {t('dynadot.importTitle')}</h3>
                            <button type="button" className="btn btn-ghost btn-icon" disabled={imp.importing} onClick={() => setShowImport(false)}><X size={20} /></button>
                        </div>
                        <div className="space-y-4">
                            <p className="text-xs" style={{ color: 'var(--color-text-secondary)' }}>{t('dynadot.importHintLong')}</p>
                            {accountSelect(id => { setAccountId(id); openImport(id); }, imp.loading || imp.importing)}
                            {imp.loading ? (
                                <div className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>
                                    <RefreshCw size={20} className="animate-spin mx-auto mb-2" />{t('common.loading')}
                                </div>
                            ) : (
                                <>
                                    <div className="overflow-y-auto rounded-2xl" style={{ maxHeight: '320px', border: '1px solid var(--color-border)' }}>
                                        {imp.domains.map(d => {
                                            const inTracker = !Object.prototype.hasOwnProperty.call(imp.selected, d);
                                            const isSel = !!imp.selected[d];
                                            return (
                                                <label key={d} className="flex items-center gap-3 px-4 py-2 cursor-pointer" style={{ borderBottom: '1px solid var(--color-border)' }}>
                                                    <input type="checkbox" checked={isSel} disabled={imp.importing}
                                                        onChange={e => setImp(s => ({ ...s, selected: { ...s.selected, [d]: e.target.checked } }))} />
                                                    <span className="font-mono text-sm" style={{ color: isSel ? 'var(--color-text-primary)' : 'var(--color-text-secondary)' }}>{d}</span>
                                                    {inTracker && <span className="badge badge-success ml-auto text-xs"><Check size={12} /> {t('dynadot.inTracker')}</span>}
                                                </label>
                                            );
                                        })}
                                        {!imp.domains.length && !imp.message && (
                                            <div className="text-center py-8" style={{ color: 'var(--color-text-muted)' }}>{t('dynadot.noDomains')}</div>
                                        )}
                                    </div>
                                    {imp.message && (
                                        <div className="alert alert-danger flex items-start gap-2">
                                            <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
                                            <div className="flex-1">
                                                <div>{t('dynadot.errConnection')}: {imp.message}</div>
                                                {(imp.ip || clientIp) && (
                                                    <div className="mt-2 flex items-center gap-2 text-xs">
                                                        <span style={{ color: 'var(--color-text-secondary)' }}>{t('dynadot.whitelistIp')}:</span>
                                                        <code className="px-2 py-0.5 rounded font-mono" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-primary)' }}>{imp.ip || clientIp}</code>
                                                        <button type="button" title={t('common.copy')} style={{ color: 'var(--color-text-secondary)' }}
                                                            onClick={async () => { if (await copyToClipboard(imp.ip || clientIp)) { setCopied(true); setTimeout(() => setCopied(false), 2000); } }}>
                                                            {copied ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                    <div className="modal-footer">
                                        <button type="button" className="btn btn-secondary" disabled={imp.importing} onClick={() => setShowImport(false)}>{t('common.cancel')}</button>
                                        <button type="button" className="btn btn-primary"
                                            disabled={imp.importing || !Object.values(imp.selected).some(Boolean)} onClick={importSelected}>
                                            <Download size={16} />
                                            {imp.importing
                                                ? `${t('dynadot.importing')} ${imp.progress}`
                                                : `${t('dynadot.importBtn2')} (${Object.values(imp.selected).filter(Boolean).length})`}
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
};

export default DynadotDomainTools;
