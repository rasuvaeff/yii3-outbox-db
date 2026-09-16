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
        // delete an acknowledged row instead of keeping it as Published: no
        // deleteByStatus(Published) purge needed, no audit trail of what was sent
        'delete_published' => false,
    ],
];
