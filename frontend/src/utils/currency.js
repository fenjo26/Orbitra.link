/**
 * Currency symbol lookup for the panel.
 *
 * The panel stores only an ISO-4217 code in settings.currency (e.g. "RUB")
 * while the UI historically hard-coded "$" everywhere. This module maps the
 * code to the symbol the operator actually means, and is the single place
 * that decides the symbol — replace a hard-coded "$" in a component with
 * currencySymbol() so the amount follows the configured currency.
 *
 * Mirrors the code→symbol map in core/PopupConfig.php (orbitraPopupCurrencyForCountry),
 * keyed by ISO code instead of country.
 */

const CURRENCY_SYMBOLS = {
    USD: '$', CAD: 'C$', GBP: '£', EUR: '€',
    PLN: 'zł', CZK: 'Kč', HUF: 'Ft', RON: 'lei', RSD: 'дин',
    UAH: '₴', TRY: '₺', RUB: '₽', KZT: '₸', UZS: 'сум',
    AZN: '₼', AMD: '֏', GEL: '₾', ILS: '₪', AED: 'د.إ',
    SAR: 'ر.س', QAR: 'ر.ق', KWD: 'د.ك', EGP: 'ج.م', MAD: 'د.م.',
    DZD: 'د.ج', TND: 'د.ت', ZAR: 'R', NGN: '₦', KES: 'KSh',
    GHS: '₵', BRL: 'R$', MXN: 'MX$', ARS: 'AR$', CLP: 'CL$',
    COP: 'COL$', PEN: 'S/', VES: 'Bs', AUD: 'A$', NZD: 'NZ$',
    JPY: '¥', KRW: '₩', CNY: '¥', HKD: 'HK$', TWD: 'NT$',
    SGD: 'S$', MYR: 'RM', THB: '฿', VND: '₫', IDR: 'Rp',
    PHP: '₱', INR: '₹', PKR: 'Rs', BDT: '৳', LKR: 'Rs',
    NPR: 'रू', MMK: 'K', KHR: '៛', LAK: '₭', MNT: '₮',
    SEK: 'kr', NOK: 'kr', DKK: 'kr', ISK: 'kr', CHF: 'CHF',
};

// Default when the configured code is not in the map: keep "$" — it is the
// de-facto fallback for unknown codes and matches the old hard-coded output.
const DEFAULT_SYMBOL = '$';

// The symbol the panel currently shows. Set from the global_settings.currency
// code once per session (see App.jsx). Defaults to USD so nothing renders
// empty before settings load.
let currentSymbol = '$';

/** Make the whole panel format amounts in the given ISO-4217 code. */
export const setCurrencySymbol = (isoCode) => {
    const code = String(isoCode || '').toUpperCase();
    currentSymbol = CURRENCY_SYMBOLS[code] || DEFAULT_SYMBOL;
};

/** The currency symbol for the configured currency (default '$'). */
export const currencySymbol = () => currentSymbol;

/** "1234.56" → "1 234,56" done the panel's way: sign, then symbol. */
export const formatMoney = (value, symbol = currentSymbol) => {
    const num = Number(value) || 0;
    const abs = Math.abs(num);
    const body = abs >= 100000
        ? new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(abs)
        : new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(abs);
    return `${num < 0 ? '-' : ''}${symbol}${body}`;
};