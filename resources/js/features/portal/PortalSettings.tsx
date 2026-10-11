import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '../../components/ui/Button';
import { useToast } from '../../components/ui/Toast';
import { PortalCard, PortalPage } from './PortalCard';
import { usePortalMe } from './usePortal';

type Copyable = 'username' | 'incoming' | 'outgoing';

/** The button's name, and what the live region says once it has copied. */
const COPY_LABEL = {
    username: { copy: 'settings.copyUsername', copied: 'settings.copiedUsername' },
    incoming: { copy: 'settings.copyIncoming', copied: 'settings.copiedIncoming' },
    outgoing: { copy: 'settings.copyOutgoing', copied: 'settings.copiedOutgoing' },
} as const;

/** How long a copy button says "Copied". */
const COPIED_MS = 2500;

/** One labelled row: the name on the left (above it on a phone), the value and any copy button on the right. */
function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-center gap-x-3 gap-y-1 border-t border-line-soft py-2.5 first:border-t-0 max-md:flex-wrap">
            <dt className="w-24 flex-none text-[13px] text-muted max-md:w-full max-md:text-sm">{label}</dt>
            <dd className="flex min-w-0 flex-1 items-center gap-3">{children}</dd>
        </div>
    );
}

interface CopyButtonProps {
    which: Copyable;
    text: string;
    /** The button that copied last, which says "Copied" for a moment. */
    copied: Copyable | null;
    onCopy: (which: Copyable, text: string) => void;
}

function CopyButton({ which, text, copied, onCopy }: CopyButtonProps) {
    const { t } = useTranslation('portal');
    const done = copied === which;
    return (
        <Button size="sm" className="flex-none" aria-label={t(COPY_LABEL[which].copy)} onClick={() => onCopy(which, text)}>
            {done ? t('settings.copied') : t('settings.copy')}
        </Button>
    );
}

function Value({ children }: { children: ReactNode }) {
    return <span className="min-w-0 flex-1 font-mono text-[13.5px] wrap-anywhere text-ink max-md:text-sm">{children}</span>;
}

/**
 * `/my/settings`: what a mail app needs, read-only. The username and the two server names have copy
 * buttons (ports and security are typed or picked from a list); the webmail card shows when the server has
 * one. The username is the ASCII address the mail server signs in with.
 */
export function PortalSettings() {
    const { t } = useTranslation('portal');
    const toast = useToast();
    const me = usePortalMe().data;
    const [copied, setCopied] = useState<Copyable | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    if (!me) return null;
    const settings = me.client_settings;

    function copy(which: Copyable, text: string) {
        // Through a promise so a missing clipboard (plain http) lands in the failure branch too.
        Promise.resolve()
            .then(() => navigator.clipboard.writeText(text))
            .then(
                () => {
                    setCopied(which);
                    clearTimeout(timer.current);
                    timer.current = setTimeout(() => setCopied(null), COPIED_MS);
                },
                () => {
                    setCopied(null);
                    toast.show({ kind: 'danger', text: t('settings.copyFailed') });
                },
            );
    }

    // Only a web address: the setting is the operator's, but a `javascript:` link would still run here.
    const webmail = settings.webmail_url !== null && /^https?:\/\//i.test(settings.webmail_url) ? settings.webmail_url : null;

    return (
        <PortalPage title={t('settings.title')}>
            <p className="-mt-2 text-[13.5px] text-muted max-md:text-sm">{t('settings.subtitle')}</p>
            {/* The button keeps its name (and the focus); this says what was copied. */}
            <p role="status" data-testid="copy-status" className="sr-only">
                {copied === null ? '' : t(COPY_LABEL[copied].copied)}
            </p>
            <PortalCard title={t('settings.account')}>
                <dl>
                    <Row label={t('settings.username')}>
                        <Value>{me.address}</Value>
                        <CopyButton which="username" text={me.address} copied={copied} onCopy={copy} />
                    </Row>
                    <Row label={t('settings.password')}>
                        <span className="text-[13.5px] text-body max-md:text-sm">{t('settings.passwordValue')}</span>
                    </Row>
                </dl>
            </PortalCard>
            <div className="grid gap-4 lg:grid-cols-2">
                <PortalCard title={t('settings.incoming')}>
                    <dl>
                        <Row label={t('settings.server')}>
                            <Value>{settings.imap_host}</Value>
                            <CopyButton which="incoming" text={settings.imap_host} copied={copied} onCopy={copy} />
                        </Row>
                        <Row label={t('settings.port')}>
                            <Value>{settings.imap_port}</Value>
                        </Row>
                        <Row label={t('settings.security')}>
                            <Value>{t('settings.sslTls')}</Value>
                        </Row>
                    </dl>
                </PortalCard>
                <PortalCard title={t('settings.outgoing')}>
                    <dl>
                        <Row label={t('settings.server')}>
                            <Value>{settings.smtp_host}</Value>
                            <CopyButton which="outgoing" text={settings.smtp_host} copied={copied} onCopy={copy} />
                        </Row>
                        <Row label={t('settings.port')}>
                            <Value>{settings.smtp_port}</Value>
                        </Row>
                        <Row label={t('settings.security')}>
                            <Value>{t('settings.starttls')}</Value>
                        </Row>
                    </dl>
                    <p className="mt-2 text-[13px] text-muted max-md:text-sm">{t('settings.outgoingAuth')}</p>
                </PortalCard>
            </div>
            {webmail !== null && (
                <PortalCard title={t('settings.webmailTitle')} subtitle={t('settings.webmail')}>
                    <a
                        href={webmail}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex h-10 items-center rounded-md border border-transparent bg-teal px-4 text-sm font-semibold text-white hover:bg-teal-dark max-md:h-12"
                    >
                        {t('settings.openWebmail')}
                    </a>
                </PortalCard>
            )}
        </PortalPage>
    );
}
