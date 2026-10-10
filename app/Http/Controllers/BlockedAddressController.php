<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlockedAddressRequest;
use App\Http\Resources\BlockedAddressResource;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Repositories\BlockedAddressRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlockedAddressController extends Controller
{
    /**
     * @response array{data: list<\App\Http\Resources\BlockedAddressResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, BlockedAddressRepository $blocked): JsonResponse
    {
        $q = $request->query('q');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(200, max(1, $request->integer('limit', 10)));
        $result = $blocked->paginate($this->domainScope($request), is_string($q) ? $q : null, $page, $perPage);

        return response()->json([
            'data' => BlockedAddressResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function store(StoreBlockedAddressRequest $request, BlockedAddressRepository $blocked): JsonResponse
    {
        $row = $blocked->block($request->emailAddress(), $request->reason());

        return (new BlockedAddressResource($row))->response()->setStatusCode(201);
    }

    public function destroy(string $address, AddressNormalizer $normalizer, BlockedAddressRepository $blocked): Response
    {
        try {
            $normalised = $normalizer->address($address)->address();
        } catch (InvalidAddress) {
            abort(404);
        }

        $blocked->unblock($normalised);

        return response()->noContent();
    }
}
