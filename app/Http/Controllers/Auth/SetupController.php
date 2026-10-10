<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AdminSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\Models\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SetupController extends Controller
{
    public function __invoke(SetupRequest $request, AdminSetup $setup): JsonResponse
    {
        $user = $setup->complete(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        // Task 7 routes this through App\MailServer\AuditLog.
        AuditEntry::create([
            'occurred_at' => now(),
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'ip' => $request->ip(),
            'action' => 'admin.setup',
            'object_type' => 'user',
            'object_key' => $user->email,
        ]);

        Auth::guard()->login($user);
        $request->session()->regenerate();

        return response()->json(MeController::payload($user));
    }
}
