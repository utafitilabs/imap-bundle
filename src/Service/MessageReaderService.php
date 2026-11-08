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

namespace UtafitiLabs\ImapBundle\Service;

use UtafitiLabs\ImapBundle\Model\Address;
use UtafitiLabs\ImapBundle\Model\Attachment;
use UtafitiLabs\ImapBundle\Model\DeliveryFailure;
use UtafitiLabs\ImapBundle\Model\FailedRecipient;
use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;

/**
 * Reads RFC 5322 messages and their MIME structure (RFC 2045, 2046, 2047,
 * 2231, 3676) into UTF-8 models. Content never makes it throw: an unknown
 * charset, broken base64 or an unterminated multipart is read as well as it
 * can be.
 *
 * It also tells automated mail from mail a person wrote, and reads delivery
 * reports: the RFC 3464 delivery-status part when there is one, and
 * otherwise the address list and the returned copy of an Exim-style text
 * bounce.
 *
 * @see https://www.rfc-editor.org/rfc/rfc5322
 * @see https://www.rfc-editor.org/rfc/rfc2045
 * @see https://www.rfc-editor.org/rfc/rfc2047
 * @see https://www.rfc-editor.org/rfc/rfc3834
 * @see https://www.rfc-editor.org/rfc/rfc3464
 * @see https://www.rfc-editor.org/rfc/rfc3463
 */
final class MessageReaderService
{
    private const int MAX_DEPTH = 20;

    private const string BOUNCE_SUBJECT = '/mail delivery failed|undeliver(?:ed|able)|delivery status notification \(failure\)|returned mail|failure notice|delivery failure|non-?delivery|could not be delivered|delivery has failed/i';

    private const string UNDELIVERED = 'The message could not be delivered';

    private const array STATUS_REASONS = [
        '1.1' => 'The mailbox does not exist',
        '1.2' => 'The recipient\'s domain does not exist',
        '2.1' => 'The mailbox is disabled',
        '2.2' => 'The mailbox is full',
        '2.3' => 'The message is too large for the mailbox',
        '3.4' => 'The message is too large',
        '4.4' => 'The recipient\'s server could not be reached',
        '4.7' => 'The message expired before it could be delivered',
        '7.1' => 'The recipient\'s server refused the message',
    ];

    /** @var array<string, true>|null */
    private static ?array $mbEncodings = null;

    /**
     * Reads the headers alone; the raw bytes may be a whole message or only
     * its header block. $fallbackMessageId stands in for a missing Message-ID;
     * without it, one is made from a hash of the header block.
     */
    public function readHeaders(string $raw, ?string $fallbackMessageId = null, ?\DateTimeImmutable $fallbackDate = null): MessageHeaders
    {
        [$headerBlock] = $this->split(str_replace("\r\n", "\n", $raw));

        return $this->headers($headerBlock, $fallbackMessageId, $fallbackDate);
    }

