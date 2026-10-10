import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { renderRoutes } from '../../test/routing';
import { BulkLimitDialog } from './BulkLimitDialog';
import { DeleteMailboxDialog } from './DeleteMailboxDialog';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));

const mocked = vi.mocked(apiFetch);

beforeEach(() => {
    mocked.mockReset();
});

describe('BulkLimitDialog', () => {
    function open() {
        const onDone = vi.fn();
        const onClose = vi.fn();
        mocked.mockResolvedValue({ changed: 2 });
        renderRoutes(
            [
                {
                    path: '/',
                    element: <BulkLimitDialog open domain="example.org" locals={['anna', 'bob']} onClose={onClose} onDone={onDone} />,
                },
            ],
            '/',
        );
        return { onDone, onClose };
    }

    it('refuses 0', async () => {
        const user = userEvent.setup();
        open();
        const input = screen.getByLabelText('New limit');
        await user.clear(input);
        await user.type(input, '0');
        expect(screen.getByRole('button', { name: 'Set limit' })).toBeDisabled();
        expect(screen.getByText('Enter a size.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Set limit' }));
        expect(mocked).not.toHaveBeenCalled();
    });

    it('posts quota_bytes 10737418240 for 10 GB, toasts and reports done', async () => {
        const user = userEvent.setup();
        const { onDone, onClose } = open();
        let release: (v: unknown) => void = () => {};
        mocked.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const input = screen.getByLabelText('New limit');
        await user.clear(input);
        await user.type(input, '10');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Unit' }), 'GB');
        await user.dblClick(screen.getByRole('button', { name: 'Set limit' }));
        release({ changed: 2 });
        await waitFor(() => expect(onDone).toHaveBeenCalled());
        expect(onClose).toHaveBeenCalled();
        expect(mocked).toHaveBeenCalledTimes(1);
        expect(mocked).toHaveBeenCalledWith('/domains/example.org/mailboxes/bulk', {
            method: 'POST',
            json: { locals: ['anna', 'bob'], action: 'quota', quota_bytes: 10737418240 },
        });
        expect(screen.getByText('Limit set to 10 GB for 2 mailboxes.')).toBeInTheDocument();
    });

    it('shows the server error in the dialog', async () => {
        const user = userEvent.setup();
        open();
        mocked.mockRejectedValue(
            new ApiProblem({ status: 422, title: 'Invalid', errors: { quota_bytes: ['The limit is too large.'] } }),
        );
        await user.click(screen.getByRole('button', { name: 'Set limit' }));
        expect(await within(screen.getByRole('dialog')).findByText('The limit is too large.')).toBeInTheDocument();
    });
});

describe('DeleteMailboxDialog', () => {
    function open() {
        const onDeleted = vi.fn();
        const onClose = vi.fn();
        renderRoutes(
            [
                {
                    path: '/',
                    element: (
                        <DeleteMailboxDialog
                            open
                            domain="xn--bcher-kva.example"
                            local="anna"
                            address="anna@bücher.example"
                            onClose={onClose}
                            onDeleted={onDeleted}
                        />
                    ),
                },
            ],
            '/',
        );
        return { onDeleted, onClose };
    }

    it('lists the consequences, with the trash copy', () => {
        open();
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Delete anna@bücher.example?');
        expect(within(dialog).getAllByRole('listitem').map((li) => li.textContent)).toEqual([
            'Sign-ins stop immediately and open sessions are closed.',
            'Its mail is moved to trash and can be restored for 30 days.',
            'Forwarding from this address is removed. Forwarding to it from other addresses is kept.',
            "The address can't be reused until its mail has been moved.",
        ]);
    });

    it('deletes once, busy while pending, then toasts and reports', async () => {
        const user = userEvent.setup();
        let release: (v: unknown) => void = () => {};
        mocked.mockImplementation(() => new Promise((resolve) => (release = resolve)));
        const { onDeleted, onClose } = open();
        const button = screen.getByRole('button', { name: 'Delete mailbox' });
        await user.dblClick(button);
        await waitFor(() => expect(button).toHaveAttribute('aria-busy', 'true'));
        expect(button).toBeDisabled();
        release({ forwarding_removed: 0 });
        await waitFor(() => expect(onDeleted).toHaveBeenCalled());
        expect(onClose).toHaveBeenCalled();
        expect(mocked).toHaveBeenCalledTimes(1);
        expect(mocked).toHaveBeenCalledWith('/domains/xn--bcher-kva.example/mailboxes/anna', { method: 'DELETE' });
        expect(screen.getByText('Deleted anna@bücher.example. Its mail is moved to trash.')).toBeInTheDocument();
    });

    it('shows a 409 as readable copy', async () => {
        const user = userEvent.setup();
        mocked.mockRejectedValue(new ApiProblem({ status: 409, title: 'Conflict', code: 'busy' }));
        open();
        await user.click(screen.getByRole('button', { name: 'Delete mailbox' }));
        expect(
            await within(screen.getByRole('dialog')).findByText('Someone else is changing this address. Try again.'),
        ).toBeInTheDocument();
    });
});
