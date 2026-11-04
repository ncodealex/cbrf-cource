<?php

declare(strict_types=1);

use Symfony\Component\Clock\DatePoint;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$request = Request::createFromGlobals();
if ('/health' === $request->getPathInfo()) {
    new Response('ok')->send();

    return;
}

// Только тестовая сеть Compose. Состояние общее для всех отдельных Worker.
$handle = fopen('/tmp/cbr-stub-state.json', 'c+');
if (false === $handle) {
    throw new RuntimeException('Не удалось открыть состояние Stub.');
}
try {
    if (!flock($handle, \LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать состояние Stub.');
    }
    /**
     * @var array{mode: string, count: int} $state
     */
    $state = json_decode(stream_get_contents($handle) ?: '{"mode":"ok","count":0}', true, flags: \JSON_THROW_ON_ERROR);
    $path = $request->getPathInfo();
    $response = null;
    if ('/control' === $path && $request->isMethod('POST')) {
        $mode = trim($request->getContent());
        if (!in_array($mode, ['ok', '503', 'invalid', 'delay'], true)) {
            $response = new Response('Неизвестный режим.', 400);
        } else {
            $state = ['mode' => $mode, 'count' => 0];
            $response = new JsonResponse($state);
        }
    } elseif ('/scripts/XML_daily.asp' === $path && $request->isMethod('GET')) {
        ++$state['count'];
    } elseif ('/' === $path && $request->isMethod('GET')) {
        $response = new JsonResponse($state);
    } else {
        $response = new Response('Маршрут не найден.', 404);
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, \JSON_THROW_ON_ERROR));
    fflush($handle);
} finally {
    flock($handle, \LOCK_UN);
    fclose($handle);
}

// Не держим блокировку во время HTTP-задержки: счётчик виден управляющему процессу.
if (null === $response) {
    if ('delay' === $state['mode']) {
        sleep(3);
    }
    $response = match ($state['mode']) {
        '503' => new Response('Недоступен', 503),
        'invalid' => new Response('<ValCurs>', 200, ['Content-Type' => 'application/xml']),
        default => (static function () use ($request): Response {
            $date = $request->query->getString('date_req', '26/10/2026');
            try {
                $parsed = DatePoint::createFromFormat('!d/m/Y', $date);
            } catch (DateMalformedStringException) {
                return new Response('Некорректная дата.', 400);
            }
            if ($parsed->format('d/m/Y') !== $date) {
                return new Response('Некорректная дата.', 400);
            }
            $file = __DIR__.'/recorded/'.$parsed->format('Y-m-d').'.xml';
            if (!is_file($file)) {
                return new Response('Для даты нет сохранённого ответа.', 404);
            }

            return new BinaryFileResponse($file, 200, ['Content-Type' => 'application/xml; charset=windows-1251']);
        })(),
    };
}
$response->send();
