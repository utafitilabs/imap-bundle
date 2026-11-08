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

/**
 * Cuts the earlier mail a reply quotes, leaving what the sender wrote. It cuts
 * at an attribution line ("On … wrote:", as Gmail, Apple Mail, Yahoo and
 * Thunderbird write it, also in German, French, Spanish and Dutch, wrapped
 * over up to three lines), at Outlook's "-----Original Message-----" or its
 * "From: … Sent: …" block, and at the signature delimiter "-- "; lines quoted
 * with ">" are dropped wherever they are, so answers written between quotes
 * stay. A trailing "Sent from my …" line goes too.
 */
final class QuotedReplyStripperService
{
    private const array ATTRIBUTIONS = [
        '/^On\b.{1,400}\bwrote:$/su',
        '/^Am\b.{1,400}\bschrieb\b.{0,400}:$/su',
        '/^Le\b.{1,400}\ba écrit\s?:$/su',
        '/^El\b.{1,400}\bescribió:$/su',
        '/^Op\b.{1,400}\bschreef\b.{0,400}:$/su',
    ];

    public function strip(string $text): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        $kept = [];
        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];
            if ('--' === rtrim($line) || $this->startsQuote($lines, $i)) {
                break;
            }
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        while ([] !== $kept && 1 === preg_match('/^(|Sent from my .+|Get Outlook for .+)$/', trim((string) end($kept)))) {
            array_pop($kept);
        }

        return rtrim(implode("\n", $kept));
    }

    /**
     * @param list<string> $lines
     */
    private function startsQuote(array $lines, int $index): bool
    {
        $line = trim($lines[$index]);
        if ('' === $line) {
            return false;
        }

        $joined = $line;
        for ($next = $index; $next < $index + 3 && $next < \count($lines); ++$next) {
            if ($next > $index) {
                $joined .= ' '.trim($lines[$next]);
            }
            foreach (self::ATTRIBUTIONS as $attribution) {
                if (1 === preg_match($attribution, $joined)) {
                    return true;
                }
            }
        }

        if (1 === preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $line)) {
            return true;
        }
        if (1 === preg_match('/^_{10,}$/', $line)) {
            for ($next = $index + 1; $next < \count($lines) && $next <= $index + 2; ++$next) {
                if (1 === preg_match('/^\*?From:/i', trim($lines[$next]))) {
                    return true;
                }
            }
        }
        if (1 === preg_match('/^\*?From:\*? /i', $line)) {
            for ($next = $index + 1; $next < \count($lines) && $next <= $index + 4; ++$next) {
                if (1 === preg_match('/^\*?(Sent|Date):\*? /i', trim($lines[$next]))) {
                    return true;
                }
            }
        }

        return false;
    }
}
