<?php

declare(strict_types=1);

// Доступен только внутри отдельного compose.test.yaml.
if (!is_string($_SERVER['REQUEST_URI'])) {
    throw new RuntimeException('Некорректный URI.');
}
$path = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$file = '/tmp/cbr-stub-state.json';
$handle = fopen($file, 'c+');
if (false === $handle) {
    throw new RuntimeException('Не удалось открыть состояние Stub.');
}
flock($handle, \LOCK_EX);
/**
 * @var array{mode?: string, count?: int} $state
 */
$state = json_decode(stream_get_contents($handle) ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
$state += ['mode' => 'ok', 'count' => 0];
if ('/control' === $path && 'POST' === $_SERVER['REQUEST_METHOD']) {
    $state = ['mode' => trim(file_get_contents('php://input') ?: ''), 'count' => 0];
} elseif ('/scripts/XML_daily.asp' === $path) {
    ++$state['count'];
    if ('delay' === $state['mode']) {
        sleep(3);
    }
    header('Content-Type: application/xml');
    if ('503' === $state['mode']) {
        http_response_code(503);
        echo 'Недоступен';
    } elseif ('invalid' === $state['mode']) {
        echo '<ValCurs>';
    } else {
        readfile(__DIR__.'/daily_2026-10-24.xml');
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($state, \JSON_THROW_ON_ERROR);
}
rewind($handle);
ftruncate($handle, 0);
fwrite($handle, json_encode($state, \JSON_THROW_ON_ERROR));
fflush($handle);
flock($handle, \LOCK_UN);
fclose($handle);
