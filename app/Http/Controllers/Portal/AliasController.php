<?php

namespace App\Http\Controllers\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StoreAliasRequest;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Queries\OwnForwarding;
use App\MailServer\Repositories\AliasRepository;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The mailbox owner's aliases: addresses on their own domain that deliver only to their mailbox (spec decision 7). */
#[Group('Portal')]
class AliasController extends Controller
{
    /**
     * The aliases, sorted. Readable while the domain's `aliases` switch is off.
     *
     * @operationId portal.aliases.index
     *
     * @response array{data: list<array{local: string, address: string}>}
     */
    public function index(Request $request, OwnForwarding $own): JsonResponse
    {
        return response()->json(['data' => array_map($this->alias(...), $own->aliases($this->owner($request)))]);
    }

    /**
     * Adds `local@<own domain>` delivering to this mailbox; at most 20. The address must not be a
     * mailbox, blocked, still being archived, or already forwarding anywhere. Audited as
     * `portal.alias_created`.
     *
     * @operationId portal.aliases.store
     *
     * @response array{local: string, address: string}
     */
    public function store(StoreAliasRequest $request, AliasRepository $aliases): JsonResponse
    {
        $source = $request->source();
        if (! $aliases->addOwnAlias($this->owner($request), $source)) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }

        return response()->json($this->alias($source->address()), 201);
    }

    /**
     * Removes one of this mailbox's aliases. Any other address answers 404. Audited as
     * `portal.alias_deleted`.
     *
     * @operationId portal.aliases.destroy
     */
    public function destroy(Request $request, string $local, AddressNormalizer $normalizer, AliasRepository $aliases): Response
    {
        $owner = $this->owner($request);
        try {
            $source = $normalizer->addressIn($local, $normalizer->domain($owner->domainName));
        } catch (InvalidAddress) {
            abort(404);
        }

        if (! $aliases->removeOwnAlias($owner, $source)) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }

        return response()->noContent();
    }

    /** @return array{local: string, address: string} */
    private function alias(string $address): array
    {
        return ['local' => substr($address, 0, (int) strrpos($address, '@')), 'address' => $address];
    }

    private function owner(Request $request): MailboxPrincipal
    {
        $owner = $request->user('mailbox');

        return $owner instanceof MailboxPrincipal ? $owner : throw new AuthenticationException('Unauthenticated.', ['mailbox']);
    }
}
