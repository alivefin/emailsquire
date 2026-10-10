import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it } from 'vitest';
import { Dialog } from './Dialog';
import { Drawer } from './Drawer';
import { Menu } from './Menu';

function Stack({ withMenu = false }: { withMenu?: boolean }) {
    const [drawer, setDrawer] = useState(false);
    const [dialog, setDialog] = useState(false);
    return (
        <>
            <button onClick={() => setDrawer(true)}>Open drawer</button>
            <Drawer open={drawer} onClose={() => setDrawer(false)} title="Drawer">
                <button onClick={() => setDialog(true)}>Open dialog</button>
                {withMenu && <Menu aria-label="Row actions" items={[{ label: 'Delete', onSelect: () => {} }]} />}
            </Drawer>
            <Dialog
                open={dialog}
                onClose={() => setDialog(false)}
                title="Dialog"
                footer={
                    <>
                        <button>Cancel</button>
                        <button>Confirm</button>
                    </>
                }
            >
                <p>Sure?</p>
            </Dialog>
            <button onClick={() => setDrawer(false)}>Hide drawer</button>
            <button onClick={() => setDialog(false)}>Hide dialog</button>
        </>
    );
}

const setup = (withMenu = false) =>
    render(
        <MemoryRouter>
            <Stack withMenu={withMenu} />
        </MemoryRouter>,
    );

async function openBoth() {
    await userEvent.click(screen.getByText('Open drawer'));
    await userEvent.click(screen.getByText('Open dialog'));
}

beforeEach(() => {
    document.body.style.overflow = '';
});

describe('stacked overlays', () => {
    it('Tab cycles inside the dialog and reaches its last button', async () => {
        setup();
        await openBoth();
        const confirm = screen.getByText('Confirm');
        const close = screen.getByRole('dialog', { name: 'Dialog' });
        expect(close).toHaveFocus();
        await userEvent.tab();
        expect(screen.getByText('Cancel')).toHaveFocus();
        await userEvent.tab();
        expect(confirm).toHaveFocus();
        await userEvent.tab();
        expect(screen.getByText('Cancel')).toHaveFocus();
    });

    it('Escape closes only the dialog, then the drawer', async () => {
        setup();
        await openBoth();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog', { name: 'Dialog' })).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Drawer' })).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it.each([
        ['drawer first', 'Hide drawer', 'Hide dialog'],
        ['dialog first', 'Hide dialog', 'Hide drawer'],
    ])('restores the original scroll value, %s', async (_n, first, second) => {
        document.body.style.overflow = 'scroll';
        setup();
        await openBoth();
        expect(document.body.style.overflow).toBe('hidden');
        await userEvent.click(screen.getByText(first), { pointerEventsCheck: 0 });
        expect(document.body.style.overflow).toBe('hidden');
        await userEvent.click(screen.getByText(second), { pointerEventsCheck: 0 });
        expect(document.body.style.overflow).toBe('scroll');
    });

    it('restores the original scroll value when both unmount together', async () => {
        document.body.style.overflow = 'scroll';
        const { unmount } = setup();
        await openBoth();
        unmount();
        expect(document.body.style.overflow).toBe('scroll');
    });

    it('Escape inside a Menu closes only the menu', async () => {
        setup(true);
        await userEvent.click(screen.getByText('Open drawer'));
        await userEvent.click(screen.getByRole('button', { name: 'Row actions' }));
        expect(screen.getByRole('menuitem', { name: 'Delete' })).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('menuitem')).not.toBeInTheDocument();
        expect(screen.getByRole('dialog', { name: 'Drawer' })).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
