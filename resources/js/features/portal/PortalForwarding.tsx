import { useId, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, fieldErrors } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import type { ChipKind } from '../../components/ui/Chip';
import { ChipInput } from '../../components/ui/ChipInput';
import { cx } from '../../components/ui/cx';
import { labelClass } from '../../components/ui/fieldStyles';
import { useToast } from '../../components/ui/Toast';
import { ForwardingSummary } from './PortalHome';
import { Pending, PortalCard, PortalPage } from './PortalCard';
import {
    isSelfServiceDisabled,
    MAX_TARGETS,
    refreshPortal,
    usePortalForwarding,
    usePortalMe,
    writeErrorText,
    type PortalForwarding as Forwarding,
    type PortalMe,
} from './usePortal';

/** The longest address the server takes (CLAUDE.md: at most 100 characters). */
const MAX_ADDRESS = 100;
const ADDRESS = /^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/;
const TARGET_FIELD = /^targets\.(\d+)$/;

/** With no forwarding the server answers `keep_copy: false`; a new forwarding starts with the safer choice. */
function storedKeepCopy(stored: Forwarding): boolean {
    return stored.targets.length === 0 ? true : stored.keep_copy;
}

function sameList(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((x, i) => x === b[i]);
}

/**
 * `/my/forwarding`: where the mailbox's mail goes on to, at most 10 addresses, with a copy kept or not.
 * Saving with no addresses turns forwarding off. Whether a target is on this server is the server's call:
 * its refusal lands on the chip it names. While the domain's switch is off the page is the read-only
 * summary with the locked note.
 */
export function PortalForwarding() {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    const forwarding = usePortalForwarding();
    if (!me) return null;

    return (
        <PortalPage title={t('forwarding.title')}>
            {!me.self_service.forwarding ? (
                <ForwardingSummary me={me} link={false} />
            ) : forwarding.data === undefined ? (
                <PortalCard>
                    <Pending failed={forwarding.isError} />
                </PortalCard>
            ) : (
                <Form me={me} stored={forwarding.data} />
            )}
        </PortalPage>
    );
}

