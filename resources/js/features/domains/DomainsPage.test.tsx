import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { renderRoutes } from '../../test/routing';
import { DeleteDomainDialog } from './DeleteDomainDialog';
import { DomainsPage } from './DomainsPage';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

const rows = [
    { name: 'example.org', unicode_name: 'example.org', mailbox_count: 3, forwarding_count: 2, used_bytes: 2 * 1073741824 },
    { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1, forwarding_count: 0, used_bytes: null },
];

function setup(path = '/domains', meta = { page: 1, per_page: 10, total: 2, last_page: 1 }, data = rows) {
    mocked.mockImplementation((p: string) => {
        if (p === '/domains') {
            return Promise.resolve({ data, meta });
        }
        if (p === '/domains/names') {
            return Promise.resolve({ data: rows.map(({ name, unicode_name, mailbox_count }) => ({ name, unicode_name, mailbox_count })) });
        }
        return Promise.resolve(undefined);
    });
    return renderRoutes([{ path: '/domains', element: <DomainsPage /> }], path);
}

beforeEach(() => {
    mocked.mockReset();
});

describe('DomainsPage', () => {
    it('shows the unicode name with the punycode name in muted text', async () => {
        setup();
        expect(await screen.findByText('bücher.example')).toBeInTheDocument();
        expect(screen.getByText('xn--bcher-kva.example')).toBeInTheDocument();
        expect(screen.getByText('2 GB')).toBeInTheDocument();
    });

    it('refuses names with a space or an @ and shows the message after blur', async () => {
        const user = userEvent.setup();
        setup();
        await screen.findByText('bücher.example');
        await user.click(screen.getByRole('button', { name: '+ Add domain' }));
        const input = screen.getByLabelText('Domain name');
        await user.type(input, 'exa mple.org');
        await user.tab();
        expect(await screen.findByText(/Enter a domain like example\.org/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add domain' })).toBeDisabled();

        await user.clear(input);
        await user.type(input, 'a@b.org');
        expect(screen.getByText(/Enter a domain like example\.org/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add domain' })).toBeDisabled();
        expect(mocked).not.toHaveBeenCalledWith('/domains', expect.objectContaining({ method: 'POST' }));
    });

    it('shows the duplicate message for an existing name', async () => {
        const user = userEvent.setup();
        setup();
        await screen.findByText('bücher.example');
        await user.click(screen.getByRole('button', { name: '+ Add domain' }));
        await user.type(screen.getByLabelText('Domain name'), '  Example.ORG ');
        await user.tab();
        expect(await screen.findByText('That domain already exists.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add domain' })).toBeDisabled();
    });

    it('adds a domain, toasts and opens its mailboxes', async () => {
        const user = userEvent.setup();
        const { client } = setup();
        const added = { name: 'xn--mnchen-3ya.example', unicode_name: 'münchen.example', mailbox_count: 0, forwarding_count: 0, used_bytes: 0 };
        mocked.mockImplementation((p: string, init?: { method?: string }) => {
            if (p === '/domains' && init?.method === 'POST') return Promise.resolve(added);
            if (p === '/domains') return Promise.resolve({ data: rows, meta: { page: 1, per_page: 10, total: 2, last_page: 1 } });
            return Promise.resolve({ data: [] });
        });
        await screen.findByText('bücher.example');
        await user.click(screen.getByRole('button', { name: '+ Add domain' }));
        await user.type(screen.getByLabelText('Domain name'), 'münchen.example{Enter}');
        expect(await screen.findByText('Added münchen.example.')).toBeInTheDocument();
        expect(mocked).toHaveBeenCalledWith('/domains', { method: 'POST', json: { name: 'münchen.example' } });
        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/xn--mnchen-3ya.example/mailboxes');
        expect(client).toBeDefined();
    });

    it('shows the server message under the field on a 409', async () => {
        const user = userEvent.setup();
        setup();
        mocked.mockImplementation((p: string, init?: { method?: string }) => {
            if (p === '/domains' && init?.method === 'POST') {
                return Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'domain_exists' }));
            }
            if (p === '/domains') return Promise.resolve({ data: rows, meta: { page: 1, per_page: 10, total: 2, last_page: 1 } });
            return Promise.resolve({ data: [] });
        });
        await screen.findByText('bücher.example');
        await user.click(screen.getByRole('button', { name: '+ Add domain' }));
        await user.type(screen.getByLabelText('Domain name'), 'fresh.example{Enter}');
        expect(await screen.findByText('That domain already exists.')).toBeInTheDocument();
    });

    it('debounces the search into the URL and resets the page', async () => {
        const user = userEvent.setup();
        const { router } = setup('/domains?page=2', { page: 2, per_page: 10, total: 30, last_page: 3 });
        await screen.findByText('bücher.example');
        await user.type(screen.getByLabelText('Search domains'), 'bü');
        expect(router.state.location.search).toBe('?page=2');
        await waitFor(() => expect(router.state.location.search).toBe('?q=b%C3%BC'));
    });

    it('steps back to the last page when the current one is past the end', async () => {
        const { router } = setup('/domains?page=3', { page: 3, per_page: 10, total: 20, last_page: 2 }, []);
        await waitFor(() => expect(router.state.location.search).toBe('?page=2'));
    });

    it('opens the delete dialog from the row menu', async () => {
        const user = userEvent.setup();
        setup();
        await screen.findByText('bücher.example');
        await user.click(screen.getByRole('button', { name: 'Actions for bücher.example' }));
        await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));
        expect(await screen.findByRole('dialog')).toHaveTextContent('Delete bücher.example?');
    });
});

