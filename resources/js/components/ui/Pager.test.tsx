import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Pager } from './Pager';

describe('Pager', () => {
    it('disables Previous on page 1', async () => {
        const onPage = vi.fn();
        render(<Pager page={1} perPage={10} total={34} onPage={onPage} />);
        expect(screen.getByText('Showing 1–10 of 34')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(onPage).toHaveBeenCalledWith(2);
    });

    it('disables Next on the last page', async () => {
        const onPage = vi.fn();
        render(<Pager page={4} perPage={10} total={34} onPage={onPage} />);
        expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(onPage).toHaveBeenCalledWith(3);
    });

    it('disables both buttons for an empty list', () => {
        render(<Pager page={1} perPage={10} total={0} onPage={() => {}} />);
        expect(screen.getByText('No results')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
    });
});
