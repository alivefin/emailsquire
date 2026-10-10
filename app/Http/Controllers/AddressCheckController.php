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
        $body = ['address' => $address->address(), 'status' => $status];

        if ($status === 'blocked') {
            $body['reason'] = $blocked->find($address->address())?->reason;
            $body['message'] = $claims->blockedMessage($address);
        } elseif ($status === 'archiving') {
            $body['message'] = $claims->archivingMessage($address);
        }

        return response()->json($body);
    }
}
