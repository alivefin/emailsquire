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
    },
    {
        // Every file under resources/js except tests.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['**/*.test.{ts,tsx}'],
        plugins: { i18next },
        rules: {
            // Checks JSX text, template literals in JSX and the attributes listed here; with `include`
            // set, every other attribute is skipped, so a new user-visible prop name must be added.
            'i18next/no-literal-string': [
                'error',
                {
                    mode: 'jsx-only',
                    'should-validate-template': true,
                    'jsx-attributes': {
                        include: [
                            'aria-label',
                            'aria-description',
                            'aria-valuetext',
                            'title',
                            'placeholder',
                            'alt',
                            'label',
                            'hint',
                            'subtitle',
                            'note',
                            'caption',
                            'error',
                            'message',
                            'description',
                            'heading',
                            'header',
                            'empty',
                            'text',
                            'problem',
                            'action',
                            'footer',
                        ],
                    },
                },
            ],
        },
    },
);
