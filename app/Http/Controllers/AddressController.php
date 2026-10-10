<?php

namespace App\Http\Controllers;

use App\MailServer\Queries\AddressList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function __invoke(Request $request, AddressList $list): JsonResponse
    {
        $q = $request->query('q');
        $type = $request->query('type');
        $domain = $request->query('domain');
        $result = $list->paginate(
            is_string($q) ? $q : null,
            is_string($type) ? $type : 'all',
            is_string($domain) ? $domain : null,
            max(1, $request->integer('page', 1)),
            min(200, max(1, $request->integer('limit', 10))),
        );

        return response()->json([
            'data' => $result->items(),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }
}
