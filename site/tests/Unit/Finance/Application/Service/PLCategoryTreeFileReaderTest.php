<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Service;

use App\Finance\Application\Service\PLCategoryTreeExporter;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use PHPUnit\Framework\TestCase;

final class PLCategoryTreeFileReaderTest extends TestCase
{
    /**
     * Главная гарантия задачи: то, что выгрузила одна компания, читается как
     * источник импорта в другой — включая аккаунт, где нет ни одной общей
     * сущности. Если экспорт и читатель разойдутся хоть в одном поле, перенос
     * молча потеряет настройку строки P&L.
     */
    public function testRoundTripThroughFileKeepsEveryField(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($company)
            ->withName('Выручка')->withCode('REVENUE')->withFlow(PLFlow::INCOME)
            ->withExpenseType(PLExpenseType::VARIABLE)->build();
        $root->setType(PLCategoryType::SUBTOTAL);
        $root->setFormat(PLValueFormat::PERCENT);
        $root->setWeightInParent('-0.5000');
        $root->setIsVisible(false);
        $root->setFormula('REVENUE - COGS');
        $root->setCalcOrder(7);
        $root->setSortOrder(30);

        $child = PLCategoryBuilder::aPLCategory()->forCompany($company)
            ->withName('Маркетплейсы')->withParent($root)->build();
        $child->setSortOrder(40);

        $exporter = new PLCategoryTreeExporter();
        $exported = $exporter->fromEntities([$root, $child]);
        $json = json_encode($exporter->toFilePayload($exported, 'Ромашка', new \DateTimeImmutable('2026-08-05T10:00:00+03:00')), \JSON_THROW_ON_ERROR);

        $read = (new PLCategoryTreeFileReader())->read($json);

        self::assertCount(2, $read);

        foreach ([0, 1] as $i) {
            self::assertSame($exported[$i]->name, $read[$i]->name);
            self::assertSame($exported[$i]->code, $read[$i]->code);
            self::assertSame($exported[$i]->type, $read[$i]->type);
            self::assertSame($exported[$i]->format, $read[$i]->format);
            self::assertSame($exported[$i]->flow, $read[$i]->flow);
            self::assertSame($exported[$i]->expenseType, $read[$i]->expenseType);
            self::assertSame($exported[$i]->weightInParent, $read[$i]->weightInParent);
            self::assertSame($exported[$i]->isVisible, $read[$i]->isVisible);
            self::assertSame($exported[$i]->formula, $read[$i]->formula);
            self::assertSame($exported[$i]->calcOrder, $read[$i]->calcOrder);
            self::assertSame($exported[$i]->sortOrder, $read[$i]->sortOrder);
        }

        self::assertNull($read[0]->parent);
        self::assertSame($read[0], $read[1]->parent);
    }

    public function testRejectsCategoryMissingAnyFieldOfTheFormat(): void
    {
        // Неполная категория матчится с существующей по code, а импорт
        // перезаписывает поля целиком: отсутствующий flow сбросил бы INCOME в
        // NONE, а отсутствующий weightInParent превратил бы -0.5000 в 1.0000.
        // В предпросмотре это выглядело бы обычным «обновить».
        $full = $this->category('Расходы', ['code' => 'EXP']);

        foreach (array_keys($full) as $field) {
            if ('name' === $field) {
                continue;
            }

            $partial = $full;
            unset($partial[$field]);

            try {
                (new PLCategoryTreeFileReader())->read($this->file([$partial]));
                self::fail(sprintf('Категория без поля "%s" должна отвергаться', $field));
            } catch (\DomainException $e) {
                self::assertStringContainsString(sprintf('нет поля "%s"', $field), $e->getMessage());
            }
        }
    }

