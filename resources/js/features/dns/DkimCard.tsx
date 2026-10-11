import { useEffect, useId, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem, isStepUpCancelled } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { cx } from '../../components/ui/cx';
import { useToast } from '../../components/ui/Toast';
import { formatDay } from '../../lib/format';
import { errorText, useSingleFlight } from '../domain/shared';
import { dkimPath, POLL_MS, RecordBlock, taskRunning, type DkimKey, type DkimState, type DnsCheck, type Publish } from './shared';

export interface DkimCardProps {
    /** Punycode domain name, as the API and DNS want it. */
    domain: string;
    unicode: string;
    /** The requester may create, activate and retire keys (a domain admin); everyone else reads. */
    admin: boolean;
    /** The DNS tab's latest `dkim` check, for whether DNS shows each key's record. */
    check: DnsCheck | undefined;
    /** Called once a DKIM task the card watched has finished, so the DNS check can catch up. */
    onTaskDone: () => void;
}

type Confirming = { kind: 'generate' } | { kind: 'activate'; selector: string; force: boolean } | { kind: 'retire'; selector: string } | null;

const TASK_KEYS = {
    'dkim.generate': 'dkim.task.generate',
    'dkim.activate': 'dkim.task.activate',
    'dkim.retire': 'dkim.task.retire',
    'dkim.inspect': 'dkim.task.inspect',
} as const;

/**
 * The domain's DKIM keys on the DNS tab: every role sees the keys and their records; a domain admin creates,
 * activates and retires them (each behind a confirmation, and step-up through apiFetch). While the helper
 * works on a task the card asks again every 5 s, and stops once the task is done or failed.
 */
