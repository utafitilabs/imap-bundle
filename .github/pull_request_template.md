<!--
CI must be green before this PR can be reviewed. See CONTRIBUTING.md.
-->

## What & why

<!-- Describe the change and the motivation. Link any issue. -->

## Checklist (required)

- [ ] Tests added/updated **first** (TDD) — unit, integration and/or functional
- [ ] `declare(strict_types=1);` in every new PHP file
- [ ] `composer cs:fix` run; `composer check` is green locally
- [ ] Still read-only: no command that changes a mailbox
- [ ] No password, DSN, address, subject or body in a log or an exception message
- [ ] No new dependency beyond PHP extensions and Symfony components
