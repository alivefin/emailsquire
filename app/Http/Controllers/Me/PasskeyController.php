<?php

namespace App\Http\Controllers\Me;

use App\Auth\Passkeys;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in user's own passkeys (App\Auth\Passkeys does the work). Adding one takes two requests
 * within one step-up window: the options, then the browser's credential. Removing one needs step-up;
 * renaming doesn't. Responses carrying options or recovery codes are never cached.
 */
class PasskeyController extends Controller
{
    /**
     * The user's passkeys, oldest first. A disabled one (its signature counter went backwards) can't
     * sign in and is no second factor until it is removed and added again.
     *
     * @response array{data: list<array{id: int, name: string, created_at: string|null, last_used_at: string|null, disabled: bool}>}
     */
    public function index(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json(['data' => $passkeys->list($this->user($request))->map(Passkeys::present(...))->values()->all()]);
    }

    /**
     * Creation options for `navigator.credentials.create({publicKey})`, binary values base64url. Good for
     * one POST /me/passkeys within 5 minutes. Needs step-up. 422 `too_many_passkeys` at 10 passkeys.
     *
     * @response array{rp: array{name: string, id: string}, user: array{name: string, id: string, displayName: string}, challenge: string, pubKeyCredParams: list<array{type: string, alg: int}>, timeout: int, excludeCredentials: list<array{type: string, id: string}>, authenticatorSelection: array{authenticatorAttachment?: string, residentKey: string, userVerification: string}, attestation: string}
     */
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json($passkeys->registrationOptions($this->user($request), $request->session()))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Adds a passkey from the browser's credential for the options this session last got. When it is
     * the user's first second factor, the answer carries 8 new recovery codes, shown this once. Needs
     * step-up. Any credential that doesn't verify is 422 `passkey_failed`.
     *
     * @response array{passkey: array{id: int, name: string, created_at: string|null, last_used_at: string|null, disabled: bool}, recovery_codes?: list<string>}
     */
    public function store(Request $request, Passkeys $passkeys): JsonResponse
    {
        $request->validate([
            /** A name to tell the passkey apart, such as the device: 1–64 characters of plain text (no control or format characters). */
            'name' => ['required', 'string', 'min:1', 'max:64', 'regex:/\A[^\p{C}]+\z/u'],
            /** The `PublicKeyCredential` from `navigator.credentials.create()`, as JSON with base64url binary values. */
            'credential' => ['required', 'array'],
        ]);
        /** @var array<array-key, mixed> $credential */
        $credential = $request->input('credential');

        $added = $passkeys->register($this->user($request), $request->session(), $request->string('name')->toString(), $credential);

        $body = ['passkey' => Passkeys::present($added['passkey'])];
        if ($added['recovery_codes'] !== null) {
            $body['recovery_codes'] = $added['recovery_codes'];
        }

        return response()->json($body, 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Renames one of the user's passkeys. No step-up.
     *
     * @response array{id: int, name: string, created_at: string|null, last_used_at: string|null, disabled: bool}
     */
    public function update(Request $request, Passkeys $passkeys, int $id): JsonResponse
    {
        $request->validate([
            /** The new name: 1–64 characters of plain text. */
            'name' => ['required', 'string', 'min:1', 'max:64', 'regex:/\A[^\p{C}]+\z/u'],
        ]);

        return response()->json(Passkeys::present($passkeys->rename($this->user($request), $id, $request->string('name')->toString())));
    }

    /**
     * Removes one of the user's passkeys and emails them. Needs step-up. 409 `last_second_factor` when
     * it is their last second factor and two-factor is required for them.
     */
    public function destroy(Request $request, Passkeys $passkeys, int $id): Response
    {
        $passkeys->delete($this->user($request), $id);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }
}
