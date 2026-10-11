import { useEffect, useState } from 'react';

// First day of week from the user's profile (0 = Sunday, 1 = Monday).
// It rides on the stored user (login response, refreshed on profile save) the
// same way the profile timezone does. Missing → Monday, the users-table default.
export const getFirstDayOfWeek = () => {
    try {
        const user = JSON.parse(localStorage.getItem('orbitra_user') || 'null');
        const v = Number(user?.first_day_of_week);
        return v === 0 ? 0 : 1;
    } catch {
        return 1;
    }
};

/** Re-reads on 'userUpdated' (profile save) and cross-tab storage changes. */
export function useFirstDayOfWeek() {
    const [value, setValue] = useState(getFirstDayOfWeek);
    useEffect(() => {
        const sync = () => setValue(getFirstDayOfWeek());
        const onStorage = (e) => { if (e.key === 'orbitra_user') sync(); };
        window.addEventListener('userUpdated', sync);
        window.addEventListener('storage', onStorage);
        return () => {
            window.removeEventListener('userUpdated', sync);
            window.removeEventListener('storage', onStorage);
        };
    }, []);
    return value;
}
