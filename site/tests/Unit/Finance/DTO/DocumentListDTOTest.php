<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\DTO;

use App\Finance\DTO\DocumentListDTO;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentListDTOTest extends TestCase
{
    private const PROJECT_ID = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

    public function testWithoutParametersNoFilterIsSet(): void
    {
        $dto = $this->dto();

        self::assertFalse($dto->hasFilters());
        self::assertNull($dto->q);
        self::assertNull($dto->projectDirectionId);
        self::assertNull($dto->responsibilityCenterId);
        self::assertNull($dto->direction);
        self::assertNull($dto->categoryId);
        self::assertNull($dto->amountFrom);
        self::assertNull($dto->amountTo);
    }

    public function testSearchIsTrimmedAndKeptVerbatim(): void
    {
        // Спецсимволы LIKE экранирует репозиторий; DTO их не трогает, иначе «50%» не найти.
        $dto = $this->dto(q: '  50%_\\ скидка  ');

        self::assertSame('50%_\\ скидка', $dto->q);
        self::assertTrue($dto->hasFilters());
    }

    public function testBlankSearchMeansNoFilter(): void
    {
        self::assertNull($this->dto(q: "  \t ")->q);
    }

    public function testUuidFiltersAreLowercased(): void
    {
        $dto = $this->dto(projectDirectionId: strtoupper(self::PROJECT_ID), responsibilityCenterId: ' '.self::PROJECT_ID.' ');

        self::assertSame(self::PROJECT_ID, $dto->projectDirectionId);
        self::assertSame(self::PROJECT_ID, $dto->responsibilityCenterId);
    }

    #[DataProvider('garbageUuids')]
    public function testGarbageUuidMeansNoFilter(string $value): void
    {
        $dto = $this->dto(projectDirectionId: $value, responsibilityCenterId: $value);

        self::assertNull($dto->projectDirectionId);
        self::assertNull($dto->responsibilityCenterId);
        self::assertFalse($dto->hasFilters());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbageUuids(): iterable
    {
        yield 'не uuid' => ['project-1'];
        yield 'обрезанный' => [substr(self::PROJECT_ID, 0, 30)];
        yield 'sql' => ["' OR 1=1 --"];
    }

    public function testDirectionAcceptsOnlyIncomeAndExpense(): void
    {
        self::assertSame(PLFlow::INCOME, $this->dto(direction: 'income')->direction);
        self::assertSame(PLFlow::EXPENSE, $this->dto(direction: 'expense')->direction);
        // NONE — не направление списка: статья «без потока» не доход и не расход.
        self::assertNull($this->dto(direction: 'none')->direction);
        self::assertNull($this->dto(direction: 'INCOME')->direction);
        self::assertNull($this->dto(direction: 'all')->direction);
    }

    public function testCategoryIsUuidOnly(): void
    {
        self::assertSame(self::PROJECT_ID, $this->dto(categoryId: strtoupper(self::PROJECT_ID))->categoryId);
        self::assertNull($this->dto(categoryId: 'revenue')->categoryId);
        self::assertTrue($this->dto(categoryId: self::PROJECT_ID)->hasFilters());
    }

    #[DataProvider('amounts')]
    public function testAmountBoundIsNormalized(string $raw, ?string $expected): void
    {
        $dto = $this->dto(amountFrom: $raw, amountTo: $raw);

        self::assertSame($expected, $dto->amountFrom);
        self::assertSame($expected, $dto->amountTo);
        self::assertSame(null !== $expected, $dto->hasFilters());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function amounts(): iterable
    {
        yield 'целое' => ['1500', '1500.00'];
        yield 'разряды и запятая' => ['1 000,5', '1000.50'];
        yield 'неразрывный пробел' => ["12\u{00A0}345.67", '12345.67'];
        yield 'ноль' => ['0', '0.00'];
        yield 'минус' => ['-5', null];
        yield 'три знака' => ['1.005', null];
        yield 'буквы' => ['abc', null];
        yield 'пусто' => ['  ', null];
        yield 'больше колонки' => ['12345678901234', null];
    }

    public function testSwappedAmountBoundsAreReordered(): void
    {
        $dto = $this->dto(amountFrom: '500', amountTo: '100,5');

        self::assertSame('100.50', $dto->amountFrom);
        self::assertSame('500.00', $dto->amountTo);
    }

    public function testGarbageDateIsIgnoredButValidBoundaryDateIsKept(): void
    {
        $dto = $this->dto(dateFrom: '2024-02-30', dateTo: '2024-02-29');

        self::assertNull($dto->dateFrom);
        self::assertSame('2024-02-29 23:59:59', $dto->dateTo?->format('Y-m-d H:i:s'));
        self::assertTrue($dto->hasFilters());
    }

    private function dto(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
        ?string $categoryId = null,
        ?string $amountFrom = null,
        ?string $amountTo = null,
    ): DocumentListDTO {
        return new DocumentListDTO(
            CompanyBuilder::aCompany()->build(),
            1,
            20,
            $dateFrom,
            $dateTo,
            q: $q,
            projectDirectionId: $projectDirectionId,
            responsibilityCenterId: $responsibilityCenterId,
            direction: $direction,
            categoryId: $categoryId,
            amountFrom: $amountFrom,
            amountTo: $amountTo,
        );
    }
}
