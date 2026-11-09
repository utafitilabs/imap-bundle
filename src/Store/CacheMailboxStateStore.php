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

namespace UtafitiLabs\ImapBundle\Store;

use Psr\Cache\CacheItemPoolInterface;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;

/**
 * Keeps the state of each mailbox in a PSR-6 cache pool, as two integers
 * under a key made from the XXH128 hash of the mailbox name and folder: PSR-6
 * guarantees keys of A-Z, a-z, 0-9, _ and . up to 64 characters
 * (https://www.php-fig.org/psr/psr-6/#definitions), and the reserved
 * characters {}()/\@: are refused by Symfony's adapters
 * (vendor/symfony/cache/CacheItem.php). Nothing of the DSN is stored.
 *
 * Items are saved without expiry; the pool must outlive deploys for a
 * restart to resume where the last run stopped.
 */
final readonly class CacheMailboxStateStore implements MailboxStateStoreInterface
{
    public function __construct(private CacheItemPoolInterface $pool)
    {
    }

    public function get(Mailbox $mailbox): ?MailboxState
    {
        $value = $this->pool->getItem(self::key($mailbox))->get();
        if (!\is_array($value) || !\is_int($value['uid_validity'] ?? null) || !\is_int($value['last_uid'] ?? null)) {
            return null;
        }

        return new MailboxState($value['uid_validity'], $value['last_uid']);
    }

    public function save(Mailbox $mailbox, MailboxState $state): void
    {
        $item = $this->pool->getItem(self::key($mailbox));
        $this->pool->save($item->set(['uid_validity' => $state->uidValidity, 'last_uid' => $state->lastUid])->expiresAfter(null));
    }

    private static function key(Mailbox $mailbox): string
    {
        return 'utafitilabs_imap.'.hash('xxh128', $mailbox->name."\n".$mailbox->folder);
    }
}
