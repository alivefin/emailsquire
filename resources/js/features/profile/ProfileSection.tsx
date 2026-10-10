import { useId, type ReactNode, type Ref } from 'react';
import { cx } from '../../components/ui/cx';

export interface ProfileSectionProps {
    title: string;
    subtitle?: string;
    /** Shown at the right of the heading (a status, say). */
    aside?: ReactNode;
    id?: string;
    /** For a section that a link or a redirect focuses (`#two-factor`). */
    focusable?: boolean;
    ref?: Ref<HTMLElement>;
    children: ReactNode;
}

/** One card of My profile: a region named by its heading. */
export function ProfileSection({ title, subtitle, aside, id, focusable = false, ref, children }: ProfileSectionProps) {
    const headingId = useId();
    return (
        <section
            ref={ref}
            id={id}
            aria-labelledby={headingId}
            tabIndex={focusable ? -1 : undefined}
            className={cx(
                'scroll-mt-4 rounded-[10px] border border-line bg-white p-5 max-md:p-4',
                focusable && 'outline-none focus-visible:ring-2 focus-visible:ring-teal/40',
            )}
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id={headingId} className="text-base font-bold text-ink">
                    {title}
                </h2>
                {aside}
            </div>
            {subtitle && <p className="mt-1 text-[13px] text-muted">{subtitle}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}
