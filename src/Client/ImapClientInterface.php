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

use UtafitiLabs\ImapBundle\Exception\ImapException;
use UtafitiLabs\ImapBundle\Model\MailboxStatus;
use UtafitiLabs\ImapBundle\Model\RawHeaders;
use UtafitiLabs\ImapBundle\Model\RawMessage;

/**
 * A read-only IMAP session. There is no way through it to change a mailbox:
 * folders are opened with EXAMINE and messages fetched with BODY.PEEK, so not
 * even the \Seen flag moves.
 */
interface ImapClientInterface
{
    /**
     * Connects, secures the connection and logs in.
     *
     * @throws ImapException
     */
    public function open(): void;

    public function supportsIdle(): bool;

    /**
     * Opens a folder read-only.
     *
     * @throws ImapException
     */
    public function examine(string $folder): MailboxStatus;

    /**
     * The UIDs of the messages received on or after the day of $since.
     *
     * @return list<int>
     *
     * @throws ImapException
     */
    public function searchSince(\DateTimeImmutable $since): array;

    /**
     * The UIDs from $uid upwards.
     *
     * @return list<int>
     *
     * @throws ImapException
     */
    public function searchUidsFrom(int $uid): array;

    /**
     * The identifying header fields, those that tell automated mail apart, and
     * the size of each message, in UID order.
     *
     * @param list<int> $uids
     *
     * @return list<RawHeaders>
     *
     * @throws ImapException
     */
    public function fetchHeaders(array $uids): array;

    /**
     * One whole message, or null when it is gone.
     *
     * @throws ImapException
     */
    public function fetch(int $uid): ?RawMessage;

    /**
     * Waits for new mail with IDLE (RFC 2177) for up to $seconds, polling
     * $shouldStop about once a second; true when the server announced mail.
     *
     * @param \Closure(): bool $shouldStop
     *
     * @throws ImapException
     */
    public function idle(int $seconds, \Closure $shouldStop): bool;

    /**
     * @throws ImapException
     */
    public function noop(): void;

    /**
     * Logs out and closes; never throws.
     */
    public function logout(): void;

    public function close(): void;
}
