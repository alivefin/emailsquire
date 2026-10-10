import { useMemo, useState } from 'react';
import { Link, Outlet } from 'react-router';
import { useIsPhone } from '../lib/useIsPhone';
import { Header } from './Header';
import { HeaderSlotContext } from './headerSlot';
import { PhoneBar } from './PhoneBar';
import { Sidebar } from './Sidebar';

export function AppLayout() {
    const phone = useIsPhone();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const slot = useMemo(() => ({ target, setTarget }), [target]);

    return (
        <HeaderSlotContext.Provider value={slot}>
            <div className="flex h-dvh bg-canvas text-ink">
                {!phone && (
                    <aside className="w-[250px] flex-none border-r border-line">
                        <Sidebar />
                    </aside>
                )}
                <div className="flex min-w-0 flex-1 flex-col">
                    {phone ? <PhoneBar /> : <Header />}
                    <main className="flex flex-1 flex-col gap-4 overflow-auto p-3 md:px-6 md:pt-5 md:pb-6">
                        <Outlet />
                    </main>
                </div>
            </div>
        </HeaderSlotContext.Provider>
    );
}

export function Placeholder({ title }: { title: string }) {
    return <p className="text-muted">{title}</p>;
}

export function NotFound() {
    return (
        <div>
            <h2 className="text-lg font-bold">Page not found</h2>
            <Link to="/" className="mt-2 inline-block font-semibold text-teal-dark">
                Back to the dashboard
            </Link>
        </div>
    );
}
