# @emailsquire/module-sdk

Build tooling for an EmailSquire module's SPA bundle. A module's `public/entry.js` shares React, React Router, TanStack Query and i18next with the console through `globalThis.__emailsquire`, so none of them is bundled twice.

```js
// vite.config.js
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { emailsquireModule } from '@emailsquire/module-sdk/vite';

export default defineConfig({
    plugins: [emailsquireModule({ name: 'example', entry: 'src/entry.tsx' }), tailwindcss()],
});
```

The build writes `public/entry.js` and `public/entry.css`, emptying `public/` first. The entry calls `globalThis.__emailsquire.registerModule({ name, routes, catalogues, dashboardCards? })`. From core API 1.1.0 a catalogue file named `activity` words the module's audit actions in the activity log: nest each action's dots (`example.noted` is `{ "example": { "noted": "Noted {{object}}" } }`); `{{object}}` and `{{domain}}` are available.

- `@emailsquire/module-sdk/host`: types `Host` and `ModuleDefinition`, and the `__emailsquire` global.
- `@emailsquire/module-sdk/testing`: `renderWithHost(ui, { me?, route?, module? })` renders with a real query client, a memory router, the toast provider, the core's and the module's i18n catalogues, and the host installed. Pass `module: { name, catalogues }` to resolve `t('<name>:<file>.<key>')`.
- `@emailsquire/module-sdk/catalogues`: `catalogueParity(en, fi)` returns one line per mismatch between the two languages (`[]` when they agree); assert it is empty in a test.
- `@emailsquire/module-sdk/eslint`: `noLiteralStrings({ files?, ignores? })`, the core's `i18next/no-literal-string` block.
- `@emailsquire/module-sdk/theme.css`: the console's design tokens; `@import 'tailwindcss'; @import '@emailsquire/module-sdk/theme.css';` gives a module the same Tailwind theme.

The SDK lives in the core repository and is not published to npm. It is not a standalone package: its files import the core's own sources (`../../resources/js/...`), so it works only from inside a core checkout. A module repo depends on it by a path into a checkout of the core, for example `"@emailsquire/module-sdk": "file:../emailsquire/packages/module-sdk"`, never by a git URL to the package alone. Run `npm ci` in that checkout as well: the core sources the SDK imports resolve their packages from the checkout's `node_modules`. The module's Pest suite needs the same checkout (see "Module repositories' tests" in `docs/backend-plan.md`).
