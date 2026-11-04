<?php

declare(strict_types=1);

/*
 * Адаптер Cbr (src/Courses/Infrastructure/Cbr): доступ к XML API Банка России.
 *
 * cbr.client — scoped HTTP client. Один вызов шлюза — одно обращение без повторов:
 * timeout ограничивает ожидание сетевой активности, max_duration — длительность HTTP-запроса.
 * Эти ограничения не задают общий предел времени Messenger handler, включая операции БД.
 */
return [
    'parameters' => [
        // Используется, если CBR_BASE_URL не задан (например, в app/.env, созданном до появления модуля).
        'app.cbr.default_base_url' => 'https://www.cbr.ru',
    ],
    'framework' => [
        'http_client' => [
            'scoped_clients' => [
                'cbr.client' => [
                    'base_uri' => '%env(default:app.cbr.default_base_url:CBR_BASE_URL)%',
                    'headers' => [
                        'User-Agent' => 'cb-Courses/1.0 (+exchange-rate-service)',
                        'Accept' => 'application/xml, text/xml',
                    ],
                    'timeout' => 5,
                    'max_duration' => 15,
                    'max_redirects' => 3,
                ],
            ],
        ],
    ],
];
