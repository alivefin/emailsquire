<?php

namespace App\Http\Controllers;

use App\Http\Requests\PutForwardingRequest;
use App\Http\Resources\ForwardingResource;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\ForwardingList;
use App\MailServer\Repositories\AliasRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ForwardingController extends Controller
{
    public function index(Request $request, Domain $domain, ForwardingList $list): JsonResponse
    {
        $q = $request->query('q');
        $kind = $request->query('kind');
        $result = $list->paginate(
            $domain,
            is_string($q) ? $q : null,
            is_string($kind) ? $kind : 'all',
            max(1, $request->integer('page', 1)),
            min(200, max(1, $request->integer('limit', 10))),
        );

        return response()->json([
            'data' => ForwardingResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function update(PutForwardingRequest $request, Domain $domain, string $local, AliasRepository $aliases, ForwardingList $list): JsonResponse
    {
        $source = $request->source();
        $created = $list->find($domain, $source->address()) === null;

        $aliases->replace($domain, $source, $request->targets(), $request->keepCopy(), $request->redirectMailbox());

        $row = $list->find($domain, $source->address()) ?? abort(404);

        return (new ForwardingResource($row))->response()->setStatusCode($created ? 201 : 200);
    }

    public function destroy(Domain $domain, string $local, AddressNormalizer $normalizer, AliasRepository $aliases): Response
    {
        try {
            $address = $normalizer->addressIn($local, $normalizer->domain($domain->name));
        } catch (InvalidAddress) {
            abort(404);
        }

        $aliases->remove($domain, $address->local);

        return response()->noContent();
    }
}
