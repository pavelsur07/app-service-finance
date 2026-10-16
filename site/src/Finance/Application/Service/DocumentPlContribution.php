<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PlNature;

/**
 * Доход и расход документа так, как их считает регистр ОПиУ (PLRegisterUpdater): операция
 * без статьи не входит; природа — flow статьи, у статьи без flow — знак суммы. Обычный
 * документ — модуль каждой операции. marketplace_pl хранит списание со знаком «−», сторно —
 * «+»: суммы статьи складываются со знаком, модуль берётся от итога статьи.
 *
 * Вклад каждой операции — для строк операций в списке: у marketplace_pl знак операции
 * приведён к знаку итога статьи, чтобы строки складывались в итог документа.
 */
final readonly class DocumentPlContribution
{
    public function __construct(
        private PlNatureResolver $natureResolver,
    ) {
    }

    /**
     * @return array{
     *     income: string,
     *     expense: string,
     *     result: string,
     *     currency: string,
     *     operations: array<string, array{nature: string, amount: string}>
     * }
     */
    public function forDocument(Document $document): array
    {
        $isMarketplace = DocumentType::MARKETPLACE_PL === $document->getType();
        /** @var list<array{operation: DocumentOperation, nature: PlNature, amount: string, key: string}> $counted */
        $counted = [];
        /** @var array<string, string> $categorySums */
        $categorySums = [];

        foreach ($document->getOperations() as $operation) {
            $category = $operation->getPlCategory();
            if (null === $category) {
                continue;
            }

            $nature = $this->natureResolver->forOperation($operation);
            if (null === $nature) {
                continue;
            }

            $amount = bcadd($operation->getAmount(), '0', 2);
            $key = $nature->value.'|'.$category->getId();
            $categorySums[$key] = bcadd($categorySums[$key] ?? '0', $amount, 2);
            $counted[] = ['operation' => $operation, 'nature' => $nature, 'amount' => $amount, 'key' => $key];
        }

        $totals = [PlNature::INCOME->value => '0.00', PlNature::EXPENSE->value => '0.00'];
        $operations = [];
        foreach ($counted as $row) {
            if ($isMarketplace) {
                $contribution = self::isNegative($categorySums[$row['key']]) ? self::negate($row['amount']) : $row['amount'];
            } else {
                $contribution = self::abs($row['amount']);
                $totals[$row['nature']->value] = bcadd($totals[$row['nature']->value], $contribution, 2);
            }
            $operations[(string) $row['operation']->getId()] = ['nature' => $row['nature']->value, 'amount' => $contribution];
        }

        if ($isMarketplace) {
            foreach ($categorySums as $key => $sum) {
                $nature = explode('|', $key, 2)[0];
                $totals[$nature] = bcadd($totals[$nature], self::abs($sum), 2);
            }
        }

        return [
            'income' => $totals[PlNature::INCOME->value],
            'expense' => $totals[PlNature::EXPENSE->value],
            'result' => bcsub($totals[PlNature::INCOME->value], $totals[PlNature::EXPENSE->value], 2),
            'currency' => $document->getCashTransaction()?->getCurrency() ?? 'RUB',
            'operations' => $operations,
        ];
    }

    private static function isNegative(string $value): bool
    {
        return -1 === bccomp($value, '0', 2);
    }

    private static function abs(string $value): string
    {
        return self::isNegative($value) ? self::negate($value) : $value;
    }

    private static function negate(string $value): string
    {
        return bcmul($value, '-1', 2);
    }
}
