import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import type { DomainRole } from '../../lib/roles';
import { renderRoutes } from '../../test/routing';
import { DomainSettingsDialog } from './DomainSettingsDialog';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);
const GIB = 1073741824;

interface Settings {
    max_mailboxes: number | null;
    max_quota_bytes_total: number | null;
    usage: { mailboxes: number; quota_bytes_allocated: number };
}

interface SelfService {
    forwarding: boolean;
    aliases: boolean;
    external_forwarding: boolean;
}

const DEFAULT_SWITCHES: SelfService = { forwarding: true, aliases: false, external_forwarding: false };
const SELF_SERVICE = '/domains/example.org/self-service';
const SETTINGS = '/domains/example.org/settings';

interface Serve {
    patch?: (init: ApiInit) => Promise<unknown>;
    switches?: SelfService;
    patchSwitches?: (init: ApiInit) => Promise<unknown>;
}

/** Answers the dialog's two reads; a PATCH echoes the body unless a handler is given. */
function serve(settings: Settings, { patch, switches = DEFAULT_SWITCHES, patchSwitches }: Serve = {}) {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        const method = init.method ?? 'GET';
        if (path === SELF_SERVICE) {
            if (method !== 'PATCH') return Promise.resolve(switches);
            return patchSwitches ? patchSwitches(init) : Promise.resolve({ ...switches, ...(init.json as object) });
        }
        if (path !== SETTINGS) return Promise.reject(new Error(`unexpected ${path}`));
        if (method !== 'PATCH') return Promise.resolve(settings);
        return patch ? patch(init) : Promise.resolve({ ...settings, ...(init.json as object) });
    }) as typeof apiFetch);
}

function patchesTo(path: string): ApiInit[] {
    return mocked.mock.calls.filter(([p, init]) => p === path && init?.method === 'PATCH').map(([, init]) => init ?? {});
}

const patches = () => patchesTo(SETTINGS);
const switchPatches = () => patchesTo(SELF_SERVICE);

function requested(path: string): boolean {
    return mocked.mock.calls.some(([p]) => p === path);
}

const onClose = vi.fn();

function renderDialog(role: DomainRole = 'admin') {
    return renderRoutes(
        [{ path: '/', element: <DomainSettingsDialog open onClose={onClose} domain="example.org" unicode="example.org" role={role} /> }],
        '/',
    );
}

beforeEach(() => {
    mocked.mockReset();
    onClose.mockReset();
});

