<?php

use App\MailServer\Dovecot\QuotaUsage;

it('reads used and limit from the STORAGE entry', function () {
    $usage = QuotaUsage::fromReply([
        ['root' => 'User quota', 'type' => 'STORAGE', 'value' => '1', 'limit' => '1000', 'percent' => '0'],
        ['root' => 'User quota', 'type' => 'MESSAGE', 'value' => '1', 'limit' => '-', 'percent' => '0'],
    ]);

    expect($usage)->toEqual(new QuotaUsage(1, 1000));
});

it('turns a dash limit into null', function () {
    expect(QuotaUsage::fromReply([['type' => 'STORAGE', 'value' => '20', 'limit' => '-']]))->toEqual(new QuotaUsage(20, null));
});

it('returns null without a STORAGE entry', function () {
    expect(QuotaUsage::fromReply([['type' => 'MESSAGE', 'value' => '1', 'limit' => '-']]))->toBeNull();
});

it('returns null for a non-numeric value', function () {
    expect(QuotaUsage::fromReply([['type' => 'STORAGE', 'value' => 'lots', 'limit' => '10']]))->toBeNull();
});
