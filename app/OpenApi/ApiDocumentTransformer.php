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

    private const DKIM_BUSY = 'Conflict: `dkim_busy` (another DKIM task of the domain is pending or running; try again when it has finished)';

    private const USER_GUARDS = 'Conflict: `cannot_change_self` (not on yourself) or `last_server_admin` (it would leave no active server admin)';

    private const PORTAL_401 = 'Not signed in to the portal (`unauthenticated`; a console session too, and a session whose mailbox was deleted or disabled since sign-in, even during this request), or the 2-hour session limit passed (`session_expired`)';

    private const PORTAL_WRITES = 'Too many requests (`too_many_requests`): 30 portal changes a minute per mailbox, forwarding and aliases together';

    private const PORTAL_FORWARDING_OFF = 'Cross-origin request (`cross_origin`), or the domain\'s `forwarding` switch is off (`self_service_disabled`; checked before the body)';

    private const PORTAL_ALIASES_OFF = 'Cross-origin request (`cross_origin`), or the domain\'s `aliases` switch is off (`self_service_disabled`; checked before the body)';

    private const TWO_FACTOR_REQUIRED = 'Not allowed (`forbidden`), or two-factor authentication must be set up first (`two_factor_required`)';

    /**
     * Problems Scramble cannot see because they are thrown below the controller (Conflict from the
     * repositories, Grants, ConsoleUsers and SourceLock, DomainLimitExceeded from DomainLimits), by
     * route middleware (EnsureSameOrigin, throttle) or by RequireStepUp::ensure() in a controller, keyed by
     * "method path" and listing the `code` values each one can carry.
     */
    private const EXTRA_PROBLEMS = [
        'post /domains' => [409 => 'Conflict: `domain_exists`'],
        'post /domains/{domain}/dkim/generate' => [409 => self::DKIM_BUSY],
        'post /domains/{domain}/dkim/inspect' => [409 => self::DKIM_BUSY],
        'post /domains/{domain}/dkim/{selector}/activate' => [
            404 => 'The domain is not found, or has no pending or active key with this selector (`not_found`)',
            409 => 'Conflict: `dkim_busy` (another DKIM task of the domain is pending or running), `dkim_active` (the key is already active) or `dkim_not_published` (the latest DNS check does not show the key\'s record; check DNS again, or send `force: true`)',
        ],
        'post /domains/{domain}/dkim/{selector}/retire' => [
            404 => 'The domain is not found, or has no pending or active key with this selector (`not_found`)',
            409 => 'Conflict: `dkim_busy` (another DKIM task of the domain is pending or running), `dkim_active` (the active key is never retired) or `dkim_recently_activated` (a key was activated less than 15 minutes ago; `retry_after` and the Retry-After header give the seconds left)',
        ],
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
        'patch /domains/{domain}/mailboxes/{mailbox}' => [409 => self::BUSY, 422 => self::DOMAIN_LIMIT.', or `receiving` / `sendonly` is asked for while the server lacks the optional column (`schema_extension_missing`, `errors.receiving` or `errors.sendonly`; nothing of the request is applied)'],
        'delete /domains/{domain}/mailboxes/{mailbox}' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/password' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/welcome' => [429 => 'Too many requests (`too_many_requests`): 10 welcome emails an hour per user'],
        'post /domains/{domain}/mailboxes/{mailbox}/disable' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/{mailbox}/enable' => [409 => self::BUSY],
        'post /domains/{domain}/mailboxes/bulk' => [409 => self::BUSY, 422 => self::DOMAIN_LIMIT],
        'put /domains/{domain}/forwarding/{local}' => [409 => 'Conflict: `busy` or `archiving` (the address is still being archived)'],
        'delete /domains/{domain}/forwarding/{local}' => [409 => self::BUSY],
        'put /domains/{domain}/forwarding/@' => [409 => 'Conflict: `catch_all_unsupported` (Postfix\'s virtual_alias_maps has no email2email map, or the app can\'t read the Postfix configuration to tell; `detail` says which) or `busy`'],
        'delete /domains/{domain}/forwarding/@' => [404 => 'The domain is not found, or has no catch-all (`not_found`)', 409 => self::BUSY],
        'post /auth/login' => [
            403 => 'Cross-origin request (`cross_origin`)',
            422 => 'Validation failed (`validation_failed`): the same answer, `errors.email` "Email or password is wrong.", for every refused sign-in, whatever exists',
            429 => 'Too many requests (`too_many_requests`): 5 sign-in attempts a minute per email and IP address; and, for an address on a domain this server hosts that the console account did not sign in, 5 mailbox checks a minute per address and IP address and 30 a minute per IP address across all addresses',
        ],
        'get /portal/me' => [401 => 'Not signed in to the portal (`unauthenticated`; a console session too, and a session whose mailbox was deleted or disabled since sign-in), or the 2-hour session limit passed (`session_expired`)'],
        'put /portal/password' => [
            401 => 'Not signed in to the portal (`unauthenticated`; a console session too, and a session whose mailbox was deleted or disabled since sign-in, even during this request), or the 2-hour session limit passed (`session_expired`)',
            403 => 'Cross-origin request (`cross_origin`)',
            422 => 'Validation failed (`validation_failed`): `errors.current_password` when the mail server refuses the current password, `errors.password` when the new one breaks the mailbox rules',
            429 => 'Too many requests (`too_many_requests`): 6 a minute per mailbox, wrong current passwords included',
            503 => 'The mail server could not check the current password (`password_check_unavailable`); nothing changed',
        ],
        'get /portal/forwarding' => [401 => self::PORTAL_401],
        'put /portal/forwarding' => [
            401 => self::PORTAL_401,
            403 => self::PORTAL_FORWARDING_OFF,
            409 => self::BUSY,
            422 => 'Validation failed (`validation_failed`, with `errors.targets.N` for an incomplete address, the mailbox itself, one of its own aliases, or, with `keep_copy` off, any address whose mail comes back to it: an alias that also delivers here, a catch-all, a chain; it would loop), more than 10 distinct targets or more than 50 entries (`too_many_targets`, `errors.targets`), a target on no domain of this server while external forwarding is off (`external_forwarding_disabled`, `errors.targets.N`), or the mailbox\'s own address can\'t forward (`address_unavailable`, `errors.targets`)',
            429 => self::PORTAL_WRITES,
        ],
        'get /portal/aliases' => [401 => self::PORTAL_401],
        'post /portal/aliases' => [
            401 => self::PORTAL_401,
            403 => self::PORTAL_ALIASES_OFF,
            409 => self::BUSY,
            422 => 'Validation failed (`validation_failed`, `errors.local`); the address is not available (`address_unavailable`, `errors.local`: one answer whatever the reason: a mailbox, this one included, an existing forwarding, an address this mailbox forwards to, blocked, still being archived, a reserved name such as postmaster, or the domain has a catch-all that does not go only to this mailbox); or there are 20 aliases already (`too_many_aliases`)',
            429 => self::PORTAL_WRITES,
        ],
        'delete /portal/aliases/{local}' => [
            401 => self::PORTAL_401,
            403 => self::PORTAL_ALIASES_OFF,
            404 => 'Not one of this mailbox\'s aliases (`not_found`)',
            409 => self::BUSY,
            429 => self::PORTAL_WRITES,
        ],
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

        // Fortify's LoginRequest is bound to App\Http\Requests\LoginRequest, which Scramble can't see.
        foreach ($document->components->schemas as $name => $schema) {
            if (Str::afterLast($name, '\\') === 'LoginRequest' && $schema->type instanceof ObjectType) {
                $flag = new BooleanType;
                $flag->setDescription('`true` signs in to the mailbox only: the console account is not tried, so an address whose console and mail passwords are the same reaches its portal. Absent or `false`, the console account is tried first.');
                $schema->type->addProperty('mailbox', $flag);
            }
        }
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
            // Bad credentials are a 422 with errors.email; a locked-out client gets 429. A console user
            // with two-factor gets {"two_factor": true} and finishes with POST /auth/two-factor-challenge.
            $operation->responses = [
                $this->response(200, 'Signed in to the console (`kind: "console"` and the `me` payload) or, for a mailbox owner, to the portal (`kind: "mailbox"`); or `{"two_factor": true}` when a console user must pass the two-factor challenge next', $this->signInAnswer()),
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
        } elseif ($path === '/portal/aliases' && strtolower($operation->method) === 'post') {
            // Always 201 with the new alias; Scramble reads the json() call as a 200.
            foreach ($operation->responses ?? [] as $response) {
                if ($response instanceof Response && (int) $response->code === 200) {
                    $response->code = 201;
                    $response->setDescription('Created');
                }
            }
        } elseif (in_array($path, ['/domains/{domain}/forwarding/{local}', '/domains/{domain}/forwarding/@'], true) && strtolower($operation->method) === 'put') {
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
        $answer = new AnyOf;
        $answer->setItems([$this->userType(), $this->challengeType()]);

        return $answer;
    }

    /**
     * POST /auth/login's answer: a console sign-in (`kind: "console"` and the `me` payload), a portal
     * sign-in (`{"kind": "mailbox"}`), or the pending two-factor step (`{"two_factor": true}`).
     */
    private function signInAnswer(): AnyOf
    {
        $console = $this->userType();
        $console->addProperty('kind', $this->kindType('console'));
        $console->setRequired([...$console->required, 'kind']);
        $mailbox = new ObjectType;
        $mailbox->addProperty('kind', $this->kindType('mailbox'));
        $mailbox->setRequired(['kind']);
        $answer = new AnyOf;
        $answer->setItems([$console, $mailbox, $this->challengeType()]);

        return $answer;
    }

    private function kindType(string $kind): StringType
    {
        $type = new StringType;
        $type->enum([$kind]);

        return $type;
    }

    private function challengeType(): ObjectType
    {
        $challenge = new ObjectType;
        $challenge->addProperty('two_factor', (new BooleanType)->enum([true]));
        $challenge->setRequired(['two_factor']);

        return $challenge;
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

        $extension = new ObjectType;
        $extension->addProperty('receiving', (new BooleanType)->setDescription('`true` when the guide\'s `virtual_users` has the optional `enabled` column (stop receiving mail).'));
        $extension->addProperty('sendonly', (new BooleanType)->setDescription('`true` when `virtual_users` has the optional `sendonly` column (send-only mailboxes).'));
        $extension->setRequired(['receiving', 'sendonly']);
        $capabilities = new ObjectType;
        $capabilities->addProperty('schema_extension', $extension);
        $capabilities->addProperty('catch_all', (new BooleanType)->setDescription('`true` when Postfix\'s virtual_alias_maps include the email2email map, so a catch-all is safe.'));
        $capabilities->setRequired(['schema_extension', 'catch_all']);
        $capabilities->setDescription('What this server supports; the console shows only the controls that would work. Console users only: the portal\'s `/portal/me` has none.');
        $user->addProperty('capabilities', $capabilities);
        $user->setRequired(['id', 'name', 'email', 'is_server_admin', 'locale', 'two_factor', 'two_factor_required', 'managers_can_share', 'landing', 'capabilities']);

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
