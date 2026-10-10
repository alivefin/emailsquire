import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isStepUpCancelled } from '../../api/problems';
import { useToast } from '../../components/ui/Toast';
import { errorText, useSingleFlight } from '../domain/shared';
import { refreshAfterUserWrite, type UserDetail } from './shared';

/** Runs a write to a user, then toasts and refetches; a failure stays in the dialog, a cancelled step-up is quiet. */
export function useUserWrite<V>(user: UserDetail, self: boolean, run: (v: V) => Promise<unknown>, failed: string) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const [error, setError] = useState<string | undefined>();
    const mutation = useMutation({
        mutationFn: run,
        onError: (e) => {
            if (!isStepUpCancelled(e)) setError(errorText(e, failed));
        },
    });
    return {
        error,
        busy: mutation.isPending,
        clear: () => setError(undefined),
        send(value: V, done: string, after?: () => void) {
            setError(undefined);
            once(async () => {
                await mutation.mutateAsync(value);
                toast.show({ kind: 'success', text: done });
                after?.();
                await refreshAfterUserWrite(queryClient, self);
            });
        },
    };
}
