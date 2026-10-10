import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { setLocale } from '../../i18n';
import { QuotaInput } from './QuotaInput';

describe('QuotaInput in Finnish', () => {
    it('shows Finnish units on the presets and in the unit select', async () => {
        await setLocale('fi');
        render(<QuotaInput onChange={() => {}} />);
        for (const label of ['1 Gt', '5 Gt', '10 Gt']) expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
        expect(screen.queryByText(/\bGB\b/)).toBeNull();
        expect(screen.getByRole('option', { name: 'Mt' })).toBeInTheDocument();
        expect(screen.getByLabelText('Tallennustilan raja')).toBeInTheDocument();
    });
});
