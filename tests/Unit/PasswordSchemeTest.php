<?php

use App\Doctor\Checks\PasswordSchemes;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use App\MailServer\PasswordScheme;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

it('classifies a stored hash by scheme', function (string $stored, string $strength) {
    expect(PasswordScheme::strength($stored))->toBe($strength);
})->with([
    // strong
    'BLF-CRYPT' => ['{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'strong'],
    'ARGON2I' => ['{ARGON2I}$argon2i$v=19$m=32768,t=4,p=1$c2FsdHNhbHQ$aGFzaA', 'strong'],
    'ARGON2ID' => ['{ARGON2ID}$argon2id$v=19$m=65536,t=3,p=1$c2FsdHNhbHQ$aGFzaA', 'strong'],
    'CRYPT $2a$' => ['{CRYPT}$2a$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'strong'],
    'CRYPT $2b$' => ['{CRYPT}$2b$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'strong'],
    'CRYPT $2y$' => ['{CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'strong'],
    'lowercase scheme name' => ['{blf-crypt}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'strong'],

    // legacy
    'SHA256-CRYPT' => ['{SHA256-CRYPT}$5$salt$hash', 'legacy'],
    'SHA512-CRYPT' => ['{SHA512-CRYPT}$6$3c9705362f29bbe5$OycqAm4Q4OGcMnR6Qk', 'legacy'],
    'CRYPT $5$' => ['{CRYPT}$5$salt$hash', 'legacy'],
    'CRYPT $6$' => ['{CRYPT}$6$salt$hash', 'legacy'],

    // weak
    'PLAIN' => ['{PLAIN}summersun', 'weak'],
    'CLEAR' => ['{CLEAR}summersun', 'weak'],
    'CLEARTEXT' => ['{CLEARTEXT}summersun', 'weak'],
    'PLAIN-TRUNC' => ['{PLAIN-TRUNC}9-summersun', 'weak'],
    'PLAIN-MD4' => ['{PLAIN-MD4}0123456789abcdef0123456789abcdef', 'weak'],
    'PLAIN-MD5' => ['{PLAIN-MD5}14cbfb845af1f030e372b1cb9275e6dd', 'weak'],
    'LDAP-MD5' => ['{LDAP-MD5}FMv7hFrx8DDjcrHLknXm3Q==', 'weak'],
    'MD5' => ['{MD5}$1$salt$hash', 'weak'],
    'SMD5' => ['{SMD5}c2FsdGVkaGFzaA==', 'weak'],
    'SHA' => ['{SHA}c2hhMWhhc2g=', 'weak'],
    'SSHA' => ['{SSHA}c3NoYWhhc2g=', 'weak'],
    'CRAM-MD5' => ['{CRAM-MD5}0123456789abcdef', 'weak'],
    'DIGEST-MD5' => ['{DIGEST-MD5}0123456789abcdef', 'weak'],
    'LANMAN' => ['{LANMAN}0123456789abcdef', 'weak'],
    'NTLM' => ['{NTLM}0123456789abcdef', 'weak'],
    'RPA' => ['{RPA}0123456789abcdef', 'weak'],

    // unknown
    'bare password' => ['summersun', 'unknown'],
    'empty' => ['', 'unknown'],
    'bare bcrypt without a scheme' => ['$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 'unknown'],
    'CRYPT with md5-crypt' => ['{CRYPT}$1$salt$hash', 'unknown'],
    'CRYPT with DES' => ['{CRYPT}abJnggxhB/yWI', 'unknown'],
    'unlisted scheme' => ['{SHA256}c2hhMjU2', 'unknown'],
    'unclosed brace' => ['{BLF-CRYPT$2y$05$abc', 'unknown'],
]);

it('extracts the scheme name', function () {
    expect(PasswordScheme::scheme('{blf-crypt}$2y$05$abc'))->toBe('BLF-CRYPT')
        ->and(PasswordScheme::scheme('summersun'))->toBeNull();
});

it('PasswordSchemes classifies {BLF-CRYPT}$2y$ without reading past the scheme', function () {
    GuideFixture::load('trixie');
    $table = GuideSchema::table('virtual_users');
    DB::table($table)->delete();
    DB::table($table)->insert([
        ['domain_id' => 10, 'email' => 'a@example.org', 'password' => '{BLF-CRYPT}$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234'],
        ['domain_id' => 10, 'email' => 'b@example.org', 'password' => '{SHA512-CRYPT}$6$3c9705362f29bbe5$OycqAm4Q4OGcMnR6Qk'],
    ]);

    $result = app(PasswordSchemes::class)->run();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->items)->toBe(['BLF-CRYPT: 1 (strong)', 'SHA512-CRYPT: 1 (legacy)']);
});