    /**
     * Reads a whole message.
     */
    public function read(string $raw, ?string $fallbackMessageId = null, ?\DateTimeImmutable $fallbackDate = null): Message
    {
        [$headerBlock, $body] = $this->split(str_replace("\r\n", "\n", $raw));
        $text = null;
        $html = null;
        $attachments = [];
        $fields = $this->headerFields($headerBlock);
        $this->walk($fields, $body, 0, $text, $html, $attachments);
        $headers = $this->headers($headerBlock, $fallbackMessageId, $fallbackDate);
        $textBody = rtrim($text ?? (null === $html ? '' : $this->htmlToText($html)));

        return new Message(
            $headers,
            $textBody,
            $html,
            $attachments,
            $headers->isDeliveryReport() ? $this->deliveryFailure($fields, $body, $textBody) : null,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $entity): array
    {
        if (str_starts_with($entity, "\n")) {
            return ['', substr($entity, 1)];
        }
        $end = strpos($entity, "\n\n");

        return false === $end ? [$entity, ''] : [substr($entity, 0, $end), substr($entity, $end + 2)];
    }

    /**
     * Unfolded header fields in order, names lower-cased.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function headerFields(string $headerBlock): array
    {
        $fields = [];
        foreach (explode("\n", (string) preg_replace('/\n(?=[ \t])/', '', $headerBlock)) as $line) {
            $colon = strpos($line, ':');
            if (false !== $colon && $colon > 0) {
                $fields[] = [strtolower(trim(substr($line, 0, $colon))), trim(substr($line, $colon + 1))];
            }
        }

        return $fields;
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    private function field(array $fields, string $name): ?string
    {
        foreach ($fields as [$fieldName, $value]) {
            if ($fieldName === $name) {
                return $value;
            }
        }

        return null;
    }

    private function headers(string $headerBlock, ?string $fallbackMessageId, ?\DateTimeImmutable $fallbackDate): MessageHeaders
    {
        $fields = $this->headerFields($headerBlock);

        $messageId = $this->firstId($this->field($fields, 'message-id') ?? '')
            ?? $fallbackMessageId
            ?? '<'.hash('sha256', $headerBlock).'@imap.invalid>';

        $authenticationResults = [];
        foreach ($fields as [$name, $value]) {
            if ('authentication-results' === $name) {
                $authenticationResults[] = (string) preg_replace('/\s+/', ' ', $this->toUtf8($value, 'utf-8'));
            }
        }

        $references = [];
        preg_match_all('/<[^<>\s]+>/', $this->field($fields, 'references') ?? '', $matches);
        foreach ($matches[0] as $reference) {
            $references[] = $this->toUtf8($reference, 'utf-8');
        }

        $from = $this->addresses($this->field($fields, 'from') ?? '');
        $subject = $this->decodeWords($this->field($fields, 'subject') ?? '');

        [$contentType, $contentTypeParameters] = $this->parameters($this->field($fields, 'content-type'), 'text/plain');
        $isReport = 'multipart/report' === $contentType;
        $machineSender = $this->isMachineSender($this->field($fields, 'from') ?? '');
        $deliveryReport = ($isReport && 'delivery-status' === strtolower($contentTypeParameters['report-type'] ?? ''))
            || ($machineSender && 1 === preg_match(self::BOUNCE_SUBJECT, $subject));

        return new MessageHeaders(
            $messageId,
            $from[0] ?? null,
            $this->addresses($this->field($fields, 'to') ?? ''),
            $this->addresses($this->field($fields, 'cc') ?? ''),
            $subject,
            $this->date($this->field($fields, 'date')) ?? $fallbackDate,
            $this->firstId($this->field($fields, 'in-reply-to') ?? ''),
            $references,
            $authenticationResults,
            $deliveryReport || $isReport || $machineSender || $this->hasAutomatedField($fields),
            $deliveryReport,
        );
    }

    /**
     * Whether the From field names mailer-daemon or postmaster, with or
     * without a domain.
     */
    private function isMachineSender(string $from): bool
    {
        $sender = trim((string) preg_replace('/\([^)]*\)/', '', $from));
        if (1 === preg_match('/<([^<>]*)>\s*$/', $sender, $match)) {
            $sender = $match[1];
        }

        return \in_array(strtolower(trim(explode('@', $sender)[0])), ['mailer-daemon', 'postmaster'], true);
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     */
    private function hasAutomatedField(array $fields): bool
    {
        foreach ($fields as [$name, $value]) {
            $word = strtolower(trim((string) preg_replace('/\([^)]*\)/', '', explode(';', $value)[0])));
            $automated = match ($name) {
                'auto-submitted' => 'no' !== $word,
                'return-path' => '<>' === (string) preg_replace('/\s+/', '', $word),
                'precedence' => \in_array($word, ['bulk', 'junk', 'auto_reply', 'list'], true),
                'x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'list-id', 'list-unsubscribe' => true,
                default => false,
            };
            if ($automated) {
                return true;
            }
        }

        return false;
    }

    /**
     * The failure a delivery report describes, read from its delivery-status
     * part; without one, from an Exim-style text. Null when the report is
     * only about delays or deliveries.
     *
     * @param list<array{0: string, 1: string}> $fields
     */
    private function deliveryFailure(array $fields, string $body, string $text): ?DeliveryFailure
    {
        $status = null;
        $returned = null;
        foreach ($this->leaves($fields, $body, 0) as [$type, $content]) {
            if (null === $status && \in_array($type, ['message/delivery-status', 'message/global-delivery-status'], true)) {
                $status = $content;
            } elseif (null === $returned && \in_array($type, ['text/rfc822-headers', 'message/rfc822', 'message/global', 'message/global-headers'], true)) {
                $returned = $content;
            }
        }

        $reportedId = null;
        if (null !== $status) {
            [$recipients, $reportedId] = $this->deliveryStatus($status);
            if ([] === $recipients) {
                return null;
            }
        } else {
            $recipients = $this->textFailures($text);
        }

        $returnedId = null;
        if (null !== $returned) {
            [$returnedHeaders] = $this->split(str_replace("\r\n", "\n", $returned));
            $returnedId = $this->firstId($this->field($this->headerFields($returnedHeaders), 'message-id') ?? '');
        }

        return new DeliveryFailure(
            $recipients,
            $returnedId ?? $reportedId ?? $this->quotedMessageId($text),
            $recipients[0]->reason ?? self::UNDELIVERED,
        );
    }

    /**
     * Each part that is not a multipart: its type and its decoded bytes.
     *
     * @param list<array{0: string, 1: string}> $fields
     *
     * @return \Generator<int, array{0: string, 1: string}>
     */
    private function leaves(array $fields, string $body, int $depth): \Generator
    {
        [$type, $parameters] = $this->parameters($this->field($fields, 'content-type'), 'text/plain');
        if (!str_starts_with($type, 'multipart/')) {
            yield [$type, $this->decode($body, strtolower(trim($this->field($fields, 'content-transfer-encoding') ?? '7bit')))];

            return;
        }
        $boundary = $parameters['boundary'] ?? '';
        if ('' === $boundary || $depth >= self::MAX_DEPTH) {
            return;
        }
        foreach ($this->bodyParts($body, $boundary) as $part) {
            [$partHeaders, $partBody] = $this->split($part);
            yield from $this->leaves($this->headerFields($partHeaders), $partBody, $depth + 1);
        }
    }

    /**
     * The failed recipients of a delivery-status part (RFC 3464) and the
     * X-Original-Message-ID some servers add to it.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3464#section-2
     *
     * @return array{0: list<FailedRecipient>, 1: ?string}
     */
    private function deliveryStatus(string $content): array
    {
        $recipients = [];
        $originalId = null;
        foreach (preg_split('/\n[ \t]*\n/', trim(str_replace("\r\n", "\n", $content))) ?: [] as $block) {
            $fields = $this->headerFields($block);
            $originalId ??= $this->firstId($this->field($fields, 'x-original-message-id') ?? '');
            $email = $this->typedAddress($this->field($fields, 'original-recipient'))
                ?? $this->typedAddress($this->field($fields, 'final-recipient'));
            if (null === $email || 'failed' !== strtolower(trim($this->field($fields, 'action') ?? ''))) {
                continue;
            }
            $status = 1 === preg_match('/\b[245]\.\d{1,3}\.\d{1,3}\b/', $this->field($fields, 'status') ?? '', $match) ? $match[0] : null;
            $diagnostic = $this->field($fields, 'diagnostic-code');
            if (null !== $diagnostic) {
                $diagnostic = (string) preg_replace('/\s+/', ' ', $this->toUtf8($this->typedValue($diagnostic), 'utf-8'));
                $diagnostic = '' === $diagnostic ? null : $diagnostic;
            }
            $recipients[] = new FailedRecipient($email, $this->failureReason($diagnostic, $status), $status, $diagnostic);
        }

        return [$recipients, $originalId];
    }

    /**
     * The address of an "address-type; address" field.
     */
    private function typedAddress(?string $value): ?string
    {
        $address = trim($this->typedValue($value ?? ''), '<>');

        return str_contains($address, '@') ? $this->toUtf8($address, 'utf-8') : null;
    }

    /**
     * The value of a "type; value" field, without its type.
     */
    private function typedValue(string $value): string
    {
        $semicolon = strpos($value, ';');

        return trim(false === $semicolon ? $value : substr($value, $semicolon + 1));
    }

    /**
     * The failed addresses of a text bounce that lists them under "The
     * following address(es) failed:", each followed by indented lines
     * saying why, as Exim writes it.
     *
     * @return list<FailedRecipient>
     */
    private function textFailures(string $text): array
    {
        if (1 !== preg_match('/address(?:\(es\)|es)? failed:[ \t]*\n((?:[ \t]*\n|[ \t]+\S[^\n]*(?:\n|\z))*)/i', $text, $match)) {
            return [];
        }

        $emails = [];
        $explanations = [];
        foreach (explode("\n", $match[1]) as $line) {
            $line = trim($line);
            if (1 === preg_match('/^<?([^\s<>@]+@[^\s<>@]+?)>?:?$/', $line, $address)) {
                $emails[] = $address[1];
                $explanations[] = '';
            } elseif ('' !== $line && [] !== $explanations) {
                $explanations[\count($explanations) - 1] = ltrim($explanations[\count($explanations) - 1].' '.$line);
            }
        }

        $recipients = [];
        foreach ($emails as $index => $email) {
            $diagnostic = '' === $explanations[$index] ? null : $explanations[$index];
            $status = null;
            $reason = $diagnostic;
            if (null !== $diagnostic) {
                $status = 1 === preg_match('/(?<!\S)[245]\.\d{1,3}\.\d{1,3}(?!\S)/', $diagnostic, $code) ? $code[0] : null;
                $reply = preg_match('/(?<!\S)[245]\d\d[ -]/', $diagnostic, $replyMatch, \PREG_OFFSET_CAPTURE);
                $reason = 1 === $reply ? substr($diagnostic, $replyMatch[0][1]) : $diagnostic;
            }
            $recipients[] = new FailedRecipient($email, $this->failureReason($reason, $status), $status, $diagnostic);
        }

        return $recipients;
    }

    /**
     * The Message-ID of the copy of the original a text bounce quotes, after
     * its "copy of the message" line when there is one.
     */
    private function quotedMessageId(string $text): ?string
    {
        $start = 1 === preg_match('/^-{2,}[^\n]*copy of[^\n]*$/mi', $text, $marker, \PREG_OFFSET_CAPTURE) ? $marker[0][1] : 0;

        return 1 === preg_match('/^Message-ID:\s*(<[^<>\s]+>)/mi', substr($text, $start), $match) ? $this->toUtf8($match[1], 'utf-8') : null;
    }

    /**
     * One line saying why: the diagnostic without its reply and status
     * codes or a leading address, else what the status code means (RFC
     * 3463), else that the message could not be delivered.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3463#section-3
     */
    private function failureReason(?string $diagnostic, ?string $status): string
    {
        $reason = (string) preg_replace(
            ['/(?<!\S)[245]\d\d[ -][245]\.\d{1,3}\.\d{1,3}(?!\S)/', '/^\s*[245]\d\d(?:[ -]|$)/', '/^\s*#?[245]\.\d{1,3}\.\d{1,3}(?!\S)/', '/^\s*<[^<>]*>:?/', '/\s+/'],
            ['', '', '', '', ' '],
            $diagnostic ?? '',
        );
        $reason = trim($reason, " \t:;-");
        if ('' !== $reason) {
            return $reason;
        }

        return self::STATUS_REASONS[substr((string) $status, 2)] ?? self::UNDELIVERED;
    }

    private function firstId(string $value): ?string
    {
        if (1 === preg_match('/<[^<>\s]+>/', $value, $match)) {
            return $this->toUtf8($match[0], 'utf-8');
        }
        $value = trim($value);

        return '' === $value ? null : '<'.$this->toUtf8(trim($value, '<>'), 'utf-8').'>';
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $value = trim((string) preg_replace(['/\([^)]*\)/', '/^\s*[a-z]+,/i'], '', $value));
        if (1 !== preg_match('/\d{1,2}\s+[a-z]{3}\s+\d{2,4}\s+\d{1,2}:\d{2}/i', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return list<Address>
     */
    private function addresses(string $value): array
    {
        $addresses = [];
        foreach ($this->splitOutsideQuotes($value, ',') as $part) {
            $part = trim((string) preg_replace('/^[^"<>@,]*:|;\s*$/', '', trim($part)));
            if (1 === preg_match('/^(.*)<([^<>]*)>\s*$/s', $part, $match)) {
                $name = trim($match[1]);
                $email = trim($match[2]);
            } else {
                $name = 1 === preg_match('/\(([^)]*)\)/', $part, $comment) ? $comment[1] : '';
                $email = trim((string) preg_replace('/\([^)]*\)/', '', $part));
            }
            if (!str_contains($email, '@')) {
                continue;
            }
            if (str_starts_with($name, '"') && str_ends_with($name, '"') && \strlen($name) > 1) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $name = trim($this->decodeWords($name));
            $addresses[] = new Address($this->toUtf8($email, 'utf-8'), '' === $name ? null : $name);
        }

        return $addresses;
    }

    /**
     * @return list<string>
     */
    private function splitOutsideQuotes(string $value, string $separator): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $angle = 0;
        $comment = 0;
        $length = \strlen($value);
        for ($i = 0; $i < $length; ++$i) {
            $char = $value[$i];
            if ('\\' === $char && $quoted && $i + 1 < $length) {
                $current .= $char.$value[++$i];
                continue;
            }
            if ('"' === $char && 0 === $comment) {
                $quoted = !$quoted;
            } elseif (!$quoted) {
                $angle += '<' === $char ? 1 : ('>' === $char && $angle > 0 ? -1 : 0);
                $comment += '(' === $char ? 1 : (')' === $char && $comment > 0 ? -1 : 0);
            }
            if ($char === $separator && !$quoted && 0 === $angle && 0 === $comment) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Header text with its encoded words (RFC 2047) decoded. Adjacent words
     * are joined before conversion, so a character split across two words
     * survives; raw 8-bit text is read as UTF-8, or as Windows-1252 when it is
     * not valid UTF-8.
     */
    private function decodeWords(string $value): string
    {
        $pattern = '/=\?([^?\s]+)\?([bq])\?([^?\s]*)\?=/i';
        $pieces = preg_split('/(=\?[^?\s]+\?[bq]\?[^?\s]*\?=)/i', $value, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        $decoded = '';
        $pendingBytes = '';
        $pendingCharset = null;
        foreach ($pieces as $index => $piece) {
            if (1 === $index % 2 && 1 === preg_match($pattern, $piece, $word)) {
                $charset = strtolower(explode('*', $word[1])[0]);
                $bytes = 'b' === strtolower($word[2])
                    ? (string) base64_decode($word[3], false)
                    : quoted_printable_decode(str_replace('_', ' ', $word[3]));
                if (null !== $pendingCharset && $charset !== $pendingCharset) {
                    $decoded .= $this->toUtf8($pendingBytes, $pendingCharset);
                    $pendingBytes = '';
                }
                $pendingCharset = $charset;
                $pendingBytes .= $bytes;
                continue;
            }
            $between = 0 === $index % 2 && '' === trim($piece) && null !== $pendingCharset && isset($pieces[$index + 1]);
            if ($between) {
                continue;
            }
            if (null !== $pendingCharset) {
                $decoded .= $this->toUtf8($pendingBytes, $pendingCharset);
                $pendingBytes = '';
                $pendingCharset = null;
            }
            $decoded .= $this->toUtf8($piece, 'utf-8');
        }
        if (null !== $pendingCharset) {
            $decoded .= $this->toUtf8($pendingBytes, $pendingCharset);
        }

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $decoded));
    }

    /**
     * A content type or disposition value and its parameters, with RFC 2231
     * continuations and charsets resolved and encoded words decoded.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parameters(?string $value, string $default): array
    {
        $parts = $this->splitOutsideQuotes($value ?? '', ';');
        $main = strtolower(trim((string) array_shift($parts)));
        $raw = [];
        foreach ($parts as $part) {
            $equals = strpos($part, '=');
            if (false === $equals) {
                continue;
            }
            $name = strtolower(trim(substr($part, 0, $equals)));
            $parameterValue = trim(substr($part, $equals + 1));
            if (str_starts_with($parameterValue, '"') && str_ends_with($parameterValue, '"') && \strlen($parameterValue) > 1) {
                $parameterValue = stripcslashes(substr($parameterValue, 1, -1));
            }
            $raw[$name] = $parameterValue;
        }
        ksort($raw, \SORT_NATURAL);

        $parameters = [];
        $extended = [];
        foreach ($raw as $name => $parameterValue) {
            if (1 !== preg_match('/^([^*]+)(?:\*(\d+))?(\*)?$/', $name, $match)) {
                continue;
            }
            $isEncoded = isset($match[3]);
            if (!isset($match[2]) || '' === $match[2]) {
                if ($isEncoded) {
                    $extended[$match[1]] = $parameterValue;
                } else {
                    $parameters[$match[1]] = $this->decodeWords($parameterValue);
                }
                continue;
            }
            $extended[$match[1]] = ($extended[$match[1]] ?? '').($isEncoded ? $parameterValue : rawurlencode($parameterValue));
        }
        foreach ($extended as $name => $parameterValue) {
            $charset = 'us-ascii';
            if (1 === preg_match("/^([^']*)'[^']*'(.*)$/s", $parameterValue, $match)) {
                $charset = '' === $match[1] ? 'us-ascii' : $match[1];
                $parameterValue = $match[2];
            }
            $parameters[$name] = $this->toUtf8(rawurldecode($parameterValue), $charset);
        }

        return ['' === $main ? $default : $main, $parameters];
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     * @param list<Attachment>                  $attachments
     */
    private function walk(array $fields, string $body, int $depth, ?string &$text, ?string &$html, array &$attachments): void
    {
        [$type, $parameters] = $this->parameters($this->field($fields, 'content-type'), 'text/plain');
        [$disposition, $dispositionParameters] = $this->parameters($this->field($fields, 'content-disposition'), 'inline');
        $encoding = strtolower(trim($this->field($fields, 'content-transfer-encoding') ?? '7bit'));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $parameters['boundary'] ?? '';
            if ('' === $boundary || $depth >= self::MAX_DEPTH) {
                return;
            }
            foreach ($this->bodyParts($body, $boundary) as $part) {
                [$partHeaders, $partBody] = $this->split($part);
                $this->walk($this->headerFields($partHeaders), $partBody, $depth + 1, $text, $html, $attachments);
            }

            return;
        }

        $filename = $dispositionParameters['filename'] ?? $parameters['name'] ?? null;
        if ('attachment' === $disposition || null !== $filename || !\in_array($type, ['text/plain', 'text/html'], true)) {
            $attachments[] = new Attachment($filename, $type, $this->decodedSize($body, $encoding));

            return;
        }

        $content = $this->toUtf8($this->decode($body, $encoding), $parameters['charset'] ?? 'us-ascii');
        if ('text/plain' === $type && null === $text) {
            $text = 'flowed' === strtolower($parameters['format'] ?? '')
                ? $this->unflow($content, 'yes' === strtolower($parameters['delsp'] ?? ''))
                : $content;
        } elseif ('text/html' === $type && null === $html) {
            $html = rtrim($content);
        }
    }

    /**
     * The parts between the boundary lines; an unterminated multipart ends at
     * the end of the body.
     *
     * @return list<string>
     */
    private function bodyParts(string $body, string $boundary): array
    {
        $chunks = explode("\n--".$boundary, "\n".$body);
        array_shift($chunks);
        $parts = [];
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break;
            }
            $lineEnd = strpos($chunk, "\n");
            $parts[] = false === $lineEnd ? '' : substr($chunk, $lineEnd + 1);
        }

        return $parts;
    }

    private function decode(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/]/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodedSize(string $body, string $encoding): int
    {
        if ('base64' === $encoding) {
            $counts = count_chars($body, 1);
            $symbols = 0;
            foreach ($counts as $byte => $count) {
                if (($byte >= 48 && $byte <= 57) || ($byte >= 65 && $byte <= 90) || ($byte >= 97 && $byte <= 122) || 43 === $byte || 47 === $byte) {
                    $symbols += $count;
                }
            }

            return intdiv($symbols * 3, 4);
        }

        return 'quoted-printable' === $encoding ? \strlen(quoted_printable_decode($body)) : \strlen($body);
    }

    /**
     * Joins the soft line breaks of format=flowed text (RFC 3676).
     *
     * @see https://www.rfc-editor.org/rfc/rfc3676#section-4.2
     */
    private function unflow(string $content, bool $deleteSpace): string
    {
        $result = '';
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, ' ')) {
                $line = substr($line, 1);
            }
            $soft = '-- ' !== $line && str_ends_with($line, ' ');
            $result .= $soft ? ($deleteSpace ? substr($line, 0, -1) : $line) : $line."\n";
        }

