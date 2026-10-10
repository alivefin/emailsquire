import { useId, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { cx } from '../../components/ui/cx';
import { borderFor, controlClass, labelClass } from '../../components/ui/fieldStyles';
import { useToast } from '../../components/ui/Toast';

export interface DeleteDomainDialogProps {
    open: boolean;
    onClose: () => void;
    domain: { name: string; unicode_name: string; mailbox_count: number; forwarding_count: number };
    onDeleted?: () => void;
}

export function DeleteDomainDialog({ open, ...rest }: DeleteDomainDialogProps) {
    // Mounting the body only while open drops the typed text and any error when the dialog closes.
    return open ? <Body open {...rest} /> : null;
}

function Body({ onClose, domain, onDeleted }: DeleteDomainDialogProps) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const inputId = useId();
    const [text, setText] = useState('');
    const [error, setError] = useState<string | undefined>();

    const matches = text === domain.name || text === domain.unicode_name;

    const remove = useMutation({
        mutationFn: () =>
            apiFetch<undefined>(`/domains/${encodeURIComponent(domain.name)}`, {
                method: 'DELETE',
                json: { confirm_name: text },
            }),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.domainNames }),
                queryClient.invalidateQueries({ queryKey: ['addresses'] }),
                queryClient.invalidateQueries({ queryKey: queryKeys.dashboard }),
                queryClient.invalidateQueries({ queryKey: queryKeys.pinned }),
            ]);
            toast.show({ kind: 'success', text: `Deleted ${domain.unicode_name}.` });
            onClose();
            onDeleted?.();
        },
        onError: (e) => {
            const field = fieldErrors(e).confirm_name;
            setError(field ?? (e instanceof ApiProblem ? problemMessage(e) : 'The domain could not be deleted.'));
        },
    });

    function submit() {
        if (!matches || remove.isPending) return;
        setError(undefined);
        remove.mutate();
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Delete ${domain.unicode_name}?`}
            footer={
                <>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button variant="danger" disabled={!matches} busy={remove.isPending} onClick={submit}>
                        Delete domain
                    </Button>
                </>
            }
        >
            <p>
                {domain.mailbox_count} mailboxes and {domain.forwarding_count} forwarding addresses will be deleted. Their
                mail is moved to trash and can be restored for 30 days.
            </p>
            <form
                className="mt-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label htmlFor={inputId} className={labelClass}>
                    Type <span className="rounded bg-subtle px-1.5 py-px font-mono text-[12.5px]">{domain.unicode_name}</span> to
                    confirm
                </label>
                <input
                    id={inputId}
                    value={text}
                    onChange={(e) => {
                        setText(e.target.value);
                        setError(undefined);
                    }}
                    spellCheck={false}
                    autoComplete="off"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${inputId}-msg` : undefined}
                    className={cx(controlClass, borderFor(error))}
                />
                {error && (
                    <p id={`${inputId}-msg`} role="alert" className="mt-1 text-[12.5px] text-danger">
                        {error}
                    </p>
                )}
            </form>
        </Dialog>
    );
}
