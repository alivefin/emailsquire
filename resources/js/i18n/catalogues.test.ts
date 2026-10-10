import { describe, expect, it } from 'vitest';

const modules = import.meta.glob<Record<string, unknown>>('./*/*.json', { eager: true, import: 'default' });

function byLanguage(lang: string): Map<string, Record<string, unknown>> {
    const out = new Map<string, Record<string, unknown>>();
    for (const [path, content] of Object.entries(modules)) {
        const match = /^\.\/([a-z]+)\/(.+)\.json$/.exec(path);
        if (match && match[1] === lang) out.set(match[2]!, content);
    }
    return out;
}

/** Every leaf by full key. A leaf that is not a string (a number, array, null) is recorded as a failure. */
function flatten(node: unknown, prefix = '', problems: string[] = []): { leaves: Map<string, string>; problems: string[] } {
    const leaves = new Map<string, string>();
    if (typeof node === 'string') {
        leaves.set(prefix, node);
    } else if (typeof node === 'object' && node !== null && !Array.isArray(node)) {
        for (const [key, value] of Object.entries(node)) {
            const inner = flatten(value, prefix === '' ? key : `${prefix}.${key}`, problems);
            for (const [k, v] of inner.leaves) leaves.set(k, v);
        }
    } else {
        problems.push(prefix === '' ? '(root)' : prefix);
    }
    return { leaves, problems };
}

const PLURAL = /_(one|other)$/;
const placeholders = (value: string) => [...new Set([...value.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]!))].sort().join(',');

/** Base keys that have a plural form, with the suffixes each has. */
function pluralForms(leaves: Map<string, string>): Map<string, string[]> {
    const out = new Map<string, string[]>();
    for (const key of leaves.keys()) {
        const match = PLURAL.exec(key);
        if (!match) continue;
        const base = key.replace(PLURAL, '');
        out.set(base, [...(out.get(base) ?? []), match[1]!].sort());
    }
    return out;
}

const en = byLanguage('en');
const fi = byLanguage('fi');

describe('catalogues', () => {
    it('has at least the common namespace', () => {
        expect(en.has('common')).toBe(true);
    });

    it('has the same files in both languages', () => {
        expect([...fi.keys()].sort()).toEqual([...en.keys()].sort());
    });

    for (const [namespace, enContent] of en) {
        describe(namespace, () => {
            const enFlat = flatten(enContent);
            const fiFlat = flatten(fi.get(namespace) ?? {});

            it('has only string values', () => {
                expect(enFlat.problems).toEqual([]);
                expect(fiFlat.problems).toEqual([]);
            });

            it('has identical keys in en and fi', () => {
                expect([...fiFlat.leaves.keys()].sort()).toEqual([...enFlat.leaves.keys()].sort());
            });

            it('has identical placeholders for every full key, plural forms included', () => {
                const of = (flat: Map<string, string>) => Object.fromEntries([...flat].map(([k, v]) => [k, placeholders(v)]));
                expect(of(fiFlat.leaves)).toEqual(of(enFlat.leaves));
            });

            it('gives every plural key both _one and _other in both languages', () => {
                for (const [lang, flat] of [
                    ['en', enFlat],
                    ['fi', fiFlat],
                ] as const) {
                    for (const [base, forms] of pluralForms(flat.leaves)) {
                        expect(forms, `${lang}:${namespace}.${base}`).toEqual(['one', 'other']);
                    }
                }
            });

            it('has no empty values', () => {
                for (const flat of [enFlat, fiFlat]) {
                    for (const [key, value] of flat.leaves) expect(value.trim(), key).not.toBe('');
                }
            });
        });
    }
});
