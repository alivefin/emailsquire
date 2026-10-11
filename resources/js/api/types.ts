import type { components, paths } from './schema';

export type Schemas = components['schemas'];

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

type JsonOf<R> = R extends { content: { 'application/json': infer B } } ? B : never;

type Op<P extends keyof paths, M extends Method> = paths[P][M];

type Responses<P extends keyof paths, M extends Method> = Op<P, M> extends { responses: infer R } ? R : never;

type Body200<P extends keyof paths, M extends Method> = Responses<P, M> extends { 200: infer R } ? JsonOf<R> : never;
type Body201<P extends keyof paths, M extends Method> = Responses<P, M> extends { 201: infer R } ? JsonOf<R> : never;

/** The JSON body of the 200 response (else the 201 one); `undefined` for a 204-only operation. */
export type Json<P extends keyof paths, M extends Method> = [Body200<P, M>] extends [never]
    ? [Body201<P, M>] extends [never]
        ? undefined
        : Body201<P, M>
    : Body200<P, M>;

type Params = Record<string, unknown>;

export const queryKeys = {
    me: ['me'] as const,
    dashboard: ['dashboard'] as const,
    domains: (params: Params = {}) => ['domains', params] as const,
    domainNames: ['domains', 'names'] as const,
    pinned: ['pinned'] as const,
    mailboxes: (domain: string, params: Params = {}) => ['domains', domain, 'mailboxes', params] as const,
    mailbox: (domain: string, local: string) => ['domains', domain, 'mailbox', local] as const,
    usage: (domain: string, local: string) => ['domains', domain, 'mailbox', local, 'usage'] as const,
    addressCheck: (domain: string, local: string) => ['domains', domain, 'address-check', local] as const,
    domainSettings: (domain: string) => ['domains', domain, 'settings'] as const,
    forwarding: (domain: string, params: Params = {}) => ['domains', domain, 'forwarding', params] as const,
    access: (domain: string) => ['domains', domain, 'access'] as const,
    addresses: (params: Params = {}) => ['addresses', params] as const,
    blocked: (params: Params = {}) => ['blocked', params] as const,
    search: (q: string) => ['search', q] as const,
    users: (params: Params = {}) => ['users', 'list', params] as const,
    user: (id: number | string) => ['users', 'detail', String(id)] as const,
    userCount: ['users', 'count'] as const,
    userInvitations: (params: Params = {}) => ['users', 'invitations', params] as const,
};
