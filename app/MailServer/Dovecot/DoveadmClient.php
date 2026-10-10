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
        $result = $this->callMany([[$command, $parameters]])[0];
        if ($result instanceof DoveadmException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Sends several commands in one request. Replies are matched by tag, not by position.
     * A command that doveadm rejects (for example exit code 67 for an unknown user) comes back
     * as a DoveadmException in its slot; transport failures and malformed replies throw.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $commands
     * @return list<array<mixed>|DoveadmException>
     *
     * @throws DoveadmException
     */
    public function callMany(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $tags = [];
        $payload = [];
        foreach ($commands as [$command, $parameters]) {
            $tag = bin2hex(random_bytes(4));
            $tags[] = $tag;
            $payload[] = [$command, (object) $parameters, $tag];
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

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
        if (! is_array($decoded) || count($decoded) !== count($commands)) {
            throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply does not hold one reply per command');
        }

        $byTag = [];
        foreach ($decoded as $reply) {
            if (! is_array($reply) || count($reply) !== 3 || ! is_string($reply[2]) || isset($byTag[$reply[2]])) {
                throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply has a malformed or duplicate tag');
            }
            $byTag[$reply[2]] = $reply;
        }

        $results = [];
        foreach ($tags as $tag) {
            $reply = $byTag[$tag] ?? null;
            if ($reply === null) {
                throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply is missing one of our tags');
            }

            if ($reply[0] === 'error') {
                $exitCode = is_array($reply[1]) && is_int($reply[1]['exitCode'] ?? null) ? $reply[1]['exitCode'] : null;
                $results[] = new DoveadmException(DoveadmFailure::CommandError, 'doveadm command failed'.($exitCode !== null ? " with exit code {$exitCode}" : ''), $exitCode);

                continue;
            }
            if ($reply[0] !== 'doveadmResponse' || ! is_array($reply[1])) {
                throw new DoveadmException(DoveadmFailure::Malformed, 'doveadm API reply has an unexpected shape');
            }
            $results[] = $reply[1];
        }

        return $results;
    }
}
