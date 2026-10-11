import { useId, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { cx } from '../../components/ui/cx';

/** A portal page: its heading, then one card per task. */
export function PortalPage({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-4">
            <h1 className="text-xl font-bold text-ink max-md:text-lg">{title}</h1>
            {children}
        </div>
    );
}

export interface PortalCardProps {
    /** The card's heading. Left out on a page that is one card: the page's heading names it. */
    title?: string;
    subtitle?: string;
    className?: string;
    children: ReactNode;
}

const cardClass = 'rounded-[10px] border border-line bg-white p-5 max-md:p-4';
const subtitleClass = 'text-[13px] text-muted max-md:text-sm';

/** One task's card: a region named by its heading, as on My profile. */
export function PortalCard({ title, subtitle, className, children }: PortalCardProps) {
    const headingId = useId();
    if (title === undefined) {
        return (
            <div className={cx(cardClass, className)}>
                {subtitle && <p className={cx(subtitleClass, 'mb-3')}>{subtitle}</p>}
                {children}
            </div>
        );
    }
    return (
        <section aria-labelledby={headingId} className={cx(cardClass, className)}>
            <h2 id={headingId} className="text-base font-bold text-ink">
                {title}
            </h2>
            {subtitle && <p className={cx(subtitleClass, 'mt-1')}>{subtitle}</p>}
            <div className="mt-3">{children}</div>
        </section>
    );
}

/** The locked state: the domain doesn't let its mailbox owners change this. */
export function LockedNote({ children }: { children: ReactNode }) {
    return (
        <p className="mt-3 flex items-start gap-2 rounded-md border border-line bg-subtle px-3 py-2 text-[13px] text-body max-md:text-sm">
            <svg aria-hidden="true" viewBox="0 0 16 16" className="mt-0.5 size-3.5 flex-none fill-none stroke-muted stroke-[1.6]">
                <rect x="3" y="7" width="10" height="7" rx="1.5" />
                <path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2" />
            </svg>
            <span>{children}</span>
        </p>
    );
}

/** A card's body before its answer: loading, or a note that it failed. */
export function Pending({ failed }: { failed: boolean }) {
    const { t } = useTranslation('portal');
    return <p className="text-[13px] text-muted max-md:text-sm">{failed ? t('layout.loadSummaryFailed') : t('layout.loading')}</p>;
}
