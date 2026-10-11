<?php

namespace App\Http\Requests;

use App\Mail\MailboxWelcome;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The welcome email's recipient and language (POST /domains/{domain}/mailboxes/{mailbox}/welcome).
 * `to` is one bare address: the endpoint sends mail to an address a manager types, so it must never
 * carry a display name, a list or a header.
 */
class SendWelcomeRequest extends FormRequest
{
    /**
     * Anything `email:rfc` lets through that is not one bare address: control characters (CR, LF, tab),
     * whitespace, display-name and list punctuation (`<`, `>`, `,`, `;`, `:`), quoted local parts,
     * comments and domain literals (`"`, `(`, `)`, `[`, `]`, `\`).
     */
    private const NOT_PLAIN = '/[\x00-\x20\x7F<>,;:"()\[\]\\\\]|\p{Z}/u';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /** One plain email address, at most 254 characters: no display name, list or header characters. Sent as typed, trimmed, with the domain lowercased. */
            'to' => ['bail', 'required', 'string', 'max:254', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match(self::NOT_PLAIN, $value) !== 0) {
                    $fail(__('validation.email', ['attribute' => $attribute]));
                }
            }, 'email:rfc'],
            /** The language of the mail. */
            'locale' => ['required', 'string', Rule::in(MailboxWelcome::LOCALES)],
        ];
    }

    /** The recipient: trimmed, the local part as typed and the domain lowercased. */
    public function recipient(): string
    {
        $to = $this->string('to')->trim()->toString();
        $at = strrpos($to, '@');

        return $at === false ? $to : substr($to, 0, $at + 1).mb_strtolower(substr($to, $at + 1));
    }

    /** @return 'en'|'fi' */
    public function mailLocale(): string
    {
        return $this->string('locale')->toString() === 'fi' ? 'fi' : 'en';
    }
}
