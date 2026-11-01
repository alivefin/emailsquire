import i18next from 'eslint-plugin-i18next';

/**
 * The core's `i18next/no-literal-string` block, for a module's own ESLint config: every user-visible string
 * goes through `t()`. Checks JSX text, template literals in JSX and the attributes listed here; with `include`
 * set, every other attribute is skipped, so a new user-visible prop name must be added.
 *
 * @param {{ files?: string[], ignores?: string[] }} [options]
 */
export function noLiteralStrings({ files = ['src/**/*.{ts,tsx}'], ignores = ['**/*.test.{ts,tsx}'] } = {}) {
    return {
        files,
        ignores,
        plugins: { i18next },
        rules: {
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
    };
}
