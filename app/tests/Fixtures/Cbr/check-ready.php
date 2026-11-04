<?php

declare(strict_types=1);

// Короткий таймаут без загрузки приложения или Composer.
$context = stream_context_create(['http' => ['timeout' => 1.0]]);
exit('ok' === @file_get_contents('http://127.0.0.1:8080/health', false, $context) ? 0 : 1);
