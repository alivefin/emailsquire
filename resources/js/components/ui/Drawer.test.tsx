import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { MemoryRouter, useNavigate } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Drawer } from './Drawer';

vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));
import { useIsPhone } from '../../lib/useIsPhone';

function Harness({ withInput = true }: { withInput?: boolean }) {
    const [open, setOpen] = useState(false);
    const navigate = useNavigate();
    return (
        <>
            <button onClick={() => setOpen(true)}>Open it</button>
            <button onClick={() => navigate('/other')}>Go other</button>
            <button onClick={() => navigate('/?page=2')}>Go page 2</button>
            <Drawer open={open} onClose={() => setOpen(false)} title="New mailbox" subtitle="example.com" footer={<button>Save</button>}>
                {withInput ? <input aria-label="Address" /> : <p>Secret body</p>}
                {withInput && <p>Secret body</p>}
            </Drawer>
        </>
    );
}

const setup = (props?: { withInput?: boolean }) =>
    render(
        <MemoryRouter>
            <Harness {...props} />
        </MemoryRouter>,
    );

afterEach(() => {
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('Drawer', () => {
    it('renders a modal dialog with title and subtitle when open, nothing when closed', async () => {
        setup();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await userEvent.click(screen.getByText('Open it'));
        const dialog = screen.getByRole('dialog', { name: 'New mailbox' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(screen.getByText('example.com')).toBeInTheDocument();
        expect(screen.getByText('Save')).toBeInTheDocument();
    });

    it('closes on Escape and removes its children from the DOM', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        expect(screen.getByText('Secret body')).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('Secret body')).not.toBeInTheDocument();
    });

    it('closes on a backdrop click but not on a panel click', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        await userEvent.click(screen.getByText('Secret body'));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        await userEvent.click(screen.getByTestId('overlay-backdrop'));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('closes via the close button', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('closes when the pathname changes', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        await userEvent.click(screen.getByText('Go other'));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('stays open when only the search params change', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        // the harness buttons are outside the modal; click via the DOM to bypass nothing (no inert here)
        await userEvent.click(screen.getByText('Go page 2'));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('moves focus to the first focusable in the body and returns it to the opener', async () => {
        setup();
        const opener = screen.getByText('Open it');
        await userEvent.click(opener);
        expect(screen.getByLabelText('Address')).toHaveFocus();
        await userEvent.keyboard('{Escape}');
        expect(opener).toHaveFocus();
    });

    it('focuses the panel itself when the body has nothing focusable', async () => {
        setup({ withInput: false });
        await userEvent.click(screen.getByText('Open it'));
        expect(screen.getByRole('dialog')).toHaveFocus();
    });

    it('traps Tab and Shift+Tab inside the panel', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        const close = screen.getByRole('button', { name: 'Close' });
        const save = screen.getByText('Save');
        save.focus();
        await userEvent.tab();
        expect(close).toHaveFocus();
        await userEvent.tab({ shift: true });
        expect(save).toHaveFocus();
    });

    it('locks body scroll while open and restores it', async () => {
        setup();
        await userEvent.click(screen.getByText('Open it'));
        expect(document.body.style.overflow).toBe('hidden');
        await userEvent.keyboard('{Escape}');
        expect(document.body.style.overflow).toBe('');
    });

    it('uses the full-screen sheet on phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        setup();
        await userEvent.click(screen.getByText('Open it'));
        expect(screen.getByRole('dialog')).toHaveClass('w-full');
        expect(screen.getByRole('dialog')).not.toHaveClass('w-[440px]');
    });

    it('ignores Escape and backdrop clicks while closeDisabled is set', async () => {
        const onClose = vi.fn();
        render(
            <MemoryRouter>
                <Drawer open onClose={onClose} title="Busy" closeDisabled>
                    <input aria-label="Address" />
                </Drawer>
            </MemoryRouter>,
        );
        await userEvent.keyboard('{Escape}');
        await userEvent.click(screen.getByTestId('overlay-backdrop'));
        expect(screen.getByRole('button', { name: 'Close' })).toBeDisabled();
        expect(onClose).not.toHaveBeenCalled();
    });

    it('leaves focus where the caller moved it on close instead of returning it to the opener', async () => {
        function MovesFocus() {
            const [open, setOpen] = useState(false);
            return (
                <>
                    <button onClick={() => setOpen(true)}>Open it</button>
                    <input aria-label="Search" />
                    <Drawer open={open} onClose={() => setOpen(false)} title="Delete">
                        <button
                            onClick={() => {
                                setOpen(false);
                                document.querySelector<HTMLInputElement>('[aria-label="Search"]')?.focus();
                            }}
                        >
                            Done
                        </button>
                    </Drawer>
                </>
            );
        }
        render(
            <MemoryRouter>
                <MovesFocus />
            </MemoryRouter>,
        );
        await userEvent.click(screen.getByText('Open it'));
        await userEvent.click(screen.getByText('Done'));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Search')).toHaveFocus();
    });
});
