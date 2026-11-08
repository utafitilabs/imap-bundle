<?php

declare(strict_types=1);

/*
 * This file is part of the UtafitiLabs IMAP Bundle.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Usage: php tls-server.php <implicit|starttls|inject|no-handshake|close> <cert+key.pem> <port-file>

[, $mode, $certificate, $portFile] = $argv;

$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'verify_peer' => false]]);
$plain = in_array($mode, ['starttls', 'inject', 'no-handshake'], true);
$server = stream_socket_server(($plain ? 'tcp' : 'ssl').'://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
if (false === $server) {
    fwrite(\STDERR, $errstr);
    exit(1);
}
file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), \PHP_URL_PORT));

$connection = @stream_socket_accept($server, 10);
if (false === $connection) {
    exit(0);
}
if ('close' === $mode) {
    fclose($connection);
    exit(0);
}
if ($plain) {
    fwrite($connection, "* OK [CAPABILITY IMAP4rev1 STARTTLS] plain ready\r\n");
    fgets($connection);
    fwrite($connection, "A0001 OK Begin TLS negotiation now.\r\n".('inject' === $mode ? "* 1 EXISTS\r\n" : ''));
    if ('no-handshake' === $mode) {
        fclose($connection);
        exit(0);
    }
    if (true !== @stream_socket_enable_crypto($connection, true, \STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        exit(0);
    }
}
fwrite($connection, "* OK [CAPABILITY IMAP4rev1] test ready\r\n");
$line = fgets($connection);
fwrite($connection, 'A0002 OK '.trim((string) $line)." {5}\r\nhello\r\n");
while (!feof($connection) && false !== fread($connection, 1024)) {
}
