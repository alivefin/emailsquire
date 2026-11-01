import { expectModules, moduleSettled, recordFailure } from './registry';

export type ModuleEntry = { name: string; route: string; entry: string };

/** The shell's `#emailsquire-modules` block; `[]` when it is missing or unreadable. */
export function readModuleList(doc: Document = document): ModuleEntry[] {
    const text = doc.getElementById('emailsquire-modules')?.textContent;
    if (!text) return [];
    let list: unknown;
    try {
        list = JSON.parse(text);
    } catch {
        return [];
    }
    if (!Array.isArray(list)) return [];
    return list.filter((m): m is ModuleEntry => {
        const e = m as Record<string, unknown> | null;
        return typeof e === 'object' && e !== null && typeof e.name === 'string' && typeof e.route === 'string' && typeof e.entry === 'string';
    }).map(({ name, route, entry }) => ({ name, route, entry }));
}

const TIMED_OUT = Symbol('timeout');

/**
 * Imports every listed entry in parallel; each entry calls `registerModule()` as it runs. A failed or
 * slow entry is recorded, never thrown: the console starts with whatever loaded. `importer` is for tests.
 */
export async function loadModules(
    list: ModuleEntry[],
    { timeoutMs, importer = (entry) => import(/* @vite-ignore */ entry) }: { timeoutMs: number; importer?: (entry: string) => Promise<unknown> },
): Promise<void> {
    expectModules(list);
    await Promise.allSettled(
        list.map(async ({ name, entry }) => {
            let timer: ReturnType<typeof setTimeout> | undefined;
            const timeout = new Promise<typeof TIMED_OUT>((resolve) => {
                timer = setTimeout(() => resolve(TIMED_OUT), timeoutMs);
            });
            try {
                const result = await Promise.race([importer(entry), timeout]);
                if (result === TIMED_OUT) recordFailure(name, 'timeout');
                else if (!moduleSettled(name)) recordFailure(name, 'not_registered');
            } catch (e) {
                recordFailure(name, 'import_failed', e);
            } finally {
                clearTimeout(timer);
            }
        }),
    );
}
