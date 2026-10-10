<?php

namespace App\Http\Controllers;

use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainList;
use App\MailServer\Repositories\PinnedDomainRepository;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PinnedDomainController extends Controller
{
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

    public function store(Request $request, Domain $domain, PinnedDomainRepository $pins): Response
    {
        $userId = $this->user($request)->id;

        $pins->pin($userId, $domain);

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
