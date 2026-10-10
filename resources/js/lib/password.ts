import { scorePassword } from './passwordStrength';

/** Same alphabet and length as App\MailServer\PasswordGenerator. */
export const PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!';
export const PASSWORD_LENGTH = 16;

// Largest multiple of the alphabet size that fits in a byte; bytes at or above it are redrawn.
const LIMIT = 256 - (256 % PASSWORD_ALPHABET.length);

function draw(): string {
    let out = '';
    while (out.length < PASSWORD_LENGTH) {
        const bytes = crypto.getRandomValues(new Uint8Array(PASSWORD_LENGTH * 2));
        for (const b of bytes) {
            if (b < LIMIT && out.length < PASSWORD_LENGTH) {
                out += PASSWORD_ALPHABET.charAt(b % PASSWORD_ALPHABET.length);
            }
        }
    }
    return out;
}

/** Draws with rejection sampling and redraws until the result scores at least 3. */
export function generatePassword(): string {
    let pw: string;
    do {
        pw = draw();
    } while (scorePassword(pw) < 3);
    return pw;
}
