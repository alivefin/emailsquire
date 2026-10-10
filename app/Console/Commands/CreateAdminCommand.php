<?php

namespace App\Console\Commands;

use App\Auth\AdminSetup;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    protected $signature = 'emailsquire:create-admin
        {email : The admin\'s email address}
        {--name= : Display name (defaults to the part before the @)}
        {--reset : Issue a new setup link for a user who already has a password}';

    protected $description = 'Create a server admin (or re-issue their link) and print a one-time setup link';

    public function handle(AdminSetup $setup): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['email', 'max:255']])->fails()) {
            $this->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if ($user?->password_set_at !== null && ! $this->option('reset')) {
            $this->error("{$email} already has a password; use --reset to issue a new setup link.");

            return self::FAILURE;
        }

        $created = $user === null;
        if ($user === null) {
            $name = $this->option('name');
            $user = new User;
            $user->forceFill([
                'name' => is_string($name) && $name !== '' ? $name : Str::before($email, '@'),
                'email' => $email,
                // Unusable until setup completes; password_set_at stays null, so sign-in refuses it anyway.
                'password' => Hash::make(Str::random(64)),
                'is_server_admin' => true,
            ])->save();
        } elseif (! $user->is_server_admin) {
            $user->forceFill(['is_server_admin' => true])->save();
        }

        $token = $setup->issue($user);

        // Task 7 routes this through App\MailServer\AuditLog.
        AuditEntry::create([
            'occurred_at' => now(),
            'actor_type' => 'cli',
            'actor_id' => get_current_user(),
            'action' => 'admin.create',
            'object_type' => 'user',
            'object_key' => $email,
            'changes' => ['created' => $created, 'reset' => (bool) $this->option('reset')],
        ]);

        $this->line(url("/setup/{$token}").'?email='.rawurlencode($email));
        $this->comment('The link works once and expires in '.(int) (config()->integer('emailsquire.admin_setup_expire_minutes') / 60).' hours.');

        return self::SUCCESS;
    }
}
