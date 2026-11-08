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
use UtafitiLabs\ImapBundle\Exception\ImapProtocolException;
use UtafitiLabs\ImapBundle\Model\ResponseLine;

/**
 * Assembles server responses from the transport (RFC 9051 section 4.3: a
 * literal is "{n}" CRLF followed by exactly n octets), refusing a literal over
 * the cap before reading any of it.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9051#section-4.3
 */
final class ResponseReader
{
    public const int MAX_LINE_BYTES = 1048576;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxLiteralBytes,
        private readonly float $literalTimeout = 60.0,
    ) {
    }

    /**
     * The next response, or null when none started within the timeout.
     *
     * @throws ImapConnectionException
     * @throws ImapProtocolException
     */
    public function next(float $timeout): ?ResponseLine
    {
        $line = $this->transport->readLine($timeout, self::MAX_LINE_BYTES);
        if (null === $line) {
            return null;
        }

        $text = '';
        $literals = [];
        while (true) {
            $line = rtrim($line, "\r\n");
            if (1 !== preg_match('/\{(\d+)\}$/', $line, $match)) {
                $text .= $line;
                break;
            }
            $length = (int) $match[1];
            if ($length > $this->maxLiteralBytes) {
                throw new ImapProtocolException(\sprintf('The server sent a literal of %d bytes, over the cap of %d.', $length, $this->maxLiteralBytes));
            }
            $text .= $line;
            $literals[] = 0 === $length ? '' : $this->transport->read($length, $this->literalTimeout);
            $line = $this->transport->readLine($this->literalTimeout, self::MAX_LINE_BYTES)
                ?? throw new ImapConnectionException('The server stopped in the middle of a response.');
        }

        $space = strpos($text, ' ');

        return false === $space
            ? new ResponseLine($text, '', $literals)
            : new ResponseLine(substr($text, 0, $space), substr($text, $space + 1), $literals);
    }
}
