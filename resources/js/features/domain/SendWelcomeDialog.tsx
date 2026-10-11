import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import i18next from "i18next";
import { apiFetch } from "../../api/client";
import { ApiProblem, fieldErrors } from "../../api/problems";
import { Button } from "../../components/ui/Button";
import { Dialog } from "../../components/ui/Dialog";
import { TextInput } from "../../components/ui/TextInput";
import { useToast } from "../../components/ui/Toast";
import { currentLocale, type Locale } from "../../i18n";
import { EmailLanguageSelect } from "../access/inviteFields";
import { errorText, mailboxesPath, useSingleFlight } from "./shared";

/**
 * Queues the setup-instructions mail (never the password) for a mailbox. 202 with no body. Only mails that
 * were queued count against the 10 an hour, and no step-up is asked. The recipient is not kept anywhere.
 */
export async function postWelcome(
    domain: string,
    local: string,
    to: string,
    locale: Locale,
): Promise<void> {
    await apiFetch<undefined>(`${mailboxesPath(domain, local)}/welcome`, {
        method: "POST",
        json: { to, locale },
    });
}

export interface WelcomeFailure {
    /** The server's words for a field the form shows. */
    to?: string;
    locale?: string;
    /** Everything else: the hourly limit, a refusal, a network failure. */
    form?: string;
}

/** A failed welcome POST as text by place: 422 under its field, 429 and the rest as one message. */
export function welcomeFailure(e: unknown): WelcomeFailure {
    const t = (key: string) => i18next.t(key, { ns: "mailboxes" });
    if (e instanceof ApiProblem && e.status === 429)
        return { form: t("welcome.limit") };
    const fields = fieldErrors(e);
    if (fields.to !== undefined || fields.locale !== undefined)
        return { to: fields.to, locale: fields.locale };
    return { form: errorText(e, t("welcome.failed")) };
}

interface RecipientFieldsProps {
    to: string;
    locale: Locale;
    onTo: (to: string) => void;
    onLocale: (locale: Locale) => void;
    error?: WelcomeFailure;
}

/** The recipient and the language of the mail, shared by the dialog and the new-mailbox drawer. */
export function RecipientFields({
    to,
    locale,
    onTo,
    onLocale,
    error,
}: RecipientFieldsProps) {
    const { t } = useTranslation("mailboxes");
    return (
        <>
            <TextInput
                type="email"
                label={t("welcome.to")}
                placeholder={t("welcome.toPlaceholder")}
                autoComplete="off"
                value={to}
                onChange={(e) => onTo(e.target.value)}
                error={error?.to}
            />
            <EmailLanguageSelect
                value={locale}
                onChange={onLocale}
                error={error?.locale}
            />
        </>
    );
}

export interface SendWelcomeDialogProps {
    open: boolean;
    onClose: () => void;
    /** Punycode domain name, as the API wants it. */
    domain: string;
    local: string;
    /** The address as shown to people (unicode domain). */
    address: string;
}

export function SendWelcomeDialog({ open, ...rest }: SendWelcomeDialogProps) {
    // Mounting the body only while open drops the typed recipient and any error when the dialog closes.
    return open ? <Body {...rest} /> : null;
}

function Body({
    onClose,
    domain,
    local,
    address,
}: Omit<SendWelcomeDialogProps, "open">) {
    const { t } = useTranslation("mailboxes");
    const toast = useToast();
    const once = useSingleFlight();
    const formId = useId();
    const [to, setTo] = useState("");
    const [locale, setLocale] = useState<Locale>(currentLocale);
    const [error, setError] = useState<WelcomeFailure>({});
    const [busy, setBusy] = useState(false);

    async function send() {
        const recipient = to.trim();
        if (recipient === "") return;
        setBusy(true);
        setError({});
        try {
            await postWelcome(domain, local, recipient, locale);
            toast.show({
                kind: "success",
                text: t("welcome.sent", { to: recipient }),
            });
            onClose();
        } catch (e) {
            setError(welcomeFailure(e));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Dialog
            open
            onClose={busy ? () => {} : onClose}
            title={t("welcome.title", { address })}
            footer={
                <>
                    <Button disabled={busy} onClick={onClose}>
                        {t("welcome.cancel")}
                    </Button>
                    <Button
                        type="submit"
                        form={formId}
                        variant="primary"
                        busy={busy}
                        disabled={to.trim() === ""}
                    >
                        {t("welcome.send")}
                    </Button>
                </>
            }
        >
            <form
                id={formId}
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    once(send);
                }}
            >
                <p className="text-[13px] leading-normal text-body">
                    {t("welcome.intro")}
                </p>
                <RecipientFields
                    to={to}
                    locale={locale}
                    onTo={(v) => {
                        setTo(v);
                        setError({});
                    }}
                    onLocale={setLocale}
                    error={error}
                />
                {error.form && (
                    <p role="alert" className="text-[12.5px] text-danger">
                        {error.form}
                    </p>
                )}
            </form>
        </Dialog>
    );
}
