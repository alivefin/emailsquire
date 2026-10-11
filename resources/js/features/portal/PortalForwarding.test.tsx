import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';
import { PortalForwarding } from './PortalForwarding';
import type { PortalForwarding as Forwarding, PortalMe } from './usePortal';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const routes = [{ path: '/my/forwarding', element: <PortalForwarding /> }];

type Put = (json: { targets: string[]; keep_copy: boolean }) => unknown;

/** The portal with `stored` as the forwarding; a PUT answers through `put` (default: echoes the body). */
function renderForwarding(stored: Forwarding, me: Partial<PortalMe> = {}, put: Put = (json) => json) {
    let current = stored;
    vi.mocked(apiFetch).mockImplementation(
        portalApi(
            'mailbox',
            {
                '/portal/forwarding': (init: ApiInit | undefined) => {
                    if (init?.method !== 'PUT') return current;
                    const answer = put(init.json as { targets: string[]; keep_copy: boolean });
                    current = answer as Forwarding;
                    return answer;
                },
            },
            { ...PORTAL_ME, ...me },
        ),
    );
    return renderRoutes(routes, '/my/forwarding');
}

function puts(): unknown[] {
    return vi
        .mocked(apiFetch)
        .mock.calls.filter(([path, init]) => path === '/portal/forwarding' && init?.method === 'PUT')
        .map(([, init]) => init?.json);
}

function meCalls(): number {
    return vi.mocked(apiFetch).mock.calls.filter(([path]) => path === '/portal/me').length;
}

const field = () => screen.getByLabelText('Forward my mail to');
const save = () => screen.getByRole('button', { name: 'Save forwarding' });

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

