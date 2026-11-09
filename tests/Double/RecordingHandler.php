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

use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;
use UtafitiLabs\ImapBundle\Service\IncomingMailHandlerInterface;

/**
 * Wants every message except those it has handled or is told to decline, and
 * records what it handled; it can be told to throw for a Message-ID.
 */
final class RecordingHandler implements IncomingMailHandlerInterface
{
    /** @var list<string> */
    public array $handled = [];

    /** @var list<string> */
    public array $offered = [];

    /** Runs after each message handled. */
    public ?\Closure $onHandle = null;

    /**
     * @param list<string> $declined
     * @param list<string> $failing
     * @param list<string> $failingWants
     */
    public function __construct(
        public array $declined = [],
        public array $failing = [],
        public array $failingWants = [],
    ) {
    }

    public function wants(MessageHeaders $headers): bool
    {
        $this->offered[] = $headers->messageId;
        if (\in_array($headers->messageId, $this->failingWants, true)) {
            throw new \RuntimeException('wants failed');
        }

        return !\in_array($headers->messageId, [...$this->declined, ...$this->handled], true);
    }

    public function handle(Message $message): void
    {
        if (\in_array($message->headers->messageId, $this->failing, true)) {
            throw new \RuntimeException('handle failed');
        }
        $this->handled[] = $message->headers->messageId;
        if (null !== $this->onHandle) {
            ($this->onHandle)();
        }
    }
}
