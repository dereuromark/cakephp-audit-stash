# AuditStash Plugin For CakePHP

[![Build Status](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml/badge.svg)](https://github.com/dereuromark/cakephp-audit-stash/actions/workflows/ci.yml)
[![Coverage Status](https://img.shields.io/codecov/c/github/dereuromark/cakephp-audit-stash/master.svg?style=flat-square)](https://codecov.io/github/dereuromark/cakephp-audit-stash)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg?style=flat)](https://phpstan.org/)
[![Latest Stable Version](https://poser.pugx.org/dereuromark/cakephp-audit-stash/v/stable.svg)](https://packagist.org/packages/dereuromark/cakephp-audit-stash)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.2-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://poser.pugx.org/dereuromark/cakephp-audit-stash/d/total.svg)](https://packagist.org/packages/dereuromark/cakephp-audit-stash)
[![Coding Standards](https://img.shields.io/badge/cs-PhpCollective-purple.svg?style=flat-square)](https://github.com/php-collective/code-sniffer)

This branch is for **CakePHP 5.3+**. See [version map](https://github.com/dereuromark/cakephp-audit-stash/wiki#cakephp-version-map) for details.

Audit-trail plugin: records every create / update / delete on your Table classes, together with who made the change and from which request.

## Features

- **Entity audit trail** — single behavior captures changed fields, before/after values, user, and request context.
- **Tamper-evidence** — optional SHA-256 hash chain for GoBD / SOX / HIPAA-grade integrity, verifiable via CLI.
- **Admin viewer** — built-in dashboard, coverage report, search, diffs, timeline, and CSV/JSON export under `/admin/audit-stash`.
- **Monitoring & alerting** — rules for mass-deletion and off-hours activity, notifications via email, webhook, or log channel.
- **Retention & cleanup** — per-table retention policies and a dry-run-friendly cleanup CLI.
- **GDPR helpers** — redaction and subject-access-export tooling.
- **Custom event types** — log arbitrary actions (logins, exports, permission grants) through the same persister and viewer.
- **Flexible storage** — database persister out of the box, optional Elasticsearch driver, or plug in your own.

## Installation

```bash
composer require dereuromark/cakephp-audit-stash
bin/cake plugin load AuditStash
bin/cake migrations migrate -p AuditStash
```

Then enable the behavior on any Table you want tracked — see the [Getting Started guide](https://dereuromark.github.io/cakephp-audit-stash/guide/) for the full walkthrough.

## Documentation

Full docs: **<https://dereuromark.github.io/cakephp-audit-stash/>**

- [Getting Started](https://dereuromark.github.io/cakephp-audit-stash/guide/) — installation, behavior setup, request metadata
- [Configuration](https://dereuromark.github.io/cakephp-audit-stash/guide/configuration) — persisters, table list, route prefix
- [Usage](https://dereuromark.github.io/cakephp-audit-stash/guide/usage) — behavior options, custom events, custom persisters
- [Testing](https://dereuromark.github.io/cakephp-audit-stash/guide/testing) — `AuditAssertionsTrait` for your own test suite
- [Features overview](https://dereuromark.github.io/cakephp-audit-stash/features/) — viewer, monitoring, retention, tamper-evidence, GDPR

## Demo

<https://sandbox.dereuromark.de/sandbox/audit-stash>

## Related Plugins

If you need to moderate or approve changes **before** they happen (rather than auditing them after), check out the [Bouncer plugin](https://github.com/dereuromark/cakephp-bouncer). AuditStash records what already changed; Bouncer gates changes before they're persisted.
