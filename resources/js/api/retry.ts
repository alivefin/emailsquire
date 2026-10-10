import { ApiProblem } from './problems';

/**
 * The default query retry policy: a 4xx will not change on retry (and a 401 has already sent the user
 * to /signin), so only server errors and network failures are retried, twice.
 */
export function retryQuery(failures: number, error: unknown): boolean {
    if (error instanceof ApiProblem && error.status < 500) return false;
    return failures < 2;
}
