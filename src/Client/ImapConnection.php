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

use UtafitiLabs\ImapBundle\Exception\ImapCommandFailedException;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;
use UtafitiLabs\ImapBundle\Exception\ImapException;
use UtafitiLabs\ImapBundle\Exception\ImapProtocolException;
use UtafitiLabs\ImapBundle\Model\CommandResult;
use UtafitiLabs\ImapBundle\Model\ResponseLine;

/**
 * One IMAP conversation: tags commands, reads responses up to their tagged
 * completion, keeps the server's capabilities, and notes "* n EXISTS" whenever
 * it passes by. Failures name the command verb only, never its arguments, so a
 * LOGIN line never reaches an exception or a log.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9051#section-2.2
 */
final class ImapConnection
{
    private readonly ResponseReader $reader;

    private int $sequence = 0;

    /** @var list<string> */
    private array $capabilities = [];

    private int $capabilityAnnouncements = 0;

    private bool $existsAnnounced = false;

    public function __construct(
        private readonly TransportInterface $transport,
        int $maxLiteralBytes,
        private readonly float $commandTimeout,
    ) {
        $this->reader = new ResponseReader($transport, $maxLiteralBytes, $commandTimeout);
    }

    /**
     * Connects and reads the greeting.
     *
     * @throws ImapConnectionException when the server refuses with BYE or stays silent
     */
    public function open(string $host, int $port, bool $implicitTls, float $timeout): void
    {
        $this->transport->connect($host, $port, $implicitTls, $timeout);
        $greeting = $this->reader->next($this->commandTimeout)
            ?? throw new ImapConnectionException('The server sent no greeting.');
        if ('*' !== $greeting->tag || 1 !== preg_match('/^(OK|PREAUTH)\b/i', $greeting->text)) {
            throw new ImapConnectionException(\sprintf('The server refused the connection: %s', $greeting->text));
        }
        $this->readCapabilityCode($greeting->text);
    }

    /**
     * @throws ImapException
     */
    public function startTls(): void
    {
        $this->command('STARTTLS');
        $this->transport->enableTls($this->commandTimeout);
        $this->capabilities = [];
        $this->command('CAPABILITY');
    }

    public function hasCapability(string $capability): bool
    {
        return \in_array(strtoupper($capability), $this->capabilities, true);
    }

    public function knowsCapabilities(): bool
    {
        return [] !== $this->capabilities;
    }

    /**
     * How many times the server has announced its capabilities; they change
     * once a client is authenticated, so a login compares the count.
     */
    public function capabilityAnnouncements(): int
    {
        return $this->capabilityAnnouncements;
    }

    /**
     * Sends a command and reads up to its completion. $onContinuation answers
     * each "+" from the server with the bytes to send next.
     *
     * @param (\Closure(ResponseLine): string)|null $onContinuation
     *
     * @throws ImapException
     */
    public function command(string $verb, string $arguments = '', ?\Closure $onContinuation = null): CommandResult
    {
        return $this->complete($this->send($verb, $arguments), $verb, $onContinuation);
    }

    /**
     * Sends a command without waiting for its completion, and returns its tag.
     *
     * @throws ImapConnectionException
     */
    public function send(string $verb, string $arguments = ''): string
    {
        $tag = \sprintf('A%04d', ++$this->sequence);
        $this->transport->write($tag.' '.$verb.('' === $arguments ? '' : ' '.$arguments)."\r\n", $this->commandTimeout);

        return $tag;
    }

    /**
     * Reads up to the tagged completion of the command sent as $tag.
     *
     * @param (\Closure(ResponseLine): string)|null $onContinuation
     *
     * @throws ImapException
     */
    public function complete(string $tag, string $verb, ?\Closure $onContinuation = null): CommandResult
    {
        $untagged = [];
        while (true) {
            $line = $this->next($this->commandTimeout, 'LOGOUT' === $verb)
                ?? throw new ImapConnectionException(\sprintf('%s timed out.', $verb));
            if ('*' === $line->tag) {
                $untagged[] = $line;
                continue;
            }
            if ('+' === $line->tag) {
                if (null === $onContinuation) {
                    throw new ImapProtocolException(\sprintf('The server asked for more data during %s.', $verb));
                }
                $this->transport->write($onContinuation($line), $this->commandTimeout);
                continue;
            }
            if ($tag !== $line->tag) {
                throw new ImapProtocolException(\sprintf('The server answered %s with an unknown tag.', $verb));
            }
            if (1 !== preg_match('/^OK\b/i', $line->text)) {
                throw new ImapCommandFailedException(\sprintf('%s failed: %s', $verb, $line->text));
            }
            $this->readCapabilityCode($line->text);

            return new CommandResult($line, $untagged);
        }
    }

    /**
     * The next response within the timeout, with untagged news recorded.
     *
     * @throws ImapConnectionException on BYE, unless $byeExpected
     * @throws ImapProtocolException
     */
    public function next(float $timeout, bool $byeExpected = false): ?ResponseLine
    {
        $line = $this->reader->next($timeout);
        if (null === $line || '*' !== $line->tag) {
            return $line;
        }
        if (1 === preg_match('/^\d+ EXISTS$/i', $line->text)) {
            $this->existsAnnounced = true;
        } elseif (1 === preg_match('/^CAPABILITY (.*)$/i', $line->text, $match)) {
            $this->announceCapabilities($match[1]);
        } elseif (1 === preg_match('/^BYE\b ?(.*)$/i', $line->text, $match) && !$byeExpected) {
            throw new ImapConnectionException(\sprintf('The server closed the connection: %s', $match[1]));
        } else {
            $this->readCapabilityCode($line->text);
        }

        return $line;
    }

    /**
     * @throws ImapConnectionException
     */
    public function write(string $bytes): void
    {
        $this->transport->write($bytes, $this->commandTimeout);
    }

    /**
     * Whether "* n EXISTS" passed by since the last call.
     */
    public function takeExistsAnnouncement(): bool
    {
        $announced = $this->existsAnnounced;
        $this->existsAnnounced = false;

        return $announced;
    }

    /**
     * A quoted string (RFC 9051 section 4.3); only 7-bit text without line
     * breaks or NUL can be quoted, anything else goes as a literal.
     */
    public function quote(string $value): string
    {
        if (!self::isQuotable($value)) {
            throw new \InvalidArgumentException('Only 7-bit text without line breaks can be sent as a quoted string.');
        }

        return '"'.addcslashes($value, '"\\').'"';
    }

    public static function isQuotable(string $value): bool
    {
        return 1 !== preg_match('/[\x00\r\n\x80-\xff]/', $value);
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function readCapabilityCode(string $text): void
    {
        if (1 === preg_match('/^\S+ \[CAPABILITY ([^\]]*)\]/i', $text, $match)) {
            $this->announceCapabilities($match[1]);
        }
    }

    private function announceCapabilities(string $text): void
    {
        $this->capabilities = array_values(array_filter(explode(' ', strtoupper(trim($text))), static fn (string $item): bool => '' !== $item));
        ++$this->capabilityAnnouncements;
    }
}
