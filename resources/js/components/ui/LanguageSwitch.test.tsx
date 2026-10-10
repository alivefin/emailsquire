import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSwitch } from './LanguageSwitch';
import { currentLocale } from '../../i18n';

describe('LanguageSwitch', () => {
    it('offers Finnish in English and switches back and forth', async () => {
        const user = userEvent.setup();
        render(<LanguageSwitch />);
        await user.click(screen.getByRole('button', { name: 'Suomeksi' }));
        expect(currentLocale()).toBe('fi');
        expect(document.documentElement.lang).toBe('fi');
        await user.click(await screen.findByRole('button', { name: 'In English' }));
        expect(currentLocale()).toBe('en');
        expect(screen.getByRole('button', { name: 'Suomeksi' })).toBeInTheDocument();
    });
});
