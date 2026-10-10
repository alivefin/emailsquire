import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
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

function serve(settings: Settings, patch: (init: ApiInit) => Promise<unknown> = (init) => Promise.resolve({ ...settings, ...(init.json as object) })) {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if (path !== '/domains/example.org/settings') return Promise.reject(new Error(`unexpected ${path}`));
        return (init.method ?? 'GET') === 'PATCH' ? patch(init) : Promise.resolve(settings);
    }) as typeof apiFetch);
}

function patches(): ApiInit[] {
    return mocked.mock.calls.filter(([, init]) => init?.method === 'PATCH').map(([, init]) => init ?? {});
}

const onClose = vi.fn();

function renderDialog() {
    return renderRoutes(
        [{ path: '/', element: <DomainSettingsDialog open onClose={onClose} domain="example.org" unicode="example.org" /> }],
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
        serve({ max_mailboxes: 5, max_quota_bytes_total: null, usage: { mailboxes: 0, quota_bytes_allocated: 0 } }, () => Promise.reject(answer));
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
