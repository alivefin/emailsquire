import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Outlet } from 'react-router';
import { queryKeys } from '../../api/types';
import { StepUpDialog } from '../../components/StepUpDialog';
import { currentLocale, setLocale } from '../../i18n';
import { RequireAuth } from '../../layout/RequireAuth';
import { renderRoutes, testQueryClient } from '../../test/routing';
import { ProfilePage } from './ProfilePage';

const ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    locale: 'en' as const,
    two_factor: { enabled: false, confirmed: false, recovery_codes_left: 0 },
    two_factor_required: false,
};
const ME_ON = { ...ME, two_factor: { enabled: true, confirmed: true, recovery_codes_left: 8 } };
const SECRET = 'JBSWY3DPEHPK3PXP';
const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
const CODES = [
    'aaaaaaaaaa-1111111111',
    'bbbbbbbbbb-2222222222',
    'cccccccccc-3333333333',
    'dddddddddd-4444444444',
    'eeeeeeeeee-5555555555',
    'ffffffffff-6666666666',
    'gggggggggg-7777777777',
    'hhhhhhhhhh-8888888888',
];

const json = (body: unknown, status = 200) => () =>
    new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const problem = (status: number, body: Record<string, unknown> = {}) => () =>
    new Response(JSON.stringify({ type: 'about:blank', title: 'T', status, ...body }), {
        status,
        headers: { 'Content-Type': 'application/problem+json' },
    });
const noContent = () => () => new Response(null, { status: 204 });
const stepUp = problem(423, { code: 'step_up_required' });

type Reply = () => Response;
let replies: Map<string, Reply[]>;
let fetchMock: ReturnType<typeof vi.fn>;

/** Queue replies for `METHOD /path`; the last one repeats. */
function on(key: string, ...queue: Reply[]) {
    replies.set(key, [...(replies.get(key) ?? []), ...queue]);
}

const calls = () =>
    fetchMock.mock.calls.map((c) => {
        const init = c[1] as RequestInit;
        return `${init.method} ${(c[0] as string).replace('/api/v1', '')}${init.body ? ` ${init.body as string}` : ''}`;
    });

beforeEach(() => {
    replies = new Map();
    fetchMock = vi.fn((url: string, init: RequestInit) => {
        const key = `${init.method} ${url.replace('/api/v1', '')}`;
        const queue = replies.get(key);
        if (!queue || queue.length === 0) return Promise.reject(new Error(`unexpected ${key}`));
        const next = queue.length > 1 ? queue.shift()! : queue[0]!;
        return Promise.resolve(next());
    });
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=t; path=/';
});
afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const routes = [
    {
        element: (
            <>
                <Outlet />
                <StepUpDialog />
            </>
        ),
        children: [{ path: '/profile', element: <ProfilePage /> }],
    },
];

function setup(me: unknown = ME, path = '/profile') {
    const client = testQueryClient();
    client.setQueryData(queryKeys.me, me);
    const user = userEvent.setup();
    const utils = renderRoutes(routes, path, client);
    return { ...utils, user, client };
}

async function confirmWithPassword(user: ReturnType<typeof userEvent.setup>) {
    const dialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
    await user.type(within(dialog).getByLabelText('Password'), 'summersun-long');
    await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));
}

function cacheText(client: ReturnType<typeof testQueryClient>): string {
    const queries = client.getQueryCache().getAll().map((q) => q.state);
    const mutations = client.getMutationCache().getAll().map((m) => m.state);
    return JSON.stringify([queries, mutations]);
}

