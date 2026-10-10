import { useEffect, useId, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '../../components/ui/Button';
import { useToast } from '../../components/ui/Toast';

export const RECOVERY_CODES_FILE = 'emailsquire-recovery-codes.txt';

export interface RecoveryCodesProps {
    codes: string[];
    /** Just created (enrolment or regenerate): the heading asks the user to save them. */
    fresh: boolean;
    onDone: () => void;
}

/**
 * The recovery codes in a monospace grid, with Copy and Download. The codes come from the caller's
 * component state and leave with it: nothing here stores them, and the download's object URL is
 * revoked once the click has started it.
 */
export function RecoveryCodes({ codes, fresh, onDone }: RecoveryCodesProps) {
    const { t } = useTranslation('profile');
    const toast = useToast();
    const headingId = useId();
    const heading = useRef<HTMLHeadingElement>(null);

    useEffect(() => {
        heading.current?.focus();
    }, []);

    async function copy() {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            toast.show({ kind: 'success', text: t('codes.copied') });
        } catch {
            toast.show({ kind: 'danger', text: t('copyFailed') });
        }
    }

    function download() {
        const url = URL.createObjectURL(new Blob([codes.join('\n')], { type: 'text/plain' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = RECOVERY_CODES_FILE;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // After the click has handed the file to the browser: the URL would keep the codes in memory.
        setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    return (
        <div role="group" aria-labelledby={headingId} className="rounded-lg border border-line bg-subtle p-4">
            <h3 id={headingId} ref={heading} tabIndex={-1} className="text-[15px] font-bold text-ink outline-none">
                {fresh ? t('codes.freshTitle') : t('codes.title')}
            </h3>
            <p className="mt-1 text-[13px] text-body">{t('codes.text')}</p>
            <ul
                aria-label={t('codes.list')}
                className="mt-3 grid grid-cols-1 gap-x-4 gap-y-1.5 font-mono text-[13.5px] text-ink sm:grid-cols-2"
            >
                {codes.map((code) => (
                    <li key={code} className="rounded-md border border-line bg-white px-3 py-1.5 wrap-anywhere">
                        {code}
                    </li>
                ))}
            </ul>
            <div className="mt-4 flex flex-wrap gap-2">
                <Button onClick={() => void copy()}>{t('codes.copy')}</Button>
                <Button onClick={download}>{t('codes.download')}</Button>
                <Button variant="primary" onClick={onDone} className="ml-auto max-sm:ml-0">
                    {t('codes.done')}
                </Button>
            </div>
        </div>
    );
}
