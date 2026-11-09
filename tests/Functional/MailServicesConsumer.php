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

namespace UtafitiLabs\ImapBundle\Tests\Functional;

use UtafitiLabs\ImapBundle\Service\MailboxWatcherService;
use UtafitiLabs\ImapBundle\Service\MessageReaderService;
use UtafitiLabs\ImapBundle\Service\QuotedReplyStripperService;
use UtafitiLabs\ImapBundle\Store\MailboxStateStoreInterface;

/**
 * An application service that autowires the bundle's public services by class.
 */
final readonly class MailServicesConsumer
{
    public function __construct(
        public MessageReaderService $reader,
        public QuotedReplyStripperService $stripper,
        public MailboxWatcherService $watcher,
        public MailboxStateStoreInterface $states,
    ) {
    }
}
