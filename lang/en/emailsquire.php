<?php

// Server messages shown to the admin console. Values such as addresses and names are data and are
// passed in as placeholders; they are never translated. Problem `code` values never change.
return [
    'address' => [
        'empty' => 'Enter an address.',
        'local_chars' => 'Only letters, numbers, dots, hyphens and underscores — no spaces.',
        'domain_format' => 'That doesn’t look like a domain name.',
        'too_long' => 'Addresses can be at most 100 characters and domains 50.',
        'not_ascii' => 'Use letters a–z in the part before @.',
    ],
    'claims' => [
        'exists_mailbox' => ':address already exists.',
        'exists_forwarding' => ':address already forwards somewhere — remove that first.',
        'redirect_not_confirmed' => ':address is a mailbox. Confirm that its mail should be forwarded only.',
        'blocked' => ':address is blocked.',
        'blocked_reason' => ':address is blocked — reason: “:reason”.',
        'archiving' => ':address was deleted recently and its mail is still being archived. Try again in a few minutes.',
    ],
    'conflict' => [
        'busy' => 'Someone else is changing this address. Try again.',
        'not_failed' => 'Task :id is not a failed maildir task.',
    ],
    'check' => [
        'mailbox' => ':address is already a mailbox.',
        'forwarding' => ':address already forwards mail.',
        'available' => ':address is available.',
    ],
    'problem' => [
        'csrf' => 'The CSRF token is missing or does not match.',
        'too_many_requests' => 'Too many requests. Try again later.',
        'server_error' => 'Something went wrong.',
        'unauthenticated' => 'Authentication is required.',
        'session_expired' => 'Your session has expired. Sign in again.',
        'cross_origin' => 'Cross-origin requests are not allowed.',
        'usage_unavailable' => 'Storage usage is unavailable right now.',
        'step_up_required' => 'Confirm your password or two-factor code to continue.',
        'two_factor_required' => 'Set up two-factor authentication to continue.',
    ],
    'domain' => [
        'exists' => ':name is already on this server.',
        'confirm_name' => 'Type :name to confirm.',
        'confirm_name_idn' => 'Type :unicode (or :ascii) to confirm.',
        'pin_limit' => 'You can pin at most :max domains.',
    ],
    'mailbox' => [
        'password_weak' => 'Too weak — use 12+ characters with a number or symbol.',
        'password_too_long' => 'Use at most 72 bytes.',
        'unknown' => 'Unknown mailbox: :locals.',
    ],
    'forwarding' => [
        'targets_required' => 'Add at least one destination.',
        'targets_max' => 'A source can forward to at most 50 destinations.',
        'target_incomplete' => '“:value” is not a complete address.',
        'target_self' => 'An address can’t deliver to itself.',
    ],
    'blocked' => [
        'already_blocked' => 'Already blocked.',
    ],
    'step_up' => [
        'code_invalid' => 'The code is wrong or has expired.',
        'no_two_factor' => 'Two-factor authentication is not turned on for your account. Use your password.',
    ],
    'two_factor' => [
        'code_invalid' => 'The code is wrong or has expired.',
        'recovery_code_invalid' => 'That recovery code is wrong or has already been used.',
        'no_pending_sign_in' => 'Sign in with your email and password first.',
        'pending_expired' => 'Your sign-in has expired. Enter your email and password again.',
        'not_enabled' => 'Two-factor authentication is not turned on.',
        'already_confirmed' => 'Two-factor authentication is already on.',
    ],
    'setup' => [
        'invalid_link' => 'This setup link is invalid or has expired.',
    ],
    'reset' => [
        'invalid_link' => 'This reset link is invalid or has expired.',
    ],
];
