import React, { useState } from 'react';
import { Sparkles, Copy, Check, Key, Terminal, Cpu, Link2 } from 'lucide-react';
import { useLanguage } from '../contexts/LanguageContext';

const McpPage = () => {
    const { t } = useLanguage();
    const [copied, setCopied] = useState(false);

    const origin = (typeof window !== 'undefined' && window.location && window.location.origin)
        ? window.location.origin
        : 'https://tracker.example.com';

    const config = JSON.stringify({
        mcpServers: {
            orbitra: {
                // An absolute path, not bare "node": the desktop app is not launched
                // from a shell, so a Node installed via nvm or Homebrew is not on its
                // PATH and the server fails to start with no useful error.
                command: '/usr/local/bin/node',
                args: ['/absolute/path/to/Orbitra/mcp/src/index.js'],
                env: {
                    ORBITRA_URL: origin,
                    ORBITRA_API_KEY: '<your-api-key>'
                }
            }
        }
    }, null, 2);

    const copyConfig = () => {
        navigator.clipboard.writeText(config);
        setCopied(true);
        setTimeout(() => setCopied(false), 1800);
    };

    const cardStyle = {
        background: 'var(--color-bg-soft)',
        borderRadius: '16px',
        padding: '18px',
        marginBottom: '16px'
    };
    const stepBadge = {
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
        width: '26px', height: '26px', borderRadius: '50%',
        background: 'var(--color-accent, #2563eb)', color: '#fff',
        fontSize: '13px', fontWeight: 700, marginRight: '10px', flexShrink: 0
    };
    const codeBlock = {
        background: 'var(--color-bg-card)', border: '1px solid var(--color-border)',
        borderRadius: '10px', padding: '12px', fontSize: '12.5px',
        overflowX: 'auto', margin: '8px 0 0', lineHeight: 1.5, color: 'var(--color-text)'
    };

    return (
        <div className="space-y-6">
            <div className="page-card">
                <div className="page-header">
                    <div className="flex items-center gap-2">
                        <Sparkles className="w-5 h-5" style={{ color: 'var(--color-text-secondary)' }} />
                        <h2 className="page-title">{t('mcpPage.title', 'AI Assistant (MCP)')}</h2>
                    </div>
                </div>

                <div style={{ background: 'var(--color-info-bg)', borderRadius: '16px', padding: '16px', marginBottom: '24px' }}>
                    <p style={{ fontSize: '14px', color: 'var(--color-info)', margin: 0, lineHeight: 1.55 }}>
                        {t('mcpPage.intro', 'Connect Claude Desktop and other AI assistants to your tracker and manage it in plain language — analyse campaigns, create them in bulk, connect domains, edit offers and more.')}
                    </p>
                </div>

                {/* Route A — the one most people want: paste a URL and be done. */}
                <div style={{ ...cardStyle, border: '1px solid var(--color-accent, #2563eb)' }}>
                    <div style={{ fontWeight: 600, fontSize: '15px', marginBottom: '4px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                        <Link2 size={15} /> {t('mcpPage.remoteTitle', 'Option A — paste a URL (works in the browser and in the desktop app)')}
                    </div>
                    <p style={{ fontSize: '13.5px', color: 'var(--color-text-secondary)', margin: 0, lineHeight: 1.55 }}>
                        {t('mcpPage.remoteDesc', 'Generate an API key in Users → API Keys, press the link button next to it to copy a ready-made URL, then paste that into Claude → Settings → Connectors → Add custom connector. Nothing to install. The key travels in the URL, so treat the URL as the credential and revoke the key to cut access.')}
                    </p>
                    <pre style={codeBlock}><code>{`${origin}/mcp.php?k=<your-api-key>`}</code></pre>
                </div>

                <div style={{ fontSize: '13px', color: 'var(--color-text-muted)', margin: '0 0 16px', lineHeight: 1.6 }}>
                    {t('mcpPage.orLocal', 'Option B — run the server next to your assistant. Only Claude Desktop can do this; it cannot be added through the Connectors dialog, which is why that dialog has no field for an API key.')}
                </div>

                {/* Step 1 — API key */}
                <div style={cardStyle}>
                    <div style={{ display: 'flex', alignItems: 'flex-start' }}>
                        <span style={stepBadge}>1</span>
                        <div style={{ flex: 1 }}>
                            <div style={{ fontWeight: 600, fontSize: '15px', marginBottom: '4px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                                <Key size={15} /> {t('mcpPage.step1Title', 'Create an API key')}
                            </div>
                            <p style={{ fontSize: '13.5px', color: 'var(--color-text-secondary)', margin: 0, lineHeight: 1.55 }}>
                                {t('mcpPage.step1Desc', 'Open Users → API Keys and generate a key. A Read key allows analytics only; a Write key also allows managing campaigns, offers and domains.')}
                            </p>
                            <p style={{ fontSize: '12.5px', color: 'var(--color-text-muted)', margin: '6px 0 0', lineHeight: 1.55 }}>
                                {t('mcpPage.step1Hint', 'Asking the assistant to create or edit anything with a Read key comes back as "403 API key is read-only" — that needs a Write key.')}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Step 2 — install */}
                <div style={cardStyle}>
                    <div style={{ display: 'flex', alignItems: 'flex-start' }}>
                        <span style={stepBadge}>2</span>
                        <div style={{ flex: 1 }}>
                            <div style={{ fontWeight: 600, fontSize: '15px', marginBottom: '4px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                                <Terminal size={15} /> {t('mcpPage.step2Title', 'Install the server')}
                            </div>
                            <p style={{ fontSize: '13.5px', color: 'var(--color-text-secondary)', margin: 0, lineHeight: 1.55 }}>
                                {t('mcpPage.step2Desc', 'On the machine running your AI assistant, install dependencies once inside the tracker folder:')}
                            </p>
                            <pre style={codeBlock}><code>cd mcp{'\n'}npm install</code></pre>
                        </div>
                    </div>
                </div>

                {/* Step 3 — config */}
                <div style={cardStyle}>
                    <div style={{ display: 'flex', alignItems: 'flex-start' }}>
                        <span style={stepBadge}>3</span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <div className="flex items-center justify-between" style={{ marginBottom: '4px', gap: '8px' }}>
                                <div style={{ fontWeight: 600, fontSize: '15px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                                    <Cpu size={15} /> {t('mcpPage.step3Title', 'Add to Claude Desktop')}
                                </div>
                                <button onClick={copyConfig} className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '12px', flexShrink: 0 }}>
                                    {copied ? <Check size={14} /> : <Copy size={14} />}
                                    {copied ? t('mcpPage.copied', 'Copied') : t('mcpPage.copyConfig', 'Copy config')}
                                </button>
                            </div>
                            <p style={{ fontSize: '13.5px', color: 'var(--color-text-secondary)', margin: 0, lineHeight: 1.55 }}>
                                {t('mcpPage.step3Desc', 'Open Settings → Developer → Edit Config in Claude Desktop and paste this in, then replace the path with the absolute path to mcp/src/index.js and ORBITRA_API_KEY with the key from step 1. Quit the app completely and reopen it — closing the window is not enough.')}
                            </p>
                            <pre style={codeBlock}><code>{config}</code></pre>
                            <div style={{ fontSize: '12px', color: 'var(--color-text-muted)', marginTop: '10px', lineHeight: 1.6 }}>
                                <div><strong>macOS:</strong> <code>~/Library/Application Support/Claude/claude_desktop_config.json</code></div>
                                <div><strong>Windows:</strong> <code>%APPDATA%\Claude\claude_desktop_config.json</code></div>
                                <div style={{ marginTop: '8px' }}>
                                    {t('mcpPage.nodePathHint', 'Check the path to Node with')} <code>which node</code> {t('mcpPage.nodePathHint2', '(macOS/Linux) or')} <code>where node</code> {t('mcpPage.nodePathHint3', '(Windows) and put the result in "command". A bare "node" only works if it sits in the system PATH.')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style={{ fontSize: '13px', color: 'var(--color-text-muted)', lineHeight: 1.6 }}>
                    {t('mcpPage.toolsNote', '31 tools are available: analytics (metrics, campaigns, conversions, reports) and management (create / bulk-create / edit / delete campaigns, offers, domains, sources, landings).')}
                    <br />
                    {t('mcpPage.fullGuide', 'Full guide:')} <code>mcp/README.md</code> · <code>docs/mcp.md</code>
                </div>
            </div>
        </div>
    );
};

export default McpPage;
