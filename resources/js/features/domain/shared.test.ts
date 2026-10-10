import { describe, expect, it, vi } from 'vitest';
import { testQueryClient } from '../../test/routing';
import { refreshAfterMailboxWrite } from './shared';

describe('refreshAfterMailboxWrite', () => {
    it('refetches the address lists too, where mailboxes are listed', async () => {
        const client = testQueryClient();
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        await refreshAfterMailboxWrite(client);
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['addresses'] });
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['domains'] });
    });
});
