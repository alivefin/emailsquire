import js from '@eslint/js';
import globals from 'globals';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';
import { noLiteralStrings } from './packages/module-sdk/eslint.mjs';

export default tseslint.config(
    { ignores: ['resources/js/api/schema.d.ts'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    react.configs.flat.recommended,
    react.configs.flat['jsx-runtime'],
    { settings: { react: { version: 'detect' } } },
    {
        files: ['packages/module-sdk/**/*.mjs'],
        languageOptions: { globals: { ...globals.node, ...globals.browser } },
    },
    {
        files: ['resources/js/**/*.{ts,tsx}', 'packages/module-sdk/**/*.{ts,tsx}'],
        languageOptions: { globals: globals.browser },
        plugins: { 'react-hooks': reactHooks },
        rules: {
            'react/no-danger': 'error',
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    // Every file under resources/js except tests.
    noLiteralStrings({ files: ['resources/js/**/*.{ts,tsx}', 'packages/module-sdk/**/*.{ts,tsx}'] }),
);