export function DkimCard({ domain, unicode, admin, check, onTaskDone }: DkimCardProps) {
    const { t } = useTranslation('dns');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const titleId = useId();
    const [confirming, setConfirming] = useState<Confirming>(null);
    const [error, setError] = useState<string | undefined>();

    const state = useQuery({
        queryKey: queryKeys.dkim(domain),
        queryFn: () => apiFetch<DkimState>(dkimPath(domain)),
        refetchInterval: (query) => (taskRunning(query.state.data) ? POLL_MS : false),
    });
    const running = taskRunning(state.data);

    // A task seen running that is now gone (done): the key list changed, so the DNS check should look again.
    const onTaskDoneRef = useRef(onTaskDone);
    useEffect(() => {
        onTaskDoneRef.current = onTaskDone;
    });
    const sawRunning = useRef(false);
    const finished = state.data !== undefined && state.data.pending_task === null;
    useEffect(() => {
        if (running) {
            sawRunning.current = true;
            return;
        }
        if (!sawRunning.current) return;
        sawRunning.current = false;
        // A failed task changed nothing on the server.
        if (finished) onTaskDoneRef.current();
    }, [running, finished]);

    const write = useMutation({
        mutationFn: ({ path, json }: { path: string; json?: unknown }) =>
            apiFetch<DkimState>(path, json === undefined ? { method: 'POST' } : { method: 'POST', json }),
    });

    function send(path: string, json: unknown, done: string, current: Confirming) {
        setError(undefined);
        once(async () => {
            try {
                const body = await write.mutateAsync({ path, json });
                queryClient.setQueryData(queryKeys.dkim(domain), body);
                toast.show({ kind: 'success', text: done });
                setConfirming(null);
                void queryClient.invalidateQueries({ queryKey: ['audit-log'] });
            } catch (e) {
                if (isStepUpCancelled(e)) return;
                if (current?.kind === 'activate' && e instanceof ApiProblem && e.code === 'dkim_not_published') {
                    // DNS doesn't show the record: say so, and offer to activate anyway.
                    setConfirming({ ...current, force: true });
                    return;
                }
                const text = errorText(e, t('dkim.failed'));
                if (current === null) toast.show({ kind: 'danger', text });
                else setError(text);
                // A busy or changed key list: show what the server has now.
                void state.refetch();
            }
        });
    }

    function confirm() {
        if (confirming === null) return;
        if (confirming.kind === 'generate') {
            send(`${dkimPath(domain)}/generate`, undefined, t('dkim.generateQueued'), confirming);
        } else if (confirming.kind === 'activate') {
            const { selector, force } = confirming;
            send(`${dkimPath(domain, selector)}/activate`, { force }, t('dkim.activateQueued', { selector }), confirming);
        } else {
            const { selector } = confirming;
            send(`${dkimPath(domain, selector)}/retire`, undefined, t('dkim.retireQueued', { selector }), confirming);
        }
    }

    function open(next: Confirming) {
        setError(undefined);
        setConfirming(next);
    }

    const keys = (state.data?.keys ?? []).filter((k) => k.state !== 'retired');
    const task = state.data?.pending_task ?? null;

    return (
        <section aria-labelledby={titleId} className="rounded-[10px] border border-line bg-white">
            <div className="flex flex-wrap items-start gap-x-3 gap-y-2 border-b border-line px-3.5 py-3 md:px-[18px] md:py-3.5">
                <div className="min-w-0 flex-1 basis-[260px]">
                    <h3 id={titleId} className="text-sm font-bold text-ink md:text-[15px]">
                        {t('dkim.title')}
                    </h3>
                    <p className="mt-0.5 text-[12.5px] text-muted">{t('dkim.intro', { domain: unicode })}</p>
                </div>
                {admin && (
                    <div className="flex flex-wrap gap-2 max-md:w-full">
                        <Button
                            size="sm"
                            className="max-md:flex-1"
                            disabled={running || !state.data}
                            onClick={() => send(`${dkimPath(domain)}/inspect`, undefined, t('dkim.inspectQueued'), null)}
                        >
                            {t('dkim.inspect')}
                        </Button>
                        <Button size="sm" variant="primary" className="max-md:flex-1" disabled={running || !state.data} onClick={() => open({ kind: 'generate' })}>
                            {t('dkim.generate')}
                        </Button>
                    </div>
                )}
            </div>

            {task && (
                <div
                    role={task.status === 'failed' ? 'alert' : 'status'}
                    className={cx(
                        'mx-3.5 mt-3 flex flex-col gap-0.5 rounded-lg border px-3 py-2.5 text-[13px] md:mx-[18px]',
                        task.status === 'failed' ? 'border-danger-line bg-danger-bg text-danger' : 'border-line bg-subtle text-body',
                    )}
                >
                    {task.status === 'failed' ? (
                        <>
                            <span className="font-semibold">{t('dkim.taskFailed')}</span>
                            {task.error && <span className="font-mono text-[12.5px] break-all">{task.error}</span>}
                        </>
                    ) : (
                        <>
                            <span className="font-semibold">{t(TASK_KEYS[task.type])}</span>
                            <span className="text-[12.5px] text-muted">{t('dkim.taskWait')}</span>
                        </>
                    )}
                </div>
            )}

            {!admin && <p className="mx-3.5 mt-3 text-[12.5px] text-muted md:mx-[18px]">{t('dkim.readOnly')}</p>}

            {state.isPending ? (
                <p aria-busy="true" className="px-3.5 py-4 text-[13px] text-muted md:px-[18px]">
                    {t('loading')}
                </p>
            ) : state.isError && !state.data ? (
                <div role="alert" className="flex items-center gap-3 px-3.5 py-4 text-[13px] text-danger md:px-[18px]">
                    <span className="min-w-0 flex-1">{errorText(state.error, t('dkim.loadFailed'))}</span>
                    <Button size="sm" onClick={() => void state.refetch()}>
                        {t('retry')}
                    </Button>
                </div>
            ) : keys.length === 0 ? (
                <p className="px-3.5 py-4 text-[13px] text-muted md:px-[18px]">{t('dkim.none')}</p>
            ) : (
                <ul className="divide-y divide-line">
                    {keys.map((k) => (
                        <KeyRow
                            key={k.selector}
                            k={k}
                            domain={domain}
                            check={check}
                            admin={admin}
                            busy={running}
                            onActivate={() => open({ kind: 'activate', selector: k.selector, force: false })}
                            onRetire={() => open({ kind: 'retire', selector: k.selector })}
                        />
                    ))}
                </ul>
            )}

            {confirming?.kind === 'generate' && (
                <ConfirmDialog
                    tone="primary"
                    title={t('dkim.generateTitle')}
                    confirm={t('dkim.generateConfirm')}
                    cancel={t('dkim.cancel')}
                    busy={write.isPending}
                    error={error}
                    onClose={() => setConfirming(null)}
                    onConfirm={confirm}
                >
                    {t('dkim.generateText', { domain: unicode })}
                </ConfirmDialog>
            )}
            {confirming?.kind === 'activate' && (
                <ConfirmDialog
                    tone={confirming.force ? 'danger' : 'primary'}
                    title={confirming.force ? t('dkim.notPublishedTitle', { selector: confirming.selector }) : t('dkim.activateTitle', { selector: confirming.selector })}
                    confirm={confirming.force ? t('dkim.activateAnyway') : t('dkim.activateConfirm')}
                    cancel={t('dkim.cancel')}
                    busy={write.isPending}
                    error={error}
                    onClose={() => setConfirming(null)}
                    onConfirm={confirm}
                >
                    {confirming.force ? t('dkim.notPublishedText') : t('dkim.activateText', { domain: unicode })}
                </ConfirmDialog>
            )}
            {confirming?.kind === 'retire' && (
                <ConfirmDialog
                    tone="danger"
                    title={t('dkim.retireTitle', { selector: confirming.selector })}
                    confirm={t('dkim.retireConfirm')}
                    cancel={t('dkim.cancel')}
                    busy={write.isPending}
                    error={error}
                    onClose={() => setConfirming(null)}
                    onConfirm={confirm}
                >
                    {t('dkim.retireText')}
                </ConfirmDialog>
            )}
        </section>
    );
}

