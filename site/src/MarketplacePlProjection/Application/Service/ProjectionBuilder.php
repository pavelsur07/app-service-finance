<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\Service;

use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;
use App\MarketplaceEconomics\Facade\MarketplaceEconomicsFacadeInterface;
use App\MarketplaceOperations\Application\DTO\CanonPlSum;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplacePlProjection\Application\DTO\ProjectionLine;
use App\MarketplacePlProjection\Application\DTO\ProjectionReport;
use App\MarketplacePlProjection\Domain\Rule;
use App\MarketplacePlProjection\Domain\RuleResolver;
use App\MarketplacePlProjection\Enum\ProjectionStatus;
use App\MarketplacePlProjection\Infrastructure\Query\RuleStore;

/**
 * Проекция ОПиУ из канона: Σ канона по дням × правило компании на этот день. Считается при чтении,
 * ничего не хранит и не пишет.
 *
 * ponytail: одна группировка `mo_operation` на запрос (0,5 с на 460 тыс. операций, прод 16.11.2026);
 * если отчёт за год станет медленным — витрина по дням с отметкой «день изменился».
 */
final readonly class ProjectionBuilder
{
    /** Статья «Продажи с СПП» стандартного дерева: продажа − СПП − соинвестирование (`DATA.md` §9.2). */
    public const SPP_SALES = 'REV_SPP_SALES';
    public const SPP_RETURNS = 'REV_SPP_RETURNS';
    /**
     * Статья себестоимости. ponytail: витрина экономики считает себестоимость от продаж за вычетом
     * возвратов одной суммой — вся она идёт сюда, «Себестоимость возвратов» отдельно не выделяется;
     * раздельно — когда витрина начнёт писать две строки.
     */
    public const COST_PRICE = 'COGS_PRODUCT_REV';

    public function __construct(
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceEconomicsFacadeInterface $economics,
        private PLCategoryFacade $categories,
        private RuleStore $rules,
    ) {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $channel = null): ProjectionReport
    {
        $sums = $this->operations->plSums($companyId, $from, $to, $channel);
        $costPrice = $this->economics->costPrice($companyId, $from, $to, $channel);
        $categoryByCode = [];
        foreach ($this->categories->getTreeByCompanyId($companyId) as $category) {
            if (null !== $category->code && PLCategoryType::LEAF_INPUT === $category->type) {
                $categoryByCode[$category->code] = $category->id;
            }
        }

        $derived = $missing = [];
        foreach (self::revenueWithSpp($sums) + [self::COST_PRICE => $costPrice->amountMinor] as $code => $amount) {
            if (0 === $amount) {
                continue;
            }
            if (isset($categoryByCode[$code])) {
                $derived[$categoryByCode[$code]] = $amount;
            } else {
                $missing[] = $code;
            }
        }

        return new ProjectionReport(self::project($sums, $this->rules->rules($companyId)), $derived, $missing, $costPrice->daysWithout);
    }

    /**
     * Справочная «Выручка с СПП» (цена покупателя): продажи − СПП и соинвестирование продаж; возвраты −
     * СПП и соинвестирование возвратов (у возврата они отрицательные).
     *
     * @param list<CanonPlSum> $sums
     *
     * @return array<string, int> код статьи → копейки
     */
    public static function revenueWithSpp(array $sums): array
    {
        $sales = $returns = 0;
        foreach ($sums as $sum) {
            if (OperationKind::SALE === $sum->kind) {
                $sales += $sum->amountMinor;
            } elseif (OperationKind::RETURN === $sum->kind) {
                $returns += $sum->amountMinor;
            } elseif (OperationKind::SPP === $sum->kind || OperationKind::COINVESTMENT === $sum->kind) {
                if ($sum->amountMinor >= 0) {
                    $sales -= $sum->amountMinor;
                } else {
                    $returns -= $sum->amountMinor;
                }
            }
        }

        return [self::SPP_SALES => $sales, self::SPP_RETURNS => $returns];
    }

    /**
     * @param list<CanonPlSum> $sums
     * @param list<Rule> $rules
     *
     * @return list<ProjectionLine>
     */
    public static function project(array $sums, array $rules): array
    {
        $lines = [];
        foreach ($sums as $sum) {
            $rule = RuleResolver::resolve($rules, $sum->kind, $sum->channel, $sum->sourceType, $sum->date);
            $status = null === $rule ? ProjectionStatus::UNMAPPED : (null === $rule->plCategoryId ? ProjectionStatus::EXCLUDED : ProjectionStatus::MAPPED);
            $key = implode("\n", [$sum->channel, $sum->kind->value, $sum->sourceType, $status->value, $rule?->plCategoryId]);
            $lines[$key] = new ProjectionLine($sum->channel, $sum->kind, $sum->sourceType, $status, $rule?->plCategoryId, ($lines[$key]->amountMinor ?? 0) + $sum->amountMinor);
        }

        return array_values($lines);
    }
}
