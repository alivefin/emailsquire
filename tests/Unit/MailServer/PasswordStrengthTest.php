<?php

use App\MailServer\PasswordGenerator;
use App\MailServer\PasswordStrength;

it('scores passwords', function (string $pw, int $score) {
    expect(PasswordStrength::score($pw))->toBe($score);
})->with([
    ['', 0], ['abc', 0], ['abcdefgh', 0], ['abcdefghijkl', 1], ['abcdefghijk1', 2],
    ['abcdefghijk!', 2], ['abcdefghij1!', 3], ['abcdefghijklmno1', 3], ['abcdefghijklmn1!', 4], ['a1!', 1],
]);

it('generates 16 character passwords from the alphabet that score at least 3', function () {
    for ($i = 0; $i < 1000; $i++) {
        $pw = PasswordGenerator::generate();
        expect($pw)->toMatch('/^[abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789\-_!]{16}$/')
            ->and(PasswordStrength::score($pw))->toBeGreaterThanOrEqual(3);
    }
});
