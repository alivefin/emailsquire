import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Json } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { cx } from '../../components/ui/cx';
import { useToast } from '../../components/ui/Toast';

export type DnsAnswer = Json<'/domains/{domain}/dns', 'get'>;
export type DnsResult = NonNullable<DnsAnswer['result']>;
export type DnsCheck = DnsResult['checks'][string];
export type DnsStatus = DnsCheck['status'];
export type Publish = NonNullable<DnsCheck['publish']>;
export type DkimState = Json<'/domains/{domain}/dkim', 'get'>;
export type DkimKey = DkimState['keys'][number];

/** How often the tab asks again while a DNS check or a DKIM task is still running. */
export const POLL_MS = 5000;

/** The checks in the order they are shown; the backend keys `checks` by these names. */
export const CHECK_ORDER = ['mx', 'spf', 'dkim', 'dmarc', 'ptr', 'mta_sts', 'autoconfig'] as const;

export function dnsPath(domain: string): string {
    return `/domains/${encodeURIComponent(domain)}/dns`;
}

export function dkimPath(domain: string, selector?: string): string {
    const base = `/domains/${encodeURIComponent(domain)}/dkim`;
    return selector === undefined ? base : `${base}/${encodeURIComponent(selector)}`;
}

/** A DKIM task the helper has not finished yet. */
export function taskRunning(state: DkimState | undefined): boolean {
    const status = state?.pending_task?.status;
    return status === 'pending' || status === 'running';
}

const badgeClass: Record<DnsStatus, string> = {
    ok: 'border-teal/30 bg-tint text-teal-dark',
    warn: 'border-warn-line bg-warn-bg text-warn',
    fail: 'border-danger-line bg-danger-bg text-danger',
    info: 'border-line bg-subtle text-body',
};

export function StatusBadge({ status }: { status: DnsStatus }) {
    const { t } = useTranslation('dns');
    return (
        <span className={cx('inline-flex flex-none items-center rounded-full border px-2.5 py-0.5 text-xs font-bold', badgeClass[status])}>
            {t(`status.${status}`)}
        </span>
    );
}

/** A value from DNS or to put there: monospaced, and long ones (DKIM keys) wrap anywhere. */
export const monoClass = 'font-mono text-[12.5px] leading-relaxed break-all whitespace-pre-wrap text-ink';

/** Copy, then Copied; the accessible name says what is copied. */
export function CopyButton({ text, label, doneLabel }: { text: string; label: string; doneLabel: string }) {
    const { t } = useTranslation('dns');
    const toast = useToast();
    const [copied, setCopied] = useState(false);

    function copy() {
        // Through a promise so a missing clipboard (plain http) lands in the failure branch too.
        Promise.resolve()
            .then(() => navigator.clipboard.writeText(text))
            .then(
                () => setCopied(true),
                () => toast.show({ kind: 'danger', text: t('copyFailed') }),
            );
    }

    return (
        <Button size="sm" className="flex-none" aria-label={copied ? doneLabel : label} onClick={copy}>
            {copied ? t('copied') : t('copy')}
        </Button>
    );
}

/**
 * A record to publish: its name and type, the value with Copy, and the zone file line with Copy. The zone
 * line is left out when it is empty (a DKIM key the DNS check has not seen yet: the server writes that line).
 */
export function RecordBlock({ record, heading }: { record: Publish; heading?: string }) {
    const { t } = useTranslation('dns');
    const names = { name: record.name, type: record.type };
    return (
        <div className="mt-2.5 rounded-lg border border-line bg-subtle px-3 py-2.5">
            {heading && <div className="mb-1.5 text-[12.5px] font-bold text-ink">{heading}</div>}
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[12.5px]">
                <dt className="font-semibold text-muted">{t('recordName')}</dt>
                <dd className={monoClass}>{record.name}</dd>
                <dt className="font-semibold text-muted">{t('recordType')}</dt>
                <dd className={monoClass}>{record.type}</dd>
            </dl>
            <div className="mt-2 text-[12px] font-semibold text-muted">{t('recordValue')}</div>
            <div className="mt-1 flex items-start gap-2 max-md:flex-col">
                <code className={cx(monoClass, 'block min-w-0 flex-1 rounded-md border border-line bg-white px-2 py-1.5 max-md:w-full')}>
                    {record.value}
                </code>
                <CopyButton text={record.value} label={t('copyValue', names)} doneLabel={t('copiedValue', names)} />
            </div>
            {record.zone !== '' && (
                <>
                    <div className="mt-2 text-[12px] font-semibold text-muted">{t('zoneLine')}</div>
                    <div className="mt-1 flex items-start gap-2 max-md:flex-col">
                        <code className={cx(monoClass, 'block min-w-0 flex-1 rounded-md border border-line bg-white px-2 py-1.5 max-md:w-full')}>
                            {record.zone}
                        </code>
                        <CopyButton text={record.zone} label={t('copyZone', names)} doneLabel={t('copiedZone', names)} />
                    </div>
                </>
            )}
        </div>
    );
}
