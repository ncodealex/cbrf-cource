<?php

declare(strict_types=1);

// Принимает TCP-соединение, но не отвечает: проверка read/handshake timeout.
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
sleep(10);
