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

namespace UtafitiLabs\ImapBundle\Tests\Double;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Every record with its placeholders filled in, as a handler would write it.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static function (array $record): string {
            $replace = [];
            foreach ($record['context'] as $key => $value) {
                if (\is_scalar($value)) {
                    $replace['{'.$key.'}'] = (string) $value;
                }
            }

            return (\is_string($record['level']) ? $record['level'] : 'log').': '.strtr($record['message'], $replace);
        }, $this->records);
    }
}
