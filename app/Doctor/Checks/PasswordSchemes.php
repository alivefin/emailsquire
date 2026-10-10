<?php

namespace App\Doctor\Checks;

use App\Doctor\CheckResult;
use App\Doctor\GuideCheck;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use App\MailServer\PasswordScheme;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PasswordSchemes extends GuideCheck
{
    public function id(): string
    {
        return 'guide.password_schemes';
    }

    public function title(): string
    {
        return 'Password hash schemes';
    }

    /**
     * Only the `{SCHEME}` prefix leaves the database, plus the next four
     * characters for `{CRYPT}` alone (enough to tell `$2y$` from `$6$`, never
     * the hash itself). Every other scheme names its own strength.
     *
     * @return Collection<int, stdClass>
     */
    public function rows(): Collection
    {
        return DB::table(GuideSchema::table('virtual_users'))
            ->selectRaw("CASE WHEN password LIKE '{%}%' THEN SUBSTRING(password, 1, LOCATE('}', password)) ELSE '' END AS prefix")
            ->selectRaw("CASE WHEN LOWER(password) LIKE '{crypt}%' THEN SUBSTRING(password, 8, 4) ELSE '' END AS head")
            ->selectRaw('COUNT(*) AS n')
            ->groupBy('prefix', 'head')
            ->get();
    }

    protected function check(): CheckResult
    {
        $rows = $this->rows();

        /** @var array<string, array{scheme: string, strength: string, count: int}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            [$prefix, $head, $n] = self::strings([$row->prefix ?? '', $row->head ?? '', $row->n ?? 0]);
            $scheme = PasswordScheme::scheme($prefix.$head) ?? '(none)';
            $strength = PasswordScheme::strength($prefix.$head);

            $groups["{$scheme} {$strength}"] ??= ['scheme' => $scheme, 'strength' => $strength, 'count' => 0];
            $groups["{$scheme} {$strength}"]['count'] += (int) $n;
        }
        ksort($groups);

        $items = [];
        $byStrength = [];
        foreach ($groups as ['scheme' => $scheme, 'strength' => $strength, 'count' => $count]) {
            $items[] = "{$scheme}: {$count} ({$strength})";
            $byStrength[$strength] = ($byStrength[$strength] ?? 0) + $count;
        }

        $bad = ($byStrength[PasswordScheme::WEAK] ?? 0) + ($byStrength[PasswordScheme::UNKNOWN] ?? 0);
        if ($bad > 0) {
            return $this->result(
                Status::Fail,
                "{$bad} mailbox(es) have a weak or unrecognised password hash; reset their passwords.",
                $items,
            );
        }

        $legacy = $byStrength[PasswordScheme::LEGACY] ?? 0;
        if ($legacy > 0) {
            return $this->result(
                Status::Warn,
                "{$legacy} mailbox(es) use a legacy SHA-crypt hash; upgrades on next password change.",
                $items,
            );
        }

        return $this->result(Status::Ok, 'All password hashes use a strong scheme.', $items);
    }
}
