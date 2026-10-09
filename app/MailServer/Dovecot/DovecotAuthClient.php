<?php

namespace App\MailServer\Dovecot;

/**
 * Checks passwords over Dovecot's auth socket (AUTH PLAIN, protocol 1.2).
 *
 * A wrong password is answered only after auth_failure_delay (2 s), hence the long default timeout.
 */
class DovecotAuthClient
{
    private const MAX_HANDSHAKE_LINES = 50;

    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeout,
    ) {}

    public static function authLine(int $id, string $user, string $password): string
    {
        return "AUTH\t{$id}\tPLAIN\tservice=emailsquire\tnologin\tresp=".base64_encode("\0{$user}\0{$password}")."\n";
    }

    /**
     * Connects, completes the handshake and closes again; a connectivity check.
     *
     * @throws DovecotAuthException
     */
    public function handshake(): void
    {
        try {
            $this->open();
        } finally {
            $this->close();
        }
    }

    /**
     * @throws DovecotAuthException
     */
    private function open(): void
    {
        $this->close();

        $stream = @stream_socket_client('unix://'.$this->socketPath, $errno, $errstr, $this->timeout);
        if ($stream === false) {
            $reason = $errno === 13 ? DovecotAuthFailure::PermissionDenied : DovecotAuthFailure::Unreachable;
            throw new DovecotAuthException($reason, "Cannot connect to {$this->socketPath}: {$errstr} ({$errno})");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $this->write("VERSION\t1\t2\nCPID\t".getmypid()."\n");

        for ($i = 0; $i < self::MAX_HANDSHAKE_LINES; $i++) {
            if ($this->readLine() === 'DONE') {
                return;
            }
        }

        throw new DovecotAuthException(DovecotAuthFailure::Protocol, 'Auth handshake did not end with DONE');
    }

    /**
     * @throws DovecotAuthException
     */
    public function verify(string $user, string $password): bool
    {
        // NUL is the PLAIN field separator; refuse before touching the socket.
        if (str_contains($user, "\0") || str_contains($password, "\0")) {
            return false;
        }

        try {
            $this->open();
            $this->write(self::authLine(1, $user, $password));
            $line = $this->readLine();
        } finally {
            $this->close();
        }

        return match (true) {
            str_starts_with($line, "OK\t1") => true,
            str_starts_with($line, "FAIL\t1") => false,
            default => throw new DovecotAuthException(DovecotAuthFailure::Protocol, 'Unexpected auth reply'),
        };
    }

    private function write(string $data): void
    {
        if ($this->stream === null || @fwrite($this->stream, $data) !== strlen($data)) {
            throw new DovecotAuthException(DovecotAuthFailure::Protocol, 'Could not write to the auth socket');
        }
    }

    private function readLine(): string
    {
        $line = $this->stream === null ? false : fgets($this->stream);
        if ($line === false) {
            $timedOut = $this->stream !== null && stream_get_meta_data($this->stream)['timed_out'];

            throw $timedOut
                ? new DovecotAuthException(DovecotAuthFailure::Timeout, "No reply from the auth socket within {$this->timeout}s")
                : new DovecotAuthException(DovecotAuthFailure::Protocol, 'Auth socket closed unexpectedly');
        }

        return rtrim($line, "\r\n");
    }

    private function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