        return $result;
    }

    private function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
        $html = (string) preg_replace('#</?(p|h[1-6]|ul|ol|table|blockquote|pre)\b[^>]*>#i', "\n\n", $html);
        $html = (string) preg_replace('#</?(div|tr|hr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * Bytes in $charset as UTF-8, best effort: ISO-8859-1 is read as
     * Windows-1252 (as browsers do), US-ASCII and an unknown charset as UTF-8,
     * and text that is not valid UTF-8 as Windows-1252.
     */
    private function toUtf8(string $bytes, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));
        $charset = match ($charset) {
            '', 'us-ascii', 'ascii', 'utf8', 'unicode-1-1-utf-8' => 'utf-8',
            'iso-8859-1', 'iso8859-1', 'latin1', 'latin-1', 'l1', 'cp819' => 'windows-1252',
            default => $charset,
        };

        if ('utf-8' !== $charset) {
            try {
                if (isset(self::mbEncodings()[$charset])) {
                    return (string) mb_convert_encoding($bytes, 'UTF-8', $charset);
                }
            } catch (\ValueError) {
            }
            $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
            if (false !== $converted && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * @return array<string, true>
     */
    private static function mbEncodings(): array
    {
        if (null === self::$mbEncodings) {
            self::$mbEncodings = [];
            $notCharsets = ['base64', 'uuencode', 'html-entities', 'quoted-printable', '7bit', '8bit', 'pass', 'wchar', 'byte2be', 'byte2le', 'byte4be', 'byte4le'];
            foreach (mb_list_encodings() as $encoding) {
                if (\in_array(strtolower($encoding), $notCharsets, true)) {
                    continue;
                }
                foreach ([$encoding, ...mb_encoding_aliases($encoding)] as $name) {
                    self::$mbEncodings[strtolower($name)] = true;
                }
            }
        }

        return self::$mbEncodings;
    }
}
