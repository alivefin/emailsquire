<?php

namespace App\Console\Commands;

use App\Auth\TwoFactorAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recovery for a console user who lost both the authenticator app and the recovery codes. A setup link
 * (`create-admin --reset`) alone can't get them in: a user with confirmed two-factor still has to pass
 * the challenge after it.
 */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'emailsquire:reset-two-factor
        {email : The console user\'s email address}';

    protected $description = 'Turn off a console user\'s two-factor authentication (they lost the app and the recovery codes)';

    public function handle(TwoFactorAccount $account): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("No console user {$email}.");

            return self::FAILURE;
        }

        if (! $account->resetFromConsole($user)) {
            $this->error("{$email} has no confirmed two-factor authentication.");

            return self::FAILURE;
        }

        $this->line("Two-factor authentication is off for {$email}; they can sign in with their password and set it up again.");

        return self::SUCCESS;
    }
}
