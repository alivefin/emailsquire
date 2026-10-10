import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DataTable } from './DataTable';

const phone = vi.hoisted(() => ({ value: false }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: () => phone.value }));

interface Row {
    id: string;
    name: string;
}
const rows: Row[] = [
    { id: 'a', name: 'Alpha' },
    { id: 'b', name: 'Beta' },
    { id: 'c', name: 'Gamma' },
];
const columns = [
    { key: 'name', header: 'Name', cell: (r: Row) => r.name },
    { key: 'act', header: 'Actions', cell: (r: Row) => <button>Open {r.id}</button> },
];

beforeEach(() => {
    phone.value = false;
});

describe('DataTable', () => {
    it('selects every row on the page with the header checkbox, keeping off-page keys', async () => {
        const onChange = vi.fn();
        render(
            <DataTable rows={rows} rowKey={(r) => r.id} columns={columns} selectable={{ selected: new Set(['z']), onChange }} />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select all on this page' }));
        expect(onChange).toHaveBeenCalledWith(new Set(['z', 'a', 'b', 'c']));
    });

    it('clears only the page keys when all are selected', async () => {
        const onChange = vi.fn();
        render(
            <DataTable
                rows={rows}
                rowKey={(r) => r.id}
                columns={columns}
                selectable={{ selected: new Set(['a', 'b', 'c', 'z']), onChange }}
            />,
        );
        const header = screen.getByRole('checkbox', { name: 'Select all on this page' }) as HTMLInputElement;
        expect(header.checked).toBe(true);
        await userEvent.click(header);
        expect(onChange).toHaveBeenCalledWith(new Set(['z']));
    });

    it('shows indeterminate on a partial selection', () => {
        render(
            <DataTable rows={rows} rowKey={(r) => r.id} columns={columns} selectable={{ selected: new Set(['b']), onChange: () => {} }} />,
        );
        const header = screen.getByRole('checkbox', { name: 'Select all on this page' }) as HTMLInputElement;
        expect(header.indeterminate).toBe(true);
        expect(header.checked).toBe(false);
        expect((screen.getByRole('checkbox', { name: 'Select b' }) as HTMLInputElement).checked).toBe(true);
    });

    it('toggles a single row', async () => {
        const onChange = vi.fn();
        render(<DataTable rows={rows} rowKey={(r) => r.id} columns={columns} selectable={{ selected: new Set(['a']), onChange }} />);
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select b' }));
        expect(onChange).toHaveBeenCalledWith(new Set(['a', 'b']));
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select a' }));
        expect(onChange).toHaveBeenLastCalledWith(new Set());
    });

    it('shows the empty node for 0 rows when not loading', () => {
        render(<DataTable rows={[]} rowKey={(r: Row) => r.id} columns={columns} empty={<p>Nothing here</p>} />);
        expect(screen.getByText('Nothing here')).toBeInTheDocument();
    });

    it('shows a skeleton instead of empty while loading', () => {
        render(<DataTable rows={[]} rowKey={(r: Row) => r.id} columns={columns} empty={<p>Nothing here</p>} loading />);
        expect(screen.queryByText('Nothing here')).not.toBeInTheDocument();
        expect(screen.getByTestId('table-skeleton').children).toHaveLength(3);
    });

    it('row click ignores checkboxes and buttons inside cells', async () => {
        const onRowClick = vi.fn();
        render(
            <DataTable
                rows={rows}
                rowKey={(r) => r.id}
                columns={columns}
                selectable={{ selected: new Set(), onChange: () => {} }}
                onRowClick={onRowClick}
            />,
        );
        await userEvent.click(screen.getByRole('checkbox', { name: 'Select a' }));
        await userEvent.click(screen.getByRole('button', { name: 'Open a' }));
        expect(onRowClick).not.toHaveBeenCalled();
        await userEvent.click(screen.getByText('Beta'));
        expect(onRowClick).toHaveBeenCalledWith(rows[1]);
    });

    it('renders cards on a phone', () => {
        phone.value = true;
        render(
            <DataTable
                rows={rows}
                rowKey={(r) => r.id}
                columns={columns}
                card={(r) => <span>Card {r.name}</span>}
            />,
        );
        expect(screen.getByText('Card Alpha')).toBeInTheDocument();
        expect(screen.getByText('Card Gamma')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('shows empty on a phone', () => {
        phone.value = true;
        render(<DataTable rows={[]} rowKey={(r: Row) => r.id} columns={columns} empty={<p>Nothing here</p>} card={() => null} />);
        expect(screen.getByText('Nothing here')).toBeInTheDocument();
    });
});
