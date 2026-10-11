<?php

namespace App\Http\Controllers\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PutPortalForwardingRequest;
use App\MailServer\Queries\OwnForwarding;
use App\MailServer\Repositories\AliasRepository;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The mailbox owner's own forwarding (spec decision 6). */
#[Group('Portal')]
class ForwardingController extends Controller
{
    /**
     * Where the mailbox forwards to, and whether it keeps a copy. No targets: mail is delivered to the
     * mailbox as usual. Readable while the domain's `forwarding` switch is off.
     *
     * @operationId portal.forwarding.show
     *
     * @response array{targets: list<string>, keep_copy: bool}
     */
    public function show(Request $request, OwnForwarding $own): JsonResponse
    {
        return response()->json($own->forwarding($this->owner($request)));
    }

    /**
     * Replaces the forwarding: up to 10 targets, with a copy kept in the mailbox or not. An empty
     * `targets` turns forwarding off. Targets on any domain of this server are always allowed; others
     * need the domain's `external_forwarding` switch. Audited as `portal.forwarding_changed`.
     *
     * @operationId portal.forwarding.update
     *
     * @response array{targets: list<string>, keep_copy: bool}
     */
    public function update(PutPortalForwardingRequest $request, AliasRepository $aliases, OwnForwarding $own): JsonResponse
    {
        $owner = $this->owner($request);
        if (! $aliases->replaceOwn($owner, $request->targets(), $request->keepCopy())) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }

        return response()->json($own->forwarding($owner));
    }

    private function owner(Request $request): MailboxPrincipal
    {
        $owner = $request->user('mailbox');

        return $owner instanceof MailboxPrincipal ? $owner : throw new AuthenticationException('Unauthenticated.', ['mailbox']);
    }
}
