import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useNavigate } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Dialog } from './Dialog';

vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));
import { useIsPhone } from '../../lib/useIsPhone';

function Nav() {
    const navigate = useNavigate();
    return (
        <>
            <button onClick={() => navigate('/other')}>Go other</button>
            <button onClick={() => navigate('/?q=x')}>Go search</button>
        </>
    );
}

afterEach(() => {
    vi.mocked(useIsPhone).mockReturnValue(false);
});

function setup(onClose = vi.fn(), size?: 'sm' | 'md') {
    render(
        <MemoryRouter>
            <Nav />
            <Dialog open onClose={onClose} title="Delete mailbox" size={size} footer={<button>Delete</button>}>
                <p>Mail is moved to trash.</p>
            </Dialog>
        </MemoryRouter>,
    );
    return onClose;
}

describe('Dialog', () => {
    it('is a centred modal on desktop', () => {
        setup();
        const d = screen.getByRole('dialog', { name: 'Delete mailbox' });
        expect(d).toHaveAttribute('aria-modal', 'true');
        expect(d).not.toHaveClass('rounded-t-2xl');
        expect(d).toHaveClass('max-w-[520px]');
    });

    it('uses the narrow width for size sm', () => {
        setup(vi.fn(), 'sm');
        expect(screen.getByRole('dialog')).toHaveClass('max-w-[400px]');
    });

    it('renders as a bottom sheet on phone', () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        setup();
        expect(screen.getByRole('dialog')).toHaveClass('rounded-t-2xl');
    });

    it('closes on Escape and on a backdrop click', async () => {
        const onClose = setup();
        await userEvent.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
        await userEvent.click(screen.getByTestId('overlay-backdrop'));
        expect(onClose).toHaveBeenCalledTimes(2);
    });

    it('closes on a pathname change but not a search-only change', async () => {
        const onClose = setup();
        await userEvent.click(screen.getByText('Go search'));
        expect(onClose).not.toHaveBeenCalled();
        await userEvent.click(screen.getByText('Go other'));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
