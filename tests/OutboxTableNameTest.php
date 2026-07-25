<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3OutboxDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3OutboxDb\OutboxTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(OutboxTableName::class)]
final class OutboxTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new OutboxTableName())->value, 'outbox');
        Assert::same((string) new OutboxTableName(), 'outbox');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new OutboxTableName('public.outbox'))->value, 'public.outbox');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new OutboxTableName('public.outbox'))->forIndexName(), 'public_outbox');
        Assert::same((new OutboxTableName('outbox'))->forIndexName(), 'outbox');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new OutboxTableName($name);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'space' => ['my table'];
        yield 'semicolon injection' => ['t; DROP TABLE users'];
        yield 'dash' => ['my-table'];
        yield 'two dots' => ['a.b.c'];
        // PCRE's $ also matches before a trailing newline — the pattern is
        // anchored with \z so this is rejected
        yield 'trailing newline' => ["outbox\n"];
    }
}
