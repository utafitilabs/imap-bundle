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
 * A read message: its headers, its text (the text/plain part, or the HTML part
 * reduced to text when there is none), its HTML when it has one, and what its
 * attachments are, without their content.
 *
 * $deliveryFailure is set on a delivery report that says delivery failed:
 * which recipients of which of your messages, and why.
 */
final readonly class Message
{
    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(
        public MessageHeaders $headers,
        public string $textBody,
        public ?string $htmlBody,
        public array $attachments,
        public ?DeliveryFailure $deliveryFailure = null,
    ) {
    }
}
