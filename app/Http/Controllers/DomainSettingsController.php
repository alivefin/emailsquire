<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDomainSettingsRequest;
use App\MailServer\DomainLimits;
use App\MailServer\Models\Domain;
use Illuminate\Http\JsonResponse;

/** A domain's limits (`null` is unlimited) and what its mailboxes take against them. */
class DomainSettingsController extends Controller
{
    /**
     * @response array{max_mailboxes: int|null, max_quota_bytes_total: int|null, usage: array{mailboxes: int, quota_bytes_allocated: int}}
     */
    public function show(Domain $domain, DomainLimits $limits): JsonResponse
    {
        return response()->json($this->body($domain, $limits));
    }

    /**
     * Sets the limits sent; a field left out keeps its value. A limit below current use is accepted:
     * existing mailboxes stay, new ones (or bigger quotas) are refused with `domain_limit`.
     *
     * @response array{max_mailboxes: int|null, max_quota_bytes_total: int|null, usage: array{mailboxes: int, quota_bytes_allocated: int}}
     */
    public function update(UpdateDomainSettingsRequest $request, Domain $domain, DomainLimits $limits): JsonResponse
    {
        $limits->update($domain, $request->limits());

        return response()->json($this->body($domain, $limits));
    }

    /** @return array{max_mailboxes: int|null, max_quota_bytes_total: int|null, usage: array{mailboxes: int, quota_bytes_allocated: int}} */
    private function body(Domain $domain, DomainLimits $limits): array
    {
        return $limits->limits($domain) + ['usage' => $limits->usage($domain)];
    }
}
