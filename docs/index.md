---
layout: home

hero:
  name: cakephp-audit-stash
  text: Audit Trail for CakePHP
  tagline: Tamper-evident entity logging, admin viewer, monitoring, retention, and GDPR tooling.
  image:
    src: /logo.svg
    alt: cakephp-audit-stash
  actions:
    - theme: brand
      text: Installation
      link: /guide/installation
    - theme: alt
      text: Getting started
      link: /guide/
    - theme: alt
      text: Features
      link: /features/
    - theme: alt
      text: Live Demo
      link: https://sandbox.dereuromark.de/sandbox/audit-stash
    - theme: alt
      text: View on GitHub
      link: https://github.com/dereuromark/cakephp-audit-stash

features:
  - icon: 📜
    title: Entity Audit Trail
    details: Track create, update, and delete on any Table via a single behavior. Captures changed fields, before/after values, user, and request context.
  - icon: 🔐
    title: Tamper-Evidence
    details: Optional SHA-256 hash chain over audit rows. Verify stored links with the CLI and read the documented limits.
  - icon: 🖥️
    title: Admin Viewer
    details: Built-in dashboard, coverage report, search, before/after diffs, timeline view, and CSV/JSON export.
  - icon: 🚨
    title: Monitoring & Alerting
    details: Detect mass deletions, off-hours activity, and custom rule violations. Notify via email, webhook, or log channels.
  - icon: 🧹
    title: Retention & GDPR
    details: Per-table retention policies, dry-run cleanup CLI, and GDPR helpers for redaction and subject-access export.
  - icon: 🗄️
    title: Flexible Storage
    details: Database persister out of the box. Optional Elasticsearch driver for high-volume installs, or plug in your own.
---
