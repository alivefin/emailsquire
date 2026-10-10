<?php

namespace App\Http\Controllers;

use App\MailServer\Queries\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * @response array{domains: list<array{name: string}>, addresses: list<array{address: string, type: 'mailbox'|'forwarding', domain: string}>}
     */
    public function __invoke(Request $request, Search $search): JsonResponse
    {
        $q = $request->query('q');

        return response()->json($search->run(is_string($q) ? $q : ''));
    }
}
