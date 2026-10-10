import { useContext } from 'react';
import { useLocation, matchPath } from 'react-router';
import { AccountMenu } from './AccountMenu';
import { HeaderSlotContext } from './headerSlot';
import { JumpBox } from './JumpBox';
import { useScreenTitle } from './screenTitle';

export function Header() {
    const { pathname } = useLocation();
    const { setTarget, filled } = useContext(HeaderSlotContext);
    const title = useScreenTitle();
    const onDomain = matchPath('/domains/:domain/*', pathname) !== null;

    return (
        <header className="flex h-[72px] flex-none items-center gap-3 border-b border-line bg-white px-6 py-3.5">
            {onDomain ? (
                <div ref={setTarget} className="flex min-w-0 items-center gap-3 text-lg font-bold">
                    {/* Domain pages fill this through HeaderSlot; until one does, it shows the domain name. */}
                    {filled === 0 && <span className="truncate">{title}</span>}
                </div>
            ) : (
                <h1 className="text-xl font-bold">{title}</h1>
            )}
            <div className="flex-1" />
            <JumpBox className="w-[260px]" />
            <AccountMenu />
        </header>
    );
}
