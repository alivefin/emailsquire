import { describe, expect, it } from 'vitest';
import { setLocale } from '../i18n';
import { ApiProblem, problemMessage } from './problems';

describe('problemMessage in Finnish', () => {
    it('translates the codes the SPA owns', async () => {
        await setLocale('fi');
        const busy = new ApiProblem({ status: 409, title: 'Conflict', code: 'busy', detail: 'raw' });
        const archiving = new ApiProblem({ status: 409, title: 'Conflict', code: 'archiving', detail: 'raw' });
        expect(problemMessage(busy)).toBe('Joku muu muokkaa tätä osoitetta. Yritä uudelleen.');
        expect(problemMessage(archiving)).toBe('Tätä osoitetta arkistoidaan vielä. Yritä uudelleen muutaman minuutin kuluttua.');
    });

    it('still falls back to the server detail for unknown codes', async () => {
        await setLocale('fi');
        expect(problemMessage(new ApiProblem({ status: 500, title: 'Server Error', code: 'nope', detail: 'Palvelinvirhe' }))).toBe('Palvelinvirhe');
    });
});
