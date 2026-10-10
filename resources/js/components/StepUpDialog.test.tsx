import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { queryKeys } from '../api/types';
import { setLocale } from '../i18n';
import { testQueryClient } from '../test/routing';
import { Drawer } from './ui/Drawer';
import { StepUpDialog } from './StepUpDialog';

function problem(status: number, body: Record<string, unknown> = {}) {
    return new Response(JSON.stringify({ type: 'about:blank', title: 'T', status, ...body }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
}
const ok = (body: unknown) => new Response(JSON.stringify(body), { status: 200 });
const noContent = () => new Response(null, { status: 204 });
const stepUp = () => problem(423, { code: 'step_up_required' });

const ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    locale: 'en',
    two_factor: { enabled: false, confirmed: false, recovery_codes_left: 0 },
    two_factor_required: false,
};
const ME_2FA = { ...ME, two_factor: { enabled: true, confirmed: true, recovery_codes_left: 8 } };

let fetchMock: ReturnType<typeof vi.fn>;
const calls = () =>
    fetchMock.mock.calls.map((c) => {
        const init = c[1] as RequestInit;
        return `${init.method} ${c[0] as string}${init.body ? ` ${init.body as string}` : ''}`;
    });

beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=t; path=/';
});
afterEach(() => {
    vi.unstubAllGlobals();
});

function Opener({ path, onSettled }: { path: string; onSettled: (r: unknown) => void }) {
    return (
        <button
            onClick={() => {
                apiFetch(path, { method: 'DELETE' }).then(onSettled, onSettled);
            }}
        >
            Delete it
        </button>
    );
}

function setup(me: unknown = ME, extra?: ReactNode) {
    const client = testQueryClient();
    client.setQueryData(queryKeys.me, me);
    const settled = vi.fn();
    render(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <Opener path="/domains/x.com" onSettled={settled} />
                {extra}
                <StepUpDialog />
            </MemoryRouter>
        </QueryClientProvider>,
    );
    return { settled, user: userEvent.setup() };
}

