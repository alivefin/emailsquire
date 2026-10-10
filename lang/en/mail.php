<?php

// Email to console users. The recipient's `users.locale` picks the file; addresses and times are
// passed in as placeholders.
return [
    'security' => [
        'password_changed' => [
            'subject' => 'Your password was changed',
            'what' => 'The password for your EmailSquire account was changed.',
        ],
        'password_reset' => [
            'subject' => 'Your password was reset',
            'what' => 'The password for your EmailSquire account was reset with a reset link.',
        ],
        'two_factor_disabled' => [
            'subject' => 'Two-factor authentication was turned off',
            'what' => 'Two-factor authentication was turned off for your EmailSquire account.',
        ],
        'sessions_revoked' => [
            'subject' => 'You were signed out everywhere',
            'what' => 'All other sessions of your EmailSquire account were signed out.',
        ],
        'when' => 'When: :time',
        'from_ip' => 'From IP address: :ip',
        'from_unknown' => 'The IP address is not known.',
        'not_you' => 'If this wasn’t you, contact your server admin.',
    ],
];
