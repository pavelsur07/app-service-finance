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
 * документ — модуль каждой операции. Строка marketplace_pl — значение для отчёта (доход +,
 * расход −, сторно — обратный знак той же статьи): вклад в расход — −amount, в доход — amount,
 * без модуля, поэтому итог статьи бывает отрицательным (сторно перевесило начисления).
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
        $totals = [PlNature::INCOME->value => '0.00', PlNature::EXPENSE->value => '0.00'];
        $operations = [];
        foreach ($document->getOperations() as $operation) {
            $contribution = $this->forOperation($operation);
            if (null === $contribution) {
                continue;
            }
            $totals[$contribution['nature']] = bcadd($totals[$contribution['nature']], $contribution['amount'], 2);
            $operations[(string) $operation->getId()] = ['nature' => $contribution['nature'], 'amount' => $contribution['amount']];
        }

        return [
            'income' => $totals[PlNature::INCOME->value],
            'expense' => $totals[PlNature::EXPENSE->value],
            'result' => bcsub($totals[PlNature::INCOME->value], $totals[PlNature::EXPENSE->value], 2),
            'currency' => $document->getCashTransaction()?->getCurrency() ?? 'RUB',
            'operations' => $operations,
        ];
    }

    /**
     * Вклад строки: обычный документ — модуль операции; marketplace_pl — amount в доход,
     * −amount в расход. Без статьи или с нулевой суммой у статьи без потока — null.
     *
     * @return array{nature: string, amount: string, currency: string}|null
     */
    public function forOperation(DocumentOperation $operation): ?array
    {
        $document = $operation->getDocument();
        if (null === $operation->getPlCategory() || null === $document) {
            return null;
        }

        $nature = $this->natureResolver->forOperation($operation);
        if (null === $nature) {
            return null;
        }

        $amount = bcadd($operation->getAmount(), '0', 2);

        return [
            'nature' => $nature->value,
            'amount' => self::contribution($document->getType(), $nature, $amount),
            'currency' => $document->getCashTransaction()?->getCurrency() ?? 'RUB',
        ];
    }

    /**
     * Вклад суммы строки в доход или расход её природы — общее правило для регистра и итогов.
     */
    public static function contribution(DocumentType $type, PlNature $nature, string $amount): string
    {
        if (DocumentType::MARKETPLACE_PL !== $type) {
            return self::abs($amount);
        }

        return PlNature::EXPENSE === $nature ? self::negate($amount) : $amount;
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
