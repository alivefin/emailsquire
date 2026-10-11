<?php

namespace App\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * The `database` session driver (AppServiceProvider), with one column per guard instead of the
 * default guard's id in `user_id`:
 *
 * - `user_id`: the console user (`web`), as before. OtherSessions ends a user's sessions by it.
 * - `mailbox_address`: the portal mailbox (`mailbox`), its stored spelling, indexed. A mailbox's other
 *   portal sessions are `where('mailbox_address', $principal->address)`, no payload parsing needed.
 *
 * Laravel's handler writes the default guard's id, which on a portal request is the mailbox address,
 * not a user id.
 */
class GuardSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return $this
     */
    protected function addUserInformation(&$payload)
    {
        if ($this->container === null || ! $this->container->bound('auth')) {
            return $this;
        }

        $auth = $this->container->make(AuthManager::class);
        $userId = $auth->guard('web')->id();
        $address = $auth->guard('mailbox')->id();
        $payload['user_id'] = is_int($userId) || is_string($userId) ? $userId : null;
        $payload['mailbox_address'] = is_string($address) && $address !== '' ? $address : null;

        return $this;
    }
}
