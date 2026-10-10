/**
 * Port of App\MailServer\PasswordStrength::score (0-4). Change both together.
 * Length counts code points like mb_strlen; the character classes are ASCII only.
 */
export type Score = 0 | 1 | 2 | 3 | 4;

export function scorePassword(pw: string): Score {
    if (pw === '') {
        return 0;
    }

    const len = [...pw].length;
    let s = 0;
    if (len >= 12) s++;
    if (len >= 16) s++;
    if (/[0-9]/.test(pw) && /[a-zA-Z]/.test(pw)) s++;
    if (/[^a-zA-Z0-9]/.test(pw)) s++;
    if (len < 8) s = Math.min(s, 1);

    return Math.min(s, 4) as Score;
}
