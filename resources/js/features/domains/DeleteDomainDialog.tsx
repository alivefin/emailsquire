import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trans, useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors, problemMessage } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { TypedConfirmDialog, typedNameClass } from '../../components/ui/TypedConfirmDialog';
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
    const { t } = useTranslation('domains');
    const queryClient = useQueryClient();
    const toast = useToast();
    const [error, setError] = useState<string | undefined>();

    const remove = useMutation({
        mutationFn: (text: string) =>
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
            toast.show({ kind: 'success', text: t('deleteDialog.deleted', { name: domain.unicode_name }) });
            onClose();
            onDeleted?.();
        },
        onError: (e) => {
            const field = fieldErrors(e).confirm_name;
            setError(field ?? (e instanceof ApiProblem ? problemMessage(e) : t('deleteDialog.failed')));
        },
    });

    return (
        <TypedConfirmDialog
            onClose={onClose}
            title={t('deleteDialog.title', { name: domain.unicode_name })}
            prompt={
                <Trans
                    t={t}
                    i18nKey="deleteDialog.typeToConfirm"
                    values={{ name: domain.unicode_name }}
                    components={[<span key="name" className={typedNameClass} />]}
                />
            }
            matches={(text) => text === domain.name || text === domain.unicode_name}
            confirm={t('deleteDialog.confirm')}
            cancel={t('cancel')}
            busy={remove.isPending}
            error={error}
            onEdit={() => setError(undefined)}
            onConfirm={(text) => {
                setError(undefined);
                remove.mutate(text);
            }}
        >
            <p>
                {t('deleteDialog.summary', {
                    mailboxes: t('deleteDialog.mailboxes', { count: domain.mailbox_count }),
                    addresses: t('deleteDialog.addresses', { count: domain.forwarding_count }),
                })}
            </p>
        </TypedConfirmDialog>
    );
}
