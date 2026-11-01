/**
 * Vite plugins for building an EmailSquire module's SPA bundle. Plain JS because Node does not strip
 * types from files inside `node_modules`, where a module's repo installs this package.
 */

// The packages the core shares; keep in step with `Host['libs']` in resources/js/modules/host.ts.
const SHARED = ['react', 'react/jsx-runtime', 'react-dom', 'react-router', '@tanstack/react-query', 'i18next', 'react-i18next'];
const PREFIX = '\0emailsquire-host:';
const IDENTIFIER = /^[A-Za-z_$][\w$]*$/;

/**
 * @param {{ name: string, entry: string }} options the module's manifest name and its SPA entry file
 * @returns {import('vite').Plugin[]}
 */
export function emailsquireModule({ name, entry }) {
    return [
        {
            name: `emailsquire-module:${name}:build`,
            config() {
                return {
                    build: {
                        lib: { entry, formats: ['es'], // A function, so the name is `entry.js` whether or not the module's package.json says `type: module`.
                        fileName: () => 'entry.js', cssFileName: 'entry' },
                        outDir: 'public',
                        emptyOutDir: true,
                    },
                };
            },
        },
        {
            name: 'emailsquire-module:shared',
            enforce: 'pre',
            resolveId(id) {
                return SHARED.includes(id) ? PREFIX + id : null;
            },
            async load(id) {
                if (!id.startsWith(PREFIX)) return null;
                const lib = id.slice(PREFIX.length);
                // The names are read from the installed package at build time, so `import { useState }` works.
                const names = Object.keys(await import(lib)).filter((n) => n !== 'default' && IDENTIFIER.test(n));
                return [
                    `const m = globalThis.__emailsquire.libs[${JSON.stringify(lib)}];`,
                    'export default (m.default ?? m);',
                    `export const { ${names.join(', ')} } = m;`,
                ].join('\n');
            },
        },
    ];
}
