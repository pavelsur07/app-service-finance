<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Doctrine\MoneyAmountType;
use App\Shared\Infrastructure\Doctrine\StringBigIntType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Гейт на типы колонок, которые ведут себя по-разному в DBAL 3 и DBAL 4.
 *
 * - Mutable date-типы (`datetime`, `date`, …): DBAL 4 принимает в них только
 *   `\DateTime` и бросает InvalidType на `\DateTimeImmutable`, который в проекте
 *   пишется везде. Cash `updatedAt` на этом уже ловился (C1, #2614).
 * - Штатный `bigint`: DBAL 3 гидрирует строку, DBAL 4 — `int`. Для `string`-свойства
 *   это лишний UPDATE на каждом flush, для `int`-свойства на DBAL 3 — TypeError.
 *   Тип выбирается явно: `bigint_string` или `money_amount_minor`.
 *
 * Проверяются метаданные всех сущностей, включая поля встроенных embeddable
 * (у владельца, через точку) — БД не нужна.
 */
final class DoctrineMappingTypesTest extends KernelTestCase
{
    private const MUTABLE_DATE_TYPES = [
        Types::DATE_MUTABLE,
        Types::DATETIME_MUTABLE,
        Types::DATETIMETZ_MUTABLE,
        Types::TIME_MUTABLE,
    ];

    public function testNoFieldUsesMutableDateTypes(): void
    {
        $violations = $this->fieldsWhere(static fn (string $type): bool => in_array($type, self::MUTABLE_DATE_TYPES, true));

        self::assertSame([], $violations, 'Mutable date-тип: DBAL 4 бросит InvalidType на DateTimeImmutable. Нужен *_immutable.');
    }

    public function testNoFieldUsesPlainBigint(): void
    {
        $violations = $this->fieldsWhere(static fn (string $type): bool => Types::BIGINT === $type);

        self::assertSame([], $violations, sprintf(
            'Штатный bigint гидрируется по-разному в DBAL 3 и 4. Для string-свойства — %s, для int — %s.',
            StringBigIntType::NAME,
            MoneyAmountType::NAME,
        ));
    }

    public function testBigintStringFieldsAreStringProperties(): void
    {
        [$checked, $violations] = $this->propertiesOfTypeNotDeclaredAs(StringBigIntType::NAME, 'string');

        self::assertGreaterThan(0, $checked, 'Ни одного поля bigint_string — гейт ничего не проверил.');
        self::assertSame([], $violations, 'bigint_string гидрирует строку: свойство обязано быть string|?string.');
    }

    public function testMoneyAmountMinorFieldsAreIntProperties(): void
    {
        [$checked, $violations] = $this->propertiesOfTypeNotDeclaredAs(MoneyAmountType::NAME, 'int');

        self::assertGreaterThan(0, $checked, 'Ни одного поля money_amount_minor — гейт ничего не проверил.');
        self::assertSame([], $violations, 'money_amount_minor гидрирует int: свойство обязано быть int|?int.');
    }

    /**
     * Поля embeddable приходят у владельца с именем через точку; свойство ищется в
     * исходном классе (originalClass/originalField), иначе Money и подобные выпали бы.
     *
     * @return array{int, list<string>}
     */
    private function propertiesOfTypeNotDeclaredAs(string $doctrineType, string $phpType): array
    {
        $checked = 0;
        $violations = [];
        foreach ($this->allMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $field => $mapping) {
                if ($doctrineType !== $mapping->type) {
                    continue;
                }
                ++$checked;
                $property = new \ReflectionProperty($mapping->originalClass ?? $metadata->getName(), $mapping->originalField ?? $field);
                $type = $property->getType();
                if (!$type instanceof \ReflectionNamedType || $phpType !== $type->getName()) {
                    $violations[] = sprintf('%s::$%s (%s)', $metadata->getName(), $field, (string) $type);
                }
            }
        }

        return [$checked, $violations];
    }

    /**
     * @param callable(string): bool $isViolation
     *
     * @return list<string>
     */
    private function fieldsWhere(callable $isViolation): array
    {
        $violations = [];
        foreach ($this->allMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $field => $mapping) {
                if ($isViolation($mapping->type)) {
                    $violations[] = sprintf('%s::$%s (%s)', $metadata->getName(), $field, $mapping->type);
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function allMetadata(): array
    {
        self::bootKernel();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        self::assertGreaterThan(100, count($metadata), 'Метаданные сущностей не загрузились.');

        return $metadata;
    }
}
