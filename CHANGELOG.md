# Changelog

All notable changes to `utafitilabs/imap-bundle` are documented here.

## Contents

- [v0.1.2 — 2026-11-09](#v012--2026-11-09)
- [v0.1.1 — 2026-11-08](#v011--2026-11-08)
- [v0.1.0 — 2026-11-08](#v010--2026-11-08)

## v0.1.2 — 2026-11-09

### Fixed

- A restart of `imap:watch` no longer offers the last `catch_up_days` of mail again:
  the watcher remembers, per mailbox name and folder, the folder's UIDVALIDITY and the
  highest UID it offered (RFC 3501 §2.3.1.1, RFC 4549 §4.3), and resumes with
  `UID SEARCH UID <last + 1>:*`. A handler that forgot what it had stored, for example
  because the application deleted those records, no longer receives that mail as new.
- The UID an open range `n:*` always returns, even below `n`, is never offered again.
- After a first catch-up the watcher moves on to the folder's newest UID, so mail older
  than the window is no longer offered when the next message arrives.

### Added

- `MailboxStateStoreInterface` (`get(Mailbox): ?MailboxState`,
  `save(Mailbox, MailboxState)`), `MailboxState` (`uidValidity`, `lastUid`) and
  `CacheMailboxStateStore`, which keeps the state in a PSR-6 pool under a hashed key.
- `utafitilabs_imap.state_pool`: the cache pool that keeps the state, `cache.app` by
  default; give it one that survives deploys (a Doctrine DBAL pool, or files on a
  persistent volume).
- `MailboxWatcherService` takes the store as an optional last argument; without one the
  state lives in memory.

### Changed

- The catch-up window applies to a first run, a lost state or a changed UIDVALIDITY
  only, no longer to every connect.
- A message whose `wants()` or `handle()` threw is not offered again after a restart;
  the state moves past it as past any other, so a message that always fails cannot stop
  the mailbox.
- `psr/cache` and `symfony/cache` are required (FrameworkBundle already installs both).

## v0.1.1 — 2026-11-08

### Added

- `MessageHeaders::isAutomated()`: true for mail a machine sent — `Auto-Submitted` other
  than `no` (RFC 3834), a `multipart/report` (RFC 6522), an empty `Return-Path`, a
  mailer-daemon or postmaster sender, `X-Autoreply`, `X-Autorespond`,
  `X-Auto-Response-Suppress`, `Precedence: bulk | junk | auto_reply | list`, `List-Id` or
  `List-Unsubscribe`.
- `MessageHeaders::isDeliveryReport()`: true for a `multipart/report` of
  `report-type=delivery-status` (RFC 3464), or a mailer-daemon or postmaster sender with a
  bounce subject ("Mail delivery failed", "Undelivered Mail Returned to Sender",
  "Delivery Status Notification (Failure)", "Undeliverable", …).
- `Message::$deliveryFailure` (`DeliveryFailure`, `FailedRecipient`): on a delivery report
  that says delivery failed, the failed recipients with their reason, status code and
  diagnostic, the returned message's Message-ID and a one-line reason; read from the
  delivery-status part, or from the address list and quoted copy of an Exim text bounce.
- The header fetch also asks for `Content-Type`, `Return-Path`, `Auto-Submitted`,
  `X-Autoreply`, `X-Autorespond`, `X-Auto-Response-Suppress`, `Precedence`, `List-Id` and
  `List-Unsubscribe`, so both checks work in `wants()`.

## v0.1.0 — 2026-11-08

### Added

- `imap:watch <mailbox>`: watches a configured mailbox with IDLE (RFC 2177), catching up on
  the last days on every connect, fetching headers first and the whole message only when
  the handler wants it, reconnecting with a doubling delay up to five minutes, and stopping
  cleanly on SIGTERM or SIGINT. A mailbox with an empty DSN is off and waits for the signal.
- `IncomingMailHandlerInterface` (`wants(MessageHeaders)`, `handle(Message)`) and the
  `utafitilabs_imap.mailboxes` configuration.
- A read-only IMAP client over a verified TLS socket (imaps, or imap with STARTTLS):
  AUTHENTICATE PLAIN and LOGIN behind `AuthenticatorInterface`, EXAMINE, UID SEARCH,
  header and `BODY.PEEK` fetches, IDLE, NOOP; a NOOP check for servers without IDLE.
- `MessageReaderService`: RFC 5322 / MIME messages into UTF-8 models with attachment
  metadata and the Authentication-Results verdicts.
- `QuotedReplyStripperService`: the new text of a reply, without the quoted mail.
