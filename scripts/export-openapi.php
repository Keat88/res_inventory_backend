<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$paths = [];
$tags = [];

foreach (app('router')->getRoutes()->getRoutes() as $route) {
    $uri = $route->uri();

    if (! str_starts_with($uri, 'api/')) {
        continue;
    }

    $relative = substr($uri, strlen('api/'));
    $segments = explode('/', $relative);
    $tag = $segments[1] ?? $segments[0] ?? 'General';
    $tags[$tag] = true;

    $openApiPath = preg_replace('/\{([^}]+)\}/', '{$1}', '/'.$uri);

    $parameters = [];
    preg_match_all('/\{([^}]+)\}/', $uri, $matches);
    foreach ($matches[1] as $parameter) {
        $parameters[] = [
            'name' => $parameter,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ];
    }

    $action = $route->getActionName();
    $public = str_starts_with($relative, 'api/v1/auth/login')
        || str_starts_with($relative, 'api/v1/auth/forgot-password')
        || str_starts_with($relative, 'api/v1/auth/reset-password')
        || str_starts_with($relative, 'api/v1/public/')
        || str_starts_with($relative, 'api/v1/webhooks/');

    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
        $operation = [
            'tags' => [$tag],
            'summary' => class_basename(strstr($action, '@', true) ?: $action).' '.$method,
            'operationId' => strtolower($method).'_'.str_replace(['/', '{', '}'], ['_', '', ''], $relative),
            'parameters' => $parameters,
            'responses' => [
                '200' => [
                    'description' => 'Successful response',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiResponse']]],
                ],
                '401' => ['description' => 'Unauthenticated', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                '403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
                '422' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]]],
            ],
        ];

        if (! $public) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ];
        }

        $paths[$openApiPath][strtolower($method)] = $operation;
    }
}

ksort($paths);

$spec = [
    'openapi' => '3.0.3',
    'info' => [
        'title' => 'POSMaster API',
        'version' => '1.0.0',
        'description' => 'Multi-branch restaurant POS and inventory management API. Generated from routes/api.php. Use POST /api/v1/auth/login to obtain a Sanctum bearer token, then send it as `Authorization: Bearer <token>`. All responses use the `{success, message, data, meta, errors}` envelope.',
    ],
    'servers' => [
        ['url' => rtrim(env('APP_URL', 'http://localhost:8000'), '/'), 'description' => 'Application server'],
    ],
    'tags' => array_map(fn ($tag) => ['name' => $tag], array_keys($tags)),
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'Sanctum token'],
        ],
        'schemas' => [
            'ApiResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => true],
                    'message' => ['type' => 'string', 'example' => 'OK'],
                    'data' => ['nullable' => true],
                    'meta' => ['type' => 'object', 'nullable' => true],
                    'errors' => ['type' => 'object', 'nullable' => true],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                    'data' => ['type' => 'object', 'nullable' => true],
                    'meta' => ['type' => 'object', 'nullable' => true],
                    'errors' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'example' => 'VALIDATION_ERROR'],
                            'details' => ['type' => 'object', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$target = __DIR__.'/../storage/api-docs/api-docs.json';

if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}

file_put_contents($target, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

echo 'Wrote '.$target.' with '.count($paths).' paths.'.PHP_EOL;
