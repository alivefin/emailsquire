<?php

namespace App\MailServer\Queries;

use App\Auth\DomainScope;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;

/** Header search: a few domains (prefix matches first) and a few addresses, within the reader's DomainScope. */
class Search
{
    public function __construct(private readonly AddressList $addresses) {}

    /** @return array{domains: list<array{name: string}>, addresses: list<array{address: string, type: 'mailbox'|'forwarding', domain: string}>} */
    public function run(DomainScope $scope, string $q): array
    {
        if (mb_strlen($q) < 2) {
            return ['domains' => [], 'addresses' => []];
        }

        $escaped = addcslashes($q, '\\%_');
        $query = DB::table(GuideSchema::table('virtual_domains').' as d')
            ->whereRaw('convert(d.name using utf8mb4) like ?', ['%'.$escaped.'%'])
            ->orderByRaw('case when convert(d.name using utf8mb4) like ? then 0 else 1 end', [$escaped.'%'])
            ->orderBy('d.name')
            ->limit(5);
        $scope->constrain($query, 'd.id');
        $rows = $query->pluck('d.name');

        $domains = [];
        foreach ($rows as $name) {
            $domains[] = ['name' => is_scalar($name) ? (string) $name : ''];
        }

        return ['domains' => $domains, 'addresses' => $this->addresses->search($scope, $q, 10)];
    }
}
