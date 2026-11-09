# UtafitiLabsImapBundle

Read-only IMAP mailbox watching for **Symfony**: an IMAP client with IDLE, an RFC 822 /
MIME reader, and a long-running watcher that hands each new message to your handler.
Written on PHP built-ins and Symfony components only.

## Contents

- [Installation](#installation)
- [Requirements](#requirements)
- [Configuration](#configuration)
  - [The DSN](#the-dsn)
  - [A mailbox that is off](#a-mailbox-that-is-off)
- [The handler](#the-handler)
- [Resuming after a restart](#resuming-after-a-restart)
  - [A pool that survives deploys](#a-pool-that-survives-deploys)
  - [Starting over](#starting-over)
  - [Your own store](#your-own-store)
- [Running the watcher](#running-the-watcher)
  - [What the watcher does](#what-the-watcher-does)
  - [Process supervisors and containers](#process-supervisors-and-containers)
  - [Logs](#logs)
- [Reading messages](#reading-messages)
  - [The models](#the-models)
  - [Automated mail and bounces](#automated-mail-and-bounces)
  - [Stripping quoted mail](#stripping-quoted-mail)
- [Security](#security)
- [Providers](#providers)
- [Using the client on its own](#using-the-client-on-its-own)
- [Limitations](#limitations)

## Installation

```console
composer require utafitilabs/imap-bundle
```

Without Symfony Flex, enable the bundle:

```php
// config/bundles.php
return [
    // ...
    UtafitiLabs\ImapBundle\UtafitiLabsImapBundle::class => ['all' => true],
];
```

## Requirements

- PHP **8.4+** with the `openssl`, `mbstring` and `iconv` extensions; `pcntl` lets
  `imap:watch` stop cleanly on SIGTERM and SIGINT.
- Symfony **7.3+ / 8**.
- An IMAP server reachable over TLS.

## Configuration

```yaml
# config/packages/utafitilabs_imap.yaml
utafitilabs_imap:
    state_pool: cache.app                       # default cache.app; see Resuming after a restart
    mailboxes:
        replies:                                # the name you pass to imap:watch
            dsn: '%env(IMAP_REPLIES_DSN)%'      # required to be set, may be empty (off)
            handler: App\Mail\ReplyHandler      # service id of your handler
            folder: INBOX                       # default INBOX
            catch_up_days: 3                    # default 3, 0–365
            idle_seconds: 1740                  # default 1740 (29 min), 60–1740
            poll_seconds: 300                   # default 300, servers without IDLE
            max_message_bytes: 10485760         # default 10 MB
```

| Key | Default | Meaning |
|---|---|---|
| `state_pool` | `cache.app` | The service id of the cache pool (PSR-6) that remembers how far each mailbox has been read; give it one that survives deploys |
| `mailboxes.<name>.…` | | One entry per mailbox, with the keys below |
| `dsn` | `''` | Where and as whom to log in; empty turns the mailbox off |
| `handler` | — | The id of the service implementing `IncomingMailHandlerInterface` |
| `folder` | `INBOX` | The folder watched; non-ASCII names are sent in modified UTF-7 |
| `catch_up_days` | `3` | On a first run, or when the folder's UIDVALIDITY changed, mail received this many days back is offered |
| `idle_seconds` | `1740` | IDLE is ended and issued again after this long; RFC 2177 lets servers drop an IDLE after 30 minutes |
| `poll_seconds` | `300` | How often a server without IDLE is checked with NOOP and UID SEARCH |
| `max_message_bytes` | `10485760` | Messages larger than this (by RFC822.SIZE) are skipped without being fetched |

### The DSN

```text
imaps://USER:PASSWORD@HOST[:993]     TLS from the first byte (recommended)
imap://USER:PASSWORD@HOST[:143]      STARTTLS, required before any credential is sent
```

User and password are URL-encoded: `replies@example.com` with password `p@ss:word` is
`imaps://replies%40example.com:p%40ss%3Aword@imap.example.com`. A connection is never made
in clear text; a server on `imap://` that does not offer STARTTLS is refused.

### A mailbox that is off

An empty DSN turns a mailbox off. `imap:watch` then prints
`Mailbox "replies" is off: its DSN is empty. Waiting until stopped.` and waits for SIGTERM
or SIGINT instead of exiting, so a process supervisor or a container orchestrator that
restarts exited processes does not restart it in a loop. Define the environment variable
empty in `.env` and set it where the mailbox should be watched.

## The handler

```php
namespace UtafitiLabs\ImapBundle\Service;

interface IncomingMailHandlerInterface
{
    public function wants(MessageHeaders $headers): bool;

    public function handle(Message $message): void;
}
```

For each message the watcher first fetches only its identifying header fields
(Message-ID, From, To, Cc, Subject, Date, In-Reply-To, References,
Authentication-Results), the fields that tell automated mail apart (Content-Type,
Return-Path, Auto-Submitted, X-Autoreply, X-Autorespond, X-Auto-Response-Suppress,
Precedence, List-Id, List-Unsubscribe) and its size, and asks `wants()`. Only when the handler says yes is
the whole message fetched, read and passed to `handle()`. An application that takes every
message returns `true`.

The watcher offers each message once: it remembers the last UID it offered (see
[Resuming after a restart](#resuming-after-a-restart)). A handler that also keeps the
Message-IDs it has stored can decline a message in `wants()` should it come again — after
the state was lost, or a folder's UIDVALIDITY changed — but it does not have to. A message
without a Message-ID gets a stable one made from its UIDVALIDITY and UID
(`<1730000000.4101@replies.imap.invalid>`).

A handler that takes replies declines what a machine sent with
`$headers->isAutomated()`, and lets bounces through with `$headers->isDeliveryReport()`
when it wants to know which of its messages failed — see
[Automated mail and bounces](#automated-mail-and-bounces).

An exception thrown by `wants()` or `handle()` is logged with the message's UID and
Message-ID, and the watcher goes on with the next message. The failed message is not
offered again, so a message that always fails cannot stop the mailbox; find it in the
log by its UID and Message-ID. Only a message cut off by a lost connection before the
handler had it is offered again, after the reconnect.

## Resuming after a restart

A watcher that offered the last days of mail on every start would rely on the handler to
recognise what it had already stored, and an application that deletes its records would
import that mail again. So the watcher keeps, per mailbox name and folder, what an IMAP
client keeps to resynchronise
([RFC 4549 §4.3](https://www.rfc-editor.org/rfc/rfc4549#section-4.3)): the folder's
UIDVALIDITY and the highest UID offered to the handler. A UID means something only under
its UIDVALIDITY ([RFC 3501 §2.3.1.1](https://www.rfc-editor.org/rfc/rfc3501#section-2.3.1.1)).

On every connect, after EXAMINE:

- **Same UIDVALIDITY as saved.** The watcher searches `UID SEARCH UID <last + 1>:*` and
  offers what it finds. An open range always matches the folder's newest message, even
  when its UID is below the start (RFC 3501 §6.4.8), so UIDs at or below the saved one
  are dropped.
- **No state, or another UIDVALIDITY.** The watcher catches up on the last
  `catch_up_days`, as on a first run, then moves the state on to the newest UID the folder
  had (UIDNEXT − 1), so mail older than the window is never offered later. A changed
  UIDVALIDITY is logged.

The state moves past each message as soon as the handler is done with it — handled,
declined, failed, too large, or gone before it was fetched — and is saved each time, in
the catch-up and during IDLE alike. A stop in the middle of a catch-up leaves the rest for
the next run.

### A pool that survives deploys

The state is kept by `CacheMailboxStateStore` in the pool `state_pool` names, `cache.app`
by default, as two integers under a key hashed from the mailbox name and folder; nothing
of the DSN is stored. Out of the box `cache.app` is a set of files under the kernel's
cache directory (`framework.cache.directory`, from Symfony 7.4 under
`%kernel.share_dir%`, which is `var/cache` unless `APP_SHARE_DIR` sets it), so a deploy
from a fresh container image starts without it and the first run after it catches up on
the window again. Give the watcher a pool that survives deploys
([Cache: custom pools](https://symfony.com/doc/current/cache.html#creating-custom-namespaced-pools)).

In the database, with the
[Doctrine DBAL adapter](https://symfony.com/doc/current/components/cache/adapters/doctrine_dbal_adapter.html)
(the adapter creates its `cache_items` table on first use, and Doctrine Migrations
includes it in a diff):

```yaml
# config/packages/cache.yaml
framework:
    cache:
        default_doctrine_dbal_provider: doctrine.dbal.default_connection
        pools:
            imap.state:
                adapter: cache.adapter.doctrine_dbal

# config/packages/utafitilabs_imap.yaml
utafitilabs_imap:
    state_pool: imap.state
```

On files on a persistent volume, with the
[filesystem adapter](https://symfony.com/doc/current/components/cache/adapters/filesystem_adapter.html):
`framework.cache.directory` (or `APP_SHARE_DIR`) moves every filesystem pool, `cache.app`
among them, onto that volume:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        directory: '/srv/data/cache-pools'      # a volume kept between deploys
```

Redis works as well ([Cache](https://symfony.com/doc/current/cache.html#configuring-cache-with-frameworkbundle)).
A pool that evicts entries (APCu, Memcached under memory pressure) loses the state; the
watcher then catches up on the window, as on a first run.

### Starting over

Clearing the pool forgets every mailbox's state, and the next start catches up on the
last `catch_up_days`:

```console
php bin/console cache:pool:clear imap.state
```

`cache:pool:clear cache.app` (and `cache:pool:clear --all`) does the same when the state is
in `cache.app`. Point a mailbox entry at another account under the same name and folder
only after clearing it: a coincidentally equal UIDVALIDITY would otherwise resume at the
old account's UID.

### Your own store

`MailboxStateStoreInterface` (`get(Mailbox): ?MailboxState`, `save(Mailbox, MailboxState)`)
is aliased to the bundle's store; decorate or replace the service
`utafitilabs_imap.mailbox_state_store` to keep the state elsewhere. `MailboxState` holds
`uidValidity` and `lastUid`; `get()` returns null when nothing usable is saved.

## Running the watcher

```console
php bin/console imap:watch replies
```

Run one process per mailbox.

### What the watcher does

1. Connects, verifies the server's certificate, logs in and opens the folder with EXAMINE
   (read-only).
2. Resumes after the last UID it offered under the folder's UIDVALIDITY, or, on a first
   run or after the UIDVALIDITY changed, catches up on the mail received since
   `catch_up_days` ago; either way the handler gets the headers first, as above. See
   [Resuming after a restart](#resuming-after-a-restart).
3. Waits with IDLE. When the server announces new mail it ends IDLE, searches by UID for
   everything above the highest UID it has seen, and offers that. IDLE is renewed every
   `idle_seconds`. A server without IDLE is checked every `poll_seconds` instead; this is
   logged once.
4. On any network or protocol error it closes the connection and reconnects after 1 s,
   2 s, 4 s … up to 5 minutes; a successful connection resets the delay, and the watcher
   resumes after the last UID it offered.
5. On SIGTERM or SIGINT it ends IDLE, logs out and exits with 0, usually within a second.

### Process supervisors and containers

```ini
; /etc/supervisor/conf.d/imap-replies.conf
[program:imap-replies]
command=php /srv/app/bin/console imap:watch replies --no-interaction
autorestart=true
stopsignal=TERM
stopwaitsecs=10
```

In a container, run the command as the process of its own role or service; the stop
signal the runtime sends ends it cleanly.

### Logs

The watcher logs on the `imap` channel (Monolog) or the application's `logger`. A record
carries the mailbox name, UIDs, Message-IDs and what was decided — handled, declined,
skipped as too large, failed, reconnecting, resuming after a UID, a changed UIDVALIDITY —
and never a DSN, a password, an address, a
subject or a body.

## Reading messages

`MessageReaderService` is a service of its own and works on any raw message, for example
an uploaded `.eml` file:

```php
$message = $reader->read(file_get_contents('reply.eml'));
$headers = $reader->readHeaders($headerBytes);   // a header block alone
```

It unfolds headers, decodes RFC 2047 encoded words (joining adjacent words before
converting, so a character split across words survives) and RFC 2231 parameters, walks
nested multiparts by boundary, decodes base64 and quoted-printable, converts every charset
to UTF-8 (ISO-8859-1 as Windows-1252, an unknown charset by best effort), joins
`format=flowed` text, and never throws on content.

### The models

| Model | Properties |
|---|---|
| `Message` | `headers`, `textBody` (the text/plain part, or the HTML part reduced to text), `htmlBody` (`?string`), `attachments` (`list<Attachment>`), `deliveryFailure` (`?DeliveryFailure`) |
| `MessageHeaders` | `messageId`, `from` (`?Address`), `to`, `cc` (`list<Address>`), `subject`, `date` (`?DateTimeImmutable`), `inReplyTo`, `references`, `authenticationResults` (`list<string>`); `isAutomated()`, `isDeliveryReport()` |
| `Address` | `email`, `name` (`?string`) |
| `Attachment` | `filename` (`?string`), `contentType`, `size` (decoded bytes) |
| `DeliveryFailure` | `recipients` (`list<FailedRecipient>`), `originalMessageId` (`?string`), `reason` |
| `FailedRecipient` | `email`, `reason`, `status` (`?string`, such as `5.1.1`), `diagnosticCode` (`?string`) |

All are read-only. An attachment's content is never decoded into memory; its size is
counted from the encoded part.

### Automated mail and bounces

`MessageHeaders::isAutomated()` is true when any of these says a machine sent the message:

| Signal | Source |
|---|---|
| `Auto-Submitted` present and not `no` | [RFC 3834](https://www.rfc-editor.org/rfc/rfc3834) |
| `Content-Type: multipart/report` (bounces, read receipts) | [RFC 6522](https://www.rfc-editor.org/rfc/rfc6522) |
| `Return-Path: <>` (the null sender of bounces and auto-replies) | [RFC 5321](https://www.rfc-editor.org/rfc/rfc5321) |
| From `mailer-daemon` or `postmaster`, any case, with or without a domain | convention |
| `X-Autoreply`, `X-Autorespond`; `X-Auto-Response-Suppress` (Microsoft) | convention |
| `Precedence: bulk`, `junk`, `auto_reply` or `list` | convention |
| `List-Id` or `List-Unsubscribe` (a mailing list is not a person) | [RFC 2919](https://www.rfc-editor.org/rfc/rfc2919), [RFC 2369](https://www.rfc-editor.org/rfc/rfc2369) |

`MessageHeaders::isDeliveryReport()` is true for a `multipart/report` with
`report-type=delivery-status` ([RFC 3464](https://www.rfc-editor.org/rfc/rfc3464)), and
for a mailer-daemon or postmaster sender whose subject reads like a bounce ("Mail delivery
failed", "Undelivered Mail Returned to Sender", "Delivery Status Notification (Failure)",
"Undeliverable", "failure notice", "Returned mail"). A delivery report is always
automated; a read receipt or an out-of-office reply is automated but not a delivery report.

A delivery report that says delivery failed is read into `Message::$deliveryFailure`:

1. **Recipients.** From the `message/delivery-status` part, each recipient with
   `Action: failed`, by its `Original-Recipient` (the address you sent to) or else its
   `Final-Recipient`, with its `Status` and `Diagnostic-Code`. Without that part, as Exim
   often sends, from the addresses listed under "The following address(es) failed:" and
   the indented lines below each.
2. **The original Message-ID.** From the returned `text/rfc822-headers` or
   `message/rfc822` part, else the `X-Original-Message-ID` some servers put in the
   delivery status, else the `Message-ID:` line of the copy quoted in the text.
3. **The reason.** The diagnostic without its SMTP reply and status codes ("Recipient
   address rejected: User unknown in virtual mailbox table", "Unrouteable address"), else
   what the status code means (`5.1.1` "The mailbox does not exist", `5.1.2`, `5.2.2` "The
   mailbox is full", `5.7.1` "The recipient's server refused the message", …), else "The
   message could not be delivered". `DeliveryFailure::$reason` is the first recipient's.

A report whose delivery-status part names no failed recipient (a delay, a delivery)
carries no `DeliveryFailure`; a text bounce whose addresses cannot be read carries one
with no recipients.

### Stripping quoted mail

```php
$newText = $stripper->strip($message->textBody);
```

`QuotedReplyStripperService` cuts at an attribution line (`On … wrote:` in the Gmail,
Apple Mail, Yahoo and Thunderbird forms, also in German, French, Spanish and Dutch, wrapped
over up to three lines), at Outlook's `-----Original Message-----` or its
`From: … Sent: …` block, and at the signature delimiter `-- `. Lines quoted with `>` are
dropped wherever they are, so answers written between quotes stay; a trailing
`Sent from my …` line goes too.

## Security

- **TLS always, verified.** The certificate chain and the host name are verified, with SNI,
  on TLS 1.2 or 1.3; there is no option to turn verification off. `imap://` requires
  STARTTLS and refuses a server that sends data before the handshake.
- **Read-only by construction.** The client has no method that stores flags, copies,
  moves, expunges, appends or deletes; folders are opened with EXAMINE and messages fetched
  with `BODY.PEEK`, so not even the `\Seen` flag changes. The test suite checks every
  command sent in its recorded conversations.
- **Data minimisation.** Only headers are fetched until the handler wants a message;
  declined mail's bodies never leave the server.
- **Bounded memory.** Messages over `max_message_bytes` are skipped unfetched; the response
  parser refuses a literal over that size plus 64 KB and a line over 1 MB.
- **No secrets in logs or errors.** Errors name the command verb, never its arguments, so
  a LOGIN line never reaches an exception; the DSN and password are hidden from dumps.
- **Spoofed senders.** `MessageHeaders::$authenticationResults` holds the SPF, DKIM and
  DMARC verdicts receiving servers added
  ([RFC 8601](https://www.rfc-editor.org/rfc/rfc8601)). Trust only the header your own
  provider's server added — usually the first one, naming its host — since a sender can
  write any header of its own:

  ```php
  public function wants(MessageHeaders $headers): bool
  {
      $verdict = $headers->authenticationResults[0] ?? '';

      return str_starts_with($verdict, 'mx.example.com;') && str_contains($verdict, 'dmarc=pass');
  }
  ```

## Providers

Any IMAP4rev1 ([RFC 3501](https://www.rfc-editor.org/rfc/rfc3501)) or IMAP4rev2
([RFC 9051](https://www.rfc-editor.org/rfc/rfc9051)) server works. The client logs in with
AUTHENTICATE PLAIN when the server offers it and LOGIN otherwise.

- **Self-hosted (Dovecot, Cyrus, …) and most hosting providers**: `imaps://` on port 993
  with the mailbox's password.
- **Gmail / Google Workspace**: turn on 2-Step Verification and create an
  [app password](https://support.google.com/accounts/answer/185833); use it as the
  password. Google may disable app passwords for Workspace accounts by policy.
- **Microsoft 365 / Outlook.com**: basic authentication is disabled; they need OAuth 2.0
  (XOAUTH2), which this bundle **does not support yet**. Logins are made by
  `AuthenticatorInterface` implementations tried in order, so XOAUTH2 is one more class.

## Using the client on its own

```php
use Symfony\Component\Clock\NativeClock;
use UtafitiLabs\ImapBundle\Factory\ImapClientFactory;

$client = (new ImapClientFactory(new NativeClock()))->create('imaps://u:p@imap.example.com', 10485760);
$client->open();
$status = $client->examine('INBOX');
foreach ($client->fetchHeaders($client->searchSince(new \DateTimeImmutable('-1 day'))) as $headers) {
    // $headers->uid, $headers->size, $headers->bytes
}
$client->logout();
```

`ImapClientInterface` offers `open`, `supportsIdle`, `examine`, `searchSince`,
`searchUidsFrom`, `fetchHeaders`, `fetch`, `idle`, `noop`, `logout` and `close`, and nothing
that writes. A server certificate signed by a private CA is trusted by passing its CA file
as the factory's second argument; verification itself stays on.

## Limitations

- No XOAUTH2 yet (see [Providers](#providers)).
- One folder per mailbox entry; watch another folder with another entry.
- Attachments are described, not delivered; fetch the raw message with the client when the
  content is needed.
- The reader's text is UTF-8 with `\n` line endings; a message's first text/plain part is
  its text.
