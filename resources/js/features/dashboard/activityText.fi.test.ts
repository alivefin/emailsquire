import { describe, expect, it } from 'vitest';
import { setLocale } from '../../i18n';
import { activityText } from './activityText';

describe('activityText in Finnish', () => {
    it('words mailbox.create in Finnish and keeps the object as data', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'mailbox.create', object_key: 'anna@example.org' })).toBe('loi postilaatikon anna@example.org');
    });

    it('shows an unknown action as it is', async () => {
        await setLocale('fi');
        expect(activityText({ action: 'x.y', object_key: 'z' })).toBe('x.y z');
    });
});
