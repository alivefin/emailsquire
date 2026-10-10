import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Outlet } from 'react-router';
import { useIsPhone } from '../lib/useIsPhone';
import { Header } from './Header';
import { HeaderSlotContext } from './headerSlot';
import { PhoneBar } from './PhoneBar';
import { Sidebar } from './Sidebar';

export function AppLayout() {
    const phone = useIsPhone();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const [filled, setFilled] = useState(0);
    const register = useCallback(() => {
        setFilled((n) => n + 1);
        return () => setFilled((n) => n - 1);
    }, []);
    const slot = useMemo(() => ({ target, setTarget, filled, register }), [target, filled, register]);

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
    const { t } = useTranslation('common');
    return (
        <div>
            <h2 className="text-lg font-bold">{t('nav.notFound')}</h2>
            <Link to="/" className="mt-2 inline-block font-semibold text-teal-dark">
                {t('layout.backToDashboard')}
            </Link>
        </div>
    );
}
