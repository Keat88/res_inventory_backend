<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$baseUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/v1';

$folders = [];

foreach (app('router')->getRoutes()->getRoutes() as $route) {
    $uri = $route->uri();

    if (! str_starts_with($uri, 'api/v1/')) {
        continue;
    }

    $relative = substr($uri, strlen('api/v1/'));
    $segments = explode('/', $relative);
    $folder = ucfirst(str_replace(['-', '_'], ' ', $segments[0] ?? 'General'));

    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
        $pathSegments = array_map(
            fn ($segment) => preg_replace('/\{([^}]+)\}/', ':$1', $segment),
            $segments
        );

        $request = [
            'name' => strtoupper($method).' '.$relative,
            'request' => [
                'method' => $method,
                'header' => [
                    ['key' => 'Accept', 'value' => 'application/json'],
                ],
                'url' => [
                    'raw' => '{{base_url}}/'.implode('/', $pathSegments),
                    'host' => ['{{base_url}}'],
                    'path' => $pathSegments,
                ],
                'description' => 'Controller: '.$route->getActionName(),
            ],
            'response' => [],
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $request['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];

            if ($relative === 'auth/login') {
                $request['request']['body'] = [
                    'mode' => 'raw',
                    'raw' => json_encode([
                        'email' => 'admin@posmaster.test',
                        'password' => 'password',
                        'device_name' => 'postman',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'options' => ['raw' => ['language' => 'json']],
                ];
            }
        }

        if (! str_starts_with($relative, 'auth/') && ! str_starts_with($relative, 'public/') && ! str_starts_with($relative, 'webhooks/')) {
            $request['request']['auth'] = [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
            ];
        }

        $folders[$folder][] = $request;
    }
}

ksort($folders);

$collection = [
    'info' => [
        'name' => 'POSMaster API',
        'description' => 'Generated from routes/api.php. Log in with POST /auth/login, then set the {{token}} collection variable.',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'variable' => [
        ['key' => 'base_url', 'value' => $baseUrl],
        ['key' => 'token', 'value' => ''],
        ['key' => 'branch_id', 'value' => '1'],
        ['key' => 'order_id', 'value' => '1'],
        ['key' => 'payment_id', 'value' => '1'],
    ],
    'item' => array_map(
        fn ($name, $items) => ['name' => $name, 'item' => $items],
        array_keys($folders),
        array_values($folders)
    ),
];

$target = __DIR__.'/../docs/postman/POSMaster.postman_collection.json';

if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}

file_put_contents($target, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

$environment = [
    'name' => 'POSMaster Local',
    'values' => [
        ['key' => 'base_url', 'value' => $baseUrl, 'enabled' => true],
        ['key' => 'token', 'value' => '', 'enabled' => true],
        ['key' => 'branch_id', 'value' => '1', 'enabled' => true],
        ['key' => 'order_id', 'value' => '1', 'enabled' => true],
        ['key' => 'payment_id', 'value' => '1', 'enabled' => true],
    ],
    '_postman_variable_scope' => 'environment',
];

file_put_contents(
    __DIR__.'/../docs/postman/POSMaster.postman_environment.json',
    json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
);

echo 'Wrote '.$target.' with '.count($folders).' folders and '.array_sum(array_map('count', $folders)).' requests.'.PHP_EOL;
