import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { PasswordField } from './PasswordField';

function Harness() {
    const [value, setValue] = useState('');
    return <PasswordField label="Password" value={value} onChange={setValue} />;
}

describe('PasswordField', () => {
    it('Generate fills a 16-character password that scores Good or Strong', async () => {
        render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: 'Generate' }));
        const input = screen.getByLabelText('Password') as HTMLInputElement;
        expect(input.value).toHaveLength(16);
        expect(screen.getByText(/^(Good|Strong)\.$/)).toBeInTheDocument();
    });

    it('Show and Hide toggle the input type', async () => {
        render(<Harness />);
        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'password');
        await userEvent.click(screen.getByRole('button', { name: 'Show' }));
        expect(input).toHaveAttribute('type', 'text');
        await userEvent.click(screen.getByRole('button', { name: 'Hide' }));
        expect(input).toHaveAttribute('type', 'password');
    });

    it('updates the meter while typing', async () => {
        render(<Harness />);
        await userEvent.type(screen.getByLabelText('Password'), 'abcdefghijk1!');
        expect(screen.getByText('Good.')).toBeInTheDocument();
    });
});
