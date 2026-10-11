<?php

namespace App\OpenApi;

use App\Http\Middleware\RequireTwoFactor;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Makes the document say what the API really does where Scramble cannot infer it: every non-2xx
 * response is the RFC 9457 `Problem` (see App\Http\Problem), the Fortify-backed login and logout
 * routes get their real responses, and the server URL is relative so APP_URL cannot leak in.
 */
class ApiDocumentTransformer implements DocumentTransformer
{
    private const DESCRIPTIONS = [
        401 => 'Not signed in',
        403 => 'Not allowed',
        404 => 'Not found',
        409 => 'Conflict with the current state',
        419 => 'CSRF token missing or wrong',
        422 => 'Validation failed',
        423 => 'Step-up required (`step_up_required`): confirm the password or code with POST /auth/confirm-password, then retry',
        429 => 'Too many requests',
        503 => 'Service unavailable',
    ];

    private const BUSY = 'Conflict: `busy` (someone else is changing this address; retry)';

    private const DOMAIN_LIMIT = 'Validation failed (`validation_failed`), or the domain\'s mailbox or total storage limit is reached (`domain_limit`, with the message in `errors.local` for the mailbox count or `errors.quota_bytes` for storage)';

    private const NOT_ENABLED = 'Two-factor authentication is not turned on (`two_factor_not_enabled`)';

    private const ALREADY_CONFIRMED = 'Two-factor authentication is already confirmed (`two_factor_already_confirmed`)';

    private const INVITE_CONFLICT = 'Conflict: `already_has_access` (the email already has a grant on the domain or a pending invitation to the same target; for a server-admin actor also when it belongs to a server admin) or, for a server-admin actor only, `user_deactivated` (it belongs to a deactivated user)';

    private const RESEND_CONFLICT = 'Conflict: `invitation_closed` (the invitation was already accepted or revoked, or its domain is gone), or what creating it would answer now: `already_has_access` (the email gained a grant on the domain, or another pending invitation to the same target; for a server-admin actor also when it belongs to a server admin) or, for a server-admin actor only, `user_deactivated`';

    private const INVITE_LIMIT = 'Too many requests (`too_many_requests`): 30 invitations created or resent an hour per user';

    private const INVITATION_CLOSED = 'Conflict: `invitation_closed` (the invitation was already accepted or revoked, or its domain is gone)';

    private const USER_GUARDS = 'Conflict: `cannot_change_self` (not on yourself) or `last_server_admin` (it would leave no active server admin)';

    private const TWO_FACTOR_REQUIRED = 'Not allowed (`forbidden`), or two-factor authentication must be set up first (`two_factor_required`)';

