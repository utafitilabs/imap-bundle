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

use UtafitiLabs\ImapBundle\Client\ImapClientInterface;
use UtafitiLabs\ImapBundle\Model\MailboxStatus;
use UtafitiLabs\ImapBundle\Model\RawHeaders;
use UtafitiLabs\ImapBundle\Model\RawMessage;

/**
 * An in-memory mailbox behind the client interface. Each idle() runs the next
 * step of $idleSteps, which may add mail, ask the test to stop, or throw.
 */
final class FakeImapClient implements ImapClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<int, int> */
    public array $sizes = [];

    /** @var list<\Closure(self): bool> */
    public array $idleSteps = [];

    public bool $idleSupported = true;

    public ?\Throwable $openFailure = null;

    public bool $closed = false;

    /**
     * @param array<int, string> $messages raw messages by UID
     */
    public function __construct(public array $messages = [], public int $uidValidity = 100)
    {
    }

    public function open(): void
    {
        $this->calls[] = 'open';
        if (null !== $this->openFailure) {
            throw $this->openFailure;
        }
    }

    public function supportsIdle(): bool
    {
        return $this->idleSupported;
    }

    public function examine(string $folder): MailboxStatus
    {
        $this->calls[] = 'examine '.$folder;

        return new MailboxStatus($this->uidValidity, \count($this->messages), [] === $this->messages ? 1 : max(array_keys($this->messages)) + 1);
    }

    public function searchSince(\DateTimeImmutable $since): array
    {
        $this->calls[] = 'searchSince '.$since->format('Y-m-d');

        return $this->uids(0);
    }

    public function searchUidsFrom(int $uid): array
    {
        $this->calls[] = 'searchUidsFrom '.$uid;

        return $this->uids($uid);
    }

    public function fetchHeaders(array $uids): array
    {
        $this->calls[] = 'fetchHeaders '.implode(',', $uids);
        $headers = [];
        foreach ($uids as $uid) {
            if (isset($this->messages[$uid])) {
                $raw = $this->messages[$uid];
                $end = strpos($raw, "\r\n\r\n");
                $headers[] = new RawHeaders($uid, $this->sizes[$uid] ?? \strlen($raw), false === $end ? $raw : substr($raw, 0, $end + 4));
            }
        }

        return $headers;
    }

    public function fetch(int $uid): ?RawMessage
    {
        $this->calls[] = 'fetch '.$uid;

        return isset($this->messages[$uid]) ? new RawMessage($uid, \strlen($this->messages[$uid]), null, $this->messages[$uid]) : null;
    }

    public function idle(int $seconds, \Closure $shouldStop): bool
    {
        $this->calls[] = 'idle '.$seconds;
        $step = array_shift($this->idleSteps) ?? throw new \LogicException('The idle script ran out.');

        return $step($this);
    }

    public function noop(): void
    {
        $this->calls[] = 'noop';
    }

    public function logout(): void
    {
        $this->calls[] = 'logout';
        $this->closed = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return list<int>
     */
    private function uids(int $from): array
    {
        $uids = array_values(array_filter(array_keys($this->messages), static fn (int $uid): bool => $uid >= $from));
        sort($uids);

        return $uids;
    }
}
