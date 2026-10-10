import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Menu } from './Menu';

function setup() {
    const edit = vi.fn();
    const del = vi.fn();
    render(
        <div>
            <button>outside</button>
            <Menu
                aria-label="Row actions"
                items={[
                    { label: 'Edit', onSelect: edit },
                    { label: 'Disabled', onSelect: vi.fn(), disabled: true },
                    { label: 'Delete', onSelect: del, danger: true },
                ]}
            />
        </div>,
    );
    return { edit, del };
}

describe('Menu', () => {
    it('opens, selects and closes', async () => {
        const { edit } = setup();
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        await userEvent.click(screen.getByRole('menuitem', { name: 'Edit' }));
        expect(edit).toHaveBeenCalledOnce();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        setup();
        const trigger = screen.getByRole('button', { name: 'Row actions' });
        await userEvent.click(trigger);
        expect(screen.getByRole('menu')).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('closes on an outside click', async () => {
        setup();
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        await userEvent.click(screen.getByRole('button', { name: 'outside' }));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('navigates with the arrow keys, skipping disabled items', async () => {
        const { del } = setup();
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        expect(screen.getByRole('menuitem', { name: 'Edit' })).toHaveFocus();
        await userEvent.keyboard('{ArrowDown}');
        expect(screen.getByRole('menuitem', { name: 'Delete' })).toHaveFocus();
        await userEvent.keyboard('{ArrowDown}');
        expect(screen.getByRole('menuitem', { name: 'Edit' })).toHaveFocus();
        await userEvent.keyboard('{ArrowUp}{Enter}');
        expect(del).toHaveBeenCalledOnce();
    });
});
