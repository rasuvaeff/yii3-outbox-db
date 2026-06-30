# Changelog

## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-06-12

- `DbOutboxStorage` — `StorageInterface` backed by `yiisoft/db`: `save` (upsert by id), `findPending(array $types, int $limit)` (status + optional type filter, ordered by `created_at`), `markPublished`, `markFailed`, `getById`, plus `deleteByStatus` for housekeeping.
- `OutboxRowMapper` — maps DB rows to `OutboxMessage`, validating status, datetimes and integer columns; throws `InvalidOutboxRowException` on corrupt rows.
- `migrations/M260611000000CreateOutboxTable` — `outbox` table (MergeTree-agnostic SQL) with the `idx_outbox_status_type` index backing the pending poll.
- Yii3 config-plugin: binds `StorageInterface` from `config/di.php`; table name in `config/params.php`.
