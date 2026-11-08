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

namespace UtafitiLabs\ImapBundle\Client;

use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;

/**
 * A TCP socket that is always TLS before any credential is sent: implicit TLS
 * from the first byte (imaps), or STARTTLS on request (imap). The certificate
 * and the host name are always verified, with SNI; there is no option to turn
 * either off. A private certificate is trusted by naming its CA file.
 *
 * The socket is non-blocking and read through stream_select() into an own
 * buffer, so a timeout never loses part of a line.
 *
 * @see https://www.php.net/manual/en/context.ssl.php
 * @see https://www.php.net/manual/en/function.stream-socket-client.php
 */
final class StreamSocketTransport implements TransportInterface
{
    private const int CRYPTO_METHOD = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var resource|null */
    private $stream;

    private string $buffer = '';

    public function __construct(private readonly ?string $caFile = null)
    {
    }

    public function connect(string $host, int $port, bool $implicitTls, float $timeout): void
    {
        $this->close();
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'allow_self_signed' => false,
            'disable_compression' => true,
            'crypto_method' => self::CRYPTO_METHOD,
        ];
        if (null !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        $warnings = [];
        set_error_handler(static function (int $type, string $message) use (&$warnings): bool {
            $warnings[] = preg_replace('/^stream_socket_client\(\): /', '', $message);

            return true;
        });
        try {
            $stream = stream_socket_client(
                ($implicitTls ? 'ssl' : 'tcp').'://'.$host.':'.$port,
                $errorCode,
                $errorMessage,
                $timeout,
                \STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => $options]),
            );
        } finally {
            restore_error_handler();
        }
        if (false === $stream) {
            throw new ImapConnectionException(\sprintf('Could not connect to %s:%d: %s', $host, $port, implode(' ', $warnings ?: [$errorMessage])));
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    public function enableTls(float $timeout): void
    {
        $stream = $this->stream();
        if ('' !== $this->buffer) {
            throw new ImapConnectionException('The server sent data before the TLS handshake; refusing to continue.');
        }

        stream_set_blocking($stream, true);
        stream_set_timeout($stream, (int) ceil($timeout));
        $enabled = @stream_socket_enable_crypto($stream, true, self::CRYPTO_METHOD);
        stream_set_blocking($stream, false);
        if (true !== $enabled) {
            $this->close();
            throw new ImapConnectionException('The TLS handshake after STARTTLS failed.');
        }
    }

    public function write(string $bytes, float $timeout): void
    {
        $stream = $this->stream();
        $deadline = microtime(true) + $timeout;
        while ('' !== $bytes) {
            $read = null;
            $write = [$stream];
            $except = null;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || 1 !== @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1.0) * 1e6))) {
                throw new ImapConnectionException('Writing to the server timed out.');
            }
            $written = @fwrite($stream, $bytes);
            if (false === $written) {
                throw new ImapConnectionException('The connection was lost while writing.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    public function readLine(float $timeout, int $maxBytes): ?string
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $end = strpos($this->buffer, "\n");
            if (false !== $end && $end < $maxBytes) {
                $line = substr($this->buffer, 0, $end + 1);
                $this->buffer = substr($this->buffer, $end + 1);

                return $line;
            }
            if (false !== $end || \strlen($this->buffer) > $maxBytes) {
                throw new ImapConnectionException(\sprintf('The server sent a line longer than %d bytes.', $maxBytes));
            }
            if (!$this->fill($deadline)) {
                return null;
            }
        }
    }

    public function read(int $length, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;
        while (\strlen($this->buffer) < $length) {
            if (!$this->fill($deadline)) {
                throw new ImapConnectionException('Reading from the server timed out.');
            }
        }
        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->buffer = '';
    }

    /**
     * Waits for bytes until the deadline and moves all of them to the buffer;
     * false on time-out.
     */
    private function fill(float $deadline): bool
    {
        $stream = $this->stream();
        $remaining = max(0.0, $deadline - microtime(true));
        $read = [$stream];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1.0) * 1e6));
        if (false === $ready) {
            throw new ImapConnectionException('Waiting for the server failed.');
        }
        if (0 === $ready) {
            return false;
        }

        $received = '';
        while (true) {
            $chunk = @fread($stream, 65536);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $received .= $chunk;
        }
        if ('' === $received && feof($stream)) {
            throw new ImapConnectionException('The server closed the connection.');
        }
        $this->buffer .= $received;

        return true;
    }

    /**
     * @return resource
     */
    private function stream()
    {
        return $this->stream ?? throw new ImapConnectionException('The connection is not open.');
    }
}
