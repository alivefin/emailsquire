<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
// The two-factor secret and recovery codes are encrypted with APP_KEY and never serialised.
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    /** The column's database default, so a new model reads as active before it is saved and refreshed. */
    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /** A deactivated user cannot sign in, and a session they still hold answers 401 (EnsureActiveUser). */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the user has a second factor: confirmed TOTP, or a passkey that isn't disabled. Required
     * two-factor (RequireTwoFactor) is met by either; recovery codes exist while one does.
     */
    public function hasSecondFactor(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication() || $this->hasPasskeysEnabled();
    }

    /** Whether the user has a passkey that may sign in (a disabled one may not). */
    public function hasPasskeysEnabled(): bool
    {
        return $this->usablePasskeys()->exists();
    }

    /**
     * The user's passkeys that may sign in. (passkeys(), from PasskeyAuthenticatable, has all of them,
     * as App\Models\Passkey: AppServiceProvider gives laravel/passkeys the app's model.)
     *
     * @return HasMany<Passkey, $this>
     */
    public function usablePasskeys(): HasMany
    {
        return $this->hasMany(Passkey::class)->whereNull('disabled_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_server_admin' => 'boolean',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
