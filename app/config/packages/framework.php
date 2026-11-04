<?php

declare(strict_types=1);

return [
    'parameters' => [
        'app.default_timezone' => 'Europe/Moscow',
    ],
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'php_errors' => [
            'log' => true,
        ],
        'serializer' => [
            'enabled' => true,
        ],
        'property_info' => [
            'with_constructor_extractor' => true,
        ],
        'http_client' => [
            'default_options' => [
                'timeout' => 5,
                'max_duration' => 20,
            ],
        ],
    ],
    'when@test' => [
        'framework' => [
            'test' => true,
        ],
    ],
];
