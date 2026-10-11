import { describe, expect, it, vi } from 'vitest';
import { testQueryClient } from '../../test/routing';
import { refreshAfterForwardingWrite, refreshAfterMailboxWrite } from './shared';

describe('refreshAfterMailboxWrite', () => {
    it('refetches the address lists too, where mailboxes are listed', async () => {
        const client = testQueryClient();
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        await refreshAfterMailboxWrite(client);
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['addresses'] });
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['domains'] });
    });

    it('refetches the activity log, which every write adds a row to', async () => {
        const client = testQueryClient();
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        await refreshAfterMailboxWrite(client);
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['audit-log'] });
    });
});

describe('refreshAfterForwardingWrite', () => {
    it('refetches the activity log too', async () => {
        const client = testQueryClient();
        const invalidate = vi.spyOn(client, 'invalidateQueries');
        await refreshAfterForwardingWrite(client);
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['audit-log'] });
    });
});
