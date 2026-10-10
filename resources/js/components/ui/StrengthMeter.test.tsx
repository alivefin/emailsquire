import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StrengthMeter } from './StrengthMeter';

const filled = () => screen.getAllByTestId('strength-segment').filter((s) => s.dataset.filled === 'true');

describe('StrengthMeter', () => {
    it.each([0, 1, 2, 3, 4] as const)('fills %i of 4 segments', (score) => {
        render(<StrengthMeter score={score} />);
        expect(screen.getAllByTestId('strength-segment')).toHaveLength(4);
        expect(filled()).toHaveLength(score);
    });

    it('shows the prototype labels', () => {
        const { rerender } = render(<StrengthMeter score={0} empty />);
        expect(screen.getByText('At least 12 characters, not just letters.')).toBeInTheDocument();
        rerender(<StrengthMeter score={1} />);
        expect(screen.getByText('Too weak — use 12+ characters with a number or symbol.')).toBeInTheDocument();
        rerender(<StrengthMeter score={2} />);
        expect(screen.getByText('Weak — add length or a symbol.')).toBeInTheDocument();
        rerender(<StrengthMeter score={3} />);
        expect(screen.getByText('Good.')).toBeInTheDocument();
        rerender(<StrengthMeter score={4} />);
        expect(screen.getByText('Strong.')).toBeInTheDocument();
    });
});
