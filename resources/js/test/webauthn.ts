import { vi } from 'vitest';

export const REQUEST_OPTIONS = {
    challenge: 'AQID', rpId: 'localhost', timeout: 60000, userVerification: 'required',
    allowCredentials: [{ type: 'public-key', id: '-_8', transports: ['internal'] }],
};
export const CREATION_OPTIONS = {
    rp: { name: 'EmailSquire', id: 'localhost' },
    user: { id: 'BAUG', name: 'anna@example.org', displayName: 'Anna' },
    challenge: 'AQID', pubKeyCredParams: [{ type: 'public-key', alg: -7 }], timeout: 60000,
    excludeCredentials: [{ type: 'public-key', id: '-_8' }],
    authenticatorSelection: { residentKey: 'preferred', userVerification: 'required' }, attestation: 'none',
};
const buffer = (...bytes: number[]) => new Uint8Array(bytes).buffer;
export const ASSERTION = {
    id: '-_8', rawId: buffer(251, 255), type: 'public-key', authenticatorAttachment: 'platform',
    response: { clientDataJSON: buffer(1), authenticatorData: buffer(2), signature: buffer(3), userHandle: buffer(4) },
    getClientExtensionResults: () => ({ credProps: { rk: true } }),
};
export const ASSERTION_JSON = {
    id: '-_8', rawId: '-_8', type: 'public-key', authenticatorAttachment: 'platform',
    response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' },
    clientExtensionResults: { credProps: { rk: true } },
};
export const ATTESTATION = {
    ...ASSERTION, response: { clientDataJSON: buffer(1), attestationObject: buffer(5), getTransports: () => ['internal'] },
};
export const ATTESTATION_JSON = {
    ...ASSERTION_JSON, response: { clientDataJSON: 'AQ', attestationObject: 'BQ', transports: ['internal'] },
};

/** Mock the browser boundary, keeping the real wrappers and the API flow under test. */
export function mockWebAuthn() {
    const get = vi.fn().mockResolvedValue(ASSERTION);
    const create = vi.fn().mockResolvedValue(ATTESTATION);
    vi.stubGlobal('PublicKeyCredential', class {});
    vi.stubGlobal('navigator', Object.create(navigator, { credentials: { value: { get, create }, configurable: true } }));
    return { get, create };
}
