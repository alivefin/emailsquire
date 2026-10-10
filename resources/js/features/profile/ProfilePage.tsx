import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { fieldErrors } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { Select } from '../../components/ui/Select';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { isLocale, setLocale, useLocale, type Locale } from '../../i18n';
import { mustSetUpTwoFactor, useMe, type Me } from '../auth/useMe';
import { errorText } from '../domain/shared';
import { PasswordSection } from './PasswordSection';
import { ProfileSection } from './ProfileSection';
import { TwoFactorSection } from './TwoFactorSection';

/**
 * `/profile`: the signed-in user's name and language, password, two-factor authentication and
 * sessions. A user who must set up two-factor first can use this page (and nothing else) until it
 * is on; the banner says so.
 */
export function ProfilePage() {
    const { t } = useTranslation('profile');
    const me = useMe().data;
    if (!me) return null;

    return (
        <div className="mx-auto flex w-full max-w-[760px] flex-col gap-4">
            {mustSetUpTwoFactor(me) && (
                <div role="status" className="rounded-[10px] border border-warn-line bg-warn-bg px-4 py-3 text-[13.5px]">
                    <p className="font-bold text-ink">{t('required')}</p>
                    <p className="mt-0.5 text-body">{t('requiredDetail')}</p>
                </div>
            )}
            <DetailsSection me={me} />
            <PasswordSection />
            <TwoFactorSection me={me} />
            <SessionsSection />
        </div>
    );
}

function DetailsSection({ me }: { me: Me }) {
    const { t } = useTranslation(['profile', 'common']);
    const queryClient = useQueryClient();
    const toast = useToast();
    const locale = useLocale();
    const [name, setName] = useState(me.name);
    const [nameError, setNameError] = useState<string | undefined>();
    const [saving, setSaving] = useState(false);
    const [pendingLocale, setPendingLocale] = useState<Locale | null>(null);
    const sending = useRef(false);

    // Server errors are in the language they arrived in; drop them when the language changes.
    useEffect(() => setNameError(undefined), [locale]);

    const trimmed = name.trim();

    async function saveName(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        if (trimmed === '') {
            setNameError(t('details.nameRequired'));
            return;
        }
        if (trimmed === me.name || sending.current) return;
        sending.current = true;
        setSaving(true);
        setNameError(undefined);
        try {
            const updated = await apiFetch<Me>('/me', { method: 'PATCH', json: { name: trimmed } });
            queryClient.setQueryData(queryKeys.me, updated);
            setName(updated.name);
            toast.show({ kind: 'success', text: t('details.saved') });
        } catch (err) {
            setNameError(fieldErrors(err).name ?? errorText(err, t('details.failed')));
        } finally {
            sending.current = false;
            setSaving(false);
        }
    }

    async function changeLanguage(next: Locale) {
        if (next === locale || pendingLocale !== null) return;
        setPendingLocale(next);
        try {
            const updated = await apiFetch<Me>('/me', { method: 'PATCH', json: { locale: next } });
            // Cache first, so nothing reading `me` sees the old locale after the switch.
            queryClient.setQueryData(queryKeys.me, updated);
            await setLocale(next);
        } catch (err) {
            toast.show({ kind: 'danger', text: errorText(err, t('common:language.switchFailed')) });
        } finally {
            setPendingLocale(null);
        }
    }

    return (
        <ProfileSection title={t('details.title')}>
            <div className="flex max-w-[460px] flex-col gap-3.5">
                <form noValidate onSubmit={(e) => void saveName(e)}>
                    <TextInput
                        label={t('details.name')}
                        value={name}
                        maxLength={100}
                        autoComplete="name"
                        onChange={(e) => {
                            setName(e.target.value);
                            setNameError(undefined);
                        }}
                        error={nameError}
                    />
                    <Button type="submit" className="mt-2.5" busy={saving} disabled={trimmed === me.name}>
                        {t('details.save')}
                    </Button>
                </form>
                <TextInput label={t('details.email')} type="email" value={me.email} readOnly hint={t('details.emailHint')} />
                <Select
                    label={t('details.language')}
                    value={pendingLocale ?? locale}
                    disabled={pendingLocale !== null}
                    onChange={(e) => {
                        if (isLocale(e.target.value)) void changeLanguage(e.target.value);
                    }}
                >
                    <option value="en" lang="en">
                        {t('languages.en')}
                    </option>
                    <option value="fi" lang="fi">
                        {t('languages.fi')}
                    </option>
                </Select>
            </div>
        </ProfileSection>
    );
}

function SessionsSection() {
    const { t } = useTranslation('profile');
    const toast = useToast();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const sending = useRef(false);

    async function signOutEverywhere() {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setError(undefined);
        try {
            await apiFetch('/auth/logout-all', { method: 'POST' });
            setOpen(false);
            toast.show({ kind: 'success', text: t('sessions.done') });
        } catch (err) {
            setError(errorText(err, t('sessions.failed')));
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }

    return (
        <ProfileSection title={t('sessions.title')} subtitle={t('sessions.text')}>
            <Button
                onClick={() => {
                    setError(undefined);
                    setOpen(true);
                }}
            >
                {t('sessions.signOutEverywhere')}
            </Button>
            <Dialog
                open={open}
                onClose={() => setOpen(false)}
                size="sm"
                title={t('sessions.confirmTitle')}
                footer={
                    <>
                        <Button onClick={() => setOpen(false)}>{t('cancel')}</Button>
                        <Button variant="primary" busy={busy} onClick={() => void signOutEverywhere()}>
                            {t('sessions.signOutEverywhere')}
                        </Button>
                    </>
                }
            >
                <p>{t('sessions.confirmBody')}</p>
                {error !== undefined && (
                    <p role="alert" className="mt-3 text-[12.5px] text-danger">
                        {error}
                    </p>
                )}
            </Dialog>
        </ProfileSection>
    );
}
