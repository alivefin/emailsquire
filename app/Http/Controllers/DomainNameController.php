<?php

namespace App\Http\Controllers;

use App\MailServer\Queries\DomainList;
use Illuminate\Http\JsonResponse;

/** Every domain, for pickers. Not paginated. */
class DomainNameController extends Controller
{
    public function __invoke(DomainList $list): JsonResponse
    {
        return response()->json(['data' => $list->names()]);
    }
}
