import { afterAll, beforeAll } from 'vitest';

// Vitest runs in Node, but the SPA's types don't include Node's: reach `process.env` through globalThis.
const env = (globalThis as unknown as { process: { env: Record<string, string | undefined> } }).process.env;

/** Runs the enclosing block (or file) in time zone `zone`. Node re-reads TZ whenever it is assigned. */
export function inTimeZone(zone: string): void {
    let saved: string | undefined;
    beforeAll(() => {
        saved = env.TZ;
        env.TZ = zone;
    });
    afterAll(() => {
        if (saved === undefined) delete env.TZ;
        else env.TZ = saved;
    });
}
