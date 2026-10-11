<?php

namespace Tests\Support;

use Laravel\Passkeys\Passkeys;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A WebAuthn authenticator for tests: a real EC P-256 key pair made with OpenSSL, a `none` attestation
 * and ES256-signed assertions, so the app's real verification (web-auth/webauthn-lib behind
 * App\Auth\Passkeys) runs on every request. By default it answers like a browser on the app's own
 * origin (Passkeys::allowedOrigins()) with user verification; `$tamper` changes one thing at a time:
 *
 * - `origin`: the clientDataJSON origin; `type`: its type; `challenge`: its challenge (base64url);
 * - `rpId`: the RP ID whose hash goes into authenticatorData;
 * - `uv` / `up`: the user verified / present flags; `counter`: the signature counter;
 * - `signature`: raw bytes sent instead of the real signature.
 */
final class FakeAuthenticator
{
    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    public readonly string $credentialId;

    public int $counter = 0;

    private OpenSSLAsymmetricKey $key;

    public function __construct(?string $credentialId = null)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('OpenSSL could not make a P-256 key.');
        }
        $this->key = $key;
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    /** The credential id as the browser sends it (base64url without padding). */
    public function id(): string
    {
        return self::b64url($this->credentialId);
    }

    /**
     * The `PublicKeyCredential` (JSON) that `navigator.credentials.create()` would return for $options,
     * the creation options the server sent (`publicKey` members, base64url binary values).
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $tamper
     * @return array<string, mixed>
     */
    public function register(array $options, array $tamper = []): array
    {
        $rp = $options['rp'] ?? [];
        $rpId = $tamper['rpId'] ?? (is_array($rp) && is_string($rp['id'] ?? null) ? $rp['id'] : Passkeys::relyingPartyId());

        $authData = $this->authData((string) $rpId, $tamper, attested: true);
        $attestationObject = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($this->clientData('webauthn.create', $options, $tamper)),
                'attestationObject' => self::b64url($attestationObject),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * The `PublicKeyCredential` (JSON) that `navigator.credentials.get()` would return for $options,
     * signed with this authenticator's key. Each call counts the signature counter up by one, as a
     * real authenticator does, unless `counter` is tampered with.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $tamper
     * @return array<string, mixed>
     */
    public function assert(array $options, ?string $userHandle = null, array $tamper = []): array
    {
        $rpId = $tamper['rpId'] ?? (is_string($options['rpId'] ?? null) ? $options['rpId'] : Passkeys::relyingPartyId());
        if (! array_key_exists('counter', $tamper)) {
            $this->counter++;
        }

        $authData = $this->authData((string) $rpId, $tamper, attested: false);
        $clientData = $this->clientData('webauthn.get', $options, $tamper);
        if (! openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL could not sign the assertion.');
        }

        return [
            'id' => $this->id(),
            'rawId' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url(is_string($tamper['signature'] ?? null) ? $tamper['signature'] : $signature),
                'userHandle' => $userHandle === null ? null : self::b64url($userHandle),
            ],
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /** The public key as COSE (EC2, ES256, P-256) CBOR, as it sits in the attested credential data. */
    public function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $ec = is_array($details) && is_array($details['ec'] ?? null) ? $details['ec'] : throw new RuntimeException('Not an EC key.');

        return self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), -3 => new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]);
    }

    /** @param  array<string, mixed>  $tamper */
    private function authData(string $rpId, array $tamper, bool $attested): string
    {
        $flags = (($tamper['up'] ?? true) ? self::FLAG_UP : 0)
            | (($tamper['uv'] ?? true) ? self::FLAG_UV : 0)
            | ($attested ? self::FLAG_AT : 0);
        $counter = is_int($tamper['counter'] ?? null) ? $tamper['counter'] : $this->counter;

        $data = hash('sha256', $rpId, true).chr($flags).pack('N', $counter);
        if ($attested) {
            // AAGUID (all zero: none attestation), the credential id's length and the id, the COSE key.
            $data .= str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $tamper
     */
    private function clientData(string $type, array $options, array $tamper): string
    {
        $origin = $tamper['origin'] ?? Passkeys::allowedOrigins()[0];

        return (string) json_encode([
            'type' => $tamper['type'] ?? $type,
            'challenge' => $tamper['challenge'] ?? $options['challenge'] ?? '',
            'origin' => rtrim((string) $origin, '/'),
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** A minimal CBOR encoder: unsigned and negative integers, text, byte strings (CborBytes), maps. */
    public static function cbor(mixed $value): string
    {
        return match (true) {
            is_int($value) && $value >= 0 => self::head(0, $value),
            is_int($value) => self::head(1, -1 - $value),
            $value instanceof CborBytes => self::head(2, strlen($value->bytes)).$value->bytes,
            is_string($value) => self::head(3, strlen($value)).$value,
            is_array($value) => self::head(5, count($value)).implode('', array_map(
                fn (int|string $k, mixed $v) => self::cbor($k).self::cbor($v),
                array_keys($value),
                $value,
            )),
            default => throw new RuntimeException('Unsupported CBOR value.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        $type = $major << 5;

        return match (true) {
            $length < 24 => chr($type | $length),
            $length < 0x100 => chr($type | 24).chr($length),
            $length < 0x10000 => chr($type | 25).pack('n', $length),
            default => chr($type | 26).pack('N', $length),
        };
    }
}
