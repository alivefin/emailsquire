/** Every leaf by full key. A leaf that is not a string (a number, array, null) is recorded as a problem. */
export function flatten(node: unknown, prefix = '', problems: string[] = []): { leaves: Map<string, string>; problems: string[] } {
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

export const placeholders = (value: string) => [...new Set([...value.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]!))].sort().join(',');

/** Base keys that have a plural form, with the suffixes each has. */
export function pluralForms(leaves: Map<string, string>): Map<string, string[]> {
    const out = new Map<string, string[]>();
    for (const key of leaves.keys()) {
        const match = PLURAL.exec(key);
        if (!match) continue;
        const base = key.replace(PLURAL, '');
        out.set(base, [...(out.get(base) ?? []), match[1]!].sort());
    }
    return out;
}

/**
 * Compares a module's English and Finnish catalogues (file name → content) and returns one line per
 * mismatch, `[]` when they agree: files and keys present in one language only, placeholders that differ,
 * values that are empty or not strings, and plural keys without both `_one` and `_other`.
 */
export function catalogueParity(en: Record<string, object>, fi: Record<string, object>): string[] {
    const out: string[] = [];
    const files = [...new Set([...Object.keys(en), ...Object.keys(fi)])].sort();
    for (const file of files) {
        const enFile = en[file];
        const fiFile = fi[file];
        if (enFile === undefined || fiFile === undefined) {
            out.push(`${file}: file missing in ${enFile === undefined ? 'en' : 'fi'}`);
            continue;
        }
        const flat = { en: flatten(enFile), fi: flatten(fiFile) };
        for (const lang of ['en', 'fi'] as const) {
            for (const key of flat[lang].problems) out.push(`${file}.${key}: not a string in ${lang}`);
            for (const [key, value] of flat[lang].leaves) if (value.trim() === '') out.push(`${file}.${key}: empty in ${lang}`);
            for (const [base, forms] of pluralForms(flat[lang].leaves)) {
                if (forms.join() !== 'one,other') out.push(`${file}.${base}: needs _one and _other in ${lang}`);
            }
        }
        for (const [key, value] of flat.en.leaves) {
            const other = flat.fi.leaves.get(key);
            if (other === undefined) out.push(`${file}.${key}: missing in fi`);
            else if (placeholders(value) !== placeholders(other)) out.push(`${file}.${key}: placeholders differ (en: ${placeholders(value)}, fi: ${placeholders(other)})`);
        }
        for (const key of flat.fi.leaves.keys()) if (!flat.en.leaves.has(key)) out.push(`${file}.${key}: missing in en`);
    }
    return out;
}
