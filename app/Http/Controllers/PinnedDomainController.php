<?php

namespace App\Http\Controllers;

use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainList;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PinnedDomainController extends Controller
{
    private const MAX_PINS = 10;

    public function index(Request $request, DomainList $domains): JsonResponse
    {
        $pins = PinnedDomain::query()->where('user_id', $this->user($request)->id)->orderBy('position')->get();
        $ids = [];
        foreach ($pins as $pin) {
            $ids[] = $pin->domain_id;
        }
        $live = $domains->byIds($ids);

        $data = [];
        foreach ($pins as $pin) {
            if (isset($live[$pin->domain_id])) {
                $data[] = ['name' => $live[$pin->domain_id]['name'], 'mailbox_count' => $live[$pin->domain_id]['mailbox_count']];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, Domain $domain): Response
    {
        $userId = $this->user($request)->id;

        DB::transaction(function () use ($userId, $domain) {
            // Lock this user's pins so two concurrent pins cannot both take the last slot.
            User::query()->whereKey($userId)->lockForUpdate()->first();
            $pins = PinnedDomain::query()->where('user_id', $userId);

            if ((clone $pins)->where('domain_id', $domain->id)->exists()) {
                return;
            }
            if ((clone $pins)->count() >= self::MAX_PINS) {
                throw ValidationException::withMessages(['domain' => 'You can pin at most '.self::MAX_PINS.' domains.']);
            }

            $max = (clone $pins)->max('position');
            PinnedDomain::create([
                'user_id' => $userId,
                'domain_id' => $domain->id,
                'domain_name' => $domain->name,
                'position' => (is_numeric($max) ? (int) $max : 0) + 1,
            ]);
        });

        return response()->noContent();
    }

    public function destroy(Request $request, Domain $domain): Response
    {
        PinnedDomain::query()->where('user_id', $this->user($request)->id)->where('domain_id', $domain->id)->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }
}
