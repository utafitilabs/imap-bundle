# Changelog

All notable changes to `utafitilabs/imap-bundle` are documented here.

## Contents

- [v0.1.0 — 2026-11-08](#v010--2026-11-08)

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