describe('DomainSettingsDialog', () => {
    it('shows the current limits and the use', async () => {
        serve({ max_mailboxes: 20, max_quota_bytes_total: 100 * GIB, usage: { mailboxes: 12, quota_bytes_allocated: 48 * GIB } });
        renderDialog();
        const dialog = await screen.findByRole('dialog', { name: 'Domain settings' });
        expect(await within(dialog).findByLabelText('Maximum mailboxes')).toHaveValue('20');
        expect(within(dialog).getByLabelText('Total storage limit')).toHaveValue('100');
        expect(within(dialog).getByText('In use: 12 mailboxes · 48 GB allocated')).toBeInTheDocument();
    });

    it('saves both limits through PATCH, and clearing a field sends null', async () => {
        const user = userEvent.setup();
        serve({ max_mailboxes: 20, max_quota_bytes_total: 100 * GIB, usage: { mailboxes: 12, quota_bytes_allocated: 48 * GIB } });
        renderDialog();
        const field = await screen.findByLabelText('Maximum mailboxes');
        await user.clear(field);
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(patches()).toHaveLength(1));
        expect(patches()[0]).toEqual({ method: 'PATCH', json: { max_mailboxes: null, max_quota_bytes_total: 100 * GIB } });
        await waitFor(() => expect(onClose).toHaveBeenCalled());
        expect(await screen.findByText('Limits saved.')).toBeInTheDocument();
    });

    it('starts empty for an unlimited domain and sends the typed limits', async () => {
        const user = userEvent.setup();
        serve({ max_mailboxes: null, max_quota_bytes_total: null, usage: { mailboxes: 2, quota_bytes_allocated: 10 * GIB } });
        renderDialog();
        const mailboxes = await screen.findByLabelText('Maximum mailboxes');
        const quota = screen.getByLabelText('Total storage limit');
        expect(mailboxes).toHaveValue('');
        expect(quota).toHaveValue('');
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();

        await user.type(mailboxes, '25');
        await user.type(quota, '200');
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() =>
            expect(patches()[0]).toEqual({ method: 'PATCH', json: { max_mailboxes: 25, max_quota_bytes_total: 200 * GIB } }),
        );
    });

    it('warns when a limit is below the current use, but still saves', async () => {
        const user = userEvent.setup();
        serve({ max_mailboxes: null, max_quota_bytes_total: null, usage: { mailboxes: 12, quota_bytes_allocated: 48 * GIB } });
        renderDialog();
        await user.type(await screen.findByLabelText('Maximum mailboxes'), '10');
        await user.type(screen.getByLabelText('Total storage limit'), '40');

        expect(screen.getByText('12 mailboxes already exist. They stay, but no new mailboxes can be added.')).toBeInTheDocument();
        expect(
            screen.getByText('48 GB is already allocated. Mailboxes keep their limits, but no new mailboxes or bigger limits fit.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
    });

    it('refuses a mailbox count that is not a whole number', async () => {
        const user = userEvent.setup();
        serve({ max_mailboxes: null, max_quota_bytes_total: null, usage: { mailboxes: 0, quota_bytes_allocated: 0 } });
        renderDialog();
        await user.type(await screen.findByLabelText('Maximum mailboxes'), '2.5');
        expect(screen.getByText('Enter a whole number from 1 to 100000, or leave it empty for no limit.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('puts a 422 field error under its field and a 403 in the form', async () => {
        const user = userEvent.setup();
        let answer: unknown = new ApiProblem({
            status: 422,
            title: 'Unprocessable Content',
            code: 'validation_failed',
            errors: { max_quota_bytes_total: ['The total storage limit may not be greater than 1 PiB.'] },
        });
        serve({ max_mailboxes: 5, max_quota_bytes_total: null, usage: { mailboxes: 0, quota_bytes_allocated: 0 } }, { patch: () => Promise.reject(answer) });
        renderDialog();
        await user.type(await screen.findByLabelText('Total storage limit'), '50');
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('The total storage limit may not be greater than 1 PiB.')).toBeInTheDocument();

        answer = new ApiProblem({ status: 403, title: 'Forbidden', code: 'forbidden' });
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText("You don't have permission to do that.")).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        serve({ max_mailboxes: 20, max_quota_bytes_total: 100 * GIB, usage: { mailboxes: 12, quota_bytes_allocated: 48 * GIB } });
        renderDialog();
        const dialog = await screen.findByRole('dialog', { name: 'Verkkotunnuksen asetukset' });
        expect(await within(dialog).findByLabelText('Postilaatikoiden enimmäismäärä')).toHaveValue('20');
        expect(within(dialog).getByLabelText('Tallennustilan yhteisraja')).toHaveValue('100');
        expect(within(dialog).getByText('Käytössä: 12 postilaatikkoa · 48 Gt varattu')).toBeInTheDocument();
        expect(within(dialog).getAllByText('Jätä tyhjäksi, jos rajaa ei ole.')).toHaveLength(2);
        expect(within(dialog).getByRole('button', { name: 'Tallenna' })).toBeInTheDocument();
    });
});

const LIMITS: Settings = { max_mailboxes: 20, max_quota_bytes_total: null, usage: { mailboxes: 12, quota_bytes_allocated: 48 * GIB } };

