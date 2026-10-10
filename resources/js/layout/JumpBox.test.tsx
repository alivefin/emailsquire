import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../api/client';
import { renderRoutes } from '../test/routing';
import { JumpBox } from './JumpBox';

vi.mock('../api/client', () => ({ apiFetch: vi.fn() }));

const NAMES = {
    data: [
        { name: 'example.org', unicode_name: 'example.org', mailbox_count: 3 },
        { name: 'example.net', unicode_name: 'example.net', mailbox_count: 1 },
        { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 0 },
    ],
};

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockResolvedValue(NAMES);
});

async function jump(text: string) {
    const user = userEvent.setup();
    renderRoutes([{ path: '/', element: <JumpBox /> }], '/');
    const input = await screen.findByRole('searchbox', { name: 'Jump to domain or address' });
    await vi.waitFor(() => expect(apiFetch).toHaveBeenCalled());
    await new Promise((r) => setTimeout(r, 0));
    if (text !== '') await user.type(input, text);
    await user.type(input, '{Enter}');
}

describe('JumpBox', () => {
    it('goes to a domain by its unicode name', async () => {
        await jump('bücher.example');
        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/xn--bcher-kva.example/mailboxes');
    });

    it('goes to the first prefix match in list order', async () => {
        await jump('exa');
        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.org/mailboxes');
    });

    it('trims and lowercases', async () => {
        await jump('  EXAMPLE.NET ');
        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.net/mailboxes');
    });

    it('falls back to the address search', async () => {
        await jump('anna');
        expect(await screen.findByTestId('location')).toHaveTextContent('/addresses?q=anna');
    });

    it('does nothing for empty input', async () => {
        await jump('');
        expect(screen.queryByTestId('location')).not.toBeInTheDocument();
    });
});
