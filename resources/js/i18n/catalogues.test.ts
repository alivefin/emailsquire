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

function flatten(node: unknown, prefix = ''): Map<string, string> {
    const out = new Map<string, string>();
    if (typeof node === 'string') {
        out.set(prefix, node);
    } else if (typeof node === 'object' && node !== null) {
        for (const [key, value] of Object.entries(node)) {
            for (const [k, v] of flatten(value, prefix === '' ? key : `${prefix}.${key}`)) out.set(k, v);
        }
    }
    return out;
}

const baseKey = (key: string) => key.replace(/_(one|other)$/, '');
const placeholders = (value: string) => [...new Set([...value.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]!))].sort();

/** Base key -> union of placeholders over its plural forms. */
function summarise(flat: Map<string, string>): Map<string, string> {
    const out = new Map<string, Set<string>>();
    for (const [key, value] of flat) {
        const set = out.get(baseKey(key)) ?? new Set<string>();
        placeholders(value).forEach((p) => set.add(p));
        out.set(baseKey(key), set);
    }
    return new Map([...out].map(([k, v]) => [k, [...v].sort().join(',')]));
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
            const fiContent = fi.get(namespace) ?? {};
            const enFlat = flatten(enContent);
            const fiFlat = flatten(fiContent);

            it('has identical keys in en and fi', () => {
                expect([...summarise(fiFlat).keys()].sort()).toEqual([...summarise(enFlat).keys()].sort());
            });

            it('has identical placeholders per key', () => {
                expect(Object.fromEntries(summarise(fiFlat))).toEqual(Object.fromEntries(summarise(enFlat)));
            });

            it('has no empty values', () => {
                for (const flat of [enFlat, fiFlat]) {
                    for (const [key, value] of flat) expect(value.trim(), key).not.toBe('');
                }
            });
        });
    }
});
