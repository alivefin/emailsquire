<?php

namespace App\Http\Controllers;

use App\MailServer\Queries\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, Search $search): JsonResponse
    {
        $q = $request->query('q');

        return response()->json($search->run(is_string($q) ? $q : ''));
    }
}
