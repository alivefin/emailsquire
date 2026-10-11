<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDomainSelfServiceRequest;
use App\MailServer\Models\Domain;
use App\MailServer\SelfService;
use Illuminate\Http\JsonResponse;

/** What a domain lets its mailbox owners change in the portal (spec decisions 4 and 5). */
class DomainSelfServiceController extends Controller
{
    /**
     * @response array{forwarding: bool, aliases: bool, external_forwarding: bool}
     */
    public function show(Domain $domain): JsonResponse
    {
        return response()->json(SelfService::for($domain));
    }

    /**
     * Sets the switches sent; a field left out keeps its value. Audited as `domain.self_service_changed`
     * with the switches that really changed, as `{field: [old, new]}`.
     *
     * @response array{forwarding: bool, aliases: bool, external_forwarding: bool}
     */
    public function update(UpdateDomainSelfServiceRequest $request, Domain $domain, SelfService $selfService): JsonResponse
    {
        $values = $request->switches();

        $selfService->update($domain, $request->switches());

        return response()->json(SelfService::for($domain));
    }
}