    public function testRejectsNullInNonNullableField(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Недопустимое значение "NULL" в поле "flow"/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['flow' => null])]));
    }

    public function testReadsFullCategory(): void
    {
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['code' => 'EXP', 'flow' => 'EXPENSE', 'sortOrder' => 10]),
        ]));

        self::assertCount(1, $read);
        self::assertSame('Расходы', $read[0]->name);
        self::assertSame('EXP', $read[0]->code);
        self::assertSame(PLCategoryType::LEAF_INPUT, $read[0]->type);
        self::assertSame(PLValueFormat::MONEY, $read[0]->format);
        self::assertSame(PLFlow::EXPENSE, $read[0]->flow);
        self::assertSame(PLExpenseType::OTHER, $read[0]->expenseType);
        self::assertSame('1.0000', $read[0]->weightInParent);
        self::assertTrue($read[0]->isVisible);
        self::assertNull($read[0]->formula);
        self::assertNull($read[0]->calcOrder);
        self::assertSame(10, $read[0]->sortOrder);
    }

    public function testKeepsNameExactlyAsInFileIncludingSurroundingSpaces(): void
    {
        // PLCategory::setName() имя не нормализует, экспорт пишет его как есть.
        // Обрезка пробелов здесь означала бы, что источник " Расходы" совпадёт
        // с целевой категорией "Расходы" по паре (родитель, имя) и молча
        // перезапишет её настройки вместо создания отдельной строки.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category(' Расходы'),
            $this->category('Выручка '),
        ]));

        self::assertSame(' Расходы', $read[0]->name);
        self::assertSame('Выручка ', $read[1]->name);
    }

    public function testKeepsEmptyFormulaAsEmptyString(): void
    {
        // setFormula() пустую строку в null не превращает: иначе первый же
        // импорт собственной выгрузки показывал бы «обновить» на ровном месте.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['formula' => '']),
        ]));

        self::assertSame('', $read[0]->formula);
    }

    public function testNormalizesCodeExactlyLikeEntitySetter(): void
    {
        // PLCategory::setCode() делает trim + mb_strtoupper. Если читатель
        // нормализует иначе, матчинг пойдёт по одному значению, а сохранится
        // другое — и повторный импорт того же файла перестанет быть
        // идемпотентным.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['code' => '  exp  ']),
            $this->category('Прочее', ['code' => '   ']),
        ]));

        self::assertSame('EXP', $read[0]->code);
        self::assertNull($read[1]->code);
    }

    public function testReadsFlatDfsPreOrderWithParentLinks(): void
    {
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['children' => [
                $this->category('Реклама', ['children' => [
                    $this->category('Ozon'),
                ]]),
                $this->category('Логистика'),
            ]]),
            $this->category('Выручка'),
        ]));

        self::assertSame(
            ['Расходы', 'Реклама', 'Ozon', 'Логистика', 'Выручка'],
            array_map(static fn ($node): string => $node->name, $read),
        );
        self::assertNull($read[0]->parent);
        self::assertSame($read[0], $read[1]->parent);
        self::assertSame($read[1], $read[2]->parent);
        self::assertSame($read[0], $read[3]->parent);
        self::assertNull($read[4]->parent);
    }

    public function testAcceptsBareListFromLegacyExport(): void
    {
        // Прежний эндпоинт выгрузки отдавал массив без конверта, и такие файлы
        // могли быть сохранены вручную.
        $read = (new PLCategoryTreeFileReader())->read((string) json_encode([$this->category('Расходы', ['code' => 'EXP'])], \JSON_THROW_ON_ERROR));

        self::assertCount(1, $read);
        self::assertSame('EXP', $read[0]->code);
    }

    public function testIgnoresUnknownKeysIncludingSourceIdentifiers(): void
    {
        // id и level из чужого аккаунта не должны ни использоваться, ни ломать
        // чтение: старые выгрузки их содержали.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['id' => '11111111-1111-1111-1111-000000000001', 'level' => 3, 'somethingNew' => 42]),
        ]));

        self::assertCount(1, $read);
        self::assertSame('Расходы', $read[0]->name);
    }

    public function testRejectsBrokenJson(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не является корректным JSON/');

        (new PLCategoryTreeFileReader())->read('{"version": 1, "categories": [');
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/пустой/');

        (new PLCategoryTreeFileReader())->read('   ');
    }

    public function testRejectsUnsupportedFormatVersion(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/версии 2/');

        (new PLCategoryTreeFileReader())->read('{"version": 2, "categories": []}');
    }

    public function testRejectsFileWithoutCategoriesSection(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/нет раздела "categories"/');

        (new PLCategoryTreeFileReader())->read('{"version": 1, "rows": []}');
    }

    public function testRejectsUnknownEnumValueAndListsAllowedOnes(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/INCOME, EXPENSE, NONE/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['flow' => 'WRONG'])]));
    }

    public function testRejectsDuplicateCodeAndNamesBothCategories(): void
    {
        // Дубль кода в файле — это либо потеря одной из категорий при матчинге,
        // либо нарушение uniq_plcat_company_code уже в середине транзакции.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/"Расходы".*"Выручка"/');

        (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['code' => 'EXP']),
            $this->category('Выручка', ['code' => 'exp']),
        ]));
    }

    public function testRejectsTreeDeeperThanFiveLevels(): void
    {
        $deepest = $this->category('Уровень 6');
        for ($level = 5; $level >= 1; --$level) {
            $deepest = $this->category('Уровень '.$level, ['children' => [$deepest]]);
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/вложенность \(5 уровней\)/');

        (new PLCategoryTreeFileReader())->read($this->file([$deepest]));
    }

    public function testAcceptsExactlyFiveLevels(): void
    {
        $node = $this->category('Уровень 5');
        for ($level = 4; $level >= 1; --$level) {
            $node = $this->category('Уровень '.$level, ['children' => [$node]]);
        }

        $read = (new PLCategoryTreeFileReader())->read($this->file([$node]));

        self::assertCount(5, $read);
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/не заполнено название/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('   ')]));
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/длиннее 255 символов/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category(str_repeat('я', 256))]));
    }

    public function testRejectsNonNumericWeight(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Вес категории "Расходы"/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['weightInParent' => 'много'])]));
    }

    public function testRejectsWeightOutsideColumnRange(): void
    {
        // decimal(10,4): значение за пределом обрубилось бы в БД молча и
        // изменило бы суммирование родителя.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/вне допустимого диапазона/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['weightInParent' => '1000000'])]));
    }

    public function testNormalizesWeightToFourDecimals(): void
    {
        $read = (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['weightInParent' => 0.5])]));

        self::assertSame('0.5000', $read[0]->weightInParent);
    }

    public function testRejectsNonBooleanVisibility(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/должно быть true или false/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['isVisible' => 'yes'])]));
    }

    public function testRejectsNonIntegerSortOrder(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/должно быть целым числом/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['sortOrder' => '10'])]));
    }

    public function testRejectsNonListChildren(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/"children".*должно быть списком/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['children' => ['a' => $this->category('Реклама')]])]));
    }

    public function testRejectsOversizedFile(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/слишком большой/');

        (new PLCategoryTreeFileReader())->read(str_repeat('x', 1_048_577));
    }

    public function testRejectsTooManyCategories(): void
    {
        $rows = [];
        for ($i = 0; $i <= 1000; ++$i) {
            $rows[] = $this->category('Категория '.$i);
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/больше 1000 категорий/');

        (new PLCategoryTreeFileReader())->read($this->file($rows));
    }

    public function testErrorMessageCarriesFullPathOfBrokenNode(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Расходы \/ Реклама/');

        (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['children' => [
                $this->category('Реклама', ['flow' => 'WRONG']),
            ]]),
        ]));
    }

    public function testRejectsNullByteInStrings(): void
    {
        // PostgreSQL не хранит нулевой байт в varchar/text, а JSON его несёт.
        // Иначе гарантия «всё проверено до импорта» ломается ошибкой БД на
        // середине транзакции.
        foreach ([$this->category("Рас\u{0}ходы"), $this->category('Расходы', ['code' => "E\u{0}XP"]), $this->category('Расходы', ['formula' => "A\u{0}B"])] as $row) {
            try {
                (new PLCategoryTreeFileReader())->read($this->file([$row]));
                self::fail('Нулевой байт должен отвергаться: '.json_encode($row));
            } catch (\DomainException $e) {
                self::assertStringContainsString('Недопустимый символ', $e->getMessage());
            }
        }
    }

    public function testRejectsIntegerOutsideColumnRange(): void
    {
        // 2147483648 — валидный int в PHP, но не в PostgreSQL integer.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/"sortOrder".*вне допустимого диапазона/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['sortOrder' => 2147483648])]));
    }

    public function testRejectsWeightThatOverflowsColumnOnlyAfterRounding(): void
    {
        // 999999.99999 меньше миллиона, но округляется в 1000000.0000, которое
        // в decimal(10,4) уже не влезает.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/вне допустимого диапазона/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['weightInParent' => '999999.99999'])]));
    }

    public function testRejectsInfiniteWeight(): void
    {
        // is_numeric('1e9999') === true, во float это INF, number_format() даёт
        // строку "inf", а обратный (float) "inf" — 0.0: без явной проверки
        // конечности бесконечность проскочила бы в decimal(10,4).
        foreach (['1e9999', '-1e9999'] as $value) {
            try {
                (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['weightInParent' => $value])]));
                self::fail(sprintf('Вес %s должен отвергаться', $value));
            } catch (\DomainException $e) {
                self::assertStringContainsString('вне допустимого диапазона', $e->getMessage());
            }
        }
    }

    public function testAcceptsBothLimitsOfPostgresInteger(): void
    {
        // Границы PostgreSQL integer несимметричны. Отвергать -2147483648
        // означало бы не уметь импортировать собственную же выгрузку.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Минимум', ['code' => 'MIN', 'sortOrder' => -2147483648, 'calcOrder' => -2147483648]),
            $this->category('Максимум', ['code' => 'MAX', 'sortOrder' => 2147483647, 'calcOrder' => 2147483647]),
        ]));

        self::assertSame(-2147483648, $read[0]->sortOrder);
        self::assertSame(-2147483648, $read[0]->calcOrder);
        self::assertSame(2147483647, $read[1]->sortOrder);
        self::assertSame(2147483647, $read[1]->calcOrder);
    }

    public function testRejectsIntegerBelowColumnMinimum(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/"sortOrder".*вне допустимого диапазона/');

        (new PLCategoryTreeFileReader())->read($this->file([$this->category('Расходы', ['sortOrder' => -2147483649])]));
    }

    public function testRejectsAmbiguousSiblingsWithoutCode(): void
    {
        // Узел без code опознаётся парой (родитель, имя): два одноимённых
        // соседа неотличимы, и повторный импорт того же файла плодил бы дубли
        // вместо обновления.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/встречается дважды среди соседних/');

        (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['children' => [
                $this->category('Реклама'),
                $this->category('Реклама'),
            ]]),
        ]));
    }

    public function testRejectsSiblingsWithSameNameWhenOnlyOneHasCode(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/встречается дважды среди соседних/');

        (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Реклама', ['code' => 'ADS']),
            $this->category('Реклама'),
        ]));
    }

    public function testAllowsSameNameUnderDifferentParents(): void
    {
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Расходы', ['children' => [$this->category('Реклама')]]),
            $this->category('Выручка', ['children' => [$this->category('Реклама')]]),
        ]));

        self::assertCount(4, $read);
    }

    public function testAllowsSameSiblingNameWhenBothHaveDistinctCodes(): void
    {
        // Оба узла опознаются по code, неоднозначности нет.
        $read = (new PLCategoryTreeFileReader())->read($this->file([
            $this->category('Реклама', ['code' => 'ADS_OZON']),
            $this->category('Реклама', ['code' => 'ADS_WB']),
        ]));

        self::assertCount(2, $read);
    }

    /**
     * Категория со всеми полями формата v1. Тесты правят только то, что
     * проверяют, а не собирают файл вручную.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function category(string $name, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'code' => null,
            'type' => 'LEAF_INPUT',
            'format' => 'MONEY',
            'flow' => 'NONE',
            'expenseType' => 'other',
            'weightInParent' => '1.0000',
            'isVisible' => true,
            'formula' => null,
            'calcOrder' => null,
            'sortOrder' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $categories
     */
    private function file(array $categories): string
    {
        return json_encode([
            'version' => 1,
            'exportedAt' => '2026-08-05T10:00:00+03:00',
            'company' => 'Ромашка',
            'categories' => $categories,
        ], \JSON_THROW_ON_ERROR);
    }
}
