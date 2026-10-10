<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class CsrfController extends Controller
{
    /** The web middleware group sets the XSRF-TOKEN cookie on this response. */
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
