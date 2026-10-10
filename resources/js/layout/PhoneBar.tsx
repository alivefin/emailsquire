import { useContext, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { matchPath, useLocation } from 'react-router';
import { useModal } from '../lib/useModal';
import { createPortal } from 'react-dom';
import { AccountMenu } from './AccountMenu';
import { JumpBox } from './JumpBox';
import { HeaderSlotContext } from './headerSlot';
import { Sidebar } from './Sidebar';
import { useScreenTitle } from './screenTitle';

export function PhoneBar() {
    const { t } = useTranslation('common');
    const { pathname } = useLocation();
    const title = useScreenTitle();
    const { setTarget, filled } = useContext(HeaderSlotContext);
    const onDomain = matchPath('/domains/:domain/*', pathname) !== null;
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const body = useRef<HTMLDivElement>(null);
    useModal(open, () => setOpen(false), panel, body);

    return (
        <>
            <header className="flex h-16 flex-none items-center gap-2.5 border-b border-line bg-white px-4 py-3.5">
                <button
                    type="button"
                    aria-label={t('layout.menu')}
                    aria-expanded={open}
                    onClick={() => setOpen(true)}
                    className="size-11 flex-none rounded-md border border-line-strong bg-white text-lg text-body"
                >
                    ≡
                </button>
                {onDomain ? (
                    <div ref={setTarget} className="flex min-w-0 flex-1 items-center gap-2 text-base font-bold">
                        {filled === 0 && <span className="truncate">{title}</span>}
                    </div>
                ) : (
                    <h1 className="min-w-0 flex-1 truncate text-base font-bold">{title}</h1>
                )}
                <AccountMenu />
            </header>
            <div className="border-b border-line bg-white px-4 py-2">
                <JumpBox />
            </div>
            {open &&
                createPortal(
                    <div className="fixed inset-0 z-40">
                        <div data-testid="overlay-backdrop" onClick={() => setOpen(false)} className="absolute inset-0 bg-ink/25" />
                        <div
                            ref={panel}
                            role="dialog"
                            aria-modal="true"
                            aria-label={t('layout.navigation')}
                            tabIndex={-1}
                            className="absolute top-0 bottom-0 left-0 w-[280px] max-w-[85vw] bg-white shadow-lg outline-none"
                        >
                            <div ref={body} className="h-full">
                                <Sidebar onNavigate={() => setOpen(false)} />
                            </div>
                        </div>
                    </div>,
                    document.body,
                )}
        </>
    );
}
