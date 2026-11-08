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
 * What a delivery report (a bounce) says about one of your sent messages: the
 * recipients it could not reach, the Message-ID of the message it returns,
 * and a one-line reason (the first recipient's).
 *
 * $originalMessageId is null when the report returns neither the message nor
 * its headers. $recipients is empty when the report names no failed address
 * in a form that can be read.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3464
 */
final readonly class DeliveryFailure
{
    /**
     * @param list<FailedRecipient> $recipients
     */
    public function __construct(
        public array $recipients,
        public ?string $originalMessageId,
        public string $reason,
    ) {
    }
}