describe('DeleteDomainDialog', () => {
    const domain = { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1, forwarding_count: 4 };

    function open(onDeleted = vi.fn()) {
        mocked.mockResolvedValue(undefined);
        renderRoutes([{ path: '/', element: <DeleteDomainDialog open onClose={vi.fn()} domain={domain} onDeleted={onDeleted} /> }], '/');
        return { onDeleted };
    }

    it('states the counts and the 30 day restore window', () => {
        open();
        expect(
            screen.getByText(
                '1 mailboxes and 4 forwarding addresses will be deleted. Their mail is moved to trash and can be restored for 30 days.',
            ),
        ).toBeInTheDocument();
    });

    it('enables on the exact punycode or unicode name only', async () => {
        const user = userEvent.setup();
        open();
        const input = screen.getByLabelText(/Type .* to confirm/);
        const button = screen.getByRole('button', { name: 'Delete domain' });
        expect(button).toBeDisabled();

        await user.type(input, 'xn--bcher-kva.example');
        expect(button).toBeEnabled();

        await user.clear(input);
        await user.type(input, 'bücher.example');
        expect(button).toBeEnabled();

        await user.clear(input);
        await user.type(input, 'Bücher.example');
        expect(button).toBeDisabled();

        await user.clear(input);
        await user.type(input, 'bücher.example ');
        expect(button).toBeDisabled();
    });

    it('sends confirm_name, toasts and reports the deletion', async () => {
        const user = userEvent.setup();
        const { onDeleted } = open();
        await user.type(screen.getByLabelText(/Type .* to confirm/), 'bücher.example');
        await user.click(screen.getByRole('button', { name: 'Delete domain' }));
        await waitFor(() => expect(onDeleted).toHaveBeenCalled());
        expect(mocked).toHaveBeenCalledWith('/domains/xn--bcher-kva.example', {
            method: 'DELETE',
            json: { confirm_name: 'bücher.example' },
        });
        expect(screen.getByText('Deleted bücher.example.')).toBeInTheDocument();
    });

    it('shows the server confirm_name error', async () => {
        const user = userEvent.setup();
        open();
        mocked.mockRejectedValue(
            new ApiProblem({ status: 422, title: 'Invalid', errors: { confirm_name: ['Type bücher.example to confirm.'] } }),
        );
        await user.type(screen.getByLabelText(/Type .* to confirm/), 'bücher.example');
        await user.click(screen.getByRole('button', { name: 'Delete domain' }));
        const dialog = screen.getByRole('dialog');
        expect(await within(dialog).findByText('Type bücher.example to confirm.')).toBeInTheDocument();
    });
});
