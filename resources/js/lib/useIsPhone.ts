import { useSyncExternalStore } from 'react';

const QUERY = '(max-width: 767.98px)';

function subscribe(onChange: () => void): () => void {
    if (typeof window.matchMedia !== 'function') return () => {};
    const mql = window.matchMedia(QUERY);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
}

function getSnapshot(): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia(QUERY).matches;
}

/** True below the 768 px breakpoint, where the phone layout applies. */
export function useIsPhone(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, () => false);
}
