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

namespace UtafitiLabs\ImapBundle\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use UtafitiLabs\ImapBundle\Model\Mailbox;
use UtafitiLabs\ImapBundle\Model\MailboxState;
use UtafitiLabs\ImapBundle\Store\CacheMailboxStateStore;

final class CacheMailboxStateStoreTest extends TestCase
{
    private const string DSN = 'imaps://answers%40example.org:s3cret@imap.example.com';

    private ArrayAdapter $pool;
    private CacheMailboxStateStore $store;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->store = new CacheMailboxStateStore($this->pool);
    }

    public function testAMailboxNeverSavedHasNoState(): void
    {
        self::assertNull($this->store->get(new Mailbox('answers', self::DSN)));
    }

    public function testItGivesBackWhatWasSaved(): void
    {
        $mailbox = new Mailbox('answers', self::DSN);

        $this->store->save($mailbox, new MailboxState(1730000000, 4101));

        self::assertEquals(new MailboxState(1730000000, 4101), $this->store->get($mailbox));
        self::assertEquals(new MailboxState(1730000000, 4101), (new CacheMailboxStateStore($this->pool))->get(new Mailbox('answers', 'imaps://other@imap.example.com')));
    }

    public function testEachMailboxAndEachFolderHasItsOwnState(): void
    {
        $this->store->save(new Mailbox('answers', self::DSN), new MailboxState(100, 11));
        $this->store->save(new Mailbox('billing', self::DSN), new MailboxState(200, 22));
        $this->store->save(new Mailbox('answers', self::DSN, 'Archive'), new MailboxState(300, 33));

        self::assertEquals(new MailboxState(100, 11), $this->store->get(new Mailbox('answers', self::DSN)));
        self::assertEquals(new MailboxState(200, 22), $this->store->get(new Mailbox('billing', self::DSN)));
        self::assertEquals(new MailboxState(300, 33), $this->store->get(new Mailbox('answers', self::DSN, 'Archive')));
    }

    public function testAnyMailboxNameOrFolderMakesAValidCacheKey(): void
    {
        $mailbox = new Mailbox('{replies}(a/b)\\c@d:e', self::DSN, 'Entwürfe/Ältere');

        $this->store->save($mailbox, new MailboxState(7, 8));

        self::assertEquals(new MailboxState(7, 8), $this->store->get($mailbox));
        foreach (array_keys($this->pool->getValues()) as $key) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', (string) $key);
        }
    }

    public function testNoPartOfTheDsnIsStored(): void
    {
        $this->store->save(new Mailbox('answers', self::DSN), new MailboxState(100, 11));

        $stored = serialize($this->pool->getValues());
        self::assertStringNotContainsString('s3cret', $stored);
        self::assertStringNotContainsString('example.org', $stored);
    }

    public function testAnItemThatIsNotAStateCountsAsNone(): void
    {
        $mailbox = new Mailbox('answers', self::DSN);
        $this->store->save($mailbox, new MailboxState(100, 11));

        foreach (['garbage', ['uid_validity' => 'x', 'last_uid' => 11], ['uid_validity' => 100]] as $value) {
            foreach (array_keys($this->pool->getValues()) as $key) {
                $this->pool->save($this->pool->getItem((string) $key)->set($value));
            }

            self::assertNull($this->store->get($mailbox));
        }
    }
}
