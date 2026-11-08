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

namespace UtafitiLabs\ImapBundle\Model;

/**
 * One address a message could not be delivered to: the address as it was
 * sent to, a one-line reason, the enhanced status code (RFC 3463, "5.1.1")
 * when the report gives one, and the diagnostic the servers wrote.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3463
 */
final readonly class FailedRecipient
{
    public function __construct(
        public string $email,
        public string $reason,
        public ?string $status,
        public ?string $diagnosticCode,
    ) {
    }
}
