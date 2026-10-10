<?php

namespace App\Http\Requests;

use App\Models\DomainGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An invitation to a role on the domain in the route (POST /domains/{domain}/access/invitations). */
class StoreDomainInvitationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /** The invitee's email; stored lowercased. */
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(DomainGrant::ROLES)],
            /** A note to the invitee, printed in the mail as plain text. */
            'note' => ['nullable', 'string', 'max:500'],
            /** The language of the mail, and of the invitee's console if they are new. */
            'locale' => ['required', 'string', Rule::in(['en', 'fi'])],
            /** What to call the invitee in the mail; a new invitee chooses their own name when accepting. */
            'name' => ['nullable', 'string', 'min:1', 'max:100'],
        ];
    }

    /** @return 'manager'|'viewer' */
    public function domainRole(): string
    {
        return $this->string('role')->toString() === 'manager' ? 'manager' : 'viewer';
    }

    public function email(): string
    {
        return $this->string('email')->lower()->trim()->toString();
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** @return 'en'|'fi' */
    public function mailLocale(): string
    {
        return $this->string('locale')->toString() === 'fi' ? 'fi' : 'en';
    }
}
