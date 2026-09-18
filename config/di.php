<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    // the migration resolves this by type through Injector::make(), so the
    // storage and the migration can never disagree about the table
    OutboxTableName::class => static function () use ($params): OutboxTableName {
        $config = $params['rasuvaeff/yii3-outbox-db'] ?? [];

        return new OutboxTableName(
            ((string) ($config['table_prefix'] ?? '')) . ((string) ($config['table'] ?? 'outbox')),
        );
    },
    StorageInterface::class => static function (
        ConnectionInterface $db,
        OutboxTableName $table,
    ) use ($params): DbOutboxStorage {
        $config = $params['rasuvaeff/yii3-outbox-db'] ?? [];

        return new DbOutboxStorage(
            db: $db,
            table: $table->value,
            deletePublished: (bool) ($config['delete_published'] ?? false),
            requireTransaction: (bool) ($config['require_transaction'] ?? false),
        );
    },
];
