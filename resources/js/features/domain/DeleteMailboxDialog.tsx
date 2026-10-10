import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
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
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [error, setError] = useState<string | undefined>();

    const remove = useMutation({
        mutationFn: () => apiFetch<Deleted>(mailboxesPath(domain, local), { method: 'DELETE' }),
        onSuccess: async () => {
            await refreshAfterMailboxWrite(queryClient);
            toast.show({ kind: 'success', text: `Deleted ${address}. Its mail is moved to trash.` });
            onClose();
            onDeleted?.();
        },
        onError: (e) => setError(errorText(e, 'The mailbox could not be deleted.')),
    });

    function submit() {
        setError(undefined);
        once(() => remove.mutateAsync());
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Delete ${address}?`}
            footer={
                <>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button variant="danger" busy={remove.isPending} onClick={submit}>
                        Delete mailbox
                    </Button>
                </>
            }
        >
            <ul className="list-disc space-y-1 pl-[18px] leading-relaxed">
                <li>Sign-ins stop immediately and open sessions are closed.</li>
                <li>Its mail is moved to trash and can be restored for 30 days.</li>
                <li>Forwarding from this address is removed. Forwarding to it from other addresses is kept.</li>
                <li>The address can&apos;t be reused until its mail has been moved.</li>
            </ul>
            {error && (
                <p role="alert" className="mt-3 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </Dialog>
    );
}
