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

namespace UtafitiLabs\ImapBundle\Store;

use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;

/**
 * Where the watcher remembers how far it has read each mailbox, so that a
 * restart resumes after the last message offered instead of offering the
 * catch-up window again. The state is kept per mailbox name and folder.
 */
interface MailboxStateStoreInterface
{
    /**
     * The state last saved for the mailbox, or null when none was saved or it
     * cannot be read.
     */
    public function get(Mailbox $mailbox): ?MailboxState;

    public function save(Mailbox $mailbox, MailboxState $state): void;
}
