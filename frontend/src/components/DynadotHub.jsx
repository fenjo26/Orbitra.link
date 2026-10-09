import React, { useState, useEffect, useCallback } from 'react';
import { Globe, User, Plus, ShoppingCart, Download, RefreshCw, Edit2, Trash2, X, Check, Copy, AlertCircle } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';
import { cachedGet, cachedPost } from '../utils/apiCache';
import { copyToClipboard } from '../utils/clipboard';

// Dynadot account hub for the Integrations page — the counterpart of the
// Namecheap hub: one card per API key with its balance, buttons that jump to
// the Domains dialogs (Buy / Import), and an add/edit modal whose single
// action verifies the key live before it is stored.
const DynadotHub = ({ onAccountsChange }) => {
    const { t } = useLanguage();
    const [accounts, setAccounts] = useState([]);
    const [clientIp, setClientIp] = useState('');
    const [modal, setModal] = useState(null); // {id?, name, api_key, sandbox}
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [balanceBusy, setBalanceBusy] = useState(null);
    const [copied, setCopied] = useState(false);

    const publish = useCallback((list) => {
        setAccounts(list);
        onAccountsChange && onAccountsChange(list);
    }, [onAccountsChange]);

    const load = useCallback(() => {
        cachedGet('dynadot_accounts_list', { _: Date.now() }, 0)
            .then(({ data }) => {
                if (data.status !== 'success') return;
                publish(data.data.accounts || []);
                setClientIp(data.data.client_ip || '');
            })
            .catch(() => {});
    }, [publish]);

    useEffect(() => { load(); }, [load]);

    const save = async () => {
        if (!modal.id && !modal.api_key.trim()) {
            setMessage(t('dynadot.errKeyRequired'));
            return;
        }
        setBusy(true); setMessage('');
        try {
            const { data } = await cachedPost('dynadot_account_save', {
                id: modal.id || undefined,
                name: modal.name.trim(),
                api_key: modal.api_key.trim(),
                sandbox: !!modal.sandbox,
            });
            if (data.status === 'success') {
                setModal(null);
                load();
            } else if (data.message === 'dynadot_connection_failed') {
                setMessage(`${t('dynadot.errConnection')}: ${data.detail?.error || ''}`);
            } else if (data.message === 'dynadot_key_required') {
                setMessage(t('dynadot.errKeyRequired'));
            } else {
                setMessage(data.message || t('common.error'));
            }
        } catch (e) {
            setMessage(e?.message ? String(e.message) : t('common.networkError'));
        } finally {
            setBusy(false);
        }
    };

    const remove = async (acc) => {
        if (!window.confirm(`${t('dynadot.deleteConfirm')} «${acc.name}»?`)) return;
        try {
            const { data } = await cachedPost('dynadot_account_delete', { id: acc.id });
            if (data.status === 'success') load();
        } catch { /* the list simply stays as it was */ }
    };

    const refreshBalance = async (acc) => {
        setBalanceBusy(acc.id);
        try {
            const { data } = await cachedPost('dynadot_account_balance', { account_id: acc.id });
            if (data.status === 'success') {
                publish(accounts.map(a => (a.id === acc.id ? { ...a, last_balance: data.data.balance || a.last_balance } : a)));
            }
        } catch { /* keep the stored snapshot */ }
        finally { setBalanceBusy(null); }
    };

    // Buy & Import live in the Domains dialogs — jump there with this account
    // preselected (DynadotDomainTools reads the intent on mount).
    const openDialog = (accountId, mode) => {
        try { localStorage.setItem('orbitra_dd_intent', JSON.stringify({ account_id: accountId, mode })); } catch { /* storage blocked */ }
        window.dispatchEvent(new CustomEvent('orbitra:navigate', { detail: { tab: 'domains' } }));
    };

    const label = { fontSize: '13px', fontWeight: 500, color: 'var(--color-text-secondary)', display: 'block', marginBottom: '6px' };
    const card = { background: 'var(--color-bg-card)', borderRadius: '12px', padding: '20px 24px', border: '1px solid var(--color-border)' };

    return (
        <div style={{ padding: '24px', flex: 1, overflow: 'auto' }}>
            <div style={{ maxWidth: '680px', display: 'flex', flexDirection: 'column', gap: '20px' }}>
                <div style={{ ...card, padding: '24px' }}>
                    <p className="text-xs" style={{ color: 'var(--color-text-secondary)', marginBottom: '12px', lineHeight: 1.6 }}>
                        {t('dynadot.howTo')}
                    </p>
                    <p className="text-xs" style={{ color: 'var(--color-text-secondary)', marginBottom: clientIp ? '12px' : 0, lineHeight: 1.6 }}>
                        {t('dynadot.dnsSafety')}
                    </p>
                    {clientIp && (
                        <div className="flex items-center gap-2 text-xs">
                            <span style={{ color: 'var(--color-text-secondary)' }}>{t('dynadot.whitelistIp')}:</span>
                            <code className="px-2 py-0.5 rounded font-mono" style={{ background: 'var(--color-bg-soft)', color: 'var(--color-primary)' }}>{clientIp}</code>
                            <button type="button" title={t('common.copy')} style={{ color: 'var(--color-text-secondary)' }}
                                onClick={async () => { if (await copyToClipboard(clientIp)) { setCopied(true); setTimeout(() => setCopied(false), 2000); } }}>
                                {copied ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
                            </button>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between">
                    <span style={{ fontSize: '15px', fontWeight: 600, color: 'var(--color-text-primary)' }}>{t('dynadot.accounts')}</span>
                    <button className="btn btn-primary text-xs py-1.5 px-3 rounded-xl font-medium"
                        onClick={() => { setMessage(''); setModal({ name: '', api_key: '', sandbox: false }); }}>
                        <Plus className="w-4 h-4" /> {t('dynadot.addAccount')}
                    </button>
                </div>

                {!accounts.length && (
                    <div style={{ ...card, textAlign: 'center', color: 'var(--color-text-muted)', fontSize: '13px' }}>
                        {t('dynadot.noAccounts')}
                    </div>
                )}

                {accounts.map(acc => (
                    <div key={acc.id} style={card}>
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-3 min-w-0">
                                <div className="flex items-center justify-center" style={{ width: '38px', height: '38px', borderRadius: '10px', background: 'var(--color-bg-soft)', flexShrink: 0 }}>
                                    <User className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                                </div>
                                <div className="min-w-0 flex items-center gap-2 flex-wrap">
                                    <span style={{ fontSize: '15px', fontWeight: 600, color: 'var(--color-text-primary)' }}>{acc.name}</span>
                                    {acc.sandbox && <span className="badge badge-warning text-xs">Sandbox</span>}
                                </div>
                            </div>
                            <div className="text-right" style={{ flexShrink: 0 }}>
                                <div className="text-xs" style={{ color: 'var(--color-text-secondary)', marginBottom: '2px' }}>{t('dynadot.balance')}</div>
                                <div className="font-mono" style={{ fontSize: '17px', fontWeight: 600, color: acc.last_balance ? 'var(--color-text-primary)' : 'var(--color-text-muted)' }}>
                                    {acc.last_balance || '—'}
                                </div>
                            </div>
                        </div>
                        <div className="text-xs" style={{ color: 'var(--color-text-muted)', margin: '10px 0 14px' }}>
                            {t('dynadot.domainsInAccount')}: {acc.domains_count ?? '—'}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium" onClick={() => openDialog(acc.id, 'register')}>
                                <ShoppingCart className="w-4 h-4" /> {t('dynadot.buyDomain')}
                            </button>
                            <button className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium" onClick={() => openDialog(acc.id, 'import')}>
                                <Download className="w-4 h-4" /> {t('dynadot.importPark')}
                            </button>
                            <button className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium" disabled={balanceBusy === acc.id} onClick={() => refreshBalance(acc)}>
                                <RefreshCw className={'w-4 h-4' + (balanceBusy === acc.id ? ' animate-spin' : '')} /> {t('dynadot.refreshBalance')}
                            </button>
                            <button className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium"
                                onClick={() => { setMessage(''); setModal({ id: acc.id, name: acc.name, api_key: '', sandbox: !!acc.sandbox }); }}>
                                <Edit2 className="w-4 h-4" /> {t('common.edit')}
                            </button>
                            <button className="btn btn-secondary text-xs py-1.5 px-3 rounded-xl font-medium" onClick={() => remove(acc)}>
                                <Trash2 className="w-4 h-4" /> {t('common.delete')}
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            {modal && (
                <div className="modal-overlay">
                    <div className="modal-content w-full max-w-md" style={{ padding: '24px' }}>
                        <div className="modal-header">
                            <h3 className="modal-title flex items-center gap-2">
                                <Globe size={18} /> {modal.id ? t('dynadot.editAccount') : t('dynadot.addAccount')}
                            </h3>
                            <button type="button" className="btn btn-ghost btn-icon" onClick={() => setModal(null)}><X size={20} /></button>
                        </div>
                        <div className="space-y-4">
                            <div>
                                <label style={label}>{t('dynadot.accountLabel')}</label>
                                <input type="text" className="form-input" style={{ width: '100%' }} placeholder={t('dynadot.accountLabelPlaceholder')}
                                    value={modal.name} onChange={e => setModal({ ...modal, name: e.target.value })} />
                            </div>
                            <div>
                                <label style={label}>API Key</label>
                                <input type="password" className="form-input" style={{ width: '100%' }} autoComplete="off"
                                    placeholder={modal.id ? t('dynadot.keySaved') : ''}
                                    value={modal.api_key} onChange={e => setModal({ ...modal, api_key: e.target.value })} />
                                <p className="text-xs mt-1" style={{ color: 'var(--color-text-muted)' }}>{t('dynadot.keyWhere')}</p>
                            </div>
                            <div>
                                <label style={label}>{t('dynadot.environment')}</label>
                                <select className="form-select" style={{ width: '100%' }} value={modal.sandbox ? '1' : '0'}
                                    onChange={e => setModal({ ...modal, sandbox: e.target.value === '1' })}>
                                    <option value="0">{t('dynadot.production')}</option>
                                    <option value="1">{t('dynadot.sandbox')}</option>
                                </select>
                            </div>
                            {message && (
                                <div className="alert alert-danger flex items-start gap-2">
                                    <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
                                    <div className="flex-1">
                                        <div>{message}</div>
                                        {clientIp && <div className="text-xs mt-1">{t('dynadot.whitelistIp')}: <code className="font-mono">{clientIp}</code></div>}
                                    </div>
                                </div>
                            )}
                            <div className="modal-footer">
                                <button type="button" className="btn btn-secondary" onClick={() => setModal(null)}>{t('common.cancel')}</button>
                                <button type="button" className="btn btn-primary" disabled={busy} onClick={save}>
                                    {busy ? t('common.saving') : t('dynadot.testAndSave')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DynadotHub;
