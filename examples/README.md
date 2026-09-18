# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | record → durable persist → type-filtered poll → markPublished → stale-claim recovery → `stats()` / `requeue()` / bounded `deleteByStatus()` with SQLite | No |

## Running

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
