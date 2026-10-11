<?php

namespace App\Http\Controllers\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Http\Controllers\Controller;
use App\Mail\MailboxWelcome;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainPeople;
use App\MailServer\SelfService;
use App\Models\MailboxUsage;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in mailbox, as the portal's home, settings and help screens show it. */
#[Group('Portal')]
class MeController extends Controller
{
    private const SUPPORT_CONTACT_MAX = 200;

    public function __construct(
        private readonly DomainPeople $people,
        private readonly AddressNormalizer $normalizer,
    ) {}

    /**
     * The mailbox, its storage against the quota, what its domain lets the owner change, who to ask
     * for help, and the settings for a mail app.
     *
     * @response array{address: string, domain: string, domain_unicode: string, quota_bytes: int, used_bytes: int|null, self_service: array{forwarding: bool, aliases: bool, external_forwarding: bool}, managers: list<array{name: string, email: string}>, support_contact: string|null, client_settings: array{imap_host: string, imap_port: 993, smtp_host: string, smtp_port: 587, webmail_url: string|null}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $mailbox = $request->user('mailbox');
        $domain = $mailbox instanceof MailboxPrincipal ? Domain::query()->find($mailbox->domainId) : null;
        if (! $mailbox instanceof MailboxPrincipal || $domain === null) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }
        $usedKib = MailboxUsage::query()->find(strtolower($mailbox->address))?->used_kib;
        $host = config()->string('emailsquire.mail_host');

        return response()->json([
            'address' => $mailbox->address,
            'domain' => $domain->name,
            'domain_unicode' => $this->unicode($domain->name),
            'quota_bytes' => $mailbox->quotaBytes,
            // From the last usage refresh; null until one has read this mailbox.
            'used_bytes' => is_int($usedKib) ? $usedKib * 1024 : null,
            'self_service' => SelfService::for($domain),
            'managers' => $this->managers($domain),
            'support_contact' => $this->supportContact(),
            'client_settings' => [
                'imap_host' => $host,
                'imap_port' => MailboxWelcome::IMAP_PORT,
                'smtp_host' => $host,
                'smtp_port' => MailboxWelcome::SMTP_PORT,
                'webmail_url' => MailboxWelcome::webmailUrl(),
            ],
        ]);
    }

    /**
     * The domain's active managers, by name (the email when the name is empty). Server admins are
     * never listed (spec decision 10), and neither are viewers.
     *
     * @return list<array{name: string, email: string}>
     */
    private function managers(Domain $domain): array
    {
        $managers = [];
        foreach ($this->people->list($domain) as $person) {
            if ($person['role'] === 'manager' && $person['status'] === 'active') {
                $managers[] = ['name' => $person['name'] !== '' ? $person['name'] : $person['email'], 'email' => $person['email']];
            }
        }

        return $managers;
    }

    /**
     * `emailsquire.support_contact` as one line of plain text without control or format characters,
     * at most 200 characters; null when empty.
     */
    private function supportContact(): ?string
    {
        $contact = config('emailsquire.support_contact');
        // Control characters become spaces; format characters (bidi overrides, zero-width) go, since
        // they could reorder or hide what is shown.
        $contact = is_string($contact) ? (string) preg_replace('/\p{Cf}+/u', '', $contact) : '';
        $contact = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $contact));
        $contact = mb_substr((string) preg_replace('/ {2,}/', ' ', $contact), 0, self::SUPPORT_CONTACT_MAX);

        return $contact === '' ? null : $contact;
    }

    private function unicode(string $name): string
    {
        try {
            return $this->normalizer->domain($name)->unicode;
        } catch (InvalidAddress) {
            return $name; // restored data may hold names we would not accept today
        }
    }
}
