/**
 * Where an invitation token waits while its holder signs in (`/signin?next=/invite` and back), and only
 * then: the one place it is kept outside the invitation page's state. Session storage is per tab and gone
 * with it. The page removes the token once accepted, once the invitation turns out closed, whenever it is
 * left other than to sign in, and as soon as a link brings a token of its own; signing out removes it too.
 */
export const INVITATION_TOKEN_KEY = 'emailsquire.invitation';

export function storedInvitationToken(): string {
    try {
        return window.sessionStorage.getItem(INVITATION_TOKEN_KEY) ?? '';
    } catch {
        return '';
    }
}

/** Keeps `token` for the sign-in round trip, or forgets any kept token when `null`. */
export function storeInvitationToken(token: string | null): void {
    try {
        if (token === null) window.sessionStorage.removeItem(INVITATION_TOKEN_KEY);
        else window.sessionStorage.setItem(INVITATION_TOKEN_KEY, token);
    } catch {
        // Storage blocked: the holder opens the link again after signing in.
    }
}
