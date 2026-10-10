import { useId, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { apiFetch } from '../../api/client';
import { isStepUpCancelled } from '../../api/problems';
import type { Json, Schemas } from '../../api/types';
import { Button } from '../../components/ui/Button';
import { Dialog } from '../../components/ui/Dialog';
import { Select } from '../../components/ui/Select';
import { TextInput } from '../../components/ui/TextInput';
import { useToast } from '../../components/ui/Toast';
import { currentLocale, type Locale } from '../../i18n';
import { useDomainNames } from '../../layout/useNavData';
import { EmailLanguageSelect, NoteField, RoleChoice, invitationErrors, type RoleOption } from '../access/inviteFields';
import { useSingleFlight } from '../domain/shared';

type Created = Json<'/users/invitations', 'post'>;
type Body = Schemas['StoreUserInvitationRequest'];
type InviteRole = Body['role'];

const FIELDS = ['email', 'role', 'domain', 'note', 'locale', 'name'] as const;
type Errors = Partial<Record<(typeof FIELDS)[number] | 'form', string>>;

const ROLES: InviteRole[] = ['server_admin', 'manager', 'viewer'];

export interface InviteUserDialogProps {
    open: boolean;
    onClose: () => void;
}

/** "Invite user" on the Users screen: a server admin, or a manager or viewer of one domain. */
export function InviteUserDialog({ open, onClose }: InviteUserDialogProps) {
    // Mounting the form only while open starts every invitation from empty fields.
    return open ? <InviteForm onClose={onClose} /> : null;
}

function InviteForm({ onClose }: { onClose: () => void }) {
    const { t } = useTranslation(['users', 'access']);
    const queryClient = useQueryClient();
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const names = useDomainNames();

    const [email, setEmail] = useState('');
    const [role, setRole] = useState<InviteRole>('viewer');
    const [domain, setDomain] = useState('');
    const [note, setNote] = useState('');
    const [locale, setLocale] = useState<Locale>(currentLocale);
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Errors>({});

    const domainRole = role !== 'server_admin';

    const invite = useMutation({
        mutationFn: (json: Body) => apiFetch<Created>('/users/invitations', { method: 'POST', json }),
        onSuccess: async (_created, json) => {
            toast.show({ kind: 'success', text: t('access:shareDialog.sent', { email: json.email }) });
            onClose();
            // The invitee's page (if they have an account) lists the invitation; a domain's Access tab does too.
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['users'] }),
                queryClient.invalidateQueries({ queryKey: ['domains'] }),
            ]);
        },
        onError: (e) => {
            // Closing "Confirm it's you" is a choice, not a failure: leave the form as it was.
            if (isStepUpCancelled(e)) return;
            setErrors(invitationErrors(e, FIELDS, t('access:shareDialog.failed')));
        },
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const address = email.trim();
        const next: Errors = {};
        if (address === '') next.email = t('access:shareDialog.emailRequired');
        if (domainRole && domain === '') next.domain = t('inviteDialog.domainRequired');
        setErrors(next);
        if (Object.keys(next).length > 0) return;
        const text = note.trim();
        const shown = name.trim();
        const json: Body = {
            email: address,
            role,
            ...(domainRole ? { domain } : {}),
            ...(text === '' ? {} : { note: text }),
            locale,
            ...(shown === '' ? {} : { name: shown }),
        };
        once(() => invite.mutateAsync(json));
    }

    const roleOptions: RoleOption<InviteRole>[] = ROLES.map((value) => ({
        value,
        label: t(`inviteDialog.roles.${value}`),
        hint: t(`inviteDialog.roleHints.${value}`),
    }));

    return (
        <Dialog
            open
            onClose={onClose}
            title={t('inviteDialog.title')}
            subtitle={t('access:shareDialog.subtitle')}
            footer={
                <>
                    <Button onClick={onClose}>{t('cancel')}</Button>
                    <Button type="submit" form={formId} variant="primary" busy={invite.isPending}>
                        {t('access:shareDialog.send')}
                    </Button>
                </>
            }
        >
            <form id={formId} noValidate onSubmit={submit} className="flex flex-col gap-4">
                <TextInput
                    label={t('access:shareDialog.email')}
                    type="email"
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    placeholder={t('access:shareDialog.emailPlaceholder')}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={errors.email}
                />
                <RoleChoice
                    options={roleOptions}
                    value={role}
                    onChange={(next) => {
                        setRole(next);
                        setErrors((prev) => ({ ...prev, domain: undefined }));
                    }}
                    error={errors.role}
                />
                {domainRole && (
                    <Select
                        label={t('inviteDialog.domain')}
                        value={domain}
                        onChange={(e) => {
                            setDomain(e.target.value);
                            setErrors((prev) => ({ ...prev, domain: undefined }));
                        }}
                        error={errors.domain}
                    >
                        <option value="">{t('inviteDialog.domainPlaceholder')}</option>
                        {(names.data ?? []).map((d) => (
                            <option key={d.name} value={d.name}>
                                {d.unicode_name}
                            </option>
                        ))}
                    </Select>
                )}
                <NoteField value={note} onChange={setNote} error={errors.note} />
                <EmailLanguageSelect value={locale} onChange={setLocale} error={errors.locale} />
                <TextInput
                    label={t('inviteDialog.name')}
                    hint={t('inviteDialog.nameHint')}
                    autoComplete="off"
                    maxLength={100}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    error={errors.name}
                />
                {errors.form && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {errors.form}
                    </p>
                )}
            </form>
        </Dialog>
    );
}
