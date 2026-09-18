<?php

declare(strict_types=1);

use Rasuvaeff\Yii3OutboxDb\Console\PurgeOutboxCommand;
use Rasuvaeff\Yii3OutboxDb\Console\ReleaseStaleOutboxClaimsCommand;
use Rasuvaeff\Yii3OutboxDb\Console\RequeueFailedOutboxCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'outbox:purge' => PurgeOutboxCommand::class,
            'outbox:release-stale' => ReleaseStaleOutboxClaimsCommand::class,
            'outbox:requeue' => RequeueFailedOutboxCommand::class,
        ],
    ],
    'rasuvaeff/yii3-outbox-db' => [
        // one source of truth: both DbOutboxStorage and the bundled migration
        // read the resulting name through OutboxTableName
        'table' => 'outbox',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        // delete an acknowledged row instead of keeping it as Published: no
        // deleteByStatus(Published) purge needed, no audit trail of what was sent
        'delete_published' => false,
        // throw when Outbox::record()/recordMany() writes a new row with no
        // transaction open on the connection — the bug the outbox pattern
        // cannot survive; costs one existence check per non-transactional
        // save(), so enable it in development and CI
        'require_transaction' => false,
    ],
];
