<?php

namespace App\Http\Controllers;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\AddressClaims;
use App\MailServer\Models\Domain;
use App\MailServer\Repositories\BlockedAddressRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Drives the live available / blocked / exists hint next to the address field. */
class AddressCheckController extends Controller
{
    public function __invoke(Request $request, Domain $domain, AddressNormalizer $normalizer, AddressClaims $claims, BlockedAddressRepository $blocked): JsonResponse
    {
        $local = $request->query('local');
        $local = is_string($local) ? $local : '';

        try {
            $address = $normalizer->addressIn($local, $normalizer->domain($domain->name));
        } catch (InvalidAddress $e) {
            return response()->json([
                'address' => trim($local) === '' ? null : strtolower(trim($local)).'@'.$domain->name,
                'status' => 'invalid',
                'message' => $e->getMessage(),
            ]);
        }

        $status = $claims->status($address);
        $full = $address->address();

        return response()->json([
            'address' => $full,
            'status' => $status,
            'reason' => $status === 'blocked' ? $blocked->find($full)?->reason : null,
            'message' => match ($status) {
                'blocked' => $claims->blockedMessage($address) ?? $full.' is blocked.',
                'archiving' => $claims->archivingMessage($address),
                'mailbox' => $full.' is already a mailbox.',
                'forwarding' => $full.' already forwards mail.',
                default => $full.' is available.',
            },
        ]);
    }
}
