import { useId, useState, type ReactNode } from 'react';
import { Button } from './Button';
import { cx } from './cx';
import { Dialog } from './Dialog';
import { borderFor, controlClass, labelClass } from './fieldStyles';

export interface TypedConfirmDialogProps {
    onClose: () => void;
    title: string;
    /** What the deletion takes with it. */
    children: ReactNode;
    /** The label of the text box ("Type example.org to confirm"). */
    prompt: ReactNode;
    /** True when the typed text names the thing to delete; the confirm button waits for it. */
    matches: (text: string) => boolean;
    confirm: string;
    cancel: string;
    busy: boolean;
    /** Shown under the text box. */
    error: string | undefined;
    /** The text changed: a good moment to drop an error about the old one. */
    onEdit?: () => void;
    onConfirm: (text: string) => void;
}

/**
 * A permanent deletion that asks for the thing's name to be typed first (a domain, a user's email). Mount it
 * only while open, so the typed text never outlives the dialog.
 */
export function TypedConfirmDialog({
    onClose,
    title,
    children,
    prompt,
    matches,
    confirm,
    cancel,
    busy,
    error,
    onEdit,
    onConfirm,
}: TypedConfirmDialogProps) {
    const inputId = useId();
    const [text, setText] = useState('');
    const ok = matches(text);

    function submit() {
        if (!ok || busy) return;
        onConfirm(text);
    }

    return (
        <Dialog
            open
            onClose={onClose}
            title={title}
            footer={
                <>
                    <Button onClick={onClose}>{cancel}</Button>
                    <Button variant="danger" disabled={!ok} busy={busy} onClick={submit}>
                        {confirm}
                    </Button>
                </>
            }
        >
            {children}
            <form
                className="mt-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label htmlFor={inputId} className={labelClass}>
                    {prompt}
                </label>
                <input
                    id={inputId}
                    value={text}
                    onChange={(e) => {
                        setText(e.target.value);
                        onEdit?.();
                    }}
                    spellCheck={false}
                    autoComplete="off"
                    autoCapitalize="none"
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

/** The name to type, set apart from the prompt around it. */
export const typedNameClass = 'rounded bg-subtle px-1.5 py-px font-mono text-[12.5px]';
