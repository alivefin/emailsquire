import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider, useToast } from './Toast';

type Show = ReturnType<typeof useToast>['show'];
let show: Show;

function Grab() {
    show = useToast().show;
    return null;
}

beforeEach(() => {
    vi.useFakeTimers();
    render(
        <ToastProvider>
            <Grab />
        </ToastProvider>,
    );
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Toast', () => {
    it('shows text in a status live region and dismisses after 4.5 s', () => {
        act(() => show({ kind: 'success', text: 'Mailbox created' }));
        expect(screen.getByRole('status')).toHaveTextContent('Mailbox created');
        act(() => void vi.advanceTimersByTime(4499));
        expect(screen.getByText('Mailbox created')).toBeInTheDocument();
        act(() => void vi.advanceTimersByTime(1));
        expect(screen.queryByText('Mailbox created')).not.toBeInTheDocument();
    });

    it('keeps a toast with an action until 8 s', () => {
        act(() => show({ kind: 'info', text: 'Deleted', action: { label: 'Undo', onClick: () => {} } }));
        act(() => void vi.advanceTimersByTime(7999));
        expect(screen.getByText('Deleted')).toBeInTheDocument();
        act(() => void vi.advanceTimersByTime(1));
        expect(screen.queryByText('Deleted')).not.toBeInTheDocument();
    });

    it('runs the action and dismisses on click', () => {
        const onClick = vi.fn();
        act(() => show({ kind: 'danger', text: 'Deleted', action: { label: 'Undo', onClick } }));
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(onClick).toHaveBeenCalledTimes(1);
        expect(screen.queryByText('Deleted')).not.toBeInTheDocument();
    });

    it('shows at most 3, newest last', () => {
        act(() => {
            for (const n of ['one', 'two', 'three', 'four']) show({ kind: 'info', text: n });
        });
        const items = screen.getAllByTestId('toast').map((e) => e.textContent);
        expect(items).toEqual(['two', 'three', 'four']);
    });

    it('colours the dot by kind', () => {
        act(() => show({ kind: 'success', text: 'ok' }));
        expect(screen.getByTestId('toast-dot')).toHaveClass('bg-teal');
    });
});
