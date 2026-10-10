<?php

namespace App\Doctor;

/** Opens a TCP connection and reads the greeting line. A seam so tests need no real server. */
class SmtpProbe
{
    /** @return string|null the first line the server sent (without the line break), or null when it connected to nothing or sent nothing */
    public function banner(string $host, int $port, float $timeout = 3.0): ?string
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, $timeout);
        if ($socket === false) {
            return null;
        }

        stream_set_timeout($socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
        $line = fgets($socket, 512);
        fclose($socket);

        return $line === false ? null : rtrim($line, "\r\n");
    }
}
