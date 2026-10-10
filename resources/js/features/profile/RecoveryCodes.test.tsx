import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ToastProvider } from '../../components/ui/Toast';
import { setLocale } from '../../i18n';
import { RecoveryCodes } from './RecoveryCodes';

const CODES = [
    'aaaaaaaaaa-1111111111',
    'bbbbbbbbbb-2222222222',
    'cccccccccc-3333333333',
    'dddddddddd-4444444444',
    'eeeeeeeeee-5555555555',
    'ffffffffff-6666666666',
    'gggggggggg-7777777777',
    'hhhhhhhhhh-8888888888',
];

function setup(onDone = vi.fn()) {
    const user = userEvent.setup();
    render(
        <ToastProvider>
            <RecoveryCodes codes={CODES} fresh onDone={onDone} />
        </ToastProvider>,
    );
    return { user, onDone };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('RecoveryCodes', () => {
    it('lists the codes in a monospace grid and focuses its heading', () => {
        setup();
        const list = screen.getByRole('list', { name: 'Recovery codes' });
        expect(list).toHaveClass('font-mono');
        expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual(CODES);
        expect(screen.getByRole('heading', { name: 'Save your recovery codes' })).toHaveFocus();
    });

    it('downloads a text Blob with the codes joined by newlines and revokes the URL after the click', async () => {
        let blob: Blob | undefined;
        const create = vi.fn((b: Blob) => {
            blob = b;
            return 'blob:codes';
        });
        const revoke = vi.fn();
        Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke });
        const clicked: { href: string; download: string }[] = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked.push({ href: this.href, download: this.download });
            expect(revoke).not.toHaveBeenCalled();
        });
        const { user } = setup();
        await user.click(screen.getByRole('button', { name: 'Download' }));

        expect(clicked).toEqual([{ href: 'blob:codes', download: 'emailsquire-recovery-codes.txt' }]);
        expect(blob?.type).toBe('text/plain');
        expect(await blob?.text()).toBe(CODES.join('\n'));
        await waitFor(() => expect(revoke).toHaveBeenCalledWith('blob:codes'));
        // The temporary link does not stay in the page.
        expect(document.querySelector('a[download]')).toBeNull();
    });

    it('copies the codes, one per line, and says so without repeating them', async () => {
        const { user } = setup();
        await user.click(screen.getByRole('button', { name: 'Copy' }));

        expect(await navigator.clipboard.readText()).toBe(CODES.join('\n'));
        const toast = await screen.findByTestId('toast');
        expect(toast).toHaveTextContent('Recovery codes copied.');
        expect(toast.textContent).not.toContain(CODES[0]);
    });

    it('calls onDone', async () => {
        const { user, onDone } = setup();
        await user.click(screen.getByRole('button', { name: 'Done' }));
        expect(onDone).toHaveBeenCalledOnce();
    });

    it('renders in Finnish', async () => {
        await setLocale('fi');
        setup();
        expect(screen.getByRole('heading', { name: 'Tallenna varakoodisi' })).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'Varakoodit' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Lataa' })).toBeInTheDocument();
    });
});
