import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import i18next from 'i18next';
import { MemoryRouter } from 'react-router';
import { expectModules, registerModule, resetModules } from '../modules/registry';
import { useScreenTitle } from './screenTitle';

const me = vi.hoisted(() => ({ current: {} as Record<string, unknown> }));
vi.mock('../features/auth/useMe', () => ({ useMe: () => ({ data: me.current }) }));
vi.mock('./useNavData', () => ({ useDomainNames: () => ({ data: [] }) }));

function nav(items: { key: string; path: string; label_key: string }[]) {
    me.current = { id: 1, is_server_admin: true, modules: [{ name: 'example', nav: items, dashboard_cards: [] }] };
}

function Title() {
    return <h1>{useScreenTitle()}</h1>;
}

function at(path: string) {
    render(
        <MemoryRouter initialEntries={[path]}>
            <Title />
        </MemoryRouter>,
    );
}

beforeEach(() => {
    expectModules([{ name: 'example', route: '/example' }]);
    registerModule({ name: 'example', routes: [{ path: '/example', element: null }], catalogues: { en: { nav: { notes: 'Notes' } }, fi: { nav: { notes: 'Muistiinpanot' } } } });
    nav([{ key: 'notes', path: '/example', label_key: 'example:nav.notes' }]);
});
afterEach(() => resetModules());

describe('useScreenTitle on a module screen', () => {
    it('uses the module navigation label on its path and below it', () => {
        at('/example');
        expect(screen.getByRole('heading')).toHaveTextContent('Notes');
    });

    it('uses it below the path too', () => {
        at('/example/sub');
        expect(screen.getByRole('heading')).toHaveTextContent('Notes');
    });

    it('does not match a longer sibling path', () => {
        at('/examples');
        expect(screen.getByRole('heading')).toHaveTextContent('Page not found');
    });

    it('keeps Page not found when the label key is in no catalogue', () => {
        nav([{ key: 'x', path: '/example', label_key: 'example:nav.missing' }]);
        at('/example');
        expect(screen.getByRole('heading')).toHaveTextContent('Page not found');
    });

    it('prefers the longest matching path', () => {
        nav([
            { key: 'notes', path: '/example', label_key: 'example:nav.notes' },
            { key: 'deep', path: '/example/deep', label_key: 'example:nav.deep' },
        ]);
        i18next.addResource('en', 'example', 'nav.deep', 'Deep notes');
        at('/example/deep/x');
        expect(screen.getByRole('heading')).toHaveTextContent('Deep notes');
    });

    it('leaves core paths alone', () => {
        at('/blocked');
        expect(screen.getByRole('heading')).toHaveTextContent('Blocked');
    });
});
