/** Shared popup constants (PopupForm, CampaignPopupTab, PopupSettings). */

export const POPUP_VERTICALS = ['', 'gambling', 'betting', 'nutra', 'ecom', 'finance', 'crypto'];

export const POPUP_DEFAULTS = {
    popup_vertical: '',
    popup_delay: '12',
    popup_require_activity: '1',
    popup_frequency_hours: '24',
    popup_scroll_depth: '0',
    popup_exit_intent: '0',
    popup_designs: 'card,gift,alert',
    popup_image: '',
    popup_amount: '',
    popup_languages: '',
    popup_animation: 'zoom',
    popup_position: 'center',
    popup_accent: '',
    popup_lang_mode: 'auto',
    popup_lang: 'en',
    popup_custom_texts: '',
};

export const presetImage = (vertical) => `/assets/popup-presets/${vertical || 'neutral'}.webp`;
