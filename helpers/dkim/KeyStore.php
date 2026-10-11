<?php

namespace EmailSquire\Helpers\Dkim;

/**
 * rspamd's DKIM keys: `<dkim-dir>/<domain>.<selector>.key`, PEM, mode 0600, the path pattern of the
 * guide's dkim_signing.conf. Every path is built here from the configured directory plus a domain
 * and a selector the caller has validated. Private keys never leave this class: callers get the
 * public half as a DKIM TXT record.
 */
final class KeyStore
{
    /** Selectors the helper makes, activates and retires: es + YYYYMMDD + an optional letter. */
    public const SELECTOR = '/^es\d{8}[a-z]?$/D';

    /** Any selector a key file or the map may carry (one DNS label), for keys the helper did not make. */
    public const LABEL = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/D';

    /** An RSA 4096 PEM is about 3.3 KiB; anything near this is no key. */
    public const MAX_BYTES = 65536;

    private const DIR_NAME = 'the DKIM directory';

    public function __construct(private readonly string $dir) {}

    /**
     * The first free selector of the day: esYYYYMMDD, then esYYYYMMDDb up to esYYYYMMDDz. A selector
     * is taken when its key file exists or the map already names it for the domain.
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function nextSelector(string $domain, int $time, ?string $active): string
    {
        $this->checkDir();
        $base = 'es'.gmdate('Ymd', $time);
        foreach (['', ...range('b', 'z')] as $suffix) {
            $selector = $base.$suffix;
            $taken = $this->exists($domain, $selector) || ($active !== null && strcasecmp($active, $selector) === 0);
            if (! $taken) {
                return $selector;
            }
        }

        throw new Refused('generate refused: every selector for today is taken');
    }

    /**
     * Writes a new RSA 2048 key, mode 0600, and returns its TXT record. The key goes to a temp file in
     * the same directory first and is then hard-linked into place, which never replaces a file.
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function generate(string $domain, string $selector): string
    {
        $this->checkDir();
        $file = self::fileName($domain, $selector);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            self::clearOpenSslErrors();
            throw new \RuntimeException('cannot generate an RSA key');
        }
        $txt = self::txt($key) ?? throw new \RuntimeException('cannot derive the public key');

        $temp = "{$this->dir}/.{$file}.".bin2hex(random_bytes(6)).'.tmp';
        $umask = umask(0077);
        try {
            $handle = Io::call("cannot write the key file {$file}", static fn () => fopen($temp, 'xb'));
        } finally {
            umask($umask);
        }
        try {
            $written = fwrite($handle, $pem) === strlen($pem) && fflush($handle) && fsync($handle);
            fclose($handle);
            if (! $written) {
                throw new \RuntimeException("cannot write the key file {$file}");
            }
            Io::call("cannot write the key file {$file}", fn () => link($temp, $this->path($domain, $selector)));
            PathGuard::syncDirectory($this->dir);
        } finally {
            @unlink($temp);
        }

        return $txt;
    }

    /**
     * @throws Refused when a symlink or something other than a regular file is there
     * @throws \RuntimeException
     */
    public function exists(string $domain, string $selector): bool
    {
        $this->checkDir();

        return PathGuard::file($this->path($domain, $selector), 'the key file '.self::fileName($domain, $selector));
    }

    /**
     * The TXT record of an existing key, or null when the file holds no RSA private key that PHP can
     * read (rspamd's ed25519 keys, say).
     *
     * @throws Refused
     * @throws \RuntimeException when the file is missing or unreadable
     */
    public function txtOf(string $domain, string $selector): ?string
    {
        $this->checkDir();
        $file = self::fileName($domain, $selector);
        [$pem] = PathGuard::read($this->path($domain, $selector), "the key file {$file}", self::MAX_BYTES)
            ?? throw new \RuntimeException("the key file {$file} disappeared");

        $key = openssl_pkey_get_private($pem);
        unset($pem);
        if ($key === false) {
            self::clearOpenSslErrors();

            return null;
        }

        return self::txt($key);
    }

    /**
     * Deletes a key file. False when there was none.
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function delete(string $domain, string $selector): bool
    {
        if (! $this->exists($domain, $selector)) {
            return false;
        }
        $file = self::fileName($domain, $selector);
        Io::call("cannot delete the key file {$file}", fn () => unlink($this->path($domain, $selector)));

        return true;
    }

    /**
     * The selectors of the domain's key files, sorted. A name whose middle is not one DNS label (such as
     * `example.org.uk.sel.key` for `example.org`) belongs to another domain and is left out.
     *
     * @return list<string>
     *
     * @throws Refused
     * @throws \RuntimeException
     */
    public function selectors(string $domain): array
    {
        $this->checkDir();
        $names = @scandir($this->dir);
        if ($names === false) {
            throw new \RuntimeException('cannot read the DKIM directory');
        }

        $selectors = [];
        foreach ($names as $name) {
            if (! str_starts_with($name, "{$domain}.") || ! str_ends_with($name, '.key')) {
                continue;
            }
            $selector = substr($name, strlen($domain) + 1, -4);
            if (preg_match(self::LABEL, $selector) === 1 && $this->exists($domain, $selector)) {
                $selectors[] = $selector;
            }
        }
        sort($selectors, SORT_STRING);

        return $selectors;
    }

    public static function fileName(string $domain, string $selector): string
    {
        return "{$domain}.{$selector}.key";
    }

    /** `v=DKIM1; k=rsa; p=<base64 SubjectPublicKeyInfo>`, or null when the key is not RSA. */
    private static function txt(\OpenSSLAsymmetricKey $key): ?string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ! is_string($details['key'] ?? null)) {
            return null;
        }
        $body = preg_replace('/-----(?:BEGIN|END) PUBLIC KEY-----|\s+/', '', $details['key']);
        if (! is_string($body) || preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $body) !== 1) {
            return null;
        }

        return "v=DKIM1; k=rsa; p={$body}";
    }

    /**
     * @throws Refused
     * @throws \RuntimeException when the directory is missing (an I/O error: retried)
     */
    private function checkDir(): void
    {
        if (! PathGuard::directory($this->dir, self::DIR_NAME)) {
            throw new \RuntimeException('the DKIM directory is missing');
        }
    }

    private function path(string $domain, string $selector): string
    {
        return rtrim($this->dir, '/').'/'.self::fileName($domain, $selector);
    }

    /** OpenSSL queues error strings per process; drop them so nothing stale is ever reported. */
    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // drain
        }
    }
}
