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

use UtafitiLabs\ImapBundle\Model\MailboxDsn;
use UtafitiLabs\ImapBundle\Model\ResponseLine;

/**
 * AUTHENTICATE PLAIN (RFC 4616), with the initial response on the command
 * line when the server announces SASL-IR (RFC 4959).
 *
 * @see https://www.rfc-editor.org/rfc/rfc4616
 * @see https://www.rfc-editor.org/rfc/rfc4959
 */
final class PlainAuthenticator implements AuthenticatorInterface
{
    public function supports(ImapConnection $connection): bool
    {
        return $connection->hasCapability('AUTH=PLAIN');
    }

    public function authenticate(ImapConnection $connection, MailboxDsn $dsn): void
    {
        $response = base64_encode("\0".$dsn->user."\0".$dsn->password);
        if ($connection->hasCapability('SASL-IR')) {
            $connection->command('AUTHENTICATE', 'PLAIN '.$response);

            return;
        }

        $sent = false;
        $connection->command('AUTHENTICATE', 'PLAIN', static function (ResponseLine $challenge) use (&$sent, $response): string {
            if ($sent) {
                return "*\r\n";
            }
            $sent = true;

            return $response."\r\n";
        });
    }
}
