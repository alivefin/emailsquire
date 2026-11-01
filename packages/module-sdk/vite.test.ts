import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { build, type Plugin } from 'vite';
import { emailsquireModule } from './vite.mjs';

type Hooks = { resolveId: (id: string) => unknown; load: (id: string) => Promise<string | null> };

function shared(): Hooks {
    const plugin = emailsquireModule({ name: 'example', entry: 'src/entry.ts' }).find((p: Plugin) => p.name === 'emailsquire-module:shared');
    return plugin as unknown as Hooks;
}

describe('shared imports', () => {
    it('resolves the host libraries and nothing else', () => {
        expect(shared().resolveId('react')).toBe('\0emailsquire-host:react');
        expect(shared().resolveId('react/jsx-runtime')).toBe('\0emailsquire-host:react/jsx-runtime');
        expect(shared().resolveId('./x')).toBeNull();
        expect(shared().resolveId('lodash')).toBeNull();
    });

    it('exports the default and the named exports from the host', async () => {
        const code = await shared().load('\0emailsquire-host:react');
        expect(code).toContain('globalThis.__emailsquire.libs["react"]');
        expect(code).toMatch(/export default/);
        expect(code).toMatch(/export const \{[^}]*\buseState\b/);
        expect(code).not.toMatch(/export const \{[^}]*\bdefault\b/);
    });

    it('leaves other ids to Vite', async () => {
        expect(await shared().load('./x')).toBeNull();
    });
});

describe('library build', () => {
    let dir: string;
    beforeAll(async () => {
        dir = await mkdtemp(join(tmpdir(), 'emailsquire-sdk-'));
        await writeFile(join(dir, 'entry.ts'), "import { useState } from 'react';\nimport './style.css';\nexport const x = useState;\n");
        await writeFile(join(dir, 'style.css'), '.x { color: red; }\n');
    });
    afterAll(async () => {
        await rm(dir, { recursive: true, force: true });
    });

    it('bundles no React and reads it from the host', async () => {
        const result = await build({
            root: dir,
            configFile: false,
            logLevel: 'silent',
            plugins: [emailsquireModule({ name: 'example', entry: join(dir, 'entry.ts') })],
            build: { write: false },
        });
        const outputs = (Array.isArray(result) ? result : [result]).flatMap((r) => ('output' in r ? r.output : []));
        const entry = outputs.find((o) => o.fileName === 'entry.js');
        const code = entry && 'code' in entry ? entry.code : '';
        expect(code).toContain('globalThis.__emailsquire.libs');
        expect(code).not.toContain('react.production');
        expect(outputs.map((o) => o.fileName).sort()).toEqual(['entry.css', 'entry.js']);
    });
});
