import type { Json } from '../api/types';

/** WebAuthn uses unpadded base64url in JSON and ArrayBuffers at the browser boundary. */
export function base64urlToArrayBuffer(value: string): ArrayBuffer {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
    return Uint8Array.from(binary, (char) => char.charCodeAt(0)).buffer;
}

export function arrayBufferToBase64url(value: ArrayBuffer): string {
    // Avoid spreading a potentially large attestation buffer into function arguments.
    let binary = '';
    for (const byte of new Uint8Array(value)) binary += String.fromCharCode(byte);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function isWebAuthnSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined';
}

type CredentialJson = {
    id: string;
    rawId: string;
    type: string;
    authenticatorAttachment: string | null;
    clientExtensionResults: AuthenticationExtensionsClientOutputs;
    response: Record<string, string | string[] | null>;
};

type DescriptorJson = Omit<PublicKeyCredentialDescriptor, 'id'> & { id: string };
type RequestOptionsJson = Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
    challenge: string;
    allowCredentials?: DescriptorJson[];
};

function descriptor(value: DescriptorJson): PublicKeyCredentialDescriptor {
    return { ...value, id: base64urlToArrayBuffer(value.id) };
}

function credentialJson(credential: PublicKeyCredential, response: CredentialJson['response']): CredentialJson {
    return {
        id: credential.id,
        rawId: arrayBufferToBase64url(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment ?? null,
        clientExtensionResults: credential.getClientExtensionResults(),
        response,
    };
}

function cancelled(error: unknown): boolean {
    return (error instanceof DOMException || error instanceof Error) && (error.name === 'NotAllowedError' || error.name === 'AbortError');
}

/** No reliance on the newer toJSON/parse*FromJSON APIs, so older WebAuthn browsers work too. */
export async function createCredential(options: Json<'/me/passkeys/options', 'post'>, signal?: AbortSignal): Promise<CredentialJson | null> {
    const publicKey = {
        ...options,
        challenge: base64urlToArrayBuffer(options.challenge),
        user: { ...options.user, id: base64urlToArrayBuffer(options.user.id) },
        excludeCredentials: options.excludeCredentials.map((value) => descriptor(value as DescriptorJson)),
    } as PublicKeyCredentialCreationOptions;
    try {
        const credential = await navigator.credentials.create({ publicKey, ...(signal ? { signal } : {}) }) as PublicKeyCredential | null;
        if (!credential || signal?.aborted) return null;
        const response = credential.response as AuthenticatorAttestationResponse;
        return credentialJson(credential, {
            clientDataJSON: arrayBufferToBase64url(response.clientDataJSON),
            attestationObject: arrayBufferToBase64url(response.attestationObject),
            ...(typeof response.getTransports === 'function' ? { transports: response.getTransports() } : {}),
        });
    } catch (error) {
        if (cancelled(error)) return null;
        throw error;
    }
}

/** Assertion options are an open JSON object in the committed schema. Convert its binary fields. */
export async function getAssertion(options: Json<'/auth/passkey/options', 'post'>, signal?: AbortSignal): Promise<CredentialJson | null> {
    const json = options as RequestOptionsJson;
    const { allowCredentials, ...rest } = json;
    const publicKey: PublicKeyCredentialRequestOptions = {
        ...rest,
        challenge: base64urlToArrayBuffer(json.challenge),
        ...(allowCredentials ? { allowCredentials: allowCredentials.map(descriptor) } : {}),
    };
    try {
        const credential = await navigator.credentials.get({ publicKey, ...(signal ? { signal } : {}) }) as PublicKeyCredential | null;
        if (!credential || signal?.aborted) return null;
        const response = credential.response as AuthenticatorAssertionResponse;
        return credentialJson(credential, {
            clientDataJSON: arrayBufferToBase64url(response.clientDataJSON),
            authenticatorData: arrayBufferToBase64url(response.authenticatorData),
            signature: arrayBufferToBase64url(response.signature),
            userHandle: response.userHandle === null ? null : arrayBufferToBase64url(response.userHandle),
        });
    } catch (error) {
        if (cancelled(error)) return null;
        throw error;
    }
}
