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

    it('returns focus to the trigger after an item is chosen with the keyboard', async () => {
        const { edit } = setup();
        const trigger = screen.getByRole('button', { name: 'Row actions' });
        trigger.focus();
        await userEvent.keyboard('{Enter}');
        expect(screen.getByRole('menuitem', { name: 'Edit' })).toHaveFocus();
        await userEvent.keyboard('{Enter}');
        expect(edit).toHaveBeenCalledOnce();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('closes on an outside click', async () => {
        setup();
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        await userEvent.click(screen.getByRole('button', { name: 'outside' }));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('opens outside any clipping box, so a short table does not cut it off', async () => {
        render(
            <div data-testid="clip" style={{ overflow: 'auto', height: 40 }}>
                <Menu aria-label="Row actions" items={[{ label: 'Edit', onSelect: vi.fn() }]} />
            </div>,
        );
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        const menu = screen.getByRole('menu');
        expect(screen.getByTestId('clip')).not.toContainElement(menu);
        expect(menu.style.position).toBe('fixed');
    });

    it('opens below the trigger when there is room, and above it near the bottom of the window', async () => {
        const rect = (top: number, height: number): DOMRect =>
            ({ top, bottom: top + height, left: 270, right: 300, width: 30, height, x: 270, y: top, toJSON: () => ({}) }) as DOMRect;
        let triggerTop = 100;
        const spy = vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
            if (this.getAttribute('role') === 'menu') return rect(0, 160);
            if (this.getAttribute('aria-haspopup') === 'menu') return rect(triggerTop, 30);
            return rect(0, 0);
        });
        try {
            render(<Menu aria-label="Row actions" items={[{ label: 'Edit', onSelect: vi.fn() }]} />);
            const trigger = screen.getByRole('button', { name: 'Row actions' });

            await userEvent.click(trigger);
            expect(screen.getByRole('menu').style.top).toBe('134px'); // 100 + 30 + the 4 px gap

            await userEvent.keyboard('{Escape}');
            triggerTop = window.innerHeight - 40;
            await userEvent.click(trigger);
            expect(screen.getByRole('menu').style.top).toBe(`${triggerTop - 4 - 160}px`);
        } finally {
            spy.mockRestore();
        }
    });

    it('closes on Tab and lets focus move on from the trigger', async () => {
        render(
            <div>
                <Menu aria-label="Row actions" items={[{ label: 'Edit', onSelect: vi.fn() }]} />
                <button>after</button>
            </div>,
        );
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        expect(screen.getByRole('menuitem', { name: 'Edit' })).toHaveFocus();
        await userEvent.tab();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'after' })).toHaveFocus();
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
