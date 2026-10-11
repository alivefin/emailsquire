import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { StorageBar } from '../../components/ui/StorageBar';
import { formatBytes } from '../../lib/format';
import { storageLevel } from '../../lib/storage';
import { LockedNote, PortalCard, PortalPage } from './PortalCard';
import { displayAddress, usePortalAliases, usePortalForwarding, usePortalMe, type PortalMe } from './usePortal';

const linkClass = 'mt-3 inline-flex min-h-11 items-center font-semibold text-teal-dark hover:underline md:min-h-0';

/** A summary card's list of addresses: one per line, long ones wrap. */
function AddressList({ addresses }: { addresses: string[] }) {
    if (addresses.length === 0) return null;
    return (
        <ul className="mt-2 flex flex-col gap-1 text-[13.5px] text-ink max-md:text-sm">
            {addresses.map((address) => (
                <li key={address} className="wrap-anywhere">
                    {address}
                </li>
            ))}
        </ul>
    );
}

function StorageCard({ me }: { me: PortalMe }) {
    const { t } = useTranslation('portal');
    const used = me.used_bytes;
    const limit = me.quota_bytes;
    const level = storageLevel(used, limit, false);
    const caption =
        used === null
            ? t('home.storage.unavailable', { limit: formatBytes(limit) })
            : t('home.storage.usedOf', { used: formatBytes(used), limit: formatBytes(limit) });

    return (
        <PortalCard title={t('home.storage.title')}>
            <StorageBar used={used} limit={limit} disabled={false} caption={caption} className="[&_[role=progressbar]]:h-2.5" />
            {level === 'warn' && <p className="mt-2 text-[13px] text-warn max-md:text-sm">{t('home.storage.almostFull')}</p>}
            {level === 'crit' && <p className="mt-2 text-[13px] text-danger max-md:text-sm">{t('home.storage.full')}</p>}
        </PortalCard>
    );
}

/** What the mailbox forwards to. Readable while the domain's switch is off; the link then only views. */
export function ForwardingSummary({ me, link = true }: { me: PortalMe; link?: boolean }) {
    const { t } = useTranslation('portal');
    const forwarding = usePortalForwarding();
    const allowed = me.self_service.forwarding;
    const data = forwarding.data;

    return (
        <PortalCard title={t('home.forwarding.title')}>
            {data === undefined ? (
                forwarding.isError && <p className="text-[13px] text-muted max-md:text-sm">{t('layout.loadSummaryFailed')}</p>
            ) : data.targets.length === 0 ? (
                <p className="text-[13.5px] text-body max-md:text-sm">{t('home.forwarding.off')}</p>
            ) : (
                <>
                    <p className="text-[13.5px] text-body max-md:text-sm">
                        {t('home.forwarding.on', { count: data.targets.length })}
                    </p>
                    <AddressList addresses={data.targets} />
                    <p className="mt-2 text-[13px] text-muted max-md:text-sm">
                        {data.keep_copy ? t('home.forwarding.keepCopy') : t('home.forwarding.forwardOnly')}
                    </p>
                </>
            )}
            {!allowed && <LockedNote>{t('layout.locked')}</LockedNote>}
            {link && (
                <Link to="/my/forwarding" className={linkClass}>
                    {allowed ? t('home.forwarding.manage') : t('home.forwarding.view')}
                </Link>
            )}
        </PortalCard>
    );
}

/** The extra addresses that deliver only to this mailbox. Readable while the domain's switch is off. */
export function AliasesSummary({ me, link = true }: { me: PortalMe; link?: boolean }) {
    const { t } = useTranslation('portal');
    const aliases = usePortalAliases();
    const allowed = me.self_service.aliases;
    const data = aliases.data;
    const shown = (data?.data ?? []).map((alias) => `${alias.local}@${me.domain_unicode}`);

    return (
        <PortalCard title={t('home.aliases.title')}>
            {data === undefined ? (
                aliases.isError && <p className="text-[13px] text-muted max-md:text-sm">{t('layout.loadSummaryFailed')}</p>
            ) : shown.length === 0 ? (
                <p className="text-[13.5px] text-body max-md:text-sm">{t('home.aliases.none')}</p>
            ) : (
                <>
                    <p className="text-[13.5px] text-body max-md:text-sm">{t('home.aliases.count', { count: shown.length })}</p>
                    <AddressList addresses={shown} />
                </>
            )}
            {!allowed && <LockedNote>{t('layout.locked')}</LockedNote>}
            {link && (
                <Link to="/my/aliases" className={linkClass}>
                    {allowed ? t('home.aliases.manage') : t('home.aliases.view')}
                </Link>
            )}
        </PortalCard>
    );
}

/**
 * Forwarding and Aliases until their own screens exist (Task 6): the same summary as on Home, without the
 * link to itself.
 */
export function PortalSummaryPage({ kind }: { kind: 'forwarding' | 'aliases' }) {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    return (
        <PortalPage title={t(kind === 'forwarding' ? 'nav.forwarding' : 'nav.aliases')}>
            {me && (kind === 'forwarding' ? <ForwardingSummary me={me} link={false} /> : <AliasesSummary me={me} link={false} />)}
        </PortalPage>
    );
}

/** `/my`: the address, storage against the quota, and what forwards and aliases there are. */
export function PortalHome() {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    if (!me) return null;

    return (
        <PortalPage title={t('home.title')}>
            <div className="rounded-[10px] border border-line bg-white p-5 max-md:p-4">
                <div className="text-[13px] text-muted max-md:text-sm">{t('home.address')}</div>
                <div className="mt-0.5 text-lg font-bold wrap-anywhere">{displayAddress(me)}</div>
            </div>
            <StorageCard me={me} />
            <div className="grid gap-4 lg:grid-cols-2">
                <ForwardingSummary me={me} />
                <AliasesSummary me={me} />
            </div>
        </PortalPage>
    );
}
