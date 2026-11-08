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
 * isAutomated() and isDeliveryReport() are decided by MessageReaderService
 * from the header fields alone, so a handler can decline machine-sent mail in
 * wants() before its body is fetched.
 *
 * @see https://www.rfc-editor.org/rfc/rfc8601
 * @see https://www.rfc-editor.org/rfc/rfc3834
 * @see https://www.rfc-editor.org/rfc/rfc6522
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
        private bool $automated = false,
        private bool $deliveryReport = false,
    ) {
    }

    /**
     * Whether a machine rather than a person sent the message: an
     * Auto-Submitted field other than "no" (RFC 3834), a report (RFC 6522),
     * an empty Return-Path, a mailer-daemon or postmaster sender, an
     * X-Autoreply, X-Autorespond or X-Auto-Response-Suppress field, a
     * Precedence of bulk, junk, auto_reply or list, or a mailing list's
     * List-Id or List-Unsubscribe. Every delivery report is automated.
     */
    public function isAutomated(): bool
    {
        return $this->automated;
    }

    /**
     * Whether the message reports on the delivery of another one: a
     * multipart/report of report-type delivery-status (RFC 3464), or a
     * mailer-daemon or postmaster sender with a subject such as "Mail
     * delivery failed" or "Undelivered Mail Returned to Sender". A read
     * receipt or an out-of-office reply is not one.
     */
    public function isDeliveryReport(): bool
    {
        return $this->deliveryReport;
    }
}
