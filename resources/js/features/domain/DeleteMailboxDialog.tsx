import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import type { Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { useToast } from '../../components/ui/Toast';
import { errorText, mailboxesPath, refreshAfterMailboxWrite, useSingleFlight } from './shared';

type Deleted = Json<'/domains/{domain}/mailboxes/{mailbox}', 'delete'>;

export interface DeleteMailboxDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    local: string;
    /** The address as shown to people (unicode domain). */
    address: string;
    onDeleted?: () => void;
}

export function DeleteMailboxDialog({ open, ...rest }: DeleteMailboxDialogProps) {
    // Mounting the body only while open drops any error when the dialog closes.
    return open ? <Body open {...rest} /> : null;
}

function Body({ onClose, domain, local, address, onDeleted }: DeleteMailboxDialogProps) {
    const { t } = useTranslation('mailboxes');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [error, setError] = useState<string | undefined>();

    const remove = useMutation({
        mutationFn: () => apiFetch<Deleted>(mailboxesPath(domain, local), { method: 'DELETE' }),
        onSuccess: async () => {
            await refreshAfterMailboxWrite(queryClient);
            toast.show({ kind: 'success', text: t('deleteDialog.deleted', { address }) });
            onClose();
            onDeleted?.();
        },
        onError: (e) => setError(errorText(e, t('deleteDialog.failed'))),
    });

    function submit() {
        setError(undefined);
        once(() => remove.mutateAsync());
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={t('deleteDialog.title', { address })}
            footer={
                <>
                    <Button onClick={onClose}>{t('deleteDialog.cancel')}</Button>
                    <Button variant="danger" busy={remove.isPending} onClick={submit}>
                        {t('deleteDialog.confirm')}
                    </Button>
                </>
            }
        >
            <ul className="list-disc space-y-1 pl-[18px] leading-relaxed">
                <li>{t('deleteDialog.signIns')}</li>
                <li>{t('deleteDialog.trash')}</li>
                <li>{t('deleteDialog.forwarding')}</li>
                <li>{t('deleteDialog.reuse')}</li>
            </ul>
            {error && (
                <p role="alert" className="mt-3 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </Dialog>
    );
}
