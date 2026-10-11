<?php

namespace App\Http\Controllers;

use App\MailServer\Dkim;
use App\MailServer\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A domain's DKIM keys (spec decisions 2 to 5). Any role on the domain reads them; server admins, with
 * step-up, ask the DKIM helper (running as `_rspamd`) to generate, activate, retire or inspect keys.
 * Each write answers 202 with the same body as reading: the helper picks the task up within a minute,
 * and the next read shows its result.
 *
 * A key's `state`: `pending` (on the server, not signing: new, or replaced by a newer one), `active`
 * (rspamd signs with it) or `retired` (its file is deleted). `txt` is the DNS record to publish at
 * `<selector>._domainkey.<domain>`; null for an imported key whose public half the helper could not read.
 * `pending_task` is the DKIM task the helper has yet to finish, or the newest one when it `failed`.
 */
class DomainDkimController extends Controller
{
    /**
     * @response array{active: string|null, keys: list<array{selector: string, state: 'pending'|'active'|'retired', txt: string|null, created_at: string|null}>, pending_task: array{type: 'dkim.generate'|'dkim.activate'|'dkim.retire'|'dkim.inspect', status: 'pending'|'running'|'failed'}|null}
     */
    public function show(Domain $domain, Dkim $dkim): JsonResponse
    {
        return response()->json($dkim->state($domain));
    }

    /**
     * Queues a new key, named `esYYYYMMDD` (plus a letter when the day already has one). It does not
     * sign until it is activated. Audited as `dkim.generated`.
     */
    public function generate(Domain $domain, Dkim $dkim): JsonResponse
    {
        $dkim->generate($domain);

        return $this->accepted($domain, $dkim);
    }

    /**
     * Queues switching rspamd to the key. Refused with `dkim_not_published` unless the latest DNS check
     * shows the key's record, or `force` is true (the console warns first). Audited as `dkim.activated`.
     */
    public function activate(Request $request, Domain $domain, string $selector, Dkim $dkim): JsonResponse
    {
        $request->validate(['force' => ['sometimes', 'boolean']]);
        $dkim->activate($domain, $selector, $request->boolean('force'));

        return $this->accepted($domain, $dkim);
    }

    /**
     * Queues deleting a key that is not active. Refused for the active key (`dkim_active`), and for 15
     * minutes after the domain's latest activation (`dkim_recently_activated`, with `retry_after` in
     * seconds), while rspamd may still sign with the previous key. Audited as `dkim.retired`.
     */
    public function retire(Domain $domain, string $selector, Dkim $dkim): JsonResponse
    {
        $dkim->retire($domain, $selector);

        return $this->accepted($domain, $dkim);
    }

    /**
     * Queues reading the server's selector map and key files for the domain, which brings in keys the
     * console did not make (the installer's): the map's key becomes `active`, other key files `pending`,
     * and keys whose file is gone `retired`.
     */
    public function inspect(Domain $domain, Dkim $dkim): JsonResponse
    {
        $dkim->inspect($domain);

        return $this->accepted($domain, $dkim);
    }

    private function accepted(Domain $domain, Dkim $dkim): JsonResponse
    {
        /**
         * @status 202
         *
         * @body array{active: string|null, keys: list<array{selector: string, state: 'pending'|'active'|'retired', txt: string|null, created_at: string|null}>, pending_task: array{type: 'dkim.generate'|'dkim.activate'|'dkim.retire'|'dkim.inspect', status: 'pending'|'running'|'failed'}|null}
         */
        return response()->json($dkim->state($domain), 202);
    }
}
