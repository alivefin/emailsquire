import { describe, expect, it } from 'vitest';
import { pageInfo } from './pager';

describe('pageInfo', () => {
    it('labels the first page', () => {
        expect(pageInfo(1, 10, 34)).toEqual({ from: 1, to: 10, last: 4, label: 'Showing 1–10 of 34' });
    });

    it('clips the last page to the total', () => {
        expect(pageInfo(4, 10, 34)).toEqual({ from: 31, to: 34, last: 4, label: 'Showing 31–34 of 34' });
    });

    it('says No results for an empty list', () => {
        expect(pageInfo(1, 10, 0)).toEqual({ from: 0, to: 0, last: 1, label: 'No results' });
    });
});
