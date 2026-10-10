<?php

namespace App\Http\Requests;

use App\Auth\ConsoleUsers;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A change to a console user from the Users screen (PATCH /users/{user}); at least one field. A name
 * alone needs no step-up; an email, role or status does.
 *
 * @phpstan-import-type Changes from ConsoleUsers
 */
class UpdateUserRequest extends FormRequest
{
    /** The fields whose change needs a fresh step-up. */
    public const STEP_UP_FIELDS = ['email', 'is_server_admin', 'status'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            /** The name shown in the console, 1–100 characters. */
            'name' => ['required_without_all:'.implode(',', self::STEP_UP_FIELDS), 'string', 'min:1', 'max:100'],
            /** The address they sign in with; stored lowercased, and no other user may have it. */
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user instanceof User ? $user->id : null)],
            /** Make them a server admin (their grants are removed: they have every domain) or take the role away. */
            'is_server_admin' => ['sometimes', 'boolean'],
            /** `deactivated` signs them out everywhere and keeps them out until `active` again. */
            'status' => ['sometimes', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_DEACTIVATED])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function needsStepUp(): bool
    {
        return $this->hasAny(self::STEP_UP_FIELDS);
    }

    /** @return Changes */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->toString();
        }
        if ($this->has('email')) {
            $changes['email'] = $this->string('email')->toString();
        }
        if ($this->has('is_server_admin')) {
            $changes['is_server_admin'] = $this->boolean('is_server_admin');
        }
        if ($this->has('status')) {
            $changes['status'] = $this->string('status')->toString() === User::STATUS_ACTIVE ? User::STATUS_ACTIVE : User::STATUS_DEACTIVATED;
        }

        return $changes;
    }
}
