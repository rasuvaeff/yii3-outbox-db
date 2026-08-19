# Upgrade guide

## To the release that adds stale-claim recovery

This release adds the `claimed_at` column and the API that uses it. `claim()`
writes that column on every call, so the schema change is **mandatory** — not
optional like a new index would be.

### Run the migration before deploying the new code

```bash
./yii migrate:up
```

`M260820000000AddOutboxClaimedAt` adds `claimed_at` and the
`idx_<table>_processing` index. It is additive: no data is rewritten, and
workers running the previous version keep working against the migrated table
(they simply never write the new column).

The reverse order breaks the outbox: a worker on the new code claiming against
an unmigrated table fails with `table outbox has no column named claimed_at` on
**every** claim, and `Outbox::record()` — which runs inside your business
transaction — is unaffected only because it does not touch that column. So:

1. deploy the migration and run it;
2. then deploy the workers.

If you upgrade in the opposite order, run the migration; nothing is lost, the
claims simply fail until the column exists.

### The first recovery run releases every pre-existing stuck row

Rows already sitting in `Processing` from before the upgrade have no
`claimed_at`. `findStaleClaims()` and `releaseStaleClaims()` treat a NULL
timestamp as stale, so the first call releases all of them back to `Pending`.

That is intended — those rows were left behind by a worker that no longer
exists, and until now nothing could return them. Just be aware of what the
first run does before you schedule it: if any of those messages were in fact
delivered by a worker that died after publishing but before `markPublished()`,
releasing them delivers those messages again. At-least-once delivery already
requires the receiver to deduplicate on the message id; this is the moment that
requirement is exercised.

To look before you leap:

```php
$stuck = $storage->findStaleClaims($clock->now()->modify('-15 minutes'));
```

### Rolling back

`M260820000000AddOutboxClaimedAt::down()` works on MySQL and PostgreSQL.
`yiisoft/db-sqlite` cannot drop a column, so on SQLite the rollback throws
`NotSupportedException` — recreate the table instead.
