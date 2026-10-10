import js from '@eslint/js';
import globals from 'globals';
import i18next from 'eslint-plugin-i18next';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['resources/js/api/schema.d.ts'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    react.configs.flat.recommended,
    react.configs.flat['jsx-runtime'],
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: { globals: globals.browser },
        plugins: { 'react-hooks': reactHooks },
        settings: { react: { version: 'detect' } },
        rules: {
            'react/no-danger': 'error',
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },    {
        // Widened by each later i18n task as its files move to catalogues.
        files: [
            'resources/js/components/ui/**/*.{ts,tsx}',
            'resources/js/layout/**/*.{ts,tsx}',
            'resources/js/api/**/*.{ts,tsx}',
            'resources/js/lib/**/*.{ts,tsx}',
            'resources/js/i18n/**/*.{ts,tsx}',
            'resources/js/features/{auth,dashboard,domains,addresses,blocked}/**/*.{ts,tsx}',
        ],
        ignores: ['**/*.test.{ts,tsx}'],
        plugins: { i18next },
        rules: {
            'i18next/no-literal-string': [
                'error',
                {
                    mode: 'jsx-only',
                    'jsx-attributes': { include: ['aria-label', 'title', 'placeholder', 'alt'] },
                },
            ],
        },
    },
);
