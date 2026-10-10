import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ChipInput } from './ChipInput';
import type { ChipKind } from './Chip';

const complete = (a: string) => (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a) ? null : `“${a}” is not a complete address.`);

function Harness({ initial = [], kindOf }: { initial?: string[]; kindOf?: (a: string) => ChipKind }) {
    const [value, setValue] = useState<string[]>(initial);
    return (
        <>
            <ChipInput label="Deliver to" value={value} onChange={setValue} validate={complete} kindOf={kindOf} placeholder="Add an address" />
            <output data-testid="value">{value.join('|')}</output>
            <button type="button">Elsewhere</button>
        </>
    );
}

const input = () => screen.getByLabelText('Deliver to');
const value = () => screen.getByTestId('value').textContent;

describe('ChipInput', () => {
    it('adds the trimmed, lowercased text on a comma', async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.type(input(), '  Ann@Example.ORG,');
        expect(value()).toBe('ann@example.org');
        expect(input()).toHaveValue('');
        expect(screen.getByText('ann@example.org', { selector: 'span' })).toBeInTheDocument();
    });

    it('adds on Enter and accepts a plus in the local part', async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.type(input(), 'me+tag@gmail.com{Enter}');
        expect(value()).toBe('me+tag@gmail.com');
    });

    it('removes the last chip on Backspace in an empty input, and not while text is typed', async () => {
        const user = userEvent.setup();
        render(<Harness initial={['a@b.co', 'c@d.co']} />);
        await user.type(input(), 'x{Backspace}');
        expect(value()).toBe('a@b.co|c@d.co');
        await user.type(input(), '{Backspace}');
        expect(value()).toBe('a@b.co');
    });

    it('ignores a duplicate and clears the input', async () => {
        const user = userEvent.setup();
        render(<Harness initial={['a@b.co']} />);
        await user.type(input(), 'A@B.co{Enter}');
        expect(value()).toBe('a@b.co');
        expect(input()).toHaveValue('');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('keeps an invalid entry in the input with the error shown', async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.type(input(), 'nope{Enter}');
        expect(value()).toBe('');
        expect(input()).toHaveValue('nope');
        expect(screen.getByRole('alert')).toHaveTextContent('“nope” is not a complete address.');
        expect(input()).toHaveAttribute('aria-invalid', 'true');
        await user.type(input(), '@x.io');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('adds the typed address when the input loses focus', async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.type(input(), 'ann@example.org');
        await user.click(screen.getByRole('button', { name: 'Elsewhere' }));
        expect(value()).toBe('ann@example.org');
    });

    it('splits pasted text on commas', async () => {
        const user = userEvent.setup();
        render(<Harness />);
        await user.click(input());
        await user.paste('a@b.co, c@d.co, e@f');
        expect(value()).toBe('a@b.co|c@d.co');
        expect(input()).toHaveValue('e@f');
    });

    it('removes a chip with its × button and colours it by kind', async () => {
        const user = userEvent.setup();
        render(<Harness initial={['a@b.co', 'c@d.co']} kindOf={(a) => (a === 'a@b.co' ? 'external' : 'server')} />);
        expect(screen.getByText('a@b.co').parentElement).toHaveClass('bg-warn-bg');
        await user.click(screen.getByRole('button', { name: 'Remove a@b.co' }));
        expect(value()).toBe('c@d.co');
    });
});
