import type { ReactNode } from 'react';
import { Button } from './Button';
import { Dialog } from './Dialog';

export interface ConfirmDialogProps {
    tone: 'primary' | 'danger';
    title: string;
    confirm: string;
    cancel: string;
    busy: boolean;
    error: string | undefined;
    onClose: () => void;
    onConfirm: () => void;
    children: ReactNode;
}

/** A small yes-or-no dialog for one action, with room for the error when it fails. */
export function ConfirmDialog({ tone, title, confirm, cancel, busy, error, onClose, onConfirm, children }: ConfirmDialogProps) {
    return (
        <Dialog
            open
            size="sm"
            onClose={onClose}
            title={title}
            footer={
                <>
                    <Button onClick={onClose}>{cancel}</Button>
                    <Button variant={tone} busy={busy} onClick={onConfirm}>
                        {confirm}
                    </Button>
                </>
            }
        >
            <p className="leading-relaxed">{children}</p>
            {error && (
                <p role="alert" className="mt-3 text-[12.5px] text-danger">
                    {error}
                </p>
            )}
        </Dialog>
    );
}
