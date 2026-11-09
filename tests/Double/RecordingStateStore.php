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

use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;
use UtafitiLabs\ImapBundle\Store\MailboxStateStoreInterface;

/**
 * Passes through to another store and records the last UID of every save.
 */
final class RecordingStateStore implements MailboxStateStoreInterface
{
    /** @var list<int> */
    public array $savedUids = [];

    public function __construct(private readonly MailboxStateStoreInterface $inner)
    {
    }

    public function get(Mailbox $mailbox): ?MailboxState
    {
        return $this->inner->get($mailbox);
    }

    public function save(Mailbox $mailbox, MailboxState $state): void
    {
        $this->savedUids[] = $state->lastUid;
        $this->inner->save($mailbox, $state);
    }
}
