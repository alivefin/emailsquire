<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyDomainRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainList;
use App\MailServer\Repositories\DomainRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DomainController extends Controller
{
    /**
     * @response array{data: list<\App\Http\Resources\DomainResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, DomainList $list): JsonResponse
    {
        $q = $request->query('q');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(200, max(1, $request->integer('limit', 10)));
        $result = $list->paginate($this->domainScope($request), is_string($q) ? $q : null, $page, $perPage);

        return response()->json([
            'data' => DomainResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function store(StoreDomainRequest $request, DomainRepository $domains, DomainList $list): JsonResponse
    {
        $name = $request->domain();
        $domains->create($name);

        return (new DomainResource($list->find($name->ascii)))->response()->setStatusCode(201);
    }

    public function destroy(DestroyDomainRequest $request, Domain $domain, DomainRepository $domains): Response
    {
        $domains->delete($domain);

        return response()->noContent();
    }
}
