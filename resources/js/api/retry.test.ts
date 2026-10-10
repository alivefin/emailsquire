import { describe, expect, it } from 'vitest';
import { ApiProblem } from './problems';
import { retryQuery } from './retry';

const problem = (status: number) => new ApiProblem({ status, title: `HTTP ${status}` });

describe('retryQuery', () => {
    it.each([400, 401, 403, 404, 409, 419, 422, 429])('never retries a %i', (status) => {
        expect(retryQuery(0, problem(status))).toBe(false);
    });

    it('retries a 5xx or a network error twice', () => {
        for (const error of [problem(500), problem(503), new TypeError('Failed to fetch')]) {
            expect(retryQuery(0, error)).toBe(true);
            expect(retryQuery(1, error)).toBe(true);
            expect(retryQuery(2, error)).toBe(false);
        }
    });
});
