<?php

// Email to console users, and the welcome email to a mailbox's user. The recipient's `users.locale`
// (the welcome email: the locale its sender chose) picks the file; addresses and times are passed in
// as placeholders.
return [
    'reset' => [
        'subject' => 'Reset your EmailSquire password',
        'intro' => 'Someone asked to reset the password of your EmailSquire account. Choose a new password with the button below.',
        'action' => 'Choose a new password',
        'expires' => 'The link works for :minutes minutes and only once.',
        'not_you' => 'If you didn’t ask for this, you can ignore this email. Your password stays the same.',
        'link_text' => 'If the button doesn’t work, copy this address into your browser:',
    ],
    'invitation' => [
        'subject' => 'You’re invited to EmailSquire',
        'greeting' => 'Hello,',
        'greeting_name' => 'Hello :name,',
        'intro' => ':inviter invited you to EmailSquire, the admin console of this mail server.',
        'access_domain' => 'Domain: :domain',
        'access_server' => 'Access: the whole server',
        'role' => 'Role: :role',
        'roles' => [
            'server_admin' => 'Server admin',
            'manager' => 'Domain manager',
            'viewer' => 'Viewer',
        ],
        'note' => 'Message from :inviter:',
        'action' => 'Accept the invitation',
        'expires' => 'The link is valid for :hours hours and works only once.',
        'not_you' => 'If you weren’t expecting this invitation, you can ignore this email.',
        'link_text' => 'If the button doesn’t work, copy this address into your browser:',
    ],
    'welcome' => [
        'subject' => 'Your mailbox :address is ready',
        'intro' => 'Your mailbox :address is ready to use. Below are the settings for your email app.',
        'webmail' => 'You can also read your mail in a web browser:',
        'webmail_action' => 'Open webmail',
        'settings' => 'Email app settings',
        'username' => 'Username: :address',
        'imap' => 'Incoming mail (IMAP): :server, SSL/TLS',
        'smtp' => 'Outgoing mail (SMTP): :server, STARTTLS',
        'smtp_auth' => 'Outgoing mail uses the same username and password.',
        'password' => 'Your administrator will give you your password.',
        'not_you' => 'If you weren’t expecting this email, you can ignore it.',
    ],
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
        'passkey_added' => [
            'subject' => 'A passkey was added',
            'what' => 'A passkey was added to your EmailSquire account. It can sign in to the account.',
        ],
        'passkey_removed' => [
            'subject' => 'A passkey was removed',
            'what' => 'A passkey was removed from your EmailSquire account.',
        ],
        'passkey_cloned' => [
            'subject' => 'A passkey was turned off',
            'what' => 'One of your EmailSquire passkeys was used with a signature counter that did not go up, which can mean it was copied. It was turned off and can no longer sign in. If you did not expect this, remove it in My profile and change your password.',
        ],
        'sessions_revoked' => [
            'subject' => 'You were signed out everywhere',
            'what' => 'All other sessions of your EmailSquire account were signed out.',
        ],
        'email_changed' => [
            'subject' => 'Your email address was changed',
            'what' => 'The email address of your EmailSquire account was changed. You can no longer sign in with this address.',
        ],
        'when' => 'When: :time',
        'from_ip' => 'From IP address: :ip',
        'from_unknown' => 'The IP address is not known.',
        'not_you' => 'If this wasn’t you, contact your server admin.',
    ],
];
