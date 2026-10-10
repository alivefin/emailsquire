<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'api.json',

    'info' => [
        'version' => config('app.version', '0.1.0'),

        'description' => 'EmailSquire admin API. Errors are RFC 9457 application/problem+json.',
    ],

    'ui' => [
        'title' => 'EmailSquire API',
    ],

    'dev_tools' => [
        'enabled' => false,
    ],

    'renderer' => 'elements',

    // Overridden with a relative server in App\OpenApi\ApiDocumentTransformer (APP_URL must not leak in).
    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    // The docs UI and its JSON route exist only in local and testing; production serves neither.
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],
];
