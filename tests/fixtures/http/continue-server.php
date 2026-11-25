<?php declare(strict_types=1);
/*
 * Minimal raw-socket HTTP/1.1 server used by tests/HttpTest.php.
 *
 * `php -S` never sends interim responses, so this server answers requests carrying
 * `Expect: 100-continue` with `HTTP/1.1 100 Continue` before reading the body, then
 * replies `201 Created` with an `X-Final: yes` header and the received byte count.
 *
 * Usage: php continue-server.php <port>
 */

$port   = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "Unable to listen on port {$port}: {$errstr}\n");
    exit(1);
}

while (true) {

    $conn = stream_socket_accept($server, 3600);

    if ($conn === false) {
        continue;
    }

    // read the request head
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
        $head .= $line;
    }

    $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $matches) ? (int) $matches[1] : 0;

    if (preg_match('/^expect:\s*100-continue/mi', $head)) {
        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
    }

    // read the body
    $received = 0;
    while ($received < $length && !feof($conn)) {
        $chunk = fread($conn, min(65536, $length - $received));
        if ($chunk === false) {
            break;
        }
        $received += strlen($chunk);
    }

    $payload = (string) $received;

    fwrite(
        $conn,
        "HTTP/1.1 201 Created\r\n"
        . "Content-Type: text/plain\r\n"
        . "X-Final: yes\r\n"
        . 'Content-Length: ' . strlen($payload) . "\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . $payload,
    );

    fclose($conn);

}
