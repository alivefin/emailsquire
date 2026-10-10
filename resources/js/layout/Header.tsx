import { useContext } from 'react';
import { useLocation, matchPath } from 'react-router';
import { AccountMenu } from './AccountMenu';
import { HeaderSlotContext } from './headerSlot';
import { JumpBox } from './JumpBox';
import { screenTitle } from './screenTitle';

export function Header() {
    const { pathname } = useLocation();
    const { setTarget } = useContext(HeaderSlotContext);
    const onDomain = matchPath('/domains/:domain/*', pathname) !== null;

    return (
        <header className="flex h-[72px] flex-none items-center gap-3 border-b border-line bg-white px-6 py-3.5">
            {onDomain ? (
                <div ref={setTarget} className="flex min-w-0 items-center gap-3 text-lg font-bold">
                    {/* Task 10 fills this through HeaderSlot; until then it shows the domain name. */}
                    <span className="truncate">{screenTitle(pathname)}</span>
                </div>
            ) : (
                <h1 className="text-xl font-bold">{screenTitle(pathname)}</h1>
            )}
            <div className="flex-1" />
            <JumpBox className="w-[260px]" />
            <AccountMenu />
        </header>
    );
}
