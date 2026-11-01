<?php

declare(strict_types=1);

/*
 * Symfony Profiler (только dev/test, бандлы подключены в bundles.php).
 *
 * Профиль: http://localhost:8080/_profiler (или http://app.localhost/_profiler).
 * Для JSON API тулбар не встраивается, ссылка на профиль запроса приходит
 * в заголовке ответа X-Debug-Token-Link.
 */
return [
    'when@dev' => [
        'web_profiler' => [
            'toolbar' => true,
            'intercept_redirects' => false,
        ],
        'framework' => [
            'profiler' => [
                'only_exceptions' => false,
                'collect_serializer_data' => true,
            ],
        ],
    ],
    'when@test' => [
        'framework' => [
            // Профайлер включён, но данные собираются только по запросу теста:
            // $client->enableProfiler().
            'profiler' => [
                'collect' => false,
                'collect_serializer_data' => true,
            ],
        ],
    ],
];
