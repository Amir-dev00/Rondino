<?php

declare(strict_types=1);

$host = '127.0.0.1';
for ($port = 8080; $port <= 8099; $port++) {
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
    if ($socket) {
        fclose($socket);
        echo $port;
        exit(0);
    }
}
fwrite(STDERR, "هیچ پورت آزادی بین 8080 تا 8099 پیدا نشد. برنامه دیگری را ببندید.\n");
exit(1);
