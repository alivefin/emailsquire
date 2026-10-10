<?php

use App\Mail\SecurityNotice;
use App\Models\User;

it('accepts every kind it has a text for', function (string $kind) {
    expect((new SecurityNotice(new User, $kind, null))->kind)->toBe($kind);
})->with(SecurityNotice::KINDS);

it('refuses a kind it has no text for', function (string $kind) {
    new SecurityNotice(new User, $kind, null);
})->with(['password_changd', '', 'subject'])->throws(InvalidArgumentException::class);