function Form({ me, stored }: { me: PortalMe; stored: Forwarding }) {
    const { t } = useTranslation('portal');
    const queryClient = useQueryClient();
    const toast = useToast();
    const fieldId = useId();
    const radioName = useId();
    const [targets, setTargets] = useState<string[]>(stored.targets);
    const [keepCopy, setKeepCopy] = useState(storedKeepCopy(stored));
    const [draft, setDraft] = useState('');
    // The server's refusal: the address it named (its chip turns red) and the message under the field.
    const [refused, setRefused] = useState<string | null>(null);
    const [fieldError, setFieldError] = useState<string | undefined>(undefined);
    const [formError, setFormError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const sending = useRef(false);

    const own = me.address.toLowerCase();

    function validate(a: string): string | null {
        if (a.length > MAX_ADDRESS) return t('forwarding.tooLong', { max: MAX_ADDRESS });
        if (!ADDRESS.test(a)) return t('forwarding.incomplete', { address: a });
        if (a === own) return t('forwarding.self');
        if (targets.length >= MAX_TARGETS) return t('forwarding.tooMany', { max: MAX_TARGETS });
        return null;
    }

    const pending = draft.trim().toLowerCase();
    const pendingOk = pending !== '' && !targets.includes(pending) && validate(pending) === null;
    const changed = !sameList(targets, stored.targets) || (targets.length > 0 && keepCopy !== storedKeepCopy(stored));
    const turningOff = stored.targets.length > 0 && targets.length === 0 && pending === '';

    function changeTargets(next: string[]) {
        setTargets(next);
        setFormError(null);
        // A refusal naming one address stays until that address is removed; any other goes with the change.
        if (refused === null || !next.includes(refused)) {
            setRefused(null);
            setFieldError(undefined);
        }
    }

    function showError(e: unknown, sent: string[]) {
        const fields = fieldErrors(e);
        const indexed = Object.entries(fields).find(([key]) => TARGET_FIELD.test(key));
        if (indexed) {
            const index = Number(TARGET_FIELD.exec(indexed[0])?.[1]);
            setRefused(sent[index] ?? null);
            setFieldError(indexed[1]);
        } else if (fields.targets !== undefined) {
            setFieldError(fields.targets);
        } else if (!(e instanceof ApiProblem && e.status === 401)) {
            // A 401 has already ended the session; the guard is on its way to sign-in.
            setFormError(writeErrorText(e));
        }
    }

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (sending.current) return;
        if (pending !== '' && !targets.includes(pending) && !pendingOk) {
            setFieldError(validate(pending) ?? undefined);
            return;
        }
        const list = pendingOk ? [...targets, pending] : targets;
        if (!changed && list === targets) return;
        if (list !== targets) setTargets(list);

        sending.current = true;
        setBusy(true);
        setRefused(null);
        setFieldError(undefined);
        setFormError(null);
        try {
            const saved = await apiFetch<Forwarding>('/portal/forwarding', { method: 'PUT', json: { targets: list, keep_copy: keepCopy } });
            queryClient.setQueryData(queryKeys.portalForwarding, saved);
            setTargets(saved.targets);
            setKeepCopy(storedKeepCopy(saved));
            toast.show({ kind: 'success', text: saved.targets.length === 0 ? t('forwarding.turnedOff') : t('forwarding.saved') });
            void refreshPortal(queryClient);
        } catch (err) {
            showError(err, list);
            if (isSelfServiceDisabled(err)) void refreshPortal(queryClient);
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    const kindOf = (a: string): ChipKind => (a === refused ? 'error' : 'target');
    const max = MAX_TARGETS;
    const hint = me.self_service.external_forwarding ? t('forwarding.hint', { max }) : t('forwarding.hintInternal', { max });

    return (
        <PortalCard subtitle={t('forwarding.subtitle')}>
            <form noValidate onSubmit={(e) => void submit(e)} className="flex max-w-[560px] flex-col gap-4">
                <div>
                    <ChipInput
                        id={fieldId}
                        label={t('forwarding.field')}
                        placeholder={t('forwarding.placeholder')}
                        value={targets}
                        onChange={changeTargets}
                        validate={validate}
                        kindOf={kindOf}
                        error={fieldError}
                        hint={hint}
                        onDraftChange={setDraft}
                    />
                    {turningOff && <p className="mt-1 text-[12.5px] text-muted max-md:text-sm">{t('forwarding.offHint')}</p>}
                </div>
                <fieldset>
                    <legend className={labelClass}>{t('forwarding.copyLegend')}</legend>
                    <div className="flex flex-col gap-2">
                        {[true, false].map((keep) => (
                            <label
                                key={String(keep)}
                                className={cx(
                                    'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2 text-[13.5px] font-semibold text-ink max-md:text-sm',
                                    keepCopy === keep ? 'border-teal bg-tint' : 'border-line-strong',
                                )}
                            >
                                <input
                                    type="radio"
                                    name={radioName}
                                    checked={keepCopy === keep}
                                    onChange={() => {
                                        setKeepCopy(keep);
                                        setFormError(null);
                                    }}
                                    className="size-4 flex-none accent-teal"
                                />
                                {keep ? t('forwarding.keepCopy') : t('forwarding.forwardOnly')}
                            </label>
                        ))}
                    </div>
                    {!keepCopy && (
                        <p className="mt-2 rounded-md border border-warn-line bg-warn-bg px-3 py-2 text-[13px] text-ink max-md:text-sm">
                            {t('forwarding.warning')}
                        </p>
                    )}
                </fieldset>
                {formError !== null && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {formError}
                    </p>
                )}
                <div>
                    <Button type="submit" variant="primary" size="lg" busy={busy} disabled={!changed && !pendingOk} className="max-md:w-full">
                        {t('forwarding.save')}
                    </Button>
                </div>
            </form>
        </PortalCard>
    );
}
