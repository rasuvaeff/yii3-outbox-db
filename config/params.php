<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-outbox-db' => [
        // one source of truth: both DbOutboxStorage and the bundled migration
        // read the resulting name through OutboxTableName
        'table' => 'outbox',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
    ],
];
