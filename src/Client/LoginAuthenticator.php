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

/**
 * The LOGIN command (RFC 9051 section 6.2.3), for servers without
 * AUTHENTICATE PLAIN and without LOGINDISABLED. A user name or password that
 * cannot be quoted goes as a synchronising literal.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9051#section-6.2.3
 */
final class LoginAuthenticator implements AuthenticatorInterface
{
    public function supports(ImapConnection $connection): bool
    {
        return !$connection->hasCapability('LOGINDISABLED');
    }

    public function authenticate(ImapConnection $connection, MailboxDsn $dsn): void
    {
        $chunks = [''];
        foreach ([$dsn->user, $dsn->password] as $index => $value) {
            $last = \count($chunks) - 1;
            $separator = 0 === $index ? '' : ' ';
            if (ImapConnection::isQuotable($value)) {
                $chunks[$last] .= $separator.$connection->quote($value);
                continue;
            }
            $chunks[$last] .= $separator.'{'.\strlen($value).'}';
            $chunks[] = $value;
        }

        $arguments = array_shift($chunks);
        $connection->command('LOGIN', $arguments, static function () use (&$chunks): string {
            return (array_shift($chunks) ?? '')."\r\n";
        });
    }
}
