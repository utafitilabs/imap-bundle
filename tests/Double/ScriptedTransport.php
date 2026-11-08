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

namespace UtafitiLabs\ImapBundle\Tests\Double;

use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Clock\MockClock;
use UtafitiLabs\ImapBundle\Client\TransportInterface;
use UtafitiLabs\ImapBundle\Exception\ImapConnectionException;

/**
 * Replays a recorded IMAP conversation: every client write must equal the next
 * "C" step, server bytes ("S" steps) are served as the client reads them, and a
 * read with nothing left to serve before the next client step waits out its
 * timeout on the mock clock, as a silent server would.
 */
final class ScriptedTransport implements TransportInterface
{
    /** @var list<string> */
    public array $written = [];

    /** @var array{0: string, 1: int, 2: bool, 3: float}|null */
    public ?array $connectedTo = null;

    public bool $tlsStarted = false;

    public bool $closed = false;

    private string $buffer = '';

    /**
     * @param list<array{0: 'C'|'S'|'EOF'|'TLS', 1?: string}> $steps
     */
    public function __construct(private readonly MockClock $clock, private array $steps)
    {
    }

    /**
     * Reads a transcript whose lines start with "C: " (client) or "S: " (server);
     * each line stands for itself followed by CRLF, a line "TLS" is the TLS
     * handshake after STARTTLS, and a line "EOF" closes the connection.
     */
    public static function fromTranscript(MockClock $clock, string $path): self
    {
        $steps = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ('EOF' === $line || 'TLS' === $line) {
                $steps[] = [$line];
                continue;
            }
            $kind = substr($line, 0, 3);
            if ('C: ' !== $kind && 'S: ' !== $kind) {
                continue;
            }
            $steps[] = ['C: ' === $kind ? 'C' : 'S', substr($line, 3)."\r\n"];
        }

        return new self($clock, $steps);
    }

    public function connect(string $host, int $port, bool $implicitTls, float $timeout): void
    {
        $this->connectedTo = [$host, $port, $implicitTls, $timeout];
    }

    public function enableTls(float $timeout): void
    {
        $step = array_shift($this->steps);
        if (null === $step || 'TLS' !== $step[0]) {
            throw new AssertionFailedError(\sprintf('Unexpected TLS start; the script expected %s.', json_encode($step)));
        }
        $this->tlsStarted = true;
    }

    public function write(string $bytes, float $timeout): void
    {
        $this->written[] = $bytes;
        $step = array_shift($this->steps);
        if (null === $step || 'C' !== $step[0] || ($step[1] ?? '') !== $bytes) {
            throw new AssertionFailedError(\sprintf('Unexpected client write %s; the script expected %s.', json_encode($bytes), json_encode($step)));
        }
    }

    public function readLine(float $timeout, int $maxBytes): ?string
    {
        $this->fill();
        $end = strpos($this->buffer, "\n");
        if (false !== $end) {
            $line = substr($this->buffer, 0, $end + 1);
            $this->buffer = substr($this->buffer, $end + 1);

            return $line;
        }
        if ('EOF' === ($this->steps[0][0] ?? null)) {
            throw new ImapConnectionException('The server closed the connection.');
        }
        $this->clock->sleep($timeout);

        return null;
    }

    public function read(int $length, float $timeout): string
    {
        $this->fill();
        if (\strlen($this->buffer) < $length) {
            throw new ImapConnectionException('The server closed the connection.');
        }
        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return list<array{0: 'C'|'S'|'EOF'|'TLS', 1?: string}>
     */
    public function remainingSteps(): array
    {
        return $this->steps;
    }

    /**
     * Every command line the client sent, without literal continuations.
     *
     * @return list<string>
     */
    public function commandLines(): array
    {
        return array_values(array_filter(
            $this->written,
            static fn (string $line): bool => 1 === preg_match('/^[A-Z]\d{4} /', $line),
        ));
    }

    private function fill(): void
    {
        while ('S' === ($this->steps[0][0] ?? null)) {
            $step = array_shift($this->steps);
            $this->buffer .= $step[1] ?? '';
        }
    }
}
