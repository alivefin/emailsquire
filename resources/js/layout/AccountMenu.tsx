import { useEffect, useId, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { apiFetch } from '../api/client';
import { queryKeys } from '../api/types';
import { useToast } from '../components/ui/Toast';
import { useMe, type Me } from '../features/auth/useMe';
import { errorText } from '../features/domain/shared';
import { setLocale, useLocale } from '../i18n';

const CHEVRON = '⌄';

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

export function AccountMenu() {
    const me = useMe().data;
    const { t } = useTranslation('common');
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const menuId = useId();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const toast = useToast();

    useEffect(() => {
        if (!open) return;
        const onDown = (e: MouseEvent) => {
            if (root.current && !root.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    async function signOut() {
        setOpen(false);
        try {
            await apiFetch('/auth/logout', { method: 'POST' });
        } catch {
            // Whatever the outcome, this browser leaves the console.
        }
        queryClient.clear();
        void navigate('/signin', { replace: true });
    }

    async function switchLanguage() {
        const next = locale === 'en' ? 'fi' : 'en';
        setOpen(false);
        try {
            const updated = await apiFetch<Me>('/me', { method: 'PATCH', json: { locale: next } });
            // Cache first, so nothing reading `me` sees the old locale after the switch.
            queryClient.setQueryData(queryKeys.me, updated);
            await setLocale(next);
        } catch (e) {
            // The language and the cached me stay as they were; the next click tries again.
            toast.show({ kind: 'danger', text: errorText(e, t('language.switchFailed')) });
        }
    }

    if (!me) return null;

    return (
        <div ref={root} className="relative">
            <button
                ref={trigger}
                type="button"
                aria-label={t('layout.accountMenu')}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                onClick={() => setOpen((o) => !o)}
                className="flex items-center gap-2 rounded-full focus-visible:outline-2 focus-visible:outline-teal"
            >
                <span className="grid size-[38px] place-items-center rounded-full bg-line-strong text-xs font-bold text-body">
                    {initials(me.name)}
                </span>
                <span aria-hidden="true" className="text-body">{CHEVRON}</span>
            </button>
            {open && (
                <div
                    id={menuId}
                    role="menu"
                    aria-label={t('layout.account')}
                    className="absolute top-[46px] right-0 z-20 w-60 rounded-lg border border-line bg-white p-1.5 text-[13.5px] shadow-lg"
                >
                    <div className="border-b border-line px-2.5 pt-2.5 pb-2">
                        <div className="font-bold">{me.name}</div>
                        <div className="text-xs wrap-anywhere text-muted">{me.email}</div>
                        <div className="text-xs text-muted">{t('layout.serverAdmin')}</div>
                    </div>
                    <Link
                        to="/profile"
                        role="menuitem"
                        onClick={() => setOpen(false)}
                        className="mt-1 block w-full rounded-sm px-2.5 py-2 text-left font-semibold hover:bg-subtle focus:bg-subtle focus:outline-none"
                    >
                        {t('nav.profile')}
                    </Link>
                    {/* A shortcut: the language select lives on My profile. */}
                    <button
                        type="button"
                        role="menuitem"
                        lang={locale === 'en' ? 'fi' : 'en'}
                        onClick={() => void switchLanguage()}
                        className="mt-1 block w-full rounded-sm px-2.5 py-2 text-left font-semibold hover:bg-subtle focus:bg-subtle focus:outline-none"
                    >
                        {t('language.switchLabel')}
                    </button>
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => void signOut()}
                        className="mt-1 block w-full rounded-sm px-2.5 py-2 text-left font-semibold hover:bg-subtle focus:bg-subtle focus:outline-none"
                    >
                        {t('layout.signOut')}
                    </button>
                </div>
            )}
        </div>
    );
}
