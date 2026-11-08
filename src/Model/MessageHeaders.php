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
 * The identifying headers of a message, decoded to UTF-8.
 *
 * $authenticationResults holds every Authentication-Results header (RFC 8601)
 * as one unfolded line each: the SPF, DKIM and DMARC verdicts the receiving
 * servers added. Only a header added by your own provider's server can be
 * trusted; a sender can write any header of its own.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8601
 */
final readonly class MessageHeaders
{
    /**
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<string>  $references
     * @param list<string>  $authenticationResults
     */
    public function __construct(
        public string $messageId,
        public ?Address $from,
        public array $to,
        public array $cc,
        public string $subject,
        public ?\DateTimeImmutable $date,
        public ?string $inReplyTo,
        public array $references,
        public array $authenticationResults,
    ) {
    }
}
