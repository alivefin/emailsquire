<?php

namespace App\MailServer\Dovecot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Client for Dovecot's doveadm HTTP API.
 *
 * Pass the "field" parameter of the "user" command as a string; an array makes Dovecot 2.4.1 drop the connection.
 */
class DoveadmClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<mixed>
     *
     * @throws DoveadmException
     */
    public function call(string $command, array $parameters = []): array
    {
        $tag = bin2hex(random_bytes(4));
        $body = json_encode([[$command, (object) $parameters, $tag]], JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders(['Authorization' => 'X-Dovecot-API '.base64_encode((string) $this->apiKey)])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($this->url);
        } catch (ConnectionException $e) {
            throw new DoveadmException(DoveadmFailure::Unreachable, 'doveadm API unreachable: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new DoveadmException(DoveadmFailure::Unauthorized, 'doveadm API rejected the API key');
        }
        if (! $response->successful()) {
            throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API returned HTTP '.$response->status());
        }

        $decoded = json_decode($response->body(), true);
        $reply = is_array($decoded) ? ($decoded[0] ?? null) : null;
        if (! is_array($reply) || count($reply) !== 3 || ($reply[2] ?? null) !== $tag) {
            throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply is not a single reply with our tag');
        }

        if ($reply[0] === 'error') {
            $exitCode = is_array($reply[1]) && is_int($reply[1]['exitCode'] ?? null) ? $reply[1]['exitCode'] : null;
            throw new DoveadmException(DoveadmFailure::CommandError, 'doveadm command failed'.($exitCode !== null ? " with exit code {$exitCode}" : ''), $exitCode);
        }
        if ($reply[0] !== 'doveadmResponse' || ! is_array($reply[1])) {
            throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply has an unexpected shape');
        }

        return $reply[1];
    }
}
