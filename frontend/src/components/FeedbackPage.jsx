import React, { useState } from 'react';
import { useLanguage } from '../contexts/LanguageContext';
import { MessageSquare, Copy, Check, Heart, Mail, MessageCircle } from 'lucide-react';

const FeedbackPage = () => {
    const { t } = useLanguage();
    const [copied, setCopied] = useState(false);

    const cryptoAddress = 'TN7v2NArTXd5J2eMuGFpXmgzAFsoZpWcZu';

    const handleCopy = () => {
        navigator.clipboard.writeText(cryptoAddress);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="relative">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {/* Left Column: Direct Contact Info */}
                <div className="p-6 md:p-8 rounded-[24px] shadow-sm border border-[var(--color-border)] relative overflow-hidden flex flex-col" style={{ background: 'var(--color-bg-card)', backdropFilter: 'blur(10px)' }}>
                    <div className="mb-6 z-10">
                        <h2 className="text-2xl font-bold mb-2 flex items-center gap-2" style={{ color: 'var(--color-text-primary)' }}>
                            <MessageSquare className="text-orange-500" />
                            {t('feedback.formTitle') || 'Contact Us'}
                        </h2>
                        <p style={{ color: 'var(--color-text-secondary)' }}>
                            {t('feedback.formDesc') || 'Have a question, suggestion, or found a bug? Check out these contact options to reach the developer directly.'}
                        </p>
                    </div>

                    <div className="space-y-4 z-10 mb-8 flex-grow">
                        {/* Telegram Button */}
                        <a
                            href="https://t.me/fenjo26"
                            target="_blank"
                            rel="noreferrer"
                            className="flex items-center justify-between p-4 rounded-[16px] transition-all transform hover:-translate-y-1 hover:shadow-lg border"
                            style={{
                                background: 'linear-gradient(135deg, #0088cc 0%, #00aaff 100%)',
                                borderColor: 'rgba(0,136,204,0.3)'
                            }}
                        >
                            <div className="flex items-center gap-4 text-white">
                                <div className="p-2 bg-white/20 rounded-xl">
                                    <MessageCircle size={24} />
                                </div>
                                <div>
                                    <p className="font-semibold text-lg">Telegram</p>
                                    <p className="text-sm text-blue-100 font-medium">@fenjo26</p>
                                </div>
                            </div>
                            <div className="text-white opacity-80">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M5 12h14M12 5l7 7-7 7" />
                                </svg>
                            </div>
                        </a>

                        {/* Email Button */}
                        <a
                            href="mailto:info@orbitra.link"
                            className="flex items-center justify-between p-4 rounded-[16px] transition-all transform hover:-translate-y-1 hover:shadow-lg border"
                            style={{
                                background: 'var(--color-bg-soft)',
                                borderColor: 'var(--color-border)'
                            }}
                        >
                            <div className="flex items-center gap-4">
                                <div className="p-2 rounded-xl text-orange-500 bg-orange-500/10">
                                    <Mail size={24} />
                                </div>
                                <div>
                                    <p className="font-semibold text-lg" style={{ color: 'var(--color-text-primary)' }}>Email</p>
                                    <p className="text-sm font-medium" style={{ color: 'var(--color-text-secondary)' }}>info@orbitra.link</p>
                                </div>
                            </div>
                            <div className="opacity-50" style={{ color: 'var(--color-text-primary)' }}>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M5 12h14M12 5l7 7-7 7" />
                                </svg>
                            </div>
                        </a>
                    </div>

                    {/* Decorative background circle */}
                    <div className="absolute -bottom-24 -left-24 w-64 h-64 bg-orange-500 rounded-full mix-blend-multiply filter blur-3xl opacity-10 pointer-events-none"></div>
                </div>

                {/* Right Column: Appreciation & Crypto */}
                <div className="flex flex-col gap-6">

                    {/* Appreciation Card */}
                    <div className="p-6 md:p-8 rounded-[24px] shadow-sm border border-[var(--color-border)] relative overflow-hidden" style={{ background: 'var(--color-bg-card)', backdropFilter: 'blur(10px)' }}>
                        <div className="absolute top-6 right-6 text-orange-100 opacity-20 transform rotate-12">
                            <Heart size={80} fill="currentColor" />
                        </div>

                        <h2 className="text-2xl font-bold mb-4 relative z-10" style={{ color: 'var(--color-text-primary)' }}>
                            {t('feedback.appreciationTitle') || 'Thanks for choosing Orbitra.link!'}
                        </h2>

                        <p className="text-[15px] leading-relaxed relative z-10 mb-6" style={{ color: 'var(--color-text-secondary)' }}>
                            {t('feedback.appreciationText') || "I'm developing this tracker as a lightweight, independent alternative for the community. Your support helps me keep the project alive and add new features."}
                        </p>

                        {/* Decorative logo/favicon instead of SVG pi */}
                        <div className="absolute -bottom-10 -right-10 opacity-10 pointer-events-none w-[150px] h-[150px] flex items-center justify-center">
                            <img src="/favicon.svg" alt="" className="w-full h-full object-contain grayscale" />
                        </div>
                    </div>

                    {/* Crypto Donation Card */}
                    <div className="p-6 md:p-8 rounded-[24px] shadow-sm border border-[var(--color-border)] relative overflow-hidden flex-grow flex flex-col justify-center" style={{ background: 'var(--color-bg-card)', backdropFilter: 'blur(10px)' }}>
                        <div className="flex items-center gap-4 mb-5">
                            <div className="w-12 h-12 rounded-full flex justify-center items-center shadow-inner" style={{ background: '#26A17B', color: 'white' }}>
                                {/* USDT Tether Icon */}
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12.029 17.56c-4.4 0-8.084-1.12-8.084-2.5 0-1.38 3.684-2.5 8.084-2.5 4.4 0 8.084 1.12 8.084 2.5 0 1.38-3.684 2.5-8.084 2.5zM12.029 4.316v18.156c-4.4 0-8.084-1.12-8.084-2.5v-13.156c0-1.38 3.684-2.5 8.084-2.5 4.4 0 8.084 1.12 8.084 2.5v13.156c0 1.38-3.684 2.5-8.084 2.5V4.316C21.6 3.606 24 6.7 24 8.78c0 2.08-2.4 5.174-11.971 4.464C2.4 13.954 0 10.86 0 8.78c0-2.08 2.4-5.174 11.971-4.464h.058z" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-xl font-bold" style={{ color: 'var(--color-text-primary)' }}>
                                    {t('feedback.cryptoTitle') || 'Support the Project'}
                                </h3>
                                <p className="text-sm font-medium" style={{ color: '#26A17B' }}>
                                    Donate via Crypto (USDT TRC20)
                                </p>
                            </div>
                        </div>

                        <div className="mt-2 relative group">
                            <div className="absolute -inset-0.5 rounded-xl border border-transparent bg-gradient-to-r from-[#26A17B] to-blue-500 opacity-20 group-hover:opacity-40 transition duration-500 blur-sm pointer-events-none"></div>
                            <div className="relative flex items-center justify-between p-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-soft)] shadow-sm">
                                <div className="overflow-hidden pr-4">
                                    <p className="text-[10px] uppercase font-bold tracking-wider mb-1" style={{ color: 'var(--color-text-muted)' }}>Network: TRC20</p>
                                    <p className="font-mono text-sm md:text-base truncate select-all" style={{ color: 'var(--color-text-primary)' }}>
                                        {cryptoAddress}
                                    </p>
                                </div>
                                <button
                                    onClick={handleCopy}
                                    className={`flex-shrink-0 h-10 px-4 rounded-lg flex items-center gap-2 font-medium text-sm transition-all ${copied ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'}`}
                                    style={!copied ? { background: 'var(--color-bg-main)', borderColor: 'var(--color-border)', color: 'var(--color-text-primary)' } : {}}
                                >
                                    {copied ? (
                                        <>
                                            <Check size={16} />
                                            <span>{t('feedback.copied') || 'Copied!'}</span>
                                        </>
                                    ) : (
                                        <>
                                            <Copy size={16} />
                                            <span>{t('feedback.copy') || 'Copy'}</span>
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    );
};

export default FeedbackPage;
