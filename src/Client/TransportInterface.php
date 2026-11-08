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
 * The byte stream under the IMAP client. The production transport is a TLS
 * socket; tests replay recorded server conversations through the same seam.
 */
interface TransportInterface
{
    /**
     * Opens the connection, in TLS from the first byte when $implicitTls is true.
     *
     * @throws ImapConnectionException
     */
    public function connect(string $host, int $port, bool $implicitTls, float $timeout): void;

    /**
     * Starts TLS on the open connection, after the server accepted STARTTLS.
     *
     * @throws ImapConnectionException
     */
    public function enableTls(float $timeout): void;

    /**
     * @throws ImapConnectionException
     */
    public function write(string $bytes, float $timeout): void;

    /**
     * The next line including its line break, or null when no complete line
     * arrived within the timeout; a partial line is kept for the next call.
     *
     * @throws ImapConnectionException when the connection is closed or the line exceeds $maxBytes
     */
    public function readLine(float $timeout, int $maxBytes): ?string;

    /**
     * Exactly $length bytes.
     *
     * @throws ImapConnectionException when they do not arrive within the timeout
     */
    public function read(int $length, float $timeout): string;

    public function close(): void;
}
