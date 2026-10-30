<?php
/** A real uncached homepage request through PHP-FPM, without an Nginx dependency. */
declare(strict_types=1);
function fcgi_record(int $type, string $data): string {
    return pack('CCnnCC', 1, $type, 1, strlen($data), 0, 0) . $data;
}
function fcgi_length(int $length): string {
    return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
}
function read_exact($socket, int $length): string {
    $data = '';
    while (strlen($data) < $length && ! feof($socket)) {
        $chunk = fread($socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Incomplete FastCGI response');
        }
        $data .= $chunk;
    }
    if (strlen($data) !== $length) {
        throw new RuntimeException('Truncated FastCGI response');
    }
    return $data;
}
try {
    $socket = fsockopen('127.0.0.1', 9000, $errno, $error, 2);
    if (! $socket) {
        throw new RuntimeException('PHP-FPM unavailable');
    }
    stream_set_timeout($socket, 10);
    $site = (string) (getenv('WORDPRESS_HOME') ?: 'https://www.trovesia.com');
    $params = [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => '/var/www/html/index.php',
        'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/?trovesia-health=' . time(),
        'QUERY_STRING' => 'trovesia-health=' . time(), 'SERVER_NAME' => (string) parse_url($site, PHP_URL_HOST),
        'HTTP_HOST' => (string) parse_url($site, PHP_URL_HOST), 'SERVER_PORT' => '443',
        'HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1',
    ];
    $encoded = '';
    foreach ($params as $key => $value) {
        $encoded .= fcgi_length(strlen($key)) . fcgi_length(strlen($value)) . $key . $value;
    }
    $request = fcgi_record(1, pack('nCxxxxx', 1, 0)) . fcgi_record(4, $encoded) . fcgi_record(4, '') . fcgi_record(5, '');
    $offset = 0;
    while ($offset < strlen($request)) {
        $written = fwrite($socket, substr($request, $offset));
        if (! $written) {
            throw new RuntimeException('FastCGI write failed');
        }
        $offset += $written;
    }
    $stdout = '';
    do {
        $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', read_exact($socket, 8));
        $body = $header['length'] ? read_exact($socket, $header['length']) : '';
        if ($header['padding']) {
            read_exact($socket, $header['padding']);
        }
        if ($header['type'] === 6) {
            $stdout .= $body;
        }
    } while ($header['type'] !== 3);
    fclose($socket);
    if (preg_match('/^Status:\s*(?:[345]\d\d)/mi', $stdout) || ! str_contains(strtolower($stdout), '</html>') || ! str_contains($stdout, 'Trovesia')) {
        throw new RuntimeException('Dynamic homepage did not return expected HTML');
    }
    echo "Dynamic PHP homepage OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
