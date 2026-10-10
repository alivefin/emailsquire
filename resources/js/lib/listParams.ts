import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';

/** The `page` URL parameter as a number of at least 1. */
export function pageParam(params: URLSearchParams): number {
    return Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
}

/**
 * A search box bound to a URL parameter. Typing updates the returned value at once and the URL
 * after `delay` ms (replacing the history entry), and a new search goes back to page 1.
 */
export function useDebouncedSearchParam(key = 'q', delay = 300): [string, (value: string) => void] {
    const [params, setParams] = useSearchParams();
    const value = params.get(key) ?? '';
    const [draft, setDraft] = useState(value);

    // The URL changed from outside (back button, Clear search): follow it.
    useEffect(() => setDraft(value), [value]);

    useEffect(() => {
        if (draft === value) return;
        const t = setTimeout(() => {
            setParams(
                (prev) => {
                    const next = new URLSearchParams(prev);
                    if (draft === '') next.delete(key);
                    else next.set(key, draft);
                    next.delete('page');
                    return next;
                },
                { replace: true },
            );
        }, delay);
        return () => clearTimeout(t);
    }, [draft, value, key, delay, setParams]);

    return [draft, setDraft];
}

/** Moves to page `p`, dropping the parameter for page 1. */
export function useGoToPage(): (p: number) => void {
    const [, setParams] = useSearchParams();
    return useCallback(
        (p: number) =>
            setParams((prev) => {
                const next = new URLSearchParams(prev);
                if (p <= 1) next.delete('page');
                else next.set('page', String(p));
                return next;
            }),
        [setParams],
    );
}

/**
 * Steps the `page` URL parameter back to the last page when the list shrank under it (a filter,
 * or deleting the last row of the last page). Pass `settled = false` while the data shown is a
 * placeholder from the previous query, whose `last_page` belongs to other parameters.
 */
export function useClampPage(page: number, lastPage: number | undefined, settled: boolean): void {
    const [, setParams] = useSearchParams();
    useEffect(() => {
        if (!settled || lastPage === undefined || page <= Math.max(1, lastPage)) return;
        setParams(
            (prev) => {
                const next = new URLSearchParams(prev);
                if (lastPage <= 1) next.delete('page');
                else next.set('page', String(lastPage));
                return next;
            },
            { replace: true },
        );
    }, [settled, lastPage, page, setParams]);
}