describe('ProfilePage', () => {
    it('shows the name, the read-only email and the language', () => {
        setup();
        expect(screen.getByRole('heading', { name: 'Profile' })).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('Anna Admin');
        expect(screen.getByLabelText('Email')).toHaveValue('anna@example.org');
        expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('Language')).toHaveValue('en');
    });

    it('saves the name with PATCH /me and updates the cached me', async () => {
        const { user, client } = setup();
        on('PATCH /me', json({ ...ME, name: 'Anna B' }));
        const name = screen.getByLabelText('Name');
        await user.clear(name);
        await user.type(name, '  Anna B ');
        await user.click(screen.getByRole('button', { name: 'Save name' }));

        expect(await screen.findByText('Name saved.')).toBeInTheDocument();
        expect(calls()).toContain('PATCH /me {"name":"Anna B"}');
        expect(client.getQueryData(queryKeys.me)).toMatchObject({ name: 'Anna B' });
    });

    it('saves the language with PATCH /me and switches the interface', async () => {
        const { user, client } = setup();
        on('PATCH /me', json({ ...ME, locale: 'fi' }));
        await user.selectOptions(screen.getByLabelText('Language'), 'fi');

        await waitFor(() => expect(currentLocale()).toBe('fi'));
        expect(calls()).toContain('PATCH /me {"locale":"fi"}');
        expect(client.getQueryData(queryKeys.me)).toMatchObject({ locale: 'fi' });
        expect(await screen.findByRole('heading', { name: 'Profiili' })).toBeInTheDocument();
    });

    it('shows the server 422 under Current password, linked to the field', async () => {
        const { user } = setup();
        on(
            'PUT /me/password',
            problem(422, { errors: { current_password: ['The current password is wrong.'] } }),
        );
        await user.type(screen.getByLabelText('Current password'), 'not-my-password');
        await user.type(screen.getByLabelText('New password'), 'Correct-Horse-Battery-9');
        await user.type(screen.getByLabelText('Confirm new password'), 'Correct-Horse-Battery-9');
        await user.click(screen.getByRole('button', { name: 'Change password' }));

        const current = screen.getByLabelText('Current password');
        await waitFor(() => expect(current).toHaveAttribute('aria-invalid', 'true'));
        const msgId = current.getAttribute('aria-describedby') ?? '';
        expect(document.getElementById(msgId)).toHaveTextContent('The current password is wrong.');
        expect(calls()).toContain(
            'PUT /me/password {"current_password":"not-my-password","password":"Correct-Horse-Battery-9","password_confirmation":"Correct-Horse-Battery-9"}',
        );
    });

    it('changes the password, clears the fields and toasts', async () => {
        const { user } = setup();
        on('PUT /me/password', noContent());
        await user.type(screen.getByLabelText('Current password'), 'old-password-123');
        await user.type(screen.getByLabelText('New password'), 'Correct-Horse-Battery-9');
        await user.type(screen.getByLabelText('Confirm new password'), 'Correct-Horse-Battery-9');
        await user.click(screen.getByRole('button', { name: 'Change password' }));

        expect(await screen.findByText('Password changed. Your other sessions were signed out.')).toBeInTheDocument();
        expect(screen.getByLabelText('Current password')).toHaveValue('');
        expect(screen.getByLabelText('New password')).toHaveValue('');
    });

    it('"Sign out everywhere" asks first, then posts and toasts', async () => {
        const { user } = setup();
        on('POST /auth/logout-all', noContent());
        await user.click(screen.getByRole('button', { name: 'Sign out everywhere' }));
        const dialog = await screen.findByRole('dialog', { name: 'Sign out everywhere?' });
        expect(calls()).not.toContain('POST /auth/logout-all');
        await user.click(within(dialog).getByRole('button', { name: 'Sign out everywhere' }));

        expect(await screen.findByText('Signed out everywhere else. This browser stays signed in.')).toBeInTheDocument();
        expect(calls()).toContain('POST /auth/logout-all');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('shows the required-2FA banner only while setup is needed', () => {
        setup({ ...ME, two_factor_required: true });
        expect(screen.getByText('Set up two-factor sign-in to continue.')).toBeInTheDocument();
    });

    it('shows no banner once two-factor is confirmed, or when it is not required', () => {
        setup({ ...ME_ON, two_factor_required: true });
        expect(screen.queryByText('Set up two-factor sign-in to continue.')).not.toBeInTheDocument();
    });

    it('focuses the two-factor section for #two-factor', async () => {
        setup({ ...ME, two_factor_required: true }, '/profile#two-factor');
        const section = screen.getByRole('region', { name: 'Two-factor authentication' });
        expect(section).toHaveAttribute('id', 'two-factor');
        await waitFor(() => expect(section).toHaveFocus());
    });

    it('enrols: step-up, setup with a data-URI QR image, confirm, then the codes once', async () => {
        const { user, client } = setup({ ...ME, two_factor_required: true });
        on('POST /me/two-factor', stepUp, noContent());
        on('POST /auth/confirm-password', noContent());
        on('GET /me/two-factor/setup', json({ qr_svg_base64: btoa(SVG), secret: SECRET }));
        on('POST /me/two-factor/confirm', problem(422, { errors: { code: ['The code is wrong or expired.'] } }), json({ recovery_codes: CODES }));
        on('GET /me', json({ ...ME_ON, two_factor_required: true }));

        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        await confirmWithPassword(user);

        const qr = await screen.findByRole('img', { name: 'QR code for your authenticator app' });
        expect(qr.getAttribute('src')).toBe(`data:image/svg+xml;base64,${btoa(SVG)}`);
        expect(screen.getByText(SECRET)).toBeInTheDocument();
        expect(document.querySelector('svg rect')).toBeNull();

        const code = screen.getByLabelText('Code from the app');
        expect(code).toHaveAttribute('inputmode', 'numeric');
        expect(code).toHaveAttribute('autocomplete', 'one-time-code');
        await user.type(code, '123 456');
        await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }));
        await waitFor(() => expect(code).toHaveAttribute('aria-invalid', 'true'));
        expect(document.getElementById(code.getAttribute('aria-describedby') ?? '')).toHaveTextContent('The code is wrong or expired.');

        await user.clear(code);
        await user.type(code, '654321');
        await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }));

        const list = await screen.findByRole('list', { name: 'Recovery codes' });
        expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual(CODES);
        expect(screen.getByRole('heading', { name: 'Save your recovery codes' })).toHaveFocus();
        expect(calls()).toEqual(
            expect.arrayContaining([
                'POST /me/two-factor',
                'POST /auth/confirm-password {"password":"summersun-long"}',
                'GET /me/two-factor/setup',
                'POST /me/two-factor/confirm {"code":"123456"}',
                'POST /me/two-factor/confirm {"code":"654321"}',
                'GET /me',
            ]),
        );
        await waitFor(() => expect(screen.queryByText('Set up two-factor sign-in to continue.')).not.toBeInTheDocument());
        // The codes stay on screen although me now says two-factor is on.
        expect(screen.getByRole('list', { name: 'Recovery codes' })).toBeInTheDocument();
        expect(cacheText(client)).not.toContain(CODES[0]);
        expect(cacheText(client)).not.toContain(SECRET);
    });

    it('leaves no code or secret in the page or either cache after Done', async () => {
        const { user, client } = setup();
        on('POST /me/two-factor', noContent());
        on('GET /me/two-factor/setup', json({ qr_svg_base64: btoa(SVG), secret: SECRET }));
        on('POST /me/two-factor/confirm', json({ recovery_codes: CODES }));
        on('GET /me', json(ME_ON));

        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        await user.type(await screen.findByLabelText('Code from the app'), '654321');
        await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }));
        await screen.findByRole('list', { name: 'Recovery codes' });
        await user.click(screen.getByRole('button', { name: 'Done' }));

        expect(await screen.findByText('8 recovery codes left')).toBeInTheDocument();
        for (const c of CODES) expect(document.body.textContent).not.toContain(c);
        expect(document.body.innerHTML).not.toContain(SECRET);
        expect(document.body.innerHTML).not.toContain(btoa(SVG));
        const cached = cacheText(client);
        for (const c of CODES) expect(cached).not.toContain(c);
        expect(cached).not.toContain(SECRET);
        expect(screen.getByRole('region', { name: 'Two-factor authentication' })).toHaveFocus();
    });

    it('treats a cancelled step-up as a quiet cancel', async () => {
        const { user } = setup();
        on('POST /me/two-factor', stepUp);
        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        const dialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByTestId('toast')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Turn on' })).toBeEnabled();
    });

    it('shows an error when the retry after a confirmed step-up still answers 423', async () => {
        const { user } = setup();
        on('POST /me/two-factor', stepUp);
        on('POST /auth/confirm-password', noContent());
        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        await confirmWithPassword(user);

        expect(await screen.findByText("Confirm it's you to continue.")).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Turn on' })).toBeEnabled();
    });

    it('shows the codes left, and warns at 2 or fewer', () => {
        setup({ ...ME_ON, two_factor: { ...ME_ON.two_factor, recovery_codes_left: 2 } });
        expect(screen.getByText('2 recovery codes left')).toBeInTheDocument();
        expect(screen.getByText(/You are running out of recovery codes/)).toBeInTheDocument();
    });

    it('does not warn with 3 codes left', () => {
        setup({ ...ME_ON, two_factor: { ...ME_ON.two_factor, recovery_codes_left: 3 } });
        expect(screen.queryByText(/You are running out of recovery codes/)).not.toBeInTheDocument();
    });

    it('"Show recovery codes" goes through step-up and shows them', async () => {
        const { user, client } = setup(ME_ON);
        on('GET /me/recovery-codes', stepUp, json({ recovery_codes: CODES.slice(0, 5) }));
        on('POST /auth/confirm-password', noContent());
        await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
        const dialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
        // A user with two-factor confirms with a code by default.
        await user.type(within(dialog).getByLabelText('Two-factor code'), '111222');
        await user.click(within(dialog).getByRole('button', { name: 'Confirm' }));

        const list = await screen.findByRole('list', { name: 'Recovery codes' });
        expect(within(list).getAllByRole('listitem')).toHaveLength(5);
        expect(cacheText(client)).not.toContain(CODES[0]);
    });

    it('"Regenerate" asks first, goes through step-up and shows the new codes', async () => {
        const { user } = setup(ME_ON);
        on('POST /me/recovery-codes', stepUp, json({ recovery_codes: CODES }));
        on('POST /auth/confirm-password', noContent());
        on('GET /me', json(ME_ON));
        await user.click(screen.getByRole('button', { name: 'Regenerate' }));
        const confirm = await screen.findByRole('dialog', { name: 'Create new recovery codes?' });
        await user.click(within(confirm).getByRole('button', { name: 'Create new codes' }));
        const stepUpDialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.type(within(stepUpDialog).getByLabelText('Two-factor code'), '111222');
        await user.click(within(stepUpDialog).getByRole('button', { name: 'Confirm' }));

        const list = await screen.findByRole('list', { name: 'Recovery codes' });
        expect(within(list).getAllByRole('listitem')).toHaveLength(8);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(calls().filter((c) => c === 'POST /me/recovery-codes')).toHaveLength(2);
    });

    it('turning off goes through the confirm dialog and step-up, then refreshes me', async () => {
        const { user, client } = setup(ME_ON);
        on('DELETE /me/two-factor', stepUp, noContent());
        on('POST /auth/confirm-password', noContent());
        on('GET /me', json(ME));
        await user.click(screen.getByRole('button', { name: 'Turn off' }));
        const confirm = await screen.findByRole('dialog', { name: 'Turn off two-factor authentication?' });
        expect(calls()).not.toContain('DELETE /me/two-factor');
        await user.click(within(confirm).getByRole('button', { name: 'Turn off' }));
        const stepUpDialog = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.click(within(stepUpDialog).getByRole('button', { name: 'Use password instead' }));
        await user.type(within(stepUpDialog).getByLabelText('Password'), 'summersun-long');
        await user.click(within(stepUpDialog).getByRole('button', { name: 'Confirm' }));

        expect(await screen.findByText('Two-factor authentication turned off.')).toBeInTheDocument();
        expect(calls().filter((c) => c === 'DELETE /me/two-factor')).toHaveLength(2);
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toMatchObject({ two_factor: { confirmed: false } }));
        expect(await screen.findByRole('button', { name: 'Turn on' })).toBeInTheDocument();
    });

    it('cancelling setup drops the secret and the QR code locally, with no step-up', async () => {
        const { user } = setup();
        on('POST /me/two-factor', noContent());
        on('GET /me/two-factor/setup', json({ qr_svg_base64: btoa(SVG), secret: SECRET }));
        on('GET /me', json(ME));
        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        await screen.findByText(SECRET);
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(document.body.innerHTML).not.toContain(SECRET);
        expect(document.body.innerHTML).not.toContain(btoa(SVG));
        expect(screen.getByRole('button', { name: 'Turn on' })).toBeEnabled();
        expect(screen.getByRole('region', { name: 'Two-factor authentication' })).toHaveFocus();
        await waitFor(() => expect(calls()).toContain('GET /me'));
        expect(calls().filter((c) => c.startsWith('DELETE'))).toEqual([]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps the shown codes on screen when the me refresh after them fails', async () => {
        const client = testQueryClient();
        client.setQueryData(queryKeys.me, ME);
        const user = userEvent.setup();
        renderRoutes(
            [{ element: <RequireAuth />, children: routes }],
            '/profile',
            client,
        );
        on('POST /me/two-factor', noContent());
        on('GET /me/two-factor/setup', json({ qr_svg_base64: btoa(SVG), secret: SECRET }));
        on('POST /me/two-factor/confirm', json({ recovery_codes: CODES }));
        on('GET /me', problem(500, { title: 'Server Error' }));

        await user.click(screen.getByRole('button', { name: 'Turn on' }));
        await user.type(await screen.findByLabelText('Code from the app'), '654321');
        await user.click(screen.getByRole('button', { name: 'Confirm and turn on' }));
        await screen.findByRole('list', { name: 'Recovery codes' });

        await waitFor(() => expect(client.getQueryState(queryKeys.me)?.status).toBe('error'));
        expect(screen.getByRole('list', { name: 'Recovery codes' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        setup({ ...ME, locale: 'fi', two_factor_required: true });
        expect(screen.getByRole('heading', { name: 'Profiili' })).toBeInTheDocument();
        expect(screen.getByLabelText('Nimi')).toHaveValue('Anna Admin');
        expect(screen.getByLabelText('Kieli')).toHaveValue('fi');
        expect(screen.getByRole('heading', { name: 'Salasana' })).toBeInTheDocument();
        expect(screen.getByLabelText('Nykyinen salasana')).toBeInTheDocument();
        expect(screen.getByRole('region', { name: 'Kaksivaiheinen tunnistautuminen' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Ota käyttöön' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Kirjaudu ulos kaikkialta' })).toBeInTheDocument();
        expect(screen.getByText('Ota kaksivaiheinen tunnistautuminen käyttöön jatkaaksesi.')).toBeInTheDocument();
    });
});
