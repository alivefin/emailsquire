<?php

namespace App\Http\Controllers;

use App\Http\Requests\PutCatchAllRequest;
use App\Http\Resources\ForwardingResource;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\ForwardingList;
use App\MailServer\Repositories\AliasRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The domain's catch-all (`@domain`, backend plan §6 rule 5) on the forwarding routes, with `@` as the
 * local part. Admins and managers only (ForwardingPolicy), and setting one only while Postfix's alias
 * maps include email2email (App\MailServer\CatchAllSupport).
 */
class CatchAllController extends Controller
{
    /**
     * Sets the domain's catch-all: mail to any address of the domain with no mailbox or forwarding of its
     * own goes to `targets`. Existing mailboxes keep their mail through Postfix's email2email map, so this
     * answers 409 `catch_all_unsupported` where doctor's `postfix.alias_maps` check is not OK (or the app
     * can't read the Postfix configuration to tell). No `keep_copy`: a catch-all has no mailbox. Answers
     * 201 with the same body when the domain had no catch-all before. Audited as `forwarding.catch_all_set`.
     *
     * @response \App\Http\Resources\ForwardingResource
     */
    public function update(PutCatchAllRequest $request, Domain $domain, AliasRepository $aliases, ForwardingList $list): JsonResponse
    {
        $source = $request->source();
        $scope = $this->domainScope($request);
        $created = $list->find($scope, $domain, $source->address()) === null;

        $aliases->replace($domain, $source, $request->targets(), false, false);

        $row = $list->find($scope, $domain, $source->address()) ?? abort(404);

        return (new ForwardingResource($row))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * Removes the domain's catch-all, also where the server could not set one now (a legacy row): removing
     * it only gives the addresses back their normal answer. 404 when the domain has none. Audited as
     * `forwarding.catch_all_removed`.
     */
    public function destroy(Domain $domain, AliasRepository $aliases): Response
    {
        $aliases->remove($domain, '');

        return response()->noContent();
    }
}
