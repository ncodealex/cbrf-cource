<?php

declare(strict_types=1);

return [
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        // Stateless API: сессии не используются.
        'session' => false,
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'trusted_proxies' => '%env(default::TRUSTED_PROXIES)%',
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-host'],
        'router' => [
            'default_uri' => '%env(DEFAULT_URI)%',
        ],
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
    'when@prod' => [
        'framework' => [
            'router' => [
                'strict_requirements' => null,
            ],
        ],
    ],
];
