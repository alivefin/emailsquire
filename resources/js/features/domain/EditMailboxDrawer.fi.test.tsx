import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { ME, mailbox, NAMES } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { EditMailboxDrawer } from './EditMailboxDrawer';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

beforeEach(() => {
    mocked.mockReset();
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        void init;
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        if (path.endsWith('/forwarding')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
        if (path.endsWith('/usage')) return Promise.reject(new ApiProblem({ status: 503, title: 'Service Unavailable' }));
        if (path.endsWith('/mailboxes/anna')) return Promise.resolve(mailbox('anna'));
        if (path.endsWith('/mailboxes/sales')) return Promise.resolve(mailbox('sales', { status: 'disabled' }));
        return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
    }) as typeof apiFetch);
});

function open(local: string) {
    renderRoutes([{ path: '/', element: <EditMailboxDrawer open onClose={vi.fn()} domain="example.org" local={local} /> }], '/');
}

describe('EditMailboxDrawer in Finnish', () => {
    it('words the switch for an enabled mailbox', async () => {
        await setLocale('fi');
        open('anna');
        expect(await screen.findByRole('switch', { name: 'Käytössä – sisäänkirjautuminen sallittu.' })).toBeInTheDocument();
    });

    it('words the switch for a disabled mailbox', async () => {
        await setLocale('fi');
        open('sales');
        expect(
            await screen.findByRole('switch', { name: 'Pois käytöstä – vastaanottaa edelleen postia, sisäänkirjautuminen estetty.' }),
        ).toBeInTheDocument();
    });
});

describe('EditMailboxDrawer setup instructions in Finnish', () => {
    it('words the button, the dialog and the limit message', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        open('anna');
        await user.click(await screen.findByRole('button', { name: 'Lähetä käyttöönotto-ohjeet' }));
        const dialog = await screen.findByRole('dialog', { name: 'Lähetä käyttöönotto-ohjeet: anna@example.org' });
        expect(within(dialog).getByLabelText('Vastaanottaja')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('Sähköpostin kieli')).toHaveValue('fi');
        mocked.mockImplementationOnce((() => Promise.reject(new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }))) as typeof apiFetch);
        await user.type(within(dialog).getByLabelText('Vastaanottaja'), 'owner@gmail.com');
        await user.click(within(dialog).getByRole('button', { name: 'Lähetä' }));
        expect(await within(dialog).findByText(/10 käyttöönotto-ohjetta tunnissa/)).toBeInTheDocument();
    });
});

describe('EditMailboxDrawer mail delivery switches in Finnish', () => {
    it('words the switches and the note', async () => {
        await setLocale('fi');
        mocked.mockImplementation(((path: string) => {
            if (path === '/me') return Promise.resolve({ ...ME, capabilities: { schema_extension: { receiving: true, sendonly: true }, catch_all: false } });
            if (path === '/domains/names') return Promise.resolve({ data: NAMES });
            if (path.endsWith('/forwarding')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
            if (path.endsWith('/usage')) return Promise.reject(new ApiProblem({ status: 503, title: 'Service Unavailable' }));
            if (path.endsWith('/mailboxes/anna')) return Promise.resolve(mailbox('anna', { receiving: true, sendonly: false }));
            return Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
        }) as typeof apiFetch);
        open('anna');
        expect(await screen.findByRole('switch', { name: 'Lopeta viestien vastaanotto' })).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Vain lähettävä' })).toBeInTheDocument();
        expect(screen.getByText(/^Suoraan tähän osoitteeseen lähetetty posti hylätään\. Aliaksista ja edelleenlähetyssäännöistä tänne tuleva posti palautuu lähettäjälle\./)).toBeInTheDocument();
        expect(screen.getByText(/Verkkotunnuksen catch-all ottaa silti vastaan tähän osoitteeseen lähetetyn postin\. Toimii vasta/)).toBeInTheDocument();
    });
});
