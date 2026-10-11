<?php

namespace Tests\Support;

/** A CBOR byte string (major type 2) for FakeAuthenticator::cbor(), as opposed to a PHP string encoded as CBOR text. */
final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}
