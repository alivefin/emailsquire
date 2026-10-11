<?php

namespace App\MailServer;

use App\MailServer\Address\EmailAddress;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Domain;
use App\Models\HelperTask;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class HelperTasks
{
    /** The DKIM helper's task types (helpers/dkim.php). */
    public const DKIM_TYPES = ['dkim.generate', 'dkim.activate', 'dkim.retire', 'dkim.inspect'];

    public function queueArchive(EmailAddress $a): void
    {
        $this->insert('maildir.archive', $a);
    }

    public function queueRestore(EmailAddress $a, ?string $from): HelperTask
    {
        return $this->insert('maildir.restore', $a, ['from' => $from]);
    }

    /** Case-insensitive like restorePending(): tasks carry the guide row's stored spelling. */
    public function archivePending(string $address): bool
    {
        return HelperTask::query()
            ->where('type', 'maildir.archive')
            ->where('address', $address)
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->exists();
    }

    /** @return Collection<int, HelperTask> */
    public function open(): Collection
    {
        return HelperTask::query()
            ->where('type', 'like', 'maildir.%')
            ->whereIn('status', ['pending', 'running', 'failed'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether an open restore already exists for the address. Tasks carry the guide row's stored spelling;
     * helper_tasks.address is utf8mb4_unicode_ci, so a lowercase address still matches a legacy Anna@Example.org.
     */
    public function restorePending(string $address): bool
    {
        return HelperTask::query()
            ->where('type', 'maildir.restore')
            ->whereIn('status', ['pending', 'running'])
            ->where('address', $address)
            ->exists();
    }

    /** Must run inside the caller's transaction when it audits the reset. */
    public function retry(int $id): HelperTask
    {
        $task = HelperTask::query()->where('type', 'like', 'maildir.%')->lockForUpdate()->find($id);

        if ($task === null || $task->status !== 'failed') {
            throw new Conflict('not_failed', __('emailsquire.conflict.not_failed', ['id' => $id]));
        }

        $task->forceFill(['status' => 'pending', 'attempts' => 0, 'run_after' => now(), 'last_error' => null])->save();

        return $task;
    }

    /**
     * Queues a DKIM helper task for $domain. The payload carries the domain's stored name, which is all
     * the helper reads (with the selector), and its id, so the app applies the result only to this
     * domain and never to a later one that reuses the name (dkimTasks()).
     */
    public function queueDkim(string $type, Domain $domain, ?string $selector = null): HelperTask
    {
        if (! in_array($type, self::DKIM_TYPES, true)) {
            throw new InvalidArgumentException("{$type} is not a DKIM task type.");
        }

        $task = new HelperTask;
        $task->forceFill([
            'type' => $type,
            'address' => null,
            'domain_name' => $domain->name,
            'payload' => ['domain' => $domain->name, 'domain_id' => $domain->id] + ($selector === null ? [] : ['selector' => $selector]),
            'status' => 'pending',
            'run_after' => now(),
        ]);
        $task->save();

        return $task;
    }

    /**
     * The domain's DKIM tasks: its name (helper_tasks.domain_name is case-insensitive) and its id in the
     * payload, so tasks of an earlier domain with the same name don't count.
     *
     * @return Builder<HelperTask>
     */
    public function dkimTasks(Domain $domain): Builder
    {
        return HelperTask::query()
            ->whereIn('type', self::DKIM_TYPES)
            ->where('domain_name', $domain->name)
            ->where('payload->domain_id', $domain->id);
    }

    /** The domain's DKIM task the helper has yet to finish (pending or running), if any. */
    public function dkimOpen(Domain $domain): ?HelperTask
    {
        return $this->dkimTasks($domain)->whereIn('status', ['pending', 'running'])->orderByDesc('id')->first();
    }

    /** The domain's newest DKIM task, whatever its status. */
    public function dkimLatest(Domain $domain): ?HelperTask
    {
        return $this->dkimTasks($domain)->orderByDesc('id')->first();
    }

    /**
     * Finished (done or failed) DKIM tasks of the domain whose result the app has not read yet, oldest
     * first. With $lock, inside the caller's transaction, a second reader waits and then finds them read.
     *
     * @return Collection<int, HelperTask>
     */
    public function dkimUnapplied(Domain $domain, bool $lock = false): Collection
    {
        $query = $this->dkimTasks($domain)->whereIn('status', ['done', 'failed'])->whereNull('applied_at')->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /** When the helper finished the domain's latest activation: when rspamd's selector map changed. */
    public function dkimLastActivated(Domain $domain): ?CarbonInterface
    {
        return $this->dkimTasks($domain)->where('type', 'dkim.activate')->where('status', 'done')->orderByDesc('id')->first()?->updated_at;
    }

    /** @param array<string, mixed>|null $payload */
    private function insert(string $type, EmailAddress $a, ?array $payload = null): HelperTask
    {
        $task = new HelperTask;
        $task->forceFill([
            'type' => $type,
            'address' => $a->address(),
            'domain_name' => $a->domain->ascii,
            'payload' => $payload,
            'status' => 'pending',
            'run_after' => now(),
        ]);
        $task->save();

        return $task;
    }
}
