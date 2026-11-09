# IMAP IDLE for Symfony!

[![CI](https://github.com/utafitilabs/imap-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/utafitilabs/imap-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/utafitilabs/imap-bundle)](https://packagist.org/packages/utafitilabs/imap-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/utafitilabs/imap-bundle)](https://packagist.org/packages/utafitilabs/imap-bundle)
[![License](https://img.shields.io/packagist/l/utafitilabs/imap-bundle)](LICENSE)

This bundle lets a Symfony application **react within seconds to mail arriving in a
mailbox**: a long-running `imap:watch` process holds an IMAP connection open with **IDLE**,
fetches each new message, reads it into a **UTF-8 model** (addresses, subject, text, HTML,
attachment metadata, SPF/DKIM/DMARC verdicts) and hands it to your handler. It is
**read-only by construction** and written on **PHP built-ins and Symfony components only**
— no ext-imap, no third-party MIME parser. Configure a mailbox, write a handler, run the
command.

> Part of the [utafiti tools](https://github.com/utafitilabs).

## Contents

- [Why](#why)
- [Install](#install)
- [A taste](#a-taste)
- [Resuming after a restart](#resuming-after-a-restart)
- [Automated mail and bounces](#automated-mail-and-bounces)
- [Documentation](#documentation)
- [Requirements](#requirements)
- [Compatibility](#compatibility)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Why

Polling a mailbox from cron answers mail minutes late, and the usual PHP IMAP stacks
either need ext-imap (gone from PHP 8.4's core) or pull in a tree of mail libraries you
then have to trust with your credentials and your users' mail. This bundle keeps one
small, audited code path: a TLS-verified IMAP client, a MIME reader and a watcher that
reconnects on its own — and that can never change, move or mark a message as read.

## Install

Applications using [Symfony Flex](https://symfony.com/doc/current/setup.html):

```console
composer require utafitilabs/imap-bundle
```

Applications without Symfony Flex — after requiring the package, enable the bundle:

```php
// config/bundles.php
return [
    // ...
    UtafitiLabs\ImapBundle\UtafitiLabsImapBundle::class => ['all' => true],
];
```

That one line registers the `imap:watch` command, the mailbox watcher, the store of how far
each mailbox has been read, the message reader and the quoted-reply stripper, each
autowirable by class. Then name a mailbox and the
service that handles its mail:

```yaml
# config/packages/utafitilabs_imap.yaml
utafitilabs_imap:
    mailboxes:
        replies:
            dsn: '%env(IMAP_REPLIES_DSN)%'   # empty turns the mailbox off
            handler: App\Mail\ReplyHandler
```

```dotenv
# .env.local
IMAP_REPLIES_DSN=imaps://replies%40example.com:app-password@imap.example.com
```

## A taste

```php
// src/Mail/ReplyHandler.php
namespace App\Mail;

use UtafitiLabs\ImapBundle\Model\Message;
use UtafitiLabs\ImapBundle\Model\MessageHeaders;
use UtafitiLabs\ImapBundle\Service\IncomingMailHandlerInterface;
use UtafitiLabs\ImapBundle\Service\QuotedReplyStripperService;

final class ReplyHandler implements IncomingMailHandlerInterface
{
    public function __construct(
        private readonly QuotedReplyStripperService $stripper,
        private readonly ReplyStore $replies,
    ) {
    }

    // Only the headers are fetched so far; say no and the body never leaves the server.
    public function wants(MessageHeaders $headers): bool
    {
        return null !== $headers->inReplyTo && !$this->replies->has($headers->messageId);
    }

    public function handle(Message $message): void
    {
        $this->replies->add(
            $message->headers->messageId,
            $message->headers->from?->email,
            $this->stripper->strip($message->textBody),   // the new text, without the quoted mail
        );
    }
}
```

```console
php bin/console imap:watch replies
```

The first run catches up on the last three days, then waits with IDLE and hands over each
new message within seconds; a restart resumes after the last message read. It reconnects
with a doubling delay after any network error and stops cleanly on SIGTERM or Ctrl+C.

Models: `Message` (headers, `textBody`, `htmlBody`, `attachments`, `deliveryFailure`),
`MessageHeaders` (Message-ID, from, to, cc, subject, date, In-Reply-To, References,
`authenticationResults`, `isAutomated()`, `isDeliveryReport()`), `Address`, `Attachment`
(file name, type, size — never the content), `DeliveryFailure` and `FailedRecipient`. Services: `MessageReaderService` (raw RFC 822 → `Message`),
`QuotedReplyStripperService` (Gmail, Outlook, Apple Mail, Yahoo and Thunderbird quote
forms), `MailboxWatcherService`, `MailboxStateStoreInterface`.

## Resuming after a restart

The watcher remembers how far it has read each mailbox, as IMAP clients do: the folder's
UIDVALIDITY and the highest UID it has offered
([RFC 3501 §2.3.1.1](https://www.rfc-editor.org/rfc/rfc3501#section-2.3.1.1),
[RFC 4549 §4.3](https://www.rfc-editor.org/rfc/rfc4549#section-4.3)). A restart resumes
after that UID, so a message is offered once — handled, declined or failed — whatever
your handler keeps. Only the first run, or a folder whose UIDVALIDITY changed, catches up
on the last `catch_up_days`.

The state lives in a cache pool, `cache.app` by default. Out of the box `cache.app` is a
set of files under `var/cache`, which a deploy from a fresh image starts without; give the
watcher a pool that survives deploys, such as one in the database:

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

See [docs/index.md](docs/index.md#resuming-after-a-restart) for the details and other pools.

## Automated mail and bounces

A mailbox that receives replies also receives what machines send back: bounces from
mailer-daemon, out-of-office replies, mailing lists. A bounce often carries your own
message's Message-ID in In-Reply-To, so a handler that matches replies by thread files it
as an answer unless it asks first. Two checks on the headers tell such mail apart, before
the body is fetched:

- `MessageHeaders::isAutomated()` — a machine sent it: `Auto-Submitted` other than `no`
  ([RFC 3834](https://www.rfc-editor.org/rfc/rfc3834)), a `multipart/report`
  ([RFC 6522](https://www.rfc-editor.org/rfc/rfc6522)), an empty `Return-Path: <>`, a
  mailer-daemon or postmaster sender, `X-Autoreply`, `X-Autorespond` or
  `X-Auto-Response-Suppress`, `Precedence: bulk | junk | auto_reply | list`, or a mailing
  list's `List-Id` or `List-Unsubscribe`.
- `MessageHeaders::isDeliveryReport()` — it reports on the delivery of another message: a
  `multipart/report` of `report-type=delivery-status`
  ([RFC 3464](https://www.rfc-editor.org/rfc/rfc3464)), or a mailer-daemon or postmaster
  sender with a subject such as "Mail delivery failed" or "Undelivered Mail Returned to
  Sender". Every delivery report is also automated.

When a delivery report says delivery failed, the read message carries a
`DeliveryFailure`: the `FailedRecipient`s (address, one-line `reason`, `status` such as
`5.1.1`, the servers' `diagnosticCode`), the `originalMessageId` of the message that came
back, and the first recipient's `reason`. It is read from the report's delivery-status
part, or from the "address(es) failed" list of an Exim text bounce; a report about a delay
carries none.

```php
public function wants(MessageHeaders $headers): bool
{
    if ($headers->isDeliveryReport()) {
        return true;                       // read it, to learn which message bounced
    }

    return !$headers->isAutomated() && !$this->replies->has($headers->messageId);
}

public function handle(Message $message): void
{
    if ($message->headers->isDeliveryReport()) {
        $failure = $message->deliveryFailure;
        $sentId = $failure?->originalMessageId;
        if (null !== $failure && null !== $sentId && $this->sent->has($sentId)) {
            foreach ($failure->recipients as $recipient) {
                $this->sent->markUndelivered($sentId, $recipient->email, $recipient->reason);
            }
        }

        return;                            // a bounce is never an answer
    }

    $this->replies->add($message->headers->messageId, $message->headers->from?->email, $message->textBody);
}
```

## Documentation

Read the documentation at [docs/index.md](docs/index.md) — configuration reference, the
handler contract, resuming after a restart, running the watcher under a process supervisor or in a container,
security, providers, and using the client on its own.

## Requirements

- PHP **8.4+** with **openssl**, **mbstring** and **iconv** (and **pcntl** for a clean stop on SIGTERM)
- Symfony **7.3+ / 8**
- An IMAP server reachable over **TLS** (port 993, or STARTTLS on 143)

## Compatibility

Any server that speaks IMAP4rev1 ([RFC 3501](https://www.rfc-editor.org/rfc/rfc3501)) or
IMAP4rev2 ([RFC 9051](https://www.rfc-editor.org/rfc/rfc9051)). New mail arrives through
IDLE ([RFC 2177](https://www.rfc-editor.org/rfc/rfc2177)); a server without IDLE is checked
every five minutes instead. Logs in with AUTHENTICATE PLAIN or LOGIN. The client is tested
against a recorded Dovecot session.

- **Gmail**: turn on 2-Step Verification and use an
  [app password](https://support.google.com/accounts/answer/185833) as the password.
- **Microsoft 365 / Outlook.com**: password logins are disabled; they need OAuth 2.0
  (XOAUTH2), which is **not supported yet** — the authenticator interface is ready for it.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). CI enforces the standard (php-cs-fixer, PHPStan max,
PHPUnit with recorded server conversations and a local TLS server, lowest→newest
dependency matrix) on every pull request.

## Credits

- [Ezekiel Mjema](https://github.com/eemjema)
- [All Contributors](https://github.com/utafitilabs/imap-bundle/graphs/contributors)

## License

MIT License (MIT): see the [LICENSE](LICENSE) file for more details.
