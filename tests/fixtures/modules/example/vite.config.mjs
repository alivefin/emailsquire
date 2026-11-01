import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { emailsquireModule } from '../../../../packages/module-sdk/vite.mjs';

// The fixture module's bundle, built by `npm run build:fixture-module`. Paths are fixed to this
// directory so the build (which empties `public/`) never touches anything else.
const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
    root,
    publicDir: false,
    plugins: [emailsquireModule({ name: 'example', entry: 'resources/entry.tsx' })],
    define: { 'process.env.NODE_ENV': JSON.stringify('production') },
});
