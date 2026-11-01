import { describe, expect, it } from 'vitest';
import { flatten, placeholders, pluralForms } from '../../../packages/module-sdk/catalogues';

const modules = import.meta.glob<Record<string, unknown>>('./*/*.json', { eager: true, import: 'default' });

function byLanguage(lang: string): Map<string, Record<string, unknown>> {
    const out = new Map<string, Record<string, unknown>>();
    for (const [path, content] of Object.entries(modules)) {
        const match = /^\.\/([a-z]+)\/(.+)\.json$/.exec(path);
        if (match && match[1] === lang) out.set(match[2]!, content);
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
