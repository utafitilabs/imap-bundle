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
use UtafitiLabs\ImapBundle\Model\MailboxDsn;

/**
 * One way of logging in. The client asks each authenticator in turn whether the
 * server's capabilities allow it and uses the first that says yes; a new
 * mechanism (an OAuth 2.0 bearer token, for one) is one more implementation.
 */
interface AuthenticatorInterface
{
    public function supports(ImapConnection $connection): bool;

    /**
     * @throws ImapException when the server refuses
     */
    public function authenticate(ImapConnection $connection, MailboxDsn $dsn): void;
}
