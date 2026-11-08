# Contributing to UtafitiLabsImapBundle

Thanks for your interest! This project holds a high, automated bar so maintainers can
review with confidence. **CI is a gatekeeper: a pull request is only considered for review
once every check below is green.** Please run the gate locally before opening a PR.

## Contents

- [Requirements for every pull request](#requirements-for-every-pull-request)
- [The gate](#the-gate)
- [Scope](#scope)

## Requirements for every pull request

1. **Test-driven.** No production code without a test. Add/extend:
   - `tests/Unit/` for pure logic — the response reader, the client against recorded
     server conversations (`tests/Double/ScriptedTransport`, transcripts in
     `tests/Fixtures/conversations/`), the message reader against `.eml` fixtures, the
     watcher against `tests/Double/FakeImapClient`. No network.
   - `tests/Integration/` for the TLS socket, against a local PHP TLS server.
   - `tests/Functional/` for the bundle's wiring in a booted kernel.
2. **Read-only stays read-only.** No command that changes a mailbox (STORE, COPY, MOVE,
   EXPUNGE, APPEND, DELETE, SELECT, …); `ImapClientTest` fails on any.
3. **No secrets, addresses, subjects or bodies** in logs or exception messages.
4. **No dependency beyond PHP extensions and Symfony components.**
5. **`declare(strict_types=1);`** in every PHP file; `@Symfony` + `@Symfony:risky`
   (php-cs-fixer); PHPStan at **max**, zero errors.
6. **Synthetic data only** in tests and docs: `example.com`, `example.org`, `example.net`.

## The gate

```bash
composer install
composer cs:fix     # auto-format first
composer check      # cs:check -> phpstan (max) -> tests   (must be green)
```

The same `composer check` runs in CI (GitHub Actions) on every push and pull request.

## Scope

`UtafitiLabsImapBundle` reads mailboxes; it does not send, move or delete mail. Bug fixes,
new authenticators (XOAUTH2), more quote forms with fixtures, and documentation
improvements are all welcome.
