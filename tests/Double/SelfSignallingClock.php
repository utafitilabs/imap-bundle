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
 * A mock clock that sends SIGTERM to its own process once five seconds of
 * sleep have passed, as a container stop would.
 */
final class SelfSignallingClock implements ClockInterface
{
    private readonly MockClock $clock;

    private float $slept = 0.0;

    public function __construct()
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
        $this->slept += $seconds;
        if (5.0 === $this->slept) {
            posix_kill((int) getmypid(), \SIGTERM);
        }
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
