<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;

/**
 * The `auth` middleware (bootstrap/app.php), plus one thing for portal sessions: a session that names a
 * mailbox the `mailbox` guard no longer finds (deleted, or disabled: MailboxUserProvider) is ended on
 * the spot, not just refused, so enabling or recreating the mailbox later does not bring it back.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * @param  Request  $request
     * @param  array<int, string|null>  $guards
     *
     * @throws AuthenticationException
     */
    protected function unauthenticated($request, array $guards)
    {
        if (in_array('mailbox', $guards, true) && $request->hasSession()) {
            $mailbox = $this->auth->guard('mailbox');
            if ($mailbox instanceof SessionGuard && $request->session()->has($mailbox->getName())) {
                EnsureActiveUser::end($request, 'mailbox');
            }
        }

        parent::unauthenticated($request, $guards);
    }
}
