/** Digits only, at most six: a pasted "123 456" or "123-456" becomes "123456". */
export function sanitizeCode(raw: string): string {
    return raw.replace(/\D/g, '').slice(0, 6);
}
