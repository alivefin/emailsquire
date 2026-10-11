<?php

namespace App\Console\Commands;

use App\Auth\TwoFactorAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery for a console user who lost their authenticator app or passkeys and the recovery codes. A
 * setup link (`create-admin --reset`) alone can't get them in: a user with a second factor still has to
 * pass the challenge after it. Turns off confirmed two-factor and deletes every passkey
 * (TwoFactorAccount::reset()).
 */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'emailsquire:reset-two-factor
        {email : The console user\'s email address}';

    protected $description = 'Turn off a console user\'s two-factor authentication and delete their passkeys (they lost them and the recovery codes)';

    public function handle(TwoFactorAccount $account): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("No console user {$email}.");

            return self::FAILURE;
        }

        if (! $account->reset($user, fromConsole: true)) {
            $this->error("{$email} has no confirmed two-factor authentication and no passkeys.");

            return self::FAILURE;
        }

        $this->line("Two-factor authentication is off and the passkeys are deleted for {$email}; they can sign in with their password and set it up again.");

        return self::SUCCESS;
    }
}
