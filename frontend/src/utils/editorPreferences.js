export const EDITOR_STAY_AFTER_SAVE_KEY = 'orbitra_editor_stay_after_save';

export const getStayInEditorAfterSave = () => {
    if (typeof window === 'undefined') return true;

    try {
        const stored = window.localStorage.getItem(EDITOR_STAY_AFTER_SAVE_KEY);
        return stored === null ? true : stored !== 'false';
    } catch {
        return true;
    }
};

export const setStayInEditorAfterSave = (enabled) => {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(EDITOR_STAY_AFTER_SAVE_KEY, String(Boolean(enabled)));
    } catch {
        // A blocked localStorage should not prevent the profile form from working.
    }
};
