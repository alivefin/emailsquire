import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import type { Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { QuotaInput, type QuotaValue } from '../../components/ui/QuotaInput';
import { useToast } from '../../components/ui/Toast';
import { formatBytes } from '../../lib/format';
import { errorText, mailboxesPath, refreshAfterMailboxWrite, useSingleFlight } from './shared';

type BulkResult = Json<'/domains/{domain}/mailboxes/bulk', 'post'>;

export interface BulkLimitDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name. */
    domain: string;
    locals: string[];
    /** Called after the limit was set, before `onClose`; the page clears its selection here. */
    onDone?: () => void;
}

export function BulkLimitDialog({ open, ...rest }: BulkLimitDialogProps) {
    // Mounting the body only while open resets the value and any error when the dialog closes.
    return open ? <Body open {...rest} /> : null;
}

function Body({ onClose, domain, locals, onDone }: BulkLimitDialogProps) {
    const { t } = useTranslation('mailboxes');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [quota, setQuota] = useState<QuotaValue>({ bytes: null, problem: null });
    const [error, setError] = useState<string | undefined>();

    const save = useMutation({
        mutationFn: (bytes: number) =>
            apiFetch<BulkResult>(`${mailboxesPath(domain)}/bulk`, {
                method: 'POST',
                json: { locals, action: 'quota', quota_bytes: bytes },
            }),
        onSuccess: async (_result, bytes) => {
            await refreshAfterMailboxWrite(queryClient);
            toast.show({ kind: 'success', text: t('limitDialog.done', { limit: formatBytes(bytes), count: locals.length }) });
            onDone?.();
            onClose();
        },
        onError: (e) => {
            const fields = fieldErrors(e);
            setError(fields.quota_bytes ?? fields.locals ?? errorText(e, t('limitDialog.failed')));
        },
    });

    const bytes = quota.problem === null ? quota.bytes : null;

    function submit() {
        if (bytes === null) return;
        setError(undefined);
        once(() => save.mutateAsync(bytes));
    }

    return (
        <Dialog
            open
            onClose={onClose}
            size="sm"
            title={t('limitDialog.title', { count: locals.length })}
            footer={
                <>
                    <Button onClick={onClose}>{t('limitDialog.cancel')}</Button>
                    <Button variant="dark" disabled={bytes === null} busy={save.isPending} onClick={submit}>
                        {t('limitDialog.set')}
                    </Button>
                </>
            }
        >
            <form
                noValidate
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <QuotaInput label={t('limitDialog.newLimit')} onChange={setQuota} />
            </form>
            {error && (
                <p role="alert" className="mt-3 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </Dialog>
    );
}
