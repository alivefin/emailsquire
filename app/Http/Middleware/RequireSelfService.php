<?php

namespace App\Http\Middleware;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Exceptions\SelfServiceDisabled;
use App\MailServer\SelfService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A portal write needs its domain's self-service switch (`forwarding` or `aliases`) on; otherwise 403
 * `self_service_disabled` before the body is validated. Runs after `auth:mailbox`. The repository
 * checks the switch again under the domain row's lock, which is the check that counts.
 */
class RequireSelfService
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $switch  `forwarding` or `aliases`, from the route's middleware parameter
     */
    public function handle(Request $request, Closure $next, string $switch): Response
    {
        $switch = match ($switch) {
            'forwarding', 'aliases' => $switch,
            default => throw new \LogicException("Unknown self-service switch {$switch}."),
        };
        $mailbox = $request->user('mailbox');
        if ($mailbox instanceof MailboxPrincipal && ! SelfService::for($mailbox->domainId)[$switch]) {
            throw new SelfServiceDisabled($switch);
        }

        return $next($request);
    }
}
