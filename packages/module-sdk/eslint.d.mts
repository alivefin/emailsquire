import type { Linter } from 'eslint';

export function noLiteralStrings(options?: { files?: string[]; ignores?: string[] }): Linter.Config;
