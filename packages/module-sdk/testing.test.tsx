import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';
import { useMe } from '../../resources/js/features/auth/useMe';
import type { Me } from '../../resources/js/features/auth/useMe';
import { renderWithHost } from './testing';

const catalogues = { en: { nav: { notes: 'Notes' } }, fi: { nav: { notes: 'Muistiinpanot' } } };

function Probe() {
    const { t } = useTranslation();
    const { data } = useMe();
    const { pathname } = useLocation();
    return (
        <p>
            {t('example:nav.notes')} {data?.email} {pathname}
        </p>
    );
}

describe('renderWithHost', () => {
    it('provides the module catalogue, the signed-in user, the route and the host', async () => {
        await renderWithHost(<Probe />, { me: { email: 'a@example.org' } as Me, route: '/notes', module: { name: 'example', catalogues } });
        expect(await screen.findByText('Notes a@example.org /notes')).toBeInTheDocument();
        expect(globalThis.__emailsquire.apiVersion).toBe('1.1.0');
    });
});
