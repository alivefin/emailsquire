import { useTranslation } from 'react-i18next';
import { PortalCard, PortalPage } from './PortalCard';
import { usePortalMe } from './usePortal';

/**
 * Only a plain address becomes a `mailto:` link. Anything that a mail app would read as more than the
 * recipient (`?cc=`, `&`, a `%` escape, `#`, whitespace or a second `@`) is shown as text instead.
 */
const PLAIN_ADDRESS = /^[^\s?&%#/@]+@[^\s?&%#/@]+$/;

/**
 * `/my/help`: who to ask. The domain's managers (name and a mail link) when it has any; else the server's
 * support contact, as plain text (the operator's free text: never a link or markup); else a general line.
 * Server admins are never listed. A forgotten mailbox password can only be reset by someone else.
 */
export function PortalHelp() {
    const { t } = useTranslation('portal');
    const me = usePortalMe().data;
    if (!me) return null;

    const managers = me.managers;
    const support = me.support_contact !== null && me.support_contact.trim() !== '' ? me.support_contact : null;

    return (
        <PortalPage title={t('help.title')}>
            <PortalCard>
                {managers.length > 0 ? (
                    <>
                        <p className="text-[13.5px] text-body max-md:text-sm">{t('help.managers', { count: managers.length })}</p>
                        <ul aria-label={t('help.contacts')} className="mt-3 flex flex-col gap-2">
                            {managers.map((manager) => (
                                <li key={manager.email} className="rounded-md border border-line-soft bg-subtle px-3 py-2.5">
                                    <div className="font-semibold wrap-anywhere text-ink">{manager.name}</div>
                                    {PLAIN_ADDRESS.test(manager.email) ? (
                                        <a
                                            href={`mailto:${manager.email}`}
                                            className="inline-flex min-h-11 items-center text-[13.5px] font-semibold wrap-anywhere text-teal-dark hover:underline md:min-h-0 max-md:text-sm"
                                        >
                                            {manager.email}
                                        </a>
                                    ) : (
                                        <div className="text-[13.5px] wrap-anywhere text-body max-md:text-sm">{manager.email}</div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                ) : support !== null ? (
                    <>
                        <p className="text-[13.5px] text-body max-md:text-sm">{t('help.support')}</p>
                        <p
                            data-testid="support-contact"
                            className="mt-2 rounded-md border border-line-soft bg-subtle px-3 py-2.5 text-[13.5px] whitespace-pre-line wrap-anywhere text-ink max-md:text-sm"
                        >
                            {support}
                        </p>
                    </>
                ) : (
                    <p className="text-[13.5px] text-body max-md:text-sm">{t('help.generic')}</p>
                )}
                <p className="mt-4 text-[13px] text-muted max-md:text-sm">
                    {managers.length > 0 ? t('help.forgotManager') : t('help.forgotAdministrator')}
                </p>
            </PortalCard>
        </PortalPage>
    );
}
