<?php

namespace App\Http\Controllers;

use App\Auth\DomainAccess;
use App\MailServer\Queries\DomainList;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/** Every domain the user has a role on, for pickers, with that role. Not paginated. */
class DomainNameController extends Controller
{
    /**
     * @response array{data: list<array{name: string, unicode_name: string, mailbox_count: int, role: 'admin'|'manager'|'viewer'}>}
     */
    public function __invoke(Request $request, DomainList $list, DomainAccess $access): JsonResponse
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : abort(401);

        $data = [];
        foreach ($list->names($access->scope($user)) as $row) {
            $data[] = [
                'name' => $row['name'],
                'unicode_name' => $row['unicode_name'],
                'mailbox_count' => $row['mailbox_count'],
                'role' => $access->roleForId($user, $row['id']) ?? throw new LogicException('A listed domain has no role.'),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
