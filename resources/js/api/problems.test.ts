import { describe, it, expect } from 'vitest';
import { ApiProblem, fieldErrors, isStepUpCancelled, problemMessage } from './problems';

const p = (status: number, extra: Partial<ConstructorParameters<typeof ApiProblem>[0]> = {}) =>
    new ApiProblem({ status, title: 'Title', ...extra });

describe('problemMessage', () => {
    const table: [string, string][] = [
        ['busy', 'Someone else is changing this address. Try again.'],
        ['archiving', 'This address is still being archived. Try again in a few minutes.'],
        ['domain_exists', 'That domain already exists.'],
        ['already_blocked', 'That address is already blocked.'],
        ['cross_origin', 'This request came from another site and was refused.'],
        ['csrf_mismatch', 'Your session changed. Reload the page and try again.'],
        ['too_many_requests', 'Too many attempts. Wait a minute and try again.'],
        ['usage_unavailable', 'Storage use is unavailable right now.'],
    ];
    it.each(table)('maps %s', (code, message) => {
        expect(problemMessage(p(409, { code, detail: 'raw' }))).toBe(message);
    });

    it('uses the first field error on a 422', () => {
        expect(problemMessage(p(422, { errors: { email: ['Bad email.', 'x'], name: ['Bad name.'] } }))).toBe('Bad email.');
    });

    it('falls back to detail, then title', () => {
        expect(problemMessage(p(500, { detail: 'Detail' }))).toBe('Detail');
        expect(problemMessage(p(500))).toBe('Title');
        expect(problemMessage(p(404, { code: 'unknown_code' }))).toBe('Title');
    });
});

describe('fieldErrors', () => {
    it('returns the first message per field', () => {
        expect(fieldErrors(p(422, { errors: { a: ['one', 'two'], b: ['three'], c: [] } }))).toEqual({ a: 'one', b: 'three' });
    });
    it('returns {} for anything else', () => {
        expect(fieldErrors(new Error('x'))).toEqual({});
        expect(fieldErrors(null)).toEqual({});
        expect(fieldErrors({ errors: { a: ['x'] } })).toEqual({});
    });
});

describe('isStepUpCancelled', () => {
    it('is true only for a problem apiFetch marked as cancelled', () => {
        expect(isStepUpCancelled(p(423, { code: 'step_up_required', cancelled: true }))).toBe(true);
        expect(isStepUpCancelled(p(423, { code: 'step_up_required' }))).toBe(false);
        expect(isStepUpCancelled(new Error('step_up_required'))).toBe(false);
    });

    it('a 423 that is not a cancel has a message to show', () => {
        expect(problemMessage(p(423, { code: 'step_up_required' }))).toBe("Confirm it's you to continue.");
    });
});
