import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { dnsServer, dnsState, type DnsState } from '../../test/dnsServer';
import { renderRoutes } from '../../test/routing';
import { DnsTab } from './DnsTab';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

let state: DnsState;

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    state = dnsState();
    dnsServer(state);
});

function renderTab() {
    return renderRoutes([{ path: '/domains/:domain/dns', element: <DnsTab /> }], '/domains/example.org/dns');
}

describe('DnsTab in Finnish', () => {
    it('words the checks, the record to publish and the DKIM card', async () => {
        await setLocale('fi');
        renderTab();
        const mx = await screen.findByRole('region', { name: 'MX' });
        expect(within(mx).getByText('Kunnossa')).toBeInTheDocument();
        expect(within(mx).getByText('Verkkotunnuksen posti toimitetaan tälle palvelimelle.')).toBeInTheDocument();

        const dmarc = screen.getByRole('region', { name: 'DMARC' });
        expect(within(dmarc).getByText('Ongelma')).toBeInTheDocument();
        expect(within(dmarc).getByText('Julkaise tämä:')).toBeInTheDocument();
        expect(within(dmarc).getByRole('button', { name: 'Kopioi nimen _dmarc.example.org. TXT-tietueen arvo' })).toHaveTextContent('Kopioi');
        expect(within(dmarc).getByText('Vyöhyketiedoston rivi')).toBeInTheDocument();

        expect(screen.getByRole('region', { name: 'SPF' })).toHaveTextContent('Varoitus');
        expect(screen.getByRole('region', { name: 'MTA-STS' })).toHaveTextContent('Valinnainen');
        expect(screen.getByRole('region', { name: 'PTR (käänteinen DNS)' })).toBeInTheDocument();
        expect(screen.getByText('Tarkistettu 5 min sitten')).toBeInTheDocument();

        const card = screen.getByRole('region', { name: 'DKIM-avaimet' });
        expect(await within(card).findByRole('button', { name: 'Luo uusi avain' })).toBeInTheDocument();
        expect(within(card).getByText('Ei vielä käytössä')).toBeInTheDocument();
        expect(within(card).getByRole('button', { name: 'Ota käyttöön: es20261011' })).toBeInTheDocument();
    });

    it('words the too-soon recheck', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        state.write = () => Promise.reject(new ApiProblem({ status: 429, title: 'Too Many Requests', code: 'too_many_requests' }));
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Tarkista nyt' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('DNS tarkistettiin alle minuutti sitten. Yritä uudelleen minuutin kuluttua.');
    });

    it('words the activation warning', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        state.write = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'dkim_not_published' }));
        renderTab();
        const card = await screen.findByRole('region', { name: 'DKIM-avaimet' });
        await user.click(await within(card).findByRole('button', { name: 'Ota käyttöön: es20261011' }));
        const dialog = await screen.findByRole('dialog', { name: 'Otetaanko es20261011 käyttöön?' });
        await user.click(within(dialog).getByRole('button', { name: 'Ota käyttöön' }));
        const warning = await screen.findByRole('dialog', { name: 'DNS ei vielä näytä avainta es20261011' });
        expect(within(warning).getByRole('button', { name: 'Ota käyttöön silti' })).toBeInTheDocument();
    });
});
