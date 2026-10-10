import type { ReactNode } from 'react';
import { cx } from '../../components/ui/cx';

/**
 * The sign-in card (prototype lines 18–30): a centred card on desktop, a plain white page on phones.
 * Children start with any AuthBanner, then AuthHeading, as in the prototype.
 */
export function AuthCard({ children }: { children: ReactNode }) {
    return (
        <main className="grid min-h-dvh place-items-center bg-linear-to-b from-canvas to-[#eceef0] p-4 max-md:block max-md:bg-white max-md:p-0">
            <div className="w-[420px] max-w-full rounded-2xl border border-line bg-white p-8 shadow-[0_20px_50px_rgba(27,29,33,.08)] max-md:w-full max-md:rounded-none max-md:border-0 max-md:px-6 max-md:pt-14 max-md:pb-6 max-md:shadow-none">
                <div className="flex items-center gap-2.5 text-xl font-extrabold">
                    <span aria-hidden className="size-[30px] rounded-md bg-linear-135 from-teal to-teal-dark" />
                    EmailSquire
                </div>
                {children}
            </div>
        </main>
    );
}

export function AuthHeading({ title, subtitle }: { title: string; subtitle?: string }) {
    return (
        <>
            <h1 className="mt-[22px] text-[22px] font-bold max-md:mt-7 max-md:text-2xl">{title}</h1>
            {subtitle && <p className="mt-1 text-[13px] text-muted">{subtitle}</p>}
        </>
    );
}

type Tone = 'warn' | 'success' | 'danger';

const TONES: Record<Tone, { box: string; mark: string; glyph: string }> = {
    warn: { box: 'border-warn-line bg-warn-bg', mark: 'text-warn', glyph: '!' },
    success: { box: 'border-teal/30 bg-tint', mark: 'text-teal-dark', glyph: '✓' },
    danger: { box: 'border-danger-line bg-danger-bg/40', mark: 'text-danger', glyph: '✕' },
};

/** A notice above the heading: the session-expired, password-set or invalid-link message. */
export function AuthBanner({ tone, children }: { tone: Tone; children: ReactNode }) {
    const t = TONES[tone];
    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cx('mt-[18px] flex gap-2.5 rounded-md border px-3 py-2.5 text-[13px] text-body', t.box)}
        >
            <span aria-hidden className={cx('font-extrabold', t.mark)}>
                {t.glyph}
            </span>
            <span>{children}</span>
        </div>
    );
}

/** The red error row under the password field. */
export function AuthError({ children }: { children: ReactNode }) {
    return (
        <p role="alert" className="mt-2 flex gap-2 text-[12.5px] text-danger">
            <span aria-hidden className="font-extrabold">
                ✕
            </span>
            {children}
        </p>
    );
}

export function Spinner() {
    return (
        <span
            aria-hidden
            className="size-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"
        />
    );
}
