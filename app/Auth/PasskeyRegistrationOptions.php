<?php

namespace App\Auth;

use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRpEntity;

/**
 * laravel/passkeys' creation options with EmailSquire's settings: user verification required, a
 * discoverable credential preferred rather than required (an authenticator that can't store one still
 * works for the two-factor step and step-up, which name the user's credentials), attestation `none`
 * (the parent's), the RP ID from APP_URL (config `passkeys.relying_party_id`) and the app's name as
 * the RP's display name. The parent excludes the user's existing credentials and offers ES256 and RS256.
 */
class PasskeyRegistrationOptions extends GenerateRegistrationOptions
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        );
    }

    protected function relyingParty(): PublicKeyCredentialRpEntity
    {
        $rp = parent::relyingParty();

        return PublicKeyCredentialRpEntity::create(name: config()->string('app.name'), id: $rp->id);
    }
}
