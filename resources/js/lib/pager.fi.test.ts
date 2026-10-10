import { describe, expect, it } from 'vitest';
import { setLocale } from '../i18n';
import { pageInfo } from './pager';

describe('pageInfo in Finnish', () => {
    it('labels the first page and an empty list', async () => {
        await setLocale('fi');
        expect(pageInfo(1, 10, 34).label).toBe('Näytetään 1–10 / 34');
        expect(pageInfo(1, 10, 0).label).toBe('Ei tuloksia');
    });
});
