import { useId, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { isStepUpCancelled } from '../../api/problems';
import type { Json, Schemas } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { currentLocale, type Locale } from '../../i18n';
import { useSingleFlight } from '../domain/shared';
import { EmailLanguageSelect, NoteField, RoleChoice, invitationErrors, type RoleOption } from './inviteFields';
import { accessPath, refreshAfterAccessWrite, type GrantRole } from './shared';

type Created = Json<'/domains/{domain}/access/invitations', 'post'>;
type Body = Schemas['StoreDomainInvitationRequest'];
type Errors = Partial<Record<'email' | 'role' | 'note' | 'locale' | 'form', string>>;

const FIELDS = ['email', 'role', 'note', 'locale'] as const;
const ROLES: GrantRole[] = ['viewer', 'manager'];

export interface ShareDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    /** The domain as shown to people. */
    unicode: string;
}

export function ShareDialog({ open, ...rest }: ShareDialogProps) {
    // Mounting the form only while open starts every share from empty fields.
    return open ? <ShareForm {...rest} /> : null;
}

function ShareForm({ onClose, domain, unicode }: Omit<ShareDialogProps, 'open'>) {
    const { t } = useTranslation('access');
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();

    const [email, setEmail] = useState('');
    const [role, setRole] = useState<GrantRole>('viewer');
    const [note, setNote] = useState('');
    const [locale, setLocale] = useState<Locale>(currentLocale);
    const [errors, setErrors] = useState<Errors>({});

    const share = useMutation({
        mutationFn: (json: Body) => apiFetch<Created>(accessPath(domain, 'invitations'), { method: 'POST', json }),
        onSuccess: async (_created, json) => {
            toast.show({ kind: 'success', text: t('shareDialog.sent', { email: json.email }) });
            onClose();
            await refreshAfterAccessWrite(queryClient);
        },
        onError: (e) => {
            // Closing "Confirm it's you" is a choice, not a failure: leave the form as it was.
            if (isStepUpCancelled(e)) return;
            setErrors(invitationErrors(e, FIELDS, t('shareDialog.failed')));
        },
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const address = email.trim();
        if (address === '') {
            setErrors({ email: t('shareDialog.emailRequired') });
            return;
        }
        setErrors({});
        const text = note.trim();
        const json: Body = { email: address, role, ...(text === '' ? {} : { note: text }), locale };
        once(() => share.mutateAsync(json));
    }

    const roleOptions: RoleOption<GrantRole>[] = ROLES.map((value) => ({
        value,
        label: t(`roles.${value}`),
        hint: t(`roleHints.${value}`),
    }));

    return (
        <Dialog
            open
            onClose={onClose}
            title={t('shareDialog.title', { domain: unicode })}
            subtitle={t('shareDialog.subtitle')}
            footer={
                <>
                    <Button onClick={onClose}>{t('shareDialog.cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" busy={share.isPending}>
                        {t('shareDialog.send')}
                    </Button>
                </>
            }
        >
            <form id={formId} noValidate onSubmit={submit} className="flex flex-col gap-4">
                <TextInput
                    label={t('shareDialog.email')}
                    type="email"
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder={t('shareDialog.emailPlaceholder')}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={errors.email}
                />
                <RoleChoice options={roleOptions} value={role} onChange={setRole} error={errors.role} />
                <NoteField value={note} onChange={setNote} error={errors.note} />
                <EmailLanguageSelect value={locale} onChange={setLocale} error={errors.locale} />
                {errors.form && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {errors.form}
                    </p>
                )}
            </form>
        </Dialog>
    );
}
