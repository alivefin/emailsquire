import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { apiFetch } from '../api/client';
import { queryKeys } from '../api/types';
import { setLocale } from '../i18n';
import { testQueryClient } from '../test/routing';
import { ASSERTION_JSON, REQUEST_OPTIONS, mockWebAuthn } from '../test/webauthn';
import { StepUpDialog } from './StepUpDialog';

const ME = { passkeys: 1, second_factor: true, two_factor: { confirmed: false } };
const reply = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
let fetchMock: ReturnType<typeof vi.fn>;
let credentials: ReturnType<typeof mockWebAuthn>;
beforeEach(() => {
    credentials = mockWebAuthn();
    fetchMock = vi.fn().mockResolvedValueOnce(reply({ code: 'step_up_required', title: 'T', status: 423 }, 423))
        .mockResolvedValueOnce(reply(REQUEST_OPTIONS)).mockResolvedValueOnce(new Response(null, { status: 204 }))
        .mockResolvedValueOnce(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock); document.cookie = 'XSRF-TOKEN=t; path=/';
});
afterEach(() => vi.unstubAllGlobals());
function setup(me: unknown = ME) {
    const client = testQueryClient(); client.setQueryData(queryKeys.me, me);
    const settled = vi.fn();
    render(<QueryClientProvider client={client}><MemoryRouter>
        <button onClick={() => { apiFetch('/domains/x', { method: 'DELETE' }).then(settled, settled); }}>Delete</button>
        <StepUpDialog />
    </MemoryRouter></QueryClientProvider>);
    return { settled, user: userEvent.setup() };
}
async function open(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    return screen.findByRole('dialog');
}
describe('step-up with a passkey', () => {
    it('confirms with a passkey and retries the original request', async () => {
        const { user, settled } = setup(); await open(user);
        await user.click(screen.getByRole('button', { name: 'Use a passkey' }));
        await waitFor(() => expect(settled).toHaveBeenCalledWith(undefined));
        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/api/v1/domains/x', '/api/v1/auth/confirm-password/passkey-options',
            '/api/v1/auth/confirm-password', '/api/v1/domains/x',
        ]);
        expect(JSON.parse((fetchMock.mock.calls[2]![1] as RequestInit).body as string)).toEqual({ passkey: ASSERTION_JSON });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('keeps cancellation quiet and the password option usable', async () => {
        credentials.get.mockRejectedValue(new DOMException('', 'AbortError'));
        const { user, settled } = setup(); await open(user);
        const button = screen.getByRole('button', { name: 'Use a passkey' });
        await user.click(button); await waitFor(() => expect(button).toBeEnabled());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(settled).not.toHaveBeenCalled(); expect(fetchMock).toHaveBeenCalledTimes(2);
        await user.type(screen.getByLabelText('Password'), 'summersun-long');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));
        await waitFor(() => expect(settled).toHaveBeenCalledWith(undefined));
    });
    it.each([0, undefined])('hides the button without passkeys (%j)', async (passkeys) => {
        const { user } = setup({ ...ME, passkeys }); await open(user);
        expect(screen.queryByRole('button', { name: 'Use a passkey' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
    });
    it('hides the button when unsupported', async () => {
        vi.stubGlobal('PublicKeyCredential', undefined);
        const { user } = setup(); await open(user);
        expect(screen.queryByRole('button', { name: 'Use a passkey' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
    });
    it('shows passkey failures without closing the confirmation', async () => {
        fetchMock.mockReset().mockResolvedValueOnce(reply({ code: 'step_up_required', title: 'T', status: 423 }, 423))
            .mockResolvedValueOnce(reply(REQUEST_OPTIONS))
            .mockResolvedValueOnce(reply({ title: 'T', status: 422, code: 'passkey_cloned', errors: { passkey: ['Use another method.'] } }, 422));
        const { user, settled } = setup(); await open(user);
        await user.click(screen.getByRole('button', { name: 'Use a passkey' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Use another method.');
        expect(screen.getByRole('dialog')).toBeInTheDocument(); expect(settled).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
    });
    it('renders the button in Finnish', async () => {
        await setLocale('fi'); const { user } = setup(); await open(user);
        expect(screen.getByRole('button', { name: 'Käytä pääsyavainta' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Peruuta' }));
    });
});
