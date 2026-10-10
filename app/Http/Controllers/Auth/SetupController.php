<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AdminSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\MailServer\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SetupController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool}
     */
    public function __invoke(SetupRequest $request, AdminSetup $setup, AuditLog $audit): JsonResponse
    {
        $user = $setup->complete(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        Auth::guard()->login($user);
        $request->session()->regenerate();

        $audit->record('admin.setup', 'user', $user->email);

        return response()->json(MeController::payload($user));
    }
}
