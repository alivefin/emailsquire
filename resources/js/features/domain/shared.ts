import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import type { QueryClient } from '@tanstack/react-query';
import { ApiProblem, problemMessage } from '../../api/problems';
import { queryKeys, type Json } from '../../api/types';

export type MailboxList = Json<'/domains/{domain}/mailboxes', 'get'>;
export type Mailbox = MailboxList['data'][number];
export type ForwardingList = Json<'/domains/{domain}/forwarding', 'get'>;
export type ForwardingRow = ForwardingList['data'][number];

/** What the domain page's drawers (Tasks 11 and 12) open for. */
export type DomainDrawer = { kind: 'new' } | { kind: 'edit'; local: string; focus?: 'password' } | null;

export interface DomainActions {
    openNewMailbox(): void;
    openEditMailbox(local: string, focus?: 'password'): void;
}

export const DomainActionsContext = createContext<DomainActions>({
    openNewMailbox: () => {},
    openEditMailbox: () => {},
});

export function useDomainActions(): DomainActions {
    return useContext(DomainActionsContext);
}

/** API path of a domain's mailboxes, or of one of them. Names and locals are encoded here. */
export function mailboxesPath(domain: string, local?: string): string {
    const base = `/domains/${encodeURIComponent(domain)}/mailboxes`;
    return local === undefined ? base : `${base}/${encodeURIComponent(local)}`;
}

/** API path of a domain's forwarding list, or of one source in it. Names and locals are encoded here. */
export function forwardingPath(domain: string, local?: string): string {
    const base = `/domains/${encodeURIComponent(domain)}/forwarding`;
    return local === undefined ? base : `${base}/${encodeURIComponent(local)}`;
}

/** `local@domain` with the domain in its unicode spelling, for display only. */
export function displayAddress(local: string, unicodeDomain: string): string {
    return `${local}@${unicodeDomain}`;
}

export function mailboxCount(n: number): string {
    return `${n} ${n === 1 ? 'mailbox' : 'mailboxes'}`;
}

export function errorText(e: unknown, fallback: string): string {
    return e instanceof ApiProblem ? problemMessage(e) : fallback;
}

/** Refetches everything a mailbox write can change: lists, counts, the dashboard and the pinned counts. */
export async function refreshAfterMailboxWrite(client: QueryClient): Promise<void> {
    await Promise.all([
        client.invalidateQueries({ queryKey: ['domains'] }),
        client.invalidateQueries({ queryKey: queryKeys.dashboard }),
        client.invalidateQueries({ queryKey: queryKeys.pinned }),
    ]);
}

/** Refetches everything a forwarding write can change: what a mailbox write changes, plus the address lists. */
export async function refreshAfterForwardingWrite(client: QueryClient): Promise<void> {
    await Promise.all([
        refreshAfterMailboxWrite(client),
        client.invalidateQueries({ queryKey: ['addresses'] }),
        client.invalidateQueries({ queryKey: ['search'] }),
    ]);
}

/** `value`, `delay` ms after its last change. */
export function useDebounced<T>(value: T, delay: number): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const t = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(t);
    }, [value, delay]);
    return debounced;
}

/**
 * Runs one async action at a time. A second call while the first is in flight is dropped, so a
 * double click sends one request even before the button re-renders as busy.
 */
export function useSingleFlight(): (run: () => Promise<unknown>) => void {
    const busy = useRef(false);
    return useCallback((run: () => Promise<unknown>) => {
        if (busy.current) return;
        busy.current = true;
        void run()
            .catch(() => {
                // The mutation's onError reports it.
            })
            .finally(() => {
                busy.current = false;
            });
    }, []);
}