describe('PortalForwarding', () => {
    it('shows the targets as chips and the copy choice', async () => {
        renderForwarding({ targets: ['me@elsewhere.example', 'boss@example.org'], keep_copy: true });

        expect(await screen.findByRole('heading', { level: 1, name: 'Forwarding' })).toBeInTheDocument();
        expect(await screen.findByRole('button', { name: 'Remove me@elsewhere.example' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove boss@example.org' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Keep a copy in my mailbox' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'Forward only' })).not.toBeChecked();
        expect(screen.queryByText('A wrong address loses your mail.')).not.toBeInTheDocument();
        // Nothing changed yet.
        expect(save()).toBeDisabled();
    });

    it('adds a target and saves it with the copy choice, then refreshes the summary', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false });

        await user.type(await screen.findByLabelText('Forward my mail to'), 'me@elsewhere.example{Enter}');
        // No forwarding yet: a copy is kept unless the owner says otherwise.
        expect(screen.getByRole('radio', { name: 'Keep a copy in my mailbox' })).toBeChecked();
        const before = meCalls();
        await user.click(save());

        await waitFor(() => expect(puts()).toEqual([{ targets: ['me@elsewhere.example'], keep_copy: true }]));
        expect(await screen.findByText('Forwarding saved.')).toBeInTheDocument();
        await waitFor(() => expect(meCalls()).toBeGreaterThan(before));
        expect(save()).toBeDisabled();
    });

    it('warns that a wrong address loses mail when nothing is kept, and saves forward-only', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: ['me@elsewhere.example'], keep_copy: true });

        await user.click(await screen.findByRole('radio', { name: 'Forward only' }));
        expect(screen.getByText('A wrong address loses your mail.')).toBeInTheDocument();
        await user.click(save());
        await waitFor(() => expect(puts()).toEqual([{ targets: ['me@elsewhere.example'], keep_copy: false }]));
    });

    it('shows the forward-only warning only while there is an address to lose mail to', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false });

        await user.click(await screen.findByRole('radio', { name: 'Forward only' }));
        expect(screen.queryByText('A wrong address loses your mail.')).not.toBeInTheDocument();

        await user.type(field(), 'typed@example.org');
        expect(screen.getByText('A wrong address loses your mail.')).toBeInTheDocument();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('button', { name: 'Remove typed@example.org' })).toBeInTheDocument();
        expect(screen.getByText('A wrong address loses your mail.')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Remove typed@example.org' }));
        expect(screen.queryByText('A wrong address loses your mail.')).not.toBeInTheDocument();
    });

    it('includes an address still being typed when saving', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false });

        await user.type(await screen.findByLabelText('Forward my mail to'), 'typed@example.org');
        await user.click(save());
        await waitFor(() => expect(puts()).toEqual([{ targets: ['typed@example.org'], keep_copy: true }]));
    });

    it('turns forwarding off when saved with no targets', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: ['me@elsewhere.example'], keep_copy: true });

        await user.click(await screen.findByRole('button', { name: 'Remove me@elsewhere.example' }));
        expect(screen.getByText('Saving with no addresses turns forwarding off.')).toBeInTheDocument();
        await user.click(save());

        await waitFor(() => expect(puts()).toEqual([{ targets: [], keep_copy: true }]));
        expect(await screen.findByText('Forwarding turned off.')).toBeInTheDocument();
    });

    it('stops at 10 addresses', async () => {
        const user = userEvent.setup();
        const ten = Array.from({ length: 10 }, (_, i) => `t${i}@example.org`);
        renderForwarding({ targets: ten, keep_copy: true });

        await user.type(await screen.findByLabelText('Forward my mail to'), 'eleven@example.org{Enter}');
        expect(screen.getByText('You can forward to at most 10 addresses.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove eleven@example.org' })).not.toBeInTheDocument();
    });

    it('refuses an incomplete address and the mailbox itself', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false });

        await user.type(await screen.findByLabelText('Forward my mail to'), 'nobody{Enter}');
        expect(screen.getByText('“nobody” is not a complete email address.')).toBeInTheDocument();
        await user.clear(field());
        await user.type(field(), 'anna@example.org{Enter}');
        expect(screen.getByText('That is your own address. Choose “Keep a copy in my mailbox” instead.')).toBeInTheDocument();
    });

    it('says only addresses on this server work while external forwarding is off', async () => {
        renderForwarding({ targets: [], keep_copy: false }, { self_service: { forwarding: true, aliases: false, external_forwarding: false } });
        expect(await screen.findByText('Up to 10 addresses on this mail server. Press Enter after each one.')).toBeInTheDocument();
    });

    it('allows any address while external forwarding is on', async () => {
        renderForwarding({ targets: [], keep_copy: false }, { self_service: { forwarding: true, aliases: false, external_forwarding: true } });
        expect(await screen.findByText('Up to 10 addresses. Press Enter after each one.')).toBeInTheDocument();
    });

    it("puts the server's external-forwarding refusal on the chip it names", async () => {
        const user = userEvent.setup();
        const message = 'Forwarding to addresses outside this server is turned off. Remove me@gmail.example.';
        renderForwarding({ targets: ['boss@example.org'], keep_copy: true }, {}, () => {
            throw new ApiProblem({
                status: 422,
                title: 'The given data was invalid.',
                code: 'external_forwarding_disabled',
                detail: message,
                errors: { 'targets.1': [message] },
            });
        });

        await user.type(await screen.findByLabelText('Forward my mail to'), 'me@gmail.example{Enter}');
        await user.click(save());

        expect(await screen.findByText(message)).toBeInTheDocument();
        const chip = screen.getByText('me@gmail.example').closest('span[title]');
        expect(chip).toHaveAttribute('title', 'Not accepted');
        expect(screen.getByText('boss@example.org').closest('span[title]')).not.toHaveAttribute('title', 'Not accepted');
        expect(field()).toHaveAttribute('aria-invalid', 'true');

        // Removing the refused address clears the error.
        await user.click(screen.getByRole('button', { name: 'Remove me@gmail.example' }));
        expect(screen.queryByText(message)).not.toBeInTheDocument();
    });

    it("shows the server's too_many_targets under the field", async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false }, {}, () => {
            throw new ApiProblem({
                status: 422,
                title: 'The given data was invalid.',
                code: 'too_many_targets',
                detail: 'You can forward to at most 10 addresses.',
                errors: { targets: ['You can forward to at most 10 addresses.'] },
            });
        });
        await user.type(await screen.findByLabelText('Forward my mail to'), 'a@example.org{Enter}');
        await user.click(save());
        expect(await screen.findByText('You can forward to at most 10 addresses.')).toBeInTheDocument();
    });

    it('shows a rate limit under the form', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false }, {}, () => {
            throw new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' });
        });
        await user.type(await screen.findByLabelText('Forward my mail to'), 'a@example.org{Enter}');
        await user.click(save());
        expect(await screen.findByText('Too many attempts. Wait a minute and try again.')).toBeInTheDocument();
    });

    it('rereads the switches when the server says forwarding was turned off meanwhile', async () => {
        const user = userEvent.setup();
        renderForwarding({ targets: [], keep_copy: false }, {}, () => {
            throw new ApiProblem({
                status: 403,
                title: 'Forbidden',
                code: 'self_service_disabled',
                detail: 'Your administrator has turned this off.',
            });
        });
        await user.type(await screen.findByLabelText('Forward my mail to'), 'a@example.org{Enter}');
        const before = meCalls();
        await user.click(save());
        expect(await screen.findByText('Your administrator has turned this off.')).toBeInTheDocument();
        await waitFor(() => expect(meCalls()).toBeGreaterThan(before));
    });

    it('is a read-only card with the locked note when the domain does not allow it', async () => {
        renderForwarding(
            { targets: ['me@elsewhere.example'], keep_copy: false },
            { self_service: { forwarding: false, aliases: false, external_forwarding: false } },
        );

        expect(await screen.findByText('me@elsewhere.example')).toBeInTheDocument();
        expect(screen.getByText('Your administrator has turned this off.')).toBeInTheDocument();
        expect(screen.getByText('No copy stays in your mailbox.')).toBeInTheDocument();
        expect(screen.queryByLabelText('Forward my mail to')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save forwarding' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Remove/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        const user = userEvent.setup();
        renderForwarding({ targets: ['me@elsewhere.example'], keep_copy: true });

        expect(await screen.findByRole('heading', { level: 1, name: 'Edelleenlähetys' })).toBeInTheDocument();
        expect(await screen.findByLabelText('Lähetä postini edelleen osoitteisiin')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Säilytä kopio postilaatikossani' })).toBeChecked();
        await user.click(screen.getByRole('radio', { name: 'Lähetä vain edelleen' }));
        expect(screen.getByText('Jos osoite on väärä, postisi katoaa.')).toBeInTheDocument();
        const form = screen.getByRole('button', { name: 'Tallenna edelleenlähetys' }).closest('form');
        expect(form).not.toBeNull();
        expect(within(form as HTMLElement).getByText('Enintään 10 osoitetta tällä postipalvelimella. Paina Enter jokaisen jälkeen.')).toBeInTheDocument();
    });
});
