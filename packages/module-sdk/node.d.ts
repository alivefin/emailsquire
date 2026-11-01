// The few Node APIs `vite.test.ts` uses; the project has no `@types/node`.
declare module 'node:fs/promises' {
    export function mkdtemp(prefix: string): Promise<string>;
    export function rm(path: string, options?: { recursive?: boolean; force?: boolean }): Promise<void>;
    export function writeFile(path: string, data: string): Promise<void>;
}
declare module 'node:os' {
    export function tmpdir(): string;
}
declare module 'node:path' {
    export function join(...parts: string[]): string;
}
