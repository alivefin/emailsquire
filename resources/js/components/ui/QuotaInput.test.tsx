import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { GIB, MIB } from '../../lib/quota';
import { QuotaInput } from './QuotaInput';

describe('QuotaInput', () => {
    it('reports the default 5 GB on mount', () => {
        const onChange = vi.fn();
        render(<QuotaInput onChange={onChange} />);
        expect(onChange).toHaveBeenLastCalledWith({ bytes: 5 * GIB, problem: null });
    });

    it('presets set the value and the unit', async () => {
        const onChange = vi.fn();
        render(<QuotaInput onChange={onChange} />);
        await userEvent.selectOptions(screen.getByLabelText('Unit'), 'MB');
        await userEvent.click(screen.getByRole('button', { name: '10 GB' }));
        expect(screen.getByLabelText('Mailbox limit')).toHaveValue('10');
        expect(screen.getByLabelText('Unit')).toHaveValue('GB');
        expect(onChange).toHaveBeenLastCalledWith({ bytes: 10 * GIB, problem: null });
    });

    it('reports a problem for an empty or too small value', async () => {
        const onChange = vi.fn();
        render(<QuotaInput onChange={onChange} />);
        const field = screen.getByLabelText('Mailbox limit');
        await userEvent.clear(field);
        expect(onChange).toHaveBeenLastCalledWith({ bytes: null, problem: 'Enter a size.' });
        expect(screen.getByText('Enter a size.')).toBeInTheDocument();
        await userEvent.type(field, '0,5');
        await userEvent.selectOptions(screen.getByLabelText('Unit'), 'MB');
        expect(onChange).toHaveBeenLastCalledWith({ bytes: 0.5 * MIB, problem: 'At least 1 MB.' });
    });

    it('refuses a limit below the used space', async () => {
        const onChange = vi.fn();
        render(<QuotaInput onChange={onChange} usedBytes={2 * GIB} />);
        await userEvent.click(screen.getByRole('button', { name: '1 GB' }));
        expect(onChange).toHaveBeenLastCalledWith({
            bytes: GIB,
            problem: "Can't be lower than the space already used (2 GB).",
        });
    });
});
