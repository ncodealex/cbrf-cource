<?php

declare(strict_types=1);

// Локальный сервер для проверки зависшего AMQP handshake и SQL после успешного PG handshake.
$server = stream_socket_server('tcp://127.0.0.1:0');
if (false === $server) {
    exit(1);
}
echo stream_socket_get_name($server, false), "\n";
flush();
$client = stream_socket_accept($server, 5);
if (false === $client) {
    exit(1);
}

if ('postgres' !== ($argv[1] ?? '')) {
    sleep(10);
    exit;
}

$read = static function (int $length) use ($client): string {
    $data = '';
    while (strlen($data) < $length && !feof($client)) {
        $remaining = $length - strlen($data);
        if ($remaining <= 0) {
            break;
        }
        $chunk = fread($client, $remaining);
        if (false === $chunk || '' === $chunk) {
            exit(1);
        }
        $data .= $chunk;
    }

    return $data;
};
$length = static function (string $bytes): int {
    $unpacked = unpack('Nlength', $bytes);
    if (false === $unpacked || !isset($unpacked['length']) || !is_int($unpacked['length'])) {
        throw new RuntimeException('Не удалось прочитать длину пакета PostgreSQL');
    }

    return $unpacked['length'];
};
$send = static function (string $type, string $body) use ($client): void {
    fwrite($client, $type.pack('N', 4 + strlen($body)).$body);
};

$packet = $read($length($read(4)) - 4);
// SSLRequest / GSSENCRequest: продолжить обычный PostgreSQL protocol.
while (in_array(substr($packet, 0, 4), [pack('N', 80877103), pack('N', 80877104)], true)) {
    fwrite($client, 'N');
    $packet = $read($length($read(4)) - 4);
}
$send('R', pack('N', 0));
$send('S', "server_version\x0018.6\x00");
$send('S', "client_encoding\x00UTF8\x00");
$send('S', "standard_conforming_strings\x00on\x00");
$send('K', pack('NN', 42, 42));
$send('Z', 'I');

while (!feof($client)) {
    $type = $read(1);
    $body = $read($length($read(4)) - 4);
    if (in_array($type, ['Q', 'P'], true) && str_contains($body, 'SELECT 1')) {
        echo "query\n";
        flush();
        sleep(10);
        exit;
    }
    // Поддержка simple и extended query protocol для client encoding / PDO prepares.
    switch ($type) {
        case 'Q':
            $send('C', "SET\x00");
            $send('Z', 'I');
            break;
        case 'P':
            $send('1', '');
            break;
        case 'B':
            $send('2', '');
            break;
        case 'D':
            $send('n', '');
            break;
        case 'E':
            $send('C', "SET\x00");
            break;
        case 'S':
            $send('Z', 'I');
            break;
        case 'X':
            exit;
    }
}
