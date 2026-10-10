import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { SignInPage } from './SignInPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockRejectedValue(new ApiProblem({ status: 401, title: 'Unauthorized', code: 'unauthenticated' }));
});

describe('SignInPage in Finnish', () => {
    it('shows the Finnish card and offers English', async () => {
        await setLocale('fi');
        renderRoutes([{ path: '/signin', element: <SignInPage /> }], '/signin');
        expect(await screen.findByRole('heading', { name: 'Kirjaudu sisään' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'In English' })).toBeInTheDocument();
        expect(screen.getByText('Kirjaudu sisään hallitsemaan sähköpostisi verkkotunnuksia.')).toBeInTheDocument();
        expect(screen.getByLabelText('Salasana')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Jatka' })).toBeInTheDocument();
    });

    it('offers Finnish in English', async () => {
        renderRoutes([{ path: '/signin', element: <SignInPage /> }], '/signin');
        expect(await screen.findByRole('button', { name: 'Suomeksi' })).toBeInTheDocument();
    });
});
