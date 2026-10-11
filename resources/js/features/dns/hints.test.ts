import i18next from 'i18next';
import { describe, expect, it } from 'vitest';
import hintKeys from '../../api/dnsHintKeys.json';
import en from '../../i18n/en/dns.json';

/**
 * Every `hint_key` the backend's DNS checks give (`DnsHealth::HINT_KEYS`, exported to dnsHintKeys.json and
 * kept equal by tests/Unit/MailServer/DnsHintKeysExportTest.php) must have its own sentence in English and
 * Finnish, never the raw key.
 */
const KEYS: string[] = hintKeys;

function leaves(node: unknown, prefix = ''): string[] {
    if (typeof node !== 'object' || node === null) return [prefix];
    return Object.entries(node).flatMap(([k, v]) => leaves(v, prefix === '' ? k : `${prefix}.${k}`));
}

describe('DNS hint translations', () => {
    it('reads the backend list', () => {
        expect(KEYS.length).toBeGreaterThan(30);
        expect(KEYS).toContain('spf.include_only');
        expect(new Set(KEYS).size).toBe(KEYS.length);
    });

    it.each(['en', 'fi'])('words every hint key in %s', (lng) => {
        const missing = KEYS.filter((key) => !i18next.exists(`dns:hint.${key}`, { lng, fallbackLng: false }));
        expect(missing).toEqual([]);
    });

    it('has no hint the backend never gives', () => {
        expect(leaves(en.hint).sort()).toEqual([...KEYS].sort());
    });
});