describe('DomainSettingsDialog: what mailbox owners can change', () => {
    it('shows an admin the limits and the three switches', async () => {
        serve(LIMITS, { switches: { forwarding: true, aliases: false, external_forwarding: true } });
        renderDialog('admin');
        const dialog = await screen.findByRole('dialog', { name: 'Domain settings' });
        expect(await within(dialog).findByLabelText('Maximum mailboxes')).toHaveValue('20');
        const section = within(dialog).getByRole('group', { name: 'Mailbox owners can…' });
        expect(within(section).getByRole('switch', { name: 'Forward their mail' })).toHaveAttribute('aria-checked', 'true');
        expect(within(section).getByRole('switch', { name: 'Add and remove aliases' })).toHaveAttribute('aria-checked', 'false');
        expect(within(section).getByRole('switch', { name: 'Forward to addresses outside this server' })).toHaveAttribute('aria-checked', 'true');
        for (const name of ['Forward their mail', 'Add and remove aliases', 'Forward to addresses outside this server']) {
            expect(within(section).getByRole('switch', { name })).toBeEnabled();
        }
    });

    it('saves only the switches that changed, and both kinds in one Save', async () => {
        const user = userEvent.setup();
        serve(LIMITS);
        renderDialog('admin');
        await user.click(await screen.findByRole('switch', { name: 'Add and remove aliases' }));
        await user.clear(screen.getByLabelText('Maximum mailboxes'));
        await user.type(screen.getByLabelText('Maximum mailboxes'), '30');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(switchPatches()).toEqual([{ method: 'PATCH', json: { aliases: true } }]));
        expect(patches()).toEqual([{ method: 'PATCH', json: { max_mailboxes: 30, max_quota_bytes_total: null } }]);
        await waitFor(() => expect(onClose).toHaveBeenCalled());
        expect(await screen.findByText('Settings saved.')).toBeInTheDocument();
    });

    it('shows a manager the switches only, without the limits, and lets them save', async () => {
        const user = userEvent.setup();
        serve(LIMITS);
        renderDialog('manager');
        const dialog = await screen.findByRole('dialog', { name: 'Domain settings' });
        await user.click(await within(dialog).findByRole('switch', { name: 'Add and remove aliases' }));
        expect(within(dialog).queryByLabelText('Maximum mailboxes')).not.toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Total storage limit')).not.toBeInTheDocument();
        expect(within(dialog).queryByText(/In use:/)).not.toBeInTheDocument();
        expect(requested(SETTINGS)).toBe(false);

        await user.click(within(dialog).getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(switchPatches()).toEqual([{ method: 'PATCH', json: { aliases: true } }]));
        expect(patches()).toEqual([]);
        await waitFor(() => expect(onClose).toHaveBeenCalled());
    });

    it('shows a viewer the switches read-only, with Close and no Save', async () => {
        serve(LIMITS, { switches: { forwarding: true, aliases: true, external_forwarding: false } });
        renderDialog('viewer');
        const dialog = await screen.findByRole('dialog', { name: 'Domain settings' });
        const aliases = await within(dialog).findByRole('switch', { name: 'Add and remove aliases' });
        expect(aliases).toBeDisabled();
        expect(aliases).toHaveAttribute('aria-checked', 'true');
        expect(within(dialog).getByRole('switch', { name: 'Forward their mail' })).toBeDisabled();
        expect(within(dialog).getByRole('switch', { name: 'Forward to addresses outside this server' })).toBeDisabled();
        expect(within(dialog).getByText("Only server admins and this domain's managers can change these.")).toBeInTheDocument();
        expect(within(dialog).queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Close' })).toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Maximum mailboxes')).not.toBeInTheDocument();
        expect(requested(SETTINGS)).toBe(false);
    });

    it('turns external forwarding off-limits while forwarding is off', async () => {
        const user = userEvent.setup();
        serve(LIMITS, { switches: { forwarding: true, aliases: false, external_forwarding: true } });
        renderDialog('manager');
        const external = await screen.findByRole('switch', { name: 'Forward to addresses outside this server' });
        expect(external).toBeEnabled();

        await user.click(screen.getByRole('switch', { name: 'Forward their mail' }));
        expect(external).toBeDisabled();
        expect(screen.getByText('Turn on forwarding first.')).toBeInTheDocument();
        expect(external).toHaveAccessibleDescription('Turn on forwarding first.');

        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(switchPatches()).toEqual([{ method: 'PATCH', json: { forwarding: false } }]));
    });

    it('keeps the dialog open with the error when the switches fail to save', async () => {
        const user = userEvent.setup();
        serve(LIMITS, {
            patchSwitches: () => Promise.reject(new ApiProblem({ status: 403, title: 'Forbidden', code: 'forbidden' })),
        });
        renderDialog('manager');
        await user.click(await screen.findByRole('switch', { name: 'Add and remove aliases' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText("You don't have permission to do that.")).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('renders the section in Finnish', async () => {
        await setLocale('fi');
        serve(LIMITS);
        renderDialog('viewer');
        const dialog = await screen.findByRole('dialog', { name: 'Verkkotunnuksen asetukset' });
        const section = await within(dialog).findByRole('group', { name: 'Postilaatikon omistajat voivat…' });
        expect(within(section).getByRole('switch', { name: 'Lähettää postinsa edelleen' })).toBeDisabled();
        expect(within(section).getByRole('switch', { name: 'Lisätä ja poistaa aliaksia' })).toBeInTheDocument();
        expect(within(section).getByRole('switch', { name: 'Lähettää postia edelleen tämän palvelimen ulkopuolisiin osoitteisiin' })).toBeInTheDocument();
        expect(within(dialog).getByText('Näitä voivat muuttaa vain palvelimen ylläpitäjät ja verkkotunnuksen hallinnoijat.')).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Sulje' })).toBeInTheDocument();
    });
});
