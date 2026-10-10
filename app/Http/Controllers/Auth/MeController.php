<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi'}
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(self::payload($this->user($request)));
    }

    /**
     * Change the signed-in admin's interface language. A preference, so it is not audited.
     *
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi'}
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate([
            'locale' => ['required', 'string', Rule::in(['en', 'fi'])],
        ]);

        $user->locale = $request->string('locale')->toString();
        $user->save();

        return response()->json(self::payload($user));
    }

    /** @return array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi'} */
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_server_admin' => $user->is_server_admin,
            // The column only ever holds en or fi (PATCH /me validates it); anything else reads as en.
            'locale' => $user->locale === 'fi' ? 'fi' : 'en',
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
