<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Outbox\StorageInterface;
use Rasuvaeff\Yii3OutboxDb\DbOutboxStorage;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    StorageInterface::class => static function (ConnectionInterface $db) use ($params): DbOutboxStorage {
        $config = $params['rasuvaeff/yii3-outbox-db'] ?? [];

        return new DbOutboxStorage(
            db: $db,
            table: $config['table'] ?? 'outbox',
        );
    },
];
