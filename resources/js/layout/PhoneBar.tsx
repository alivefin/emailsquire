import { useRef, useState } from 'react';
import { useLocation } from 'react-router';
import { useModal } from '../lib/useModal';
import { createPortal } from 'react-dom';
import { AccountMenu } from './AccountMenu';
import { JumpBox } from './JumpBox';
import { Sidebar } from './Sidebar';
import { screenTitle } from './screenTitle';

export function PhoneBar() {
    const { pathname } = useLocation();
    const [open, setOpen] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const body = useRef<HTMLDivElement>(null);
    useModal(open, () => setOpen(false), panel, body);

    return (
        <>
            <header className="flex h-16 flex-none items-center gap-2.5 border-b border-line bg-white px-4 py-3.5">
                <button
                    type="button"
                    aria-label="Menu"
                    aria-expanded={open}
                    onClick={() => setOpen(true)}
                    className="size-11 flex-none rounded-md border border-line-strong bg-white text-lg text-body"
                >
                    ≡
                </button>
                <h1 className="min-w-0 flex-1 truncate text-base font-bold">{screenTitle(pathname)}</h1>
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
                            aria-label="Navigation"
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