interface KeyRowProps {
    k: DkimKey;
    domain: string;
    check: DnsCheck | undefined;
    admin: boolean;
    busy: boolean;
    onActivate: () => void;
    onRetire: () => void;
}

/** The selectors the console generates (es + date + optional letter); the installer's own `dkim` key is none. */
const CONSOLE_SELECTOR = /^es\d{8}[a-z]?$/;

function KeyRow({ k, domain, check, admin, busy, onActivate, onRetire }: KeyRowProps) {
    const { t } = useTranslation('dns');
    // What the DNS check says about this very key: its entry for the selector counts only when it looked for
    // the key's current record (the same record the entry's p_sha256 was taken from). A selector can be
    // reused for a new key, and a check from before then says nothing about it.
    const seen = check?.selectors?.find((s) => s.selector === k.selector && k.txt !== null && s.publish.value === k.txt);
    // The DNS check's own record (with the zone line) when it has looked for this key; else the bare TXT.
    const record: Publish | null =
        seen?.publish ?? (k.txt ? { name: `${k.selector}._domainkey.${domain}.`, type: 'TXT', value: k.txt, zone: '' } : null);

    const consoleKey = CONSOLE_SELECTOR.test(k.selector);

    return (
        <li className="px-3.5 py-3 md:px-[18px]">
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                <span className="font-mono text-[13.5px] font-semibold text-ink">{k.selector}</span>
                <span
                    className={cx(
                        'rounded-full border px-2.5 py-0.5 text-xs font-bold',
                        k.state === 'active' ? 'border-teal/30 bg-tint text-teal-dark' : 'border-line bg-subtle text-body',
                    )}
                >
                    {k.state === 'active' ? t('dkim.state.active') : t('dkim.state.pending')}
                </span>
                {seen && <span className={cx('text-xs font-semibold', seen.published ? 'text-teal-dark' : 'text-warn')}>{seen.published ? t('dkim.inDns') : t('dkim.notInDns')}</span>}
                {k.created_at && <span className="text-xs text-muted">{t('dkim.created', { date: formatDay(k.created_at) })}</span>}
                {admin && k.state === 'pending' && consoleKey && (
                    <div className="ml-auto flex gap-2 max-md:w-full">
                        <Button size="sm" variant="primary" className="max-md:flex-1" aria-label={t('dkim.activateFor', { selector: k.selector })} disabled={busy} onClick={onActivate}>
                            {t('dkim.activate')}
                        </Button>
                        <Button size="sm" variant="danger" className="max-md:flex-1" aria-label={t('dkim.retireFor', { selector: k.selector })} disabled={busy} onClick={onRetire}>
                            {t('dkim.retire')}
                        </Button>
                    </div>
                )}
            </div>
            {!consoleKey && <p className="mt-1.5 text-[12.5px] text-muted">{t('dkim.foreignKey')}</p>}
            {record ? <RecordBlock record={record} /> : <p className="mt-1.5 text-[12.5px] text-muted">{t('dkim.noRecord')}</p>}
        </li>
    );
}
