<?php

namespace App\Http\Controllers\Auth;

use App\Auth\DummyHash;
use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordLink;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Emails a reset link to an active user who has a password. The answer is the same 202 whether or not
 * that is the case, and the no-mail path does one dummy hash check as the token hashing costs, so
 * neither the body nor the timing tells whether an account exists. Throttled per email and per IP
 * (`forgot-password` limiter).
 */
class ForgotPasswordController extends Controller
{
    /** Always 202 with the same body, whether or not a link was sent. */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            /** The account's email address. */
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', Str::lower($request->string('email')->toString()))->first();

        // Deactivated users and users who never finished setup get no link (setup has its own).
        if ($user !== null && $user->status === 'active' && $user->password_set_at !== null) {
            $token = $this->broker()->createToken($user);
            Mail::to($user)->locale($user->locale)->queue(new ResetPasswordLink($user, $token));
        } else {
            DummyHash::check(Str::random(40));
        }

        return response()->json(['accepted' => true], 202);
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('users');
    }
}
