import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ASSERTION, ASSERTION_JSON, ATTESTATION_JSON, CREATION_OPTIONS, REQUEST_OPTIONS, mockWebAuthn } from '../test/webauthn';
import { arrayBufferToBase64url, base64urlToArrayBuffer, createCredential, getAssertion, isWebAuthnSupported } from './webauthn';

let credentials: ReturnType<typeof mockWebAuthn>;
beforeEach(() => { credentials = mockWebAuthn(); });
afterEach(() => vi.unstubAllGlobals());

describe('WebAuthn browser boundary', () => {
    it.each(['', 'AQID', '-_8'])('round trips unpadded base64url %j', (value) => {
        expect(arrayBufferToBase64url(base64urlToArrayBuffer(value))).toBe(value);
    });
    it('decodes padded base64url', () => {
        expect([...new Uint8Array(base64urlToArrayBuffer('-_8='))]).toEqual([251, 255]);
    });
    it('checks PublicKeyCredential support', () => {
        expect(isWebAuthnSupported()).toBe(true);
        vi.stubGlobal('PublicKeyCredential', undefined);
        expect(isWebAuthnSupported()).toBe(false);
    });
    it('converts registration options and returns attestation JSON without native toJSON', async () => {
        expect(await createCredential(CREATION_OPTIONS)).toEqual(ATTESTATION_JSON);
        expect(credentials.create).toHaveBeenCalledWith({ publicKey: {
            ...CREATION_OPTIONS, challenge: new Uint8Array([1, 2, 3]).buffer,
            user: { ...CREATION_OPTIONS.user, id: new Uint8Array([4, 5, 6]).buffer },
            excludeCredentials: [{ type: 'public-key', id: new Uint8Array([251, 255]).buffer }],
        } });
        expect(CREATION_OPTIONS.challenge).toBe('AQID');
    });
    it('converts assertion options and encodes every binary result', async () => {
        expect(await getAssertion(REQUEST_OPTIONS)).toEqual(ASSERTION_JSON);
        expect(credentials.get).toHaveBeenCalledWith({ publicKey: {
            ...REQUEST_OPTIONS, challenge: new Uint8Array([1, 2, 3]).buffer,
            allowCredentials: [{ type: 'public-key', id: new Uint8Array([251, 255]).buffer, transports: ['internal'] }],
        } });
    });
    it('allows discoverable assertions and a null userHandle', async () => {
        credentials.get.mockResolvedValue({ ...ASSERTION, response: { ...ASSERTION.response, userHandle: null } });
        expect(await getAssertion({ challenge: 'AQID', allowCredentials: [] })).toMatchObject({ response: { userHandle: null } });
    });
    it.each(['NotAllowedError', 'AbortError'])('quietly cancels %s for both ceremonies', async (name) => {
        credentials.get.mockRejectedValue(new DOMException('', name));
        credentials.create.mockRejectedValue(new DOMException('', name));
        expect(await getAssertion(REQUEST_OPTIONS)).toBeNull();
        expect(await createCredential(CREATION_OPTIONS)).toBeNull();
    });
    it('returns null when the browser has no result', async () => {
        credentials.get.mockResolvedValue(null);
        credentials.create.mockResolvedValue(null);
        expect(await getAssertion(REQUEST_OPTIONS)).toBeNull();
        expect(await createCredential(CREATION_OPTIONS)).toBeNull();
    });
    it('propagates unexpected browser errors', async () => {
        const error = new DOMException('', 'SecurityError');
        credentials.get.mockRejectedValue(error);
        credentials.create.mockRejectedValue(error);
        await expect(getAssertion(REQUEST_OPTIONS)).rejects.toBe(error);
        await expect(createCredential(CREATION_OPTIONS)).rejects.toBe(error);
    });
});