    /**
     * Problems Scramble cannot see because they are thrown below the controller (Conflict from the
     * repositories, Grants, ConsoleUsers and SourceLock, DomainLimitExceeded from DomainLimits), by
     * route middleware (EnsureSameOrigin, throttle) or by RequireStepUp::ensure() in a controller, keyed by
     * "method path" and listing the `code` values each one can carry.
     */
    private const EXTRA_PROBLEMS = [
        'post /domains' => [409 => 'Conflict: `domain_exists`'],
        'post /blocked-addresses' => [409 => 'Conflict: `already_blocked`'],
        'patch /domains/{domain}/access/{user}' => [409 => 'Conflict: `already_has_access` (the user is a server admin, who has every domain)'],
        'post /domains/{domain}/access/invitations' => [409 => self::INVITE_CONFLICT, 429 => self::INVITE_LIMIT],
        'post /domains/{domain}/access/invitations/{invitation}/resend' => [409 => self::RESEND_CONFLICT, 429 => self::INVITE_LIMIT],
        'delete /domains/{domain}/access/invitations/{invitation}' => [409 => self::INVITATION_CLOSED],
        'post /users/invitations' => [409 => self::INVITE_CONFLICT, 429 => self::INVITE_LIMIT],
        'post /users/invitations/{invitation}/resend' => [409 => self::RESEND_CONFLICT, 429 => self::INVITE_LIMIT],
        'delete /users/invitations/{invitation}' => [409 => self::INVITATION_CLOSED],
        'patch /users/{user}' => [
            409 => self::USER_GUARDS,
            423 => 'Step-up required (`step_up_required`) to change an `email`, `is_server_admin` or `status` (a `name` alone needs none): confirm the password or code with POST /auth/confirm-password, then retry. Checked after validation, so an invalid body answers 422 first',
        ],
        'delete /users/{user}' => [409 => self::USER_GUARDS],
        'post /users/{user}/grants' => [409 => 'Conflict: `already_has_access` (the user is a server admin, who has every domain)'],
        'post /users/{user}/two-factor/reset' => [409 => 'Conflict: `cannot_change_self` (not on yourself) or `two_factor_not_enabled` (the user has no confirmed two-factor authentication)'],
        'post /domains/{domain}/mailboxes' => [409 => 'Conflict: `busy` or `archiving` (the address is still being archived)', 422 => self::DOMAIN_LIMIT],
        'patch /domains/{domain}/mailboxes/{mailbox}' => [409 => self::BUSY, 422 => self::DOMAIN_LIMIT],
        'delete /domains/{domain}/mailboxes/{mailbox}' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/password' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/welcome' => [429 => 'Too many requests (`too_many_requests`): 10 welcome emails an hour per user'],
        'post /domains/{domain}/mailboxes/{mailbox}/disable' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/enable' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/bulk' => [409 => self::BUSY, 422 => self::DOMAIN_LIMIT],
        'put /domains/{domain}/forwarding/{local}' => [409 => 'Conflict: `busy` or `archiving` (the address is still being archived)'],
        'delete /domains/{domain}/forwarding/{local}' => [409 => self::BUSY],
        'post /auth/login' => [403 => 'Cross-origin request (`cross_origin`)'],
        'post /auth/logout' => [401 => 'Not signed in', 403 => 'Cross-origin request (`cross_origin`)'],
        'post /auth/setup' => [403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`)'],
        'post /auth/confirm-password' => [401 => 'Not signed in', 403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`)'],
        'post /auth/two-factor-challenge' => [403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`)'],
        'post /auth/forgot-password' => [403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`): 5 an hour per email, 20 an hour per IP address'],
        'post /auth/reset-password' => [403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`)'],
        'post /auth/invitations/lookup' => [404 => 'Missing, malformed, unknown or replaced token (`invitation_not_found`)', 403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`): 30 a minute per IP address'],
        'post /auth/invitations/accept' => [
            403 => 'Cross-origin request (`cross_origin`)',
            404 => 'Missing, malformed, unknown or replaced token (`invitation_not_found`)',
            409 => 'Conflict: `sign_in_required` (the email has an account: sign in as it first) or `wrong_account` (signed in as someone else)',
            410 => 'The invitation is no longer valid (`invitation_invalid`, with `state`: `expired`, `used` or `revoked`)',
            422 => 'Validation failed (a new person\'s name or password)',
            429 => 'Too many requests (`too_many_requests`): 10 a minute per IP address',
        ],
        'post /auth/logout-all' => [401 => 'Not signed in', 403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`): 5 an hour per user'],
        'put /me/password' => [401 => 'Not signed in', 403 => 'Cross-origin request (`cross_origin`)', 429 => 'Too many requests (`too_many_requests`): shared with POST /auth/confirm-password'],
        'post /me/two-factor' => [409 => self::ALREADY_CONFIRMED],
        'get /me/two-factor/setup' => [404 => self::NOT_ENABLED, 409 => self::ALREADY_CONFIRMED],
        'post /me/two-factor/confirm' => [404 => self::NOT_ENABLED, 409 => self::ALREADY_CONFIRMED],
        'get /me/recovery-codes' => [404 => self::NOT_ENABLED],
        'post /me/recovery-codes' => [404 => self::NOT_ENABLED],
    ];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->servers = [new Server('/api/v1')];

        $problemSchema = $this->schema($this->problemType());
        $problemSchema->setTitle('Problem');
        $problem = $document->components->addSchema('Problem', $problemSchema);

        $stepUp = $this->operationsWith(fn (array $middleware) => in_array('step-up', $middleware, true));
        $twoFactorGated = $this->operationsWith(fn (array $middleware, string $uri) => in_array(RequireTwoFactor::class, $middleware, true)
            && ! Str::is(RequireTwoFactor::ALLOWED, $uri));

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $route = '/'.ltrim($path->path, '/');
                $this->fixOperation($operation, $route);
                $key = strtolower($operation->method).' '.$route;
                $extra = self::EXTRA_PROBLEMS[$key] ?? [];
                if (isset($stepUp[$key])) {
                    $extra += [423 => self::DESCRIPTIONS[423]];
                }
                if (isset($twoFactorGated[$key])) {
                    $extra += [403 => self::TWO_FACTOR_REQUIRED];
                }
                if (in_array(strtolower($operation->method), ['post', 'put', 'patch', 'delete'], true)) {
                    // Every state-changing route sits behind the session's CSRF check (`csrf_mismatch`).
                    $extra += [419 => 'CSRF token missing or wrong (`csrf_mismatch`)'];
                }

                $responses = [];
                $codes = [];
                foreach ([...$operation->responses ?? [], ...array_map(fn (int $code) => new Response($code), array_keys($extra))] as $response) {
                    $code = $this->code($response);
                    if (isset($codes[$code])) {
                        continue;
                    }
                    $codes[$code] = true;
                    if ($code >= 400) {
                        $error = new Response($code);
                        $error->setDescription($extra[$code] ?? self::DESCRIPTIONS[$code] ?? 'Error');
                        $error->setContent('application/problem+json', $this->schema($problem));
                        $responses[] = $error;
                    } else {
                        $responses[] = $response;
                    }
                }
                usort($responses, fn (Response|Reference $a, Response|Reference $b): int => $this->code($a) <=> $this->code($b));
                $operation->responses = $responses;
            }
        }

        // The framework's generic error shapes are replaced above and would only mislead.
        $document->components->responses = [];
    }

    /**
     * "method /path" of every API route whose middleware passes $test, read from the router so a route
     * gains its documented 423 (`step-up`, RequireStepUp) or 403 `two_factor_required` (RequireTwoFactor)
     * the moment it gains the middleware.
     *
     * @param  callable(list<mixed>, string): bool  $test  gets the route's middleware and its uri
     * @return array<string, true>
     */
    private function operationsWith(callable $test): array
    {
        $operations = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || ! $test(array_values($route->gatherMiddleware()), $route->uri())) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $operations[strtolower($method).' /'.substr($route->uri(), strlen('api/v1/'))] = true;
            }
        }

        return $operations;
    }

    private function fixOperation(Operation $operation, string $path): void
    {
        if ($path === '/auth/logout') {
            $operation->responses = [$this->response(204, 'Signed out')];
        } elseif ($path === '/auth/login') {
            // Bad credentials are a 422 with errors.email; a locked-out client gets 429. A user with
            // two-factor gets {"two_factor": true} and finishes with POST /auth/two-factor-challenge.
            $operation->responses = [
                $this->response(200, 'Signed in, or `{"two_factor": true}` when the user must pass the two-factor challenge next', $this->signedInOrChallenge()),
                new Response(422),
                new Response(429),
            ];
        } elseif ($path === '/auth/setup') {
            // A setup link for a user with confirmed two-factor leaves the sign-in pending, as login does.
            $operation->responses = [
                $this->response(200, 'Password set and signed in, or `{"two_factor": true}` when the user must pass the two-factor challenge next', $this->signedInOrChallenge()),
                ...array_values(array_filter($operation->responses ?? [], fn (Response|Reference $response) => $this->code($response) !== 200)),
            ];
        } elseif ($path === '/auth/invitations/lookup') {
            // The token is read from the body without validation rules (a bad one is a 404), so Scramble can't see it.
            $type = new ObjectType;
            $type->addProperty('token', $this->tokenType());
            $type->setRequired(['token']);
            $body = new RequestBodyObject;
            $body->setContent('application/json', $this->schema($type));
            $body->required();
            $operation->addRequestBodyObject($body);
        } elseif ($path === '/auth/invitations/accept') {
            // Always the token; a name and password only from a new person (an existing user signed in sends just the token).
            $body = $operation->requestBodyObject?->content['application/json'] ?? null;
            if ($body instanceof Schema && $body->type instanceof ObjectType) {
                $body->type->addProperty('token', $this->tokenType());
                $body->type->setRequired(['token']);
                $body->type->setDescription('The `token`, plus `name`, `password` and `password_confirmation` for a new person (all three required then); only the `token` for an existing user signed in as the invited email.');
            }
        } elseif ($path === '/domains/{domain}/mailboxes/{mailbox}/welcome') {
            $operation->responses = [
                $this->response(202, 'Queued.'),
                ...array_values(array_filter($operation->responses ?? [], fn (Response|Reference $response) => $this->code($response) !== 202)),
            ];
        } elseif ($path === '/domains/{domain}/forwarding/{local}' && strtolower($operation->method) === 'put') {
            // The controller answers 201 with the same body when the source had no rows before.
            foreach ($operation->responses ?? [] as $response) {
                if ($response instanceof Response && (int) $response->code === 200) {
                    $created = new Response(201);
                    $created->setDescription('Created');
                    $created->content = $response->content;
                    $operation->responses = [...$operation->responses ?? [], $created];
                    break;
                }
            }
        }
    }

    private function response(int $code, string $description, ?Type $type = null): Response
    {
        $response = new Response($code);
        $response->setDescription($description);
        if ($type !== null) {
            $response->setContent('application/json', $this->schema($type));
        }

        return $response;
    }

    private function schema(Type $type): Schema
    {
        $schema = new Schema;
        $schema->type = $type;

        return $schema;
    }

    private function code(Response|Reference $response): int
    {
        $resolved = $response instanceof Reference ? $response->resolve() : $response;

        return $resolved instanceof Response ? (int) $resolved->code : 0;
    }

    /** The answer of a sign-in that may need the two-factor step: the `me` payload or `{"two_factor": true}`. */
    private function signedInOrChallenge(): AnyOf
    {
        $challenge = new ObjectType;
        $challenge->addProperty('two_factor', (new BooleanType)->enum([true]));
        $challenge->setRequired(['two_factor']);
        $answer = new AnyOf;
        $answer->setItems([$this->userType(), $challenge]);

        return $answer;
    }

    /** The `me` payload (MeController::payload()). */
    private function userType(): ObjectType
    {
        $locale = new StringType;
        $locale->enum(['en', 'fi']);
        $twoFactor = new ObjectType;
        $twoFactor->addProperty('enabled', new BooleanType);
        $twoFactor->addProperty('confirmed', new BooleanType);
        $twoFactor->addProperty('recovery_codes_left', new IntegerType);
        $twoFactor->setRequired(['enabled', 'confirmed', 'recovery_codes_left']);

        $user = new ObjectType;
        $user->addProperty('id', new IntegerType);
        $user->addProperty('name', new StringType);
        $user->addProperty('email', new StringType);
        $user->addProperty('is_server_admin', new BooleanType);
        $user->addProperty('locale', $locale);
        $user->addProperty('two_factor', $twoFactor);
        $user->addProperty('two_factor_required', new BooleanType);
        $user->addProperty('managers_can_share', new BooleanType);
        $landing = new StringType;
        $landing->nullable(true);
        $landing->setDescription('The ASCII name of the only domain of a user who is not an admin and has exactly one; otherwise null.');
        $user->addProperty('landing', $landing);
        $user->setRequired(['id', 'name', 'email', 'is_server_admin', 'locale', 'two_factor', 'two_factor_required', 'managers_can_share', 'landing']);

        return $user;
    }

    /** The invitation link's token: the fragment of {APP_URL}/invite#{token}. */
    private function tokenType(): StringType
    {
        $token = new StringType;
        $token->setDescription('The invitation token: the part of the emailed link after `#`. A missing, malformed or unknown one answers 404 `invitation_not_found`.');

        return $token;
    }

    private function problemType(): ObjectType
    {
        $type = new StringType;
        $type->enum(['about:blank']);
        $detail = new StringType;
        $detail->setDescription('Human-readable explanation; may be absent.');
        $code = new StringType;
        $code->setDescription('Stable snake_case identifier the client switches on.');
        $messages = new ArrayType;
        $messages->setItems(new StringType);
        $errors = new ObjectType;
        $errors->setDescription('Per-field messages on 422.');
        $errors->additionalProperties($messages);

        $problem = new ObjectType;
        $problem->addProperty('type', $type);
        $problem->addProperty('title', new StringType);
        $problem->addProperty('status', new IntegerType);
        $problem->addProperty('detail', $detail);
        $problem->addProperty('code', $code);
        $problem->addProperty('errors', $errors);
        $state = new StringType;
        $state->enum(['expired', 'used', 'revoked']);
        $state->setDescription('The invitation\'s state on 410 `invitation_invalid`.');
        $problem->addProperty('state', $state);
        $problem->setRequired(['type', 'title', 'status']);

        return $problem;
    }
}
