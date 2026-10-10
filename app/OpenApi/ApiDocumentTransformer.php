<?php

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

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
        429 => 'Too many requests',
        503 => 'Service unavailable',
    ];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->servers = [new Server('/api/v1')];

        $problemSchema = $this->schema($this->problemType());
        $problemSchema->setTitle('Problem');
        $problem = $document->components->addSchema('Problem', $problemSchema);

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                $this->fixOperation($operation, '/'.ltrim($path->path, '/'));

                $responses = [];
                foreach ($operation->responses ?? [] as $response) {
                    $code = $this->code($response);
                    if ($code >= 400) {
                        $error = new Response($code);
                        $error->setDescription(self::DESCRIPTIONS[$code] ?? 'Error');
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

    private function fixOperation(Operation $operation, string $path): void
    {
        if ($path === '/auth/logout') {
            $operation->responses = [$this->response(204, 'Signed out')];
        } elseif ($path === '/auth/login') {
            // Bad credentials are a 422 with errors.email; a locked-out client gets 429.
            $operation->responses = [$this->response(200, 'Signed in', $this->userType()), new Response(422), new Response(429)];
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

    private function userType(): ObjectType
    {
        $user = new ObjectType;
        $user->addProperty('id', new IntegerType);
        $user->addProperty('name', new StringType);
        $user->addProperty('email', new StringType);
        $user->addProperty('is_server_admin', new BooleanType);
        $user->setRequired(['id', 'name', 'email', 'is_server_admin']);

        return $user;
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
        $problem->setRequired(['type', 'title', 'status']);

        return $problem;
    }
}
