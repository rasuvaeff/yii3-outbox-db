<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Exception;

/**
 * A new outbox row was about to be written with no transaction open on the
 * connection — the write that the outbox pattern requires to commit together
 * with the business row. Thrown only when {@see \Rasuvaeff\Yii3OutboxDb\DbOutboxStorage}
 * was built with `requireTransaction: true`.
 *
 * @api
 */
final class OutboxWriteOutsideTransactionException extends \LogicException {}
