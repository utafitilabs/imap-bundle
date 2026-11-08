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

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * A mock clock that runs a callback once its sleeps add up to a number of
 * seconds, standing in for a signal that arrives while a process waits.
 */
final class SignallingClock implements ClockInterface
{
    private readonly MockClock $clock;

    private float $slept = 0.0;

    /**
     * @param \Closure(): void $signal
     */
    public function __construct(private readonly float $after, private readonly \Closure $signal)
    {
        $this->clock = new MockClock('2026-11-08 13:00:00 UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);
        $before = $this->slept;
        $this->slept += $seconds;
        if ($before < $this->after && $this->slept >= $this->after) {
            ($this->signal)();
        }
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
    }

    public function slept(): float
    {
        return $this->slept;
    }
}
