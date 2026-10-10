<?php

namespace App\Http\Controllers;

use App\Auth\DomainAccess;
use App\Auth\DomainScope;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The domains the signed-in user may see (DomainAccess); every list query takes it. */
    protected function domainScope(Request $request): DomainScope
    {
        $user = $request->user();

        return app(DomainAccess::class)->scope($user instanceof User ? $user : abort(401));
    }
}
