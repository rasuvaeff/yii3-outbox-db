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

## 1.x → 2.0

Only for installations still on 1.x. Do these steps first, then the section
above.

The bundled migration moved into the package namespace:

```
M260611000000CreateOutboxTable
→ Rasuvaeff\Yii3OutboxDb\Migration\M260611000000CreateOutboxTable
```

`yiisoft/db-migration` stores the applied migration's class name verbatim in the
`migration` table. Without the two steps below, `migrate:up` sees the namespaced
class as a *new* migration and fails with "table already exists".

### 1. Rewrite the applied migration's name

```sql
UPDATE migration
SET name = 'Rasuvaeff\\Yii3OutboxDb\\Migration\\M260611000000CreateOutboxTable'
WHERE name = 'M260611000000CreateOutboxTable';
```

Run this **before** the first `migrate:up` on 2.0. If you have never applied the
migration, skip it — there is nothing to rename.

### 2. Register by namespace instead of by path

```diff
 MigrationService::class => [
-    'setSourcePaths()' => [[__DIR__ . '/../vendor/rasuvaeff/yii3-outbox-db/migrations']],
+    'setSourceNamespaces()' => [['Rasuvaeff\\Yii3OutboxDb\\Migration']],
 ],
```

The path form no longer resolves: `migrations/` is gone and the class lives
under `src/Migration/`, autoloaded via PSR-4.

### 3. Remove any DI definition of the migration

```diff
-M260611000000CreateOutboxTable::class => [
-    '__construct()' => ['table' => 'my_table'],
-],
```

That recipe was documented in 1.x and **never worked** — the migration is built
by `Injector::make()`, which resolves arguments by type and ignores container
definitions keyed by the migration's class, so the definition has no effect
whatsoever. (In 1.x it was worse than inert: the class lived outside PSR-4 and
the definition took the container down at build time. Since 2.0 the migration
autoloads normally, so a leftover definition is merely dead configuration.)
`MigrationService::setSourceNamespaces()`, shown in step 2, is the supported
entry point.

Set the table name in params instead; the same value now reaches the migration
and `DbOutboxStorage`:

```php
'rasuvaeff/yii3-outbox-db' => [
    'table' => 'my_table',
    'table_prefix' => '',
],
```

### Defaults are unchanged

The default table and index names are exactly what 1.x produced, so 2.0 itself
needed no schema migration — only the `migration` table row above. The
`claimed_at` column arrives with the release covered by the first section.