describe('StepUpDialog', () => {
    it('opens on a 423, confirms the password and retries the original request once', async () => {
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(noContent()).mockResolvedValueOnce(ok({ done: 1 }));
        const { settled, user } = setup();

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        const dialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
        const password = screen.getByLabelText('Password');
        expect(password).toHaveFocus();
        expect(password).toHaveAttribute('type', 'password');
        expect(password).toHaveAttribute('autocomplete', 'current-password');
        expect(screen.queryByRole('button', { name: /use a code/i })).not.toBeInTheDocument();

        await user.type(password, 'correct horse battery');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(settled).toHaveBeenCalledWith({ done: 1 }));
        expect(dialog).not.toBeInTheDocument();
        expect(calls()).toEqual([
            'DELETE /api/v1/domains/x.com',
            'POST /api/v1/auth/confirm-password {"password":"correct horse battery"}',
            'DELETE /api/v1/domains/x.com',
        ]);
        expect(screen.getByRole('button', { name: 'Delete it' })).toHaveFocus();
    });

    it('cancelling throws the original problem and sends nothing else', async () => {
        fetchMock.mockResolvedValueOnce(stepUp());
        const { settled, user } = setup();

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        await waitFor(() => expect(settled).toHaveBeenCalledTimes(1));
        const err = settled.mock.calls[0]![0] as ApiProblem;
        expect(err).toBeInstanceOf(ApiProblem);
        expect(err).toMatchObject({ status: 423, code: 'step_up_required' });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete it' })).toHaveFocus();
    });

    it('Escape cancels', async () => {
        fetchMock.mockResolvedValueOnce(stepUp());
        const { settled, user } = setup();

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.keyboard('{Escape}');

        await waitFor(() => expect(settled).toHaveBeenCalledTimes(1));
        expect(settled.mock.calls[0]![0]).toMatchObject({ status: 423 });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('shows a 422 field error linked to the field and keeps the dialog open', async () => {
        fetchMock
            .mockResolvedValueOnce(stepUp())
            .mockResolvedValueOnce(problem(422, { code: 'validation_failed', errors: { password: ['The password is incorrect.'] } }));
        const { settled, user } = setup();

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await user.type(await screen.findByLabelText('Password'), 'wrong password');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The password is incorrect.');
        const field = screen.getByLabelText('Password');
        expect(field).toHaveAttribute('aria-invalid', 'true');
        expect(field.getAttribute('aria-describedby')).toContain(alert.id);
        expect(screen.getByRole('dialog', { name: "Confirm it's you" })).toBeInTheDocument();
        expect(settled).not.toHaveBeenCalled();
    });

    it('asks for a 6-digit code when two-factor is on, with the use-once hint and a password toggle', async () => {
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(noContent()).mockResolvedValueOnce(ok({ done: 1 }));
        const { settled, user } = setup(ME_2FA);

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await screen.findByRole('dialog', { name: "Confirm it's you" });
        const code = screen.getByLabelText('Two-factor code');
        expect(code).toHaveFocus();
        expect(code).toHaveAttribute('inputmode', 'numeric');
        expect(code).toHaveAttribute('autocomplete', 'one-time-code');
        const hint = screen.getByText('Codes work once. If you just used one, wait for the next.');
        expect(code.getAttribute('aria-describedby')).toContain(hint.id);

        await user.type(code, '12 34-56');
        expect(code).toHaveValue('123456');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(settled).toHaveBeenCalledWith({ done: 1 }));
        expect(calls()[1]).toBe('POST /api/v1/auth/confirm-password {"code":"123456"}');
    });

    it('keeps the hint next to a wrong-code error', async () => {
        fetchMock
            .mockResolvedValueOnce(stepUp())
            .mockResolvedValueOnce(problem(422, { errors: { code: ['The code is wrong or has expired.'] } }));
        const { user } = setup(ME_2FA);

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await user.type(await screen.findByLabelText('Two-factor code'), '123456');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('The code is wrong or has expired.');
        const code = screen.getByLabelText('Two-factor code');
        const hint = screen.getByText('Codes work once. If you just used one, wait for the next.');
        expect(code.getAttribute('aria-describedby')?.split(' ')).toEqual(expect.arrayContaining([alert.id, hint.id]));
    });

    it('switches to the password and back', async () => {
        fetchMock.mockResolvedValueOnce(stepUp()).mockResolvedValueOnce(noContent()).mockResolvedValueOnce(ok({ done: 1 }));
        const { settled, user } = setup(ME_2FA);

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        await screen.findByLabelText('Two-factor code');
        await user.click(screen.getByRole('button', { name: 'Use password instead' }));
        const password = screen.getByLabelText('Password');
        expect(password).toHaveFocus();
        expect(screen.queryByLabelText('Two-factor code')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Use a code instead' }));
        expect(screen.getByLabelText('Two-factor code')).toHaveFocus();
        await user.click(screen.getByRole('button', { name: 'Use password instead' }));
        await user.type(screen.getByLabelText('Password'), 'secret secret');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => expect(settled).toHaveBeenCalledWith({ done: 1 }));
        expect(calls()[1]).toBe('POST /api/v1/auth/confirm-password {"password":"secret secret"}');
    });

    it('opens one dialog for two parallel 423s and retries both requests', async () => {
        fetchMock.mockImplementation((url: string, init: RequestInit) => {
            if (url === '/api/v1/auth/confirm-password') return Promise.resolve(noContent());
            const seen = fetchMock.mock.calls.filter((c) => c[0] === url && (c[1] as RequestInit).method === init.method);
            return Promise.resolve(seen.length === 1 ? stepUp() : ok({ url }));
        });
        const { user } = setup();

        const a = apiFetch('/domains/a.com', { method: 'DELETE' });
        const b = apiFetch('/domains/b.com', { method: 'DELETE' });
        await screen.findByRole('dialog', { name: "Confirm it's you" });
        await act(() => new Promise((r) => setTimeout(r, 0)));
        expect(screen.getAllByRole('dialog')).toHaveLength(1);

        await user.type(screen.getByLabelText('Password'), 'correct horse battery');
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await expect(a).resolves.toEqual({ url: '/api/v1/domains/a.com' });
        await expect(b).resolves.toEqual({ url: '/api/v1/domains/b.com' });
        const sent = calls();
        expect(sent.filter((c) => c.startsWith('POST /api/v1/auth/confirm-password'))).toHaveLength(1);
        expect(sent.filter((c) => c === 'DELETE /api/v1/domains/a.com')).toHaveLength(2);
        expect(sent.filter((c) => c === 'DELETE /api/v1/domains/b.com')).toHaveLength(2);
    });

    it('sits above an open drawer: Escape cancels only the step-up', async () => {
        fetchMock.mockResolvedValueOnce(stepUp());
        function OpenDrawer() {
            const [open, setOpen] = useState(true);
            return (
                <Drawer open={open} onClose={() => setOpen(false)} title="Edit mailbox">
                    <Opener path="/domains/x.com/mailboxes/anna" onSettled={() => {}} />
                </Drawer>
            );
        }
        const { user } = setup(ME, <OpenDrawer />);

        const inDrawer = screen.getAllByRole('button', { name: 'Delete it' }).at(-1)!;
        await user.click(inDrawer);
        await screen.findByRole('dialog', { name: "Confirm it's you" });
        expect(screen.getByLabelText('Password')).toHaveFocus();

        await user.keyboard('{Escape}');
        await waitFor(() => expect(screen.queryByRole('dialog', { name: "Confirm it's you" })).not.toBeInTheDocument());
        expect(screen.getByRole('dialog', { name: 'Edit mailbox' })).toBeInTheDocument();
        expect(inDrawer).toHaveFocus();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        fetchMock.mockResolvedValueOnce(stepUp());
        const { user } = setup(ME_2FA);

        await user.click(screen.getByRole('button', { name: 'Delete it' }));
        expect(await screen.findByRole('dialog', { name: 'Vahvista henkilöllisyytesi' })).toBeInTheDocument();
        expect(screen.getByLabelText('Kaksivaiheisen tunnistautumisen koodi')).toBeInTheDocument();
        expect(screen.getByText('Kukin koodi toimii vain kerran. Jos käytit juuri koodin, odota seuraavaa.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Käytä salasanaa' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Peruuta' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Vahvista' })).toBeInTheDocument();
    });
});
