import { useCallback, useId, useState } from 'react';
import { useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { formatDate, formatRelative } from '../../lib/format';
import { canWrite } from '../../lib/roles';
import { useIsPhone } from '../../lib/useIsPhone';
import { useDomainNames } from '../../layout/useNavData';
import { errorText, useSingleFlight } from '../domain/shared';
import { DkimCard } from './DkimCard';
import { CHECK_ORDER, dnsPath, monoClass, POLL_MS, RecordBlock, StatusBadge, type DnsAnswer, type DnsCheck } from './shared';

const panelClass = 'rounded-[10px] border border-line bg-white';

/**
 * `/domains/:domain/dns`: the latest DNS check, a row per check with what was found and, when something is
 * wrong, the record to publish; "Check now" for admins and managers; and the DKIM keys below. Every role
 * on the domain reads it.
 */
export function DnsTab() {
    const { t } = useTranslation('dns');
    const { domain = '' } = useParams();
    const phone = useIsPhone();
    const queryClient = useQueryClient();
    const once = useSingleFlight();
    const names = useDomainNames();
    const named = names.data?.find((d) => d.name === domain);
    const unicode = named?.unicode_name ?? domain;
    const role = named?.role;
    const writable = canWrite(role);
    const titleId = useId();

    const dns = useQuery({
        queryKey: queryKeys.dns(domain),
        queryFn: () => apiFetch<DnsAnswer>(dnsPath(domain)),
        // While a check waits in the queue, ask again until it has run.
        refetchInterval: (query) => (query.state.data?.checking === true ? POLL_MS : false),
    });
    const result = dns.data?.result ?? null;
    const checking = dns.data?.checking === true;

    const [recheckError, setRecheckError] = useState<string | undefined>();
    const recheck = useMutation({
        mutationFn: () => apiFetch<DnsAnswer>(`${dnsPath(domain)}/recheck`, { method: 'POST' }),
        onSuccess: (body) => {
            queryClient.setQueryData(queryKeys.dns(domain), body);
            void queryClient.invalidateQueries({ queryKey: ['audit-log'] });
        },
        onError: (e) =>
            setRecheckError(e instanceof ApiProblem && e.status === 429 ? t('recheckTooSoon') : errorText(e, t('recheckFailed'))),
    });

    function checkNow() {
        setRecheckError(undefined);
        once(() => recheck.mutateAsync());
    }

    // A finished DKIM task changed the keys: check again where allowed (quietly, a 429 included), else
    // just read the stored result again. The recheck is a real one, with its side effects: it is audited
    // as `domain.dns_checked` under the user's name, and it takes the domain's one-a-minute recheck slot,
    // so a "Check now" right after it answers "too soon".
    const afterDkimTask = useCallback(() => {
        const reread = () => void queryClient.invalidateQueries({ queryKey: queryKeys.dns(domain) });
        if (!writable) {
            reread();
            return;
        }
        apiFetch<DnsAnswer>(`${dnsPath(domain)}/recheck`, { method: 'POST' }).then(
            (body) => queryClient.setQueryData(queryKeys.dns(domain), body),
            reread,
        );
    }, [domain, queryClient, writable]);

    const rows = result ? CHECK_ORDER.flatMap((name) => (result.checks[name] ? [{ name, check: result.checks[name] }] : [])) : [];

    let body;
    if (dns.isPending) {
        body = (
            <p aria-busy="true" className="px-3.5 py-6 text-[13px] text-muted md:px-[18px]">
                {t('loading')}
            </p>
        );
    } else if (dns.isError && !dns.data) {
        body = (
            <div role="alert" className="flex items-center gap-3 px-3.5 py-6 text-[13px] text-danger md:px-[18px]">
                <span className="min-w-0 flex-1">{errorText(dns.error, t('loadFailed'))}</span>
                <Button size="sm" onClick={() => void dns.refetch()}>
                    {t('retry')}
                </Button>
            </div>
        );
    } else if (!result) {
        body = <p className="px-3.5 py-6 text-[13px] text-muted md:px-[18px]">{checking ? t('firstCheck') : t('notChecked')}</p>;
    } else {
        body = (
            <div className={cx(phone ? 'mt-2 flex flex-col gap-2' : 'divide-y divide-line')}>
                {rows.map(({ name, check }) => (
                    <CheckRow key={name} name={name} check={check} phone={phone} />
                ))}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <section aria-labelledby={titleId} className={cx(!phone && panelClass)}>
                <div className={cx('flex flex-wrap items-center gap-x-4 gap-y-2', !phone && 'border-b border-line px-[18px] py-3.5')}>
                    <div className="min-w-0 flex-1 basis-[280px]">
                        <h2 id={titleId} className="text-sm font-bold text-ink md:text-[15px]">
                            {t('title')}
                        </h2>
                        <p className="mt-0.5 text-[12.5px] text-muted">{t('intro', { domain: unicode })}</p>
                    </div>
                    <div className="flex items-center gap-3 max-md:w-full max-md:justify-between">
                        {checking ? (
                            <span role="status" className="text-[12.5px] font-semibold text-body">
                                {t('checking')}
                            </span>
                        ) : (
                            result && (
                                <span className="text-[12.5px] text-muted" title={formatDate(result.checked_at)}>
                                    {t('checked', { time: formatRelative(result.checked_at) })}
                                </span>
                            )
                        )}
                        {writable && (
                            <Button disabled={checking} busy={recheck.isPending} onClick={checkNow}>
                                {t('checkNow')}
                            </Button>
                        )}
                    </div>
                </div>
                {recheckError && (
                    <p role="alert" className={cx('text-[12.5px] text-danger', phone ? 'mt-2' : 'border-b border-line px-[18px] py-2')}>
                        {recheckError}
                    </p>
                )}
                {body}
            </section>
            <DkimCard domain={domain} unicode={unicode} admin={role === 'admin'} check={result?.checks.dkim} onTaskDone={afterDkimTask} />
        </div>
    );
}

function CheckRow({ name, check, phone }: { name: (typeof CHECK_ORDER)[number]; check: DnsCheck; phone: boolean }) {
    const { t } = useTranslation('dns');
    const id = useId();
    const showRecord = check.status !== 'ok' && check.publish !== undefined;
    // The record to publish already holds what is expected; say it once.
    const showExpected = !showRecord && (check.status === 'warn' || check.status === 'fail') && check.expected !== undefined && check.expected !== '';
    const hint = t(`hint.${check.hint_key}`, { defaultValue: '' });

    return (
        <section aria-labelledby={id} className={cx(phone ? cx(panelClass, 'px-3.5 py-3') : 'px-[18px] py-3.5')}>
            <div className={cx('flex gap-4', phone && 'flex-col gap-2')}>
                <div className={cx('flex flex-none items-start gap-2', phone ? 'justify-between' : 'w-[210px] flex-col')}>
                    <div className="min-w-0">
                        <h3 id={id} className="text-[13.5px] font-bold text-ink">
                            {t(`check.${name}`)}
                        </h3>
                        <p className="text-xs text-muted">{t(`about.${name}`)}</p>
                    </div>
                    <StatusBadge status={check.status} />
                </div>
                <div className="min-w-0 flex-1 text-[13px]">
                    {hint !== '' && <p className="leading-normal text-body">{hint}</p>}
                    <div className="mt-1.5 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <span className="text-[12.5px] font-semibold text-muted">{t('found')}</span>{' '}
                        {check.found.length === 0 ? (
                            <span className="text-[12.5px] text-muted">{t('foundNothing')}</span>
                        ) : (
                            <ul className="flex min-w-0 flex-col gap-0.5">
                                {check.found.map((value, i) => (
                                    <li key={i}>
                                        <code className={monoClass}>{value}</code>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    {showExpected && (
                        <div className="mt-1 flex flex-wrap items-baseline gap-x-2">
                            <span className="text-[12.5px] font-semibold text-muted">{t('expected')}</span>{' '}
                            <code className={cx(monoClass, 'min-w-0')}>{check.expected}</code>
                        </div>
                    )}
                    {showRecord && check.publish && <RecordBlock record={check.publish} heading={t('publish')} />}
                </div>
            </div>
        </section>
    );
}
