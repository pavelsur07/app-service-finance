<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Гейт синхронности схемы: БД, накатанная миграциями (CI и локальные тесты собирают её только так),
 * совпадает со схемой из маппинга Doctrine и SchemaExtension модулей.
 *
 * Без него `doctrine:migrations:diff` копит ложные расхождения, и сгенерированная миграция
 * удаляет живые таблицы, внешние ключи и частичные уникальные индексы (на 03.11.2026 их было 409 строк).
 *
 * Что делать при падении:
 * - новая сущность или колонка без миграции → написать миграцию;
 * - объект есть только в миграции (DBAL-таблица, ключ на `string $companyId`, частичный индекс) →
 *   описать в `{Module}/Infrastructure/Doctrine/*SchemaExtension` ({@see \App\Shared\Infrastructure\Doctrine\Schema\SchemaExtension});
 * - атрибут расходится с миграцией (default, длина, jsonb, имя индекса) → поправить атрибут под БД.
 *
 * Исключений нет (с этапа 3 задачи docs/tasks/doctrine-schema-sync): расхождение — это либо
 * миграция, либо описание в маппинге, не запись в allowlist.
 */
final class SchemaInSyncWithMigrationsTest extends KernelTestCase
{
    /**
     * Служебная таблица doctrine/migrations: в консоли её скрывает фильтр бандла миграций,
     * в тестовом ядре фильтра нет. Не расхождение.
     */
    private const MIGRATIONS_TABLE = 'DROP TABLE doctrine_migration_versions';

    public function testMigratedDatabaseMatchesMapping(): void
    {
        self::assertSame([], $this->updateSchemaSql(), 'Схема из маппинга расходится с БД после миграций. Что делать — в docblock теста.');
    }

    /** @return list<string> */
    private function updateSchemaSql(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Тестовые фикстуры (tests/Fixtures/Doctrine) создают свои таблицы сами.
        $metadata = array_values(array_filter(
            $em->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $meta): bool => !str_starts_with($meta->getName(), 'App\\Tests\\'),
        ));

        return array_values(array_filter(
            (new SchemaTool($em))->getUpdateSchemaSql($metadata),
            static fn (string $sql): bool => self::MIGRATIONS_TABLE !== $sql,
        ));
    }
}
