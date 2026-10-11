import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Outlet } from 'react-router';
import { queryKeys } from '../../api/types';
import { StepUpDialog } from '../../components/StepUpDialog';
import { setLocale } from '../../i18n';
import { renderRoutes, testQueryClient } from '../../test/routing';
import { ATTESTATION_JSON, CREATION_OPTIONS, mockWebAuthn } from '../../test/webauthn';
import { ProfilePage } from './ProfilePage';

const ME = {
    id: 1, name: 'Anna', email: 'anna@example.org', is_server_admin: true, locale: 'en', second_factor: false,
    passkeys: 0, two_factor: { enabled: false, confirmed: false, recovery_codes_left: 0 }, two_factor_required: true,
};
const PASSKEY = { id: 7, name: 'My phone', created_at: '2026-10-10T09:00:00Z', last_used_at: null, disabled: false };
const CODES = ['aaaaaaaaaa-1111111111', 'bbbbbbbbbb-2222222222'];
const reply = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const problem = (status: number, code: string, errors?: Record<string, string[]>) => reply({ status, title: 'T', code, errors }, status);
let fetchMock: ReturnType<typeof vi.fn>;
let credentials: ReturnType<typeof mockWebAuthn>;
let passkeys: (Omit<typeof PASSKEY, 'last_used_at'> & { last_used_at: string | null })[];
let addCodes: boolean;
let removalError: boolean;
let optionsStepUp: boolean;
let deleteStepUp: boolean;
let failure: Response | undefined;
beforeEach(() => {
    credentials = mockWebAuthn(); passkeys = []; addCodes = true; removalError = false; optionsStepUp = false; deleteStepUp = false; failure = undefined;
    fetchMock = vi.fn((url: string, init: RequestInit) => {
        const path = url.replace('/api/v1', '');
        if (path === '/me/passkeys/options') {
            if (optionsStepUp) { optionsStepUp = false; return Promise.resolve(problem(423, 'step_up_required')); }
            return Promise.resolve(failure?.clone() ?? reply(CREATION_OPTIONS));
        }
        if (path === '/auth/confirm-password') return Promise.resolve(new Response(null, { status: 204 }));
        if (path === '/me/passkeys' && init.method === 'GET') return Promise.resolve(reply({ data: passkeys }));
        if (path === '/me/passkeys' && init.method === 'POST') {
            const data = JSON.parse(init.body as string) as { name: string };
            const passkey = { ...PASSKEY, id: 7 + passkeys.length, name: data.name };
            passkeys = [...passkeys, passkey];
            return Promise.resolve(reply({ passkey, ...(addCodes ? { recovery_codes: CODES } : {}) }, 201));
        }
        if (path === '/me/passkeys/7' && init.method === 'PATCH') {
            const data = JSON.parse(init.body as string) as { name: string }; passkeys = [{ ...PASSKEY, name: data.name }];
            return Promise.resolve(reply(passkeys[0]));
        }
        if (path === '/me/passkeys/7' && init.method === 'DELETE') {
            if (deleteStepUp) { deleteStepUp = false; return Promise.resolve(problem(423, 'step_up_required')); }
            if (removalError) return Promise.resolve(problem(409, 'last_second_factor'));
            passkeys = []; return Promise.resolve(new Response(null, { status: 204 }));
        }
        if (path === '/me') return Promise.resolve(reply({ ...ME, second_factor: passkeys.length > 0, passkeys: passkeys.length,
            two_factor: { ...ME.two_factor, recovery_codes_left: passkeys.length ? 8 : 0 } }));
        if (path === '/me/recovery-codes') return Promise.resolve(reply({ recovery_codes: CODES }));
        return Promise.reject(new Error(`unexpected ${init.method} ${path}`));
    });
    vi.stubGlobal('fetch', fetchMock); document.cookie = 'XSRF-TOKEN=t; path=/';
});
afterEach(() => vi.unstubAllGlobals());
function setup(me: unknown = ME) {
    const client = testQueryClient(); client.setQueryData(queryKeys.me, me);
    return { ...renderRoutes([{ element: <><Outlet /><StepUpDialog /></>, children: [{ path: '/profile', element: <ProfilePage /> }] }], '/profile', client), user: userEvent.setup() };
}
function cacheText(client: ReturnType<typeof testQueryClient>) {
    return JSON.stringify([client.getQueryCache().getAll().map((q) => q.state), client.getMutationCache().getAll().map((m) => m.state)]);
}
async function openAdd(user: ReturnType<typeof userEvent.setup>, name = 'My phone') {
    await user.click(await screen.findByRole('button', { name: 'Add a passkey' }));
    const dialog = screen.getByRole('dialog', { name: 'Add a passkey' });
    await user.type(within(dialog).getByLabelText('Passkey name'), name);
    return within(dialog).getByRole('button', { name: 'Add a passkey' });
}
describe('profile passkeys', () => {
    it('adds through step-up and shows first-factor recovery codes only in component state', async () => {
        optionsStepUp = true;
        const { user, client } = setup(); await user.click(await openAdd(user));
        const step = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.type(within(step).getByLabelText('Password'), 'summersun-long');
        await user.click(within(step).getByRole('button', { name: 'Confirm' }));
        expect(await screen.findByRole('heading', { name: 'Save your recovery codes' })).toHaveFocus();
        expect(screen.getByText(CODES[0]!)).toBeInTheDocument();
        const add = fetchMock.mock.calls.find(([url, init]) => url === '/api/v1/me/passkeys' && (init as RequestInit).method === 'POST')!;
        expect(JSON.parse((add[1] as RequestInit).body as string)).toEqual({ name: 'My phone', credential: ATTESTATION_JSON });
        expect(cacheText(client)).not.toContain(CODES[0]);
        for (const toast of screen.queryAllByRole('status')) expect(toast).not.toHaveTextContent(CODES[0]!);
        expect(sessionStorage.length).toBe(0);
        expect(location.href).not.toContain(CODES[0]);
        await user.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.queryByText(CODES[0]!)).not.toBeInTheDocument();
        expect(await screen.findByText('My phone')).toBeInTheDocument();
        expect(cacheText(client)).not.toContain(CODES[0]);
        addCodes = false;
        await user.click(await openAdd(user, 'Laptop'));
        expect(await screen.findByText('Laptop')).toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Recovery codes' })).not.toBeInTheDocument();
        expect(cacheText(client)).not.toContain(CODES[0]);
    });
    it('lists created, last-used and disabled metadata with plain text names', async () => {
        passkeys = [{ ...PASSKEY, name: '<b>My phone</b>', disabled: true, last_used_at: '2026-10-11T09:00:00Z' }];
        setup();
        const row = await screen.findByRole('listitem');
        expect(row).toHaveTextContent('<b>My phone</b>'); expect(row.querySelector('b')).toBeNull();
        expect(row).toHaveTextContent('Created'); expect(row).toHaveTextContent('Last used'); expect(row).toHaveTextContent('Disabled');
    });
    it('renames using PATCH without another credential prompt', async () => {
        passkeys = [PASSKEY]; const { user } = setup();
        await user.click(await screen.findByRole('button', { name: 'Rename My phone' }));
        const dialog = screen.getByRole('dialog', { name: 'Rename passkey' });
        const name = within(dialog).getByLabelText('Passkey name'); await user.clear(name); await user.type(name, 'Tablet');
        await user.click(within(dialog).getByRole('button', { name: 'Save name' }));
        expect(await screen.findByText('Tablet')).toBeInTheDocument();
        expect(credentials.create).not.toHaveBeenCalled(); expect(credentials.get).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledWith('/api/v1/me/passkeys/7', expect.objectContaining({ method: 'PATCH', body: '{"name":"Tablet"}' }));
    });
    it('removes after confirmation and refreshes the list and me', async () => {
        passkeys = [PASSKEY]; const { user, client } = setup({ ...ME, passkeys: 1, second_factor: true });
        await user.click(await screen.findByRole('button', { name: 'Remove My phone' }));
        const dialog = screen.getByRole('dialog', { name: 'Remove passkey?' });
        expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit).method === 'DELETE')).toBe(false);
        await user.click(within(dialog).getByRole('button', { name: 'Remove passkey' }));
        expect(await screen.findByText('You have no passkeys yet.')).toBeInTheDocument();
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toMatchObject({ passkeys: 0, second_factor: false }));
    });
    it('shows last_second_factor and keeps the key and remove dialog', async () => {
        passkeys = [PASSKEY]; removalError = true; const { user } = setup({ ...ME, passkeys: 1, second_factor: true });
        await user.click(await screen.findByRole('button', { name: 'Remove My phone' }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove passkey' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('last');
        expect(screen.getByRole('dialog')).toBeInTheDocument(); expect(passkeys).toHaveLength(1);
    });
    it('requires step-up before removing a passkey and retries DELETE once', async () => {
        passkeys = [PASSKEY]; deleteStepUp = true; const { user } = setup();
        await user.click(await screen.findByRole('button', { name: 'Remove My phone' }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove passkey' }));
        const step = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.type(within(step).getByLabelText('Password'), 'summersun-long');
        await user.click(within(step).getByRole('button', { name: 'Confirm' }));
        expect(await screen.findByText('You have no passkeys yet.')).toBeInTheDocument();
        expect(fetchMock.mock.calls.filter(([url, init]) => url === '/api/v1/me/passkeys/7' && (init as RequestInit).method === 'DELETE')).toHaveLength(2);
    });
    it.each(['NotAllowedError', 'AbortError'])('keeps cancelled registration (%s) quiet and the form reusable', async (name) => {
        credentials.create.mockRejectedValueOnce(new DOMException('', name));
        const { user } = setup(); const add = await openAdd(user); await user.click(add);
        await waitFor(() => expect(add).toBeEnabled()); expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Passkey name')).toHaveValue('My phone');
        expect(fetchMock.mock.calls.some(([url, init]) => url === '/api/v1/me/passkeys' && (init as RequestInit).method === 'POST')).toBe(false);
        await user.click(add); expect(await screen.findByText(CODES[0]!)).toBeInTheDocument();
    });
    it('keeps a cancelled step-up quiet and does not create credentials', async () => {
        optionsStepUp = true; const { user } = setup(); await user.click(await openAdd(user));
        const step = await screen.findByRole('dialog', { name: "Confirm it's you" });
        await user.click(within(step).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(within(screen.getByRole('dialog')).getByRole('button', { name: 'Add a passkey' })).toBeEnabled());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument(); expect(credentials.create).not.toHaveBeenCalled();
    });
    it('hides Add when unsupported while keeping rename and remove available', async () => {
        vi.stubGlobal('PublicKeyCredential', undefined); passkeys = [PASSKEY]; setup();
        expect(await screen.findByRole('button', { name: 'Rename My phone' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove My phone' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add a passkey' })).not.toBeInTheDocument();
        expect(screen.getByText('This browser does not support passkeys.')).toBeInTheDocument();
    });
    it.each(['too_many_passkeys', 'passkey_failed', 'too_many_requests'])('shows %s and releases the form', async (code) => {
        failure = problem(code === 'too_many_requests' ? 429 : 422, code, { credential: ['Registration failed.'] });
        const { user } = setup(); const add = await openAdd(user); await user.click(add);
        expect(await screen.findByRole('alert')).toHaveTextContent('Registration failed.'); expect(add).toBeEnabled();
    });
    it('makes recovery codes available later for a passkey-only account', async () => {
        passkeys = [PASSKEY];
        const { user, client } = setup({ ...ME, second_factor: true, passkeys: 1, two_factor: { ...ME.two_factor, recovery_codes_left: 8 } });
        expect(screen.queryByText('Set up two-factor sign-in to continue.')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
        expect(await screen.findByText(CODES[0]!)).toBeInTheDocument(); expect(cacheText(client)).not.toContain(CODES[0]);
        await user.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.getByRole('button', { name: 'Regenerate' })).toBeInTheDocument();
    });
    it('shows two-factor On for a passkey-only account', () => {
        setup({ ...ME, second_factor: true, passkeys: 1 });
        expect(within(screen.getByRole('region', { name: 'Two-factor authentication' })).getByText('On')).toBeInTheDocument();
    });
    it('drops shown recovery codes when leaving the page', async () => {
        const { user, router, client } = setup(); await user.click(await openAdd(user));
        await screen.findByText(CODES[0]!); await router.navigate('/elsewhere');
        await waitFor(() => expect(screen.queryByText(CODES[0]!)).not.toBeInTheDocument()); expect(cacheText(client)).not.toContain(CODES[0]);
    });
    it('renders list, add, rename and remove in Finnish', async () => {
        await setLocale('fi'); passkeys = [PASSKEY]; const { user } = setup({ ...ME, locale: 'fi' });
        expect(screen.getByRole('heading', { name: 'Pääsyavaimet' })).toBeInTheDocument();
        await user.click(await screen.findByRole('button', { name: 'Nimeä My phone uudelleen' }));
        expect(screen.getByRole('dialog', { name: 'Nimeä pääsyavain uudelleen' })).toBeInTheDocument();
        expect(screen.getByLabelText('Pääsyavaimen nimi')).toHaveValue('My phone');
        await user.click(screen.getByRole('button', { name: 'Peruuta' }));
        await user.click(screen.getByRole('button', { name: 'Poista My phone' }));
        expect(screen.getByRole('dialog', { name: 'Poistetaanko pääsyavain?' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Peruuta' }));
        await user.click(screen.getByRole('button', { name: 'Lisää pääsyavain' }));
        expect(screen.getByRole('dialog', { name: 'Lisää pääsyavain' })).toBeInTheDocument();
    });
});
