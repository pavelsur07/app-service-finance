<?php

declare(strict_types=1);

namespace App\Finance\Report;

use App\Company\Entity\Company;
use App\Finance\Application\DTO\PlPreviewColumn;
use App\Finance\Application\DTO\PlPreviewResult;
use App\Finance\Application\DTO\PlPreviewRow;
use App\Finance\Application\DTO\PlPreviewUnplacedAmount;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PlNature;
use App\Finance\Enum\PlPreviewUnplacedReason;
use App\Finance\Facts\InMemoryFactsProvider;
use App\Finance\Repository\PLCategoryRepository;

/**
 * Дерево ОПиУ по колонкам из сумм, которые ещё не проведены: что покажет отчёт, если провести
 * их документом marketplace_pl. Ничего не пишет.
 *
 * Сумма статьи переводится в факт так же, как PLRegisterUpdater для marketplace_pl: модуль итога
 * со знаком, доход или расход — по flow статьи, у статьи без flow — по знаку суммы. Регистр берёт
 * модуль в корзине «день документа × проект», здесь — по колонке за весь период.
 */
final class PlPreviewCalculator
{
    public function __construct(
        private readonly PLCategoryRepository $categories,
        private readonly PlReportCalculator $calculator,
    ) {
    }

    /**
     * @param list<PlPreviewColumn> $columns
     * @param string|null           $natureBaseColumn колонка-итог: модуль и знак статьи берутся по её
     *                                                сумме и одинаково применяются ко всем колонкам —
     *                                                колонки-доли складываются в итог; null — каждая
     *                                                колонка как отдельный документ
     */
    public function calculate(Company $company, PlReportPeriod $period, array $columns, ?string $natureBaseColumn = null): PlPreviewResult
    {
        if ([] === $columns) {
            throw new \InvalidArgumentException('Предпросмотр ОПиУ требует хотя бы одну колонку.');
        }

        // Регистр берёт модуль итога статьи. Колонки-доли (теги, «Общие») не должны получать
        // свой модуль: доля с обратным знаком к итогу превратилась бы в ещё один расход.
        /** @var array<string, string>|null $baseAmounts */
        $baseAmounts = null;
        if (null !== $natureBaseColumn) {
            foreach ($columns as $column) {
                if ($column->key === $natureBaseColumn) {
                    $baseAmounts = $column->amountsByCategoryId;
                }
            }
            if (null === $baseAmounts) {
                throw new \InvalidArgumentException(sprintf('Колонки-итога «%s» нет среди колонок.', $natureBaseColumn));
            }
        }

        /** @var list<PLCategory> $categories */
        $categories = $this->categories->findBy(['company' => $company]);
        $categoriesById = [];
        foreach ($categories as $category) {
            $categoriesById[(string) $category->getId()] = $category;
        }

        /** @var array<string, array{name: ?string, reason: PlPreviewUnplacedReason, values: array<string, string>}> $unplaced */
        $unplaced = [];
        /** @var array<string, PlComputedRow> $rowShapes */
        $rowShapes = [];
        /** @var array<string, array<string, float>> $valuesByRow */
        $valuesByRow = [];
        $warnings = [];
        $seenKeys = [];

        foreach ($columns as $column) {
            if (isset($seenKeys[$column->key])) {
                throw new \InvalidArgumentException(sprintf('Колонка «%s» передана дважды.', $column->key));
            }
            $seenKeys[$column->key] = true;

            $facts = [];
            foreach ($column->amountsByCategoryId as $categoryId => $amount) {
                $categoryId = (string) $categoryId;
                $category = $categoriesById[$categoryId] ?? null;
                $reason = self::unplacedReason($category);

                if (null !== $reason) {
                    $unplaced[$categoryId] ??= ['name' => $category?->getName(), 'reason' => $reason, 'values' => []];
                    $unplaced[$categoryId]['values'][$column->key] = bcadd($unplaced[$categoryId]['values'][$column->key] ?? '0', $amount, 2);
                    continue;
                }

                /** @var PLCategory $category */
                $code = (string) $category->getCode();
                $facts[$code] = ($facts[$code] ?? 0.0) + self::factValue($category, $amount, $baseAmounts[$categoryId] ?? null, null !== $baseAmounts);
            }

            $result = $this->calculator->withFacts(new InMemoryFactsProvider($facts))->calculate($company, $period);
            foreach ($result->rows as $row) {
                $rowShapes[$row->id] ??= $row;
                $valuesByRow[$row->id][$column->key] = $row->rawValue;
            }
            array_push($warnings, ...$result->warnings);
        }

        $rows = [];
        foreach ($rowShapes as $id => $shape) {
            $rows[] = new PlPreviewRow(
                id: $id,
                code: $shape->code,
                name: $shape->name,
                level: $shape->level,
                type: PLCategoryType::from($shape->type),
                format: $shape->format,
                values: $valuesByRow[$id],
            );
        }

        $unplacedAmounts = [];
        foreach ($unplaced as $categoryId => $item) {
            $unplacedAmounts[] = new PlPreviewUnplacedAmount($categoryId, $item['name'], $item['reason'], $item['values']);
        }

        return new PlPreviewResult($rows, $unplacedAmounts, array_values(array_unique($warnings)));
    }

    private static function unplacedReason(?PLCategory $category): ?PlPreviewUnplacedReason
    {
        return match (true) {
            null === $category => PlPreviewUnplacedReason::CATEGORY_NOT_FOUND,
            PLCategoryType::LEAF_INPUT !== $category->getType() => PlPreviewUnplacedReason::NOT_LEAF_INPUT,
            '' === trim((string) $category->getCode()) => PlPreviewUnplacedReason::NO_CODE,
            default => null,
        };
    }

    /**
     * Факт статьи: модуль суммы, знак — по природе статьи (как PLRegisterUpdater для marketplace_pl).
     * С колонкой-итогом направление берётся по итогу и применяется к доле линейно: доли
     * складываются в факт итога.
     */
    private static function factValue(PLCategory $category, string $signedAmount, ?string $baseAmount, bool $hasBase): float
    {
        $amount = (float) $signedAmount;
        $nature = $category->nature();
        if (null === $nature) {
            return $amount;
        }

        $direction = PlNature::INCOME === $nature ? 1.0 : -1.0;
        if (!$hasBase) {
            return $direction * abs($amount);
        }

        // Итог 0 или статьи нет в итоге: модуля нет, доли сохраняют знак документа — так
        // взаимно гасящие доли (−600 в теге, +600 в «Общих») остаются противоположными.
        $base = (float) ($baseAmount ?? '0');
        if (0.0 === $base) {
            return $amount;
        }

        return $direction * ($base > 0.0 ? 1.0 : -1.0) * $amount;
    }
}
