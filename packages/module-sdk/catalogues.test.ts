import { describe, expect, it } from 'vitest';
import { catalogueParity } from './catalogues';

describe('catalogueParity', () => {
    it('accepts matching catalogues', () => {
        expect(catalogueParity({ nav: { notes: 'Notes {{count}}' } }, { nav: { notes: 'Muistiinpanot {{count}}' } })).toEqual([]);
    });

    it('reports a key missing from fi, and one only in fi', () => {
        const problems = catalogueParity({ nav: { notes: 'Notes', extra: 'Extra' } }, { nav: { notes: 'Muistiinpanot', other: 'Muu' } });
        expect(problems).toContain('nav.extra: missing in fi');
        expect(problems).toContain('nav.other: missing in en');
    });

    it('reports a placeholder mismatch', () => {
        expect(catalogueParity({ nav: { n: 'Hello {{name}}' } }, { nav: { n: 'Hei {{nimi}}' } })).toEqual(['nav.n: placeholders differ (en: name, fi: nimi)']);
    });

    it('reports a file present in one language only, an empty value and a non-string leaf', () => {
        const problems = catalogueParity({ nav: { a: '' }, only: { b: 'x' } }, { nav: { a: 'a' }, nope: { c: 1 } as never });
        expect(problems).toContain('only: file missing in fi');
        expect(problems).toContain('nope: file missing in en');
        expect(problems).toContain('nav.a: empty in en');
    });

    it('reports a plural key without both forms', () => {
        const problems = catalogueParity({ n: { item_one: 'one' } }, { n: { item_one: 'yksi' } });
        expect(problems).toContain('n.item: needs _one and _other in en');
    });
});
