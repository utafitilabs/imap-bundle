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

That one line registers the `imap:watch` command, the mailbox watcher, the message reader
and the quoted-reply stripper, each autowirable by class. Then name a mailbox and the
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

The process catches up on the last three days, then waits with IDLE and hands over each
new message within seconds; it reconnects with a doubling delay after any network error
and stops cleanly on SIGTERM or Ctrl+C.

Models: `Message` (headers, `textBody`, `htmlBody`, `attachments`), `MessageHeaders`
(Message-ID, from, to, cc, subject, date, In-Reply-To, References,
`authenticationResults`), `Address`, `Attachment` (file name, type, size — never the
content). Services: `MessageReaderService` (raw RFC 822 → `Message`),
`QuotedReplyStripperService` (Gmail, Outlook, Apple Mail, Yahoo and Thunderbird quote
forms), `MailboxWatcherService`.

## Documentation

Read the documentation at [docs/index.md](docs/index.md) — configuration reference, the
handler contract, running the watcher under a process supervisor or in a container,
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
