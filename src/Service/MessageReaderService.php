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
use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;

/**
 * Reads RFC 5322 messages and their MIME structure (RFC 2045, 2046, 2047,
 * 2231, 3676) into UTF-8 models. Content never makes it throw: an unknown
 * charset, broken base64 or an unterminated multipart is read as well as it
 * can be.
 *
 * @see https://www.rfc-editor.org/rfc/rfc5322
 * @see https://www.rfc-editor.org/rfc/rfc2045
 * @see https://www.rfc-editor.org/rfc/rfc2047
 */
final class MessageReaderService
{
    private const int MAX_DEPTH = 20;

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
        $this->walk($this->headerFields($headerBlock), $body, 0, $text, $html, $attachments);

        return new Message(
            $this->headers($headerBlock, $fallbackMessageId, $fallbackDate),
            rtrim($text ?? (null === $html ? '' : $this->htmlToText($html))),
            $html,
            $attachments,
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

        return new MessageHeaders(
            $messageId,
            $from[0] ?? null,
            $this->addresses($this->field($fields, 'to') ?? ''),
            $this->addresses($this->field($fields, 'cc') ?? ''),
            $this->decodeWords($this->field($fields, 'subject') ?? ''),
            $this->date($this->field($fields, 'date')) ?? $fallbackDate,
            $this->firstId($this->field($fields, 'in-reply-to') ?? ''),
            $references,
            $authenticationResults,
        );
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
