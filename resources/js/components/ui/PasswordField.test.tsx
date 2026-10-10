import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { PasswordField } from './PasswordField';

const MASK = '[-webkit-text-security:disc]';

function Harness({ secretKind }: { secretKind?: 'mailbox' | 'account' }) {
    const [value, setValue] = useState('');
    return <PasswordField label="Password" value={value} onChange={setValue} secretKind={secretKind} />;
}

describe('PasswordField', () => {
    it('hides Generate when allowGenerate is false', () => {
        render(<PasswordField label="Password" value="" onChange={() => {}} allowGenerate={false} />);
        expect(screen.queryByRole('button', { name: 'Generate' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Show' })).toBeInTheDocument();
    });

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

    it('keeps a real password input for an account password', () => {
        render(<Harness />);
        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'password');
        expect(input).toHaveAttribute('autocomplete', 'new-password');
        expect(input).not.toHaveClass(MASK);
    });

    it('renders a mailbox password as masked text that password managers leave alone', async () => {
        render(<Harness secretKind="mailbox" />);
        const input = screen.getByLabelText('Password');
        expect(input).toHaveAttribute('type', 'text');
        expect(input).toHaveAttribute('autocomplete', 'off');
        expect(input).toHaveAttribute('autocapitalize', 'off');
        expect(input).toHaveAttribute('spellcheck', 'false');
        expect(input).toHaveAttribute('data-1p-ignore');
        expect(input).toHaveAttribute('data-lpignore', 'true');
        expect(input).toHaveAttribute('data-form-type', 'other');
        expect(input).toHaveClass(MASK);

        await userEvent.click(screen.getByRole('button', { name: 'Show' }));
        expect(input).toHaveAttribute('type', 'text');
        expect(input).not.toHaveClass(MASK);
        await userEvent.click(screen.getByRole('button', { name: 'Hide' }));
        expect(input).toHaveClass(MASK);
    });
});
