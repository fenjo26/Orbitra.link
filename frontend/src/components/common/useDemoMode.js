import { useState, useCallback } from 'react';

// Demo state for a page: `active` swaps the data source, `tour` shows the
// coach marks. Starting the demo always starts the tour; the tour waits one
// frame so the page has rendered its example data (and its data-tour
// targets) before the first step looks for them.
export const useDemoMode = () => {
    const [active, setActive] = useState(false);
    const [tour, setTour] = useState(false);
    const start = useCallback(() => {
        setActive(true);
        setTour(false);
        requestAnimationFrame(() => requestAnimationFrame(() => setTour(true)));
    }, []);
    const replay = useCallback(() => {
        setTour(false);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        requestAnimationFrame(() => setTour(true));
    }, []);
    const exit = useCallback(() => { setTour(false); setActive(false); }, []);
    const closeTour = useCallback(() => setTour(false), []);
    return { active, tour, start, replay, exit, closeTour };
};
