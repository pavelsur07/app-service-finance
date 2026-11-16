<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Application\Service;

use App\MarketplaceOperations\Application\DTO\CanonPlSum;
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
    public function __construct(
        private MarketplaceOperationsFacadeInterface $operations,
        private RuleStore $rules,
    ) {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $channel = null): ProjectionReport
    {
        return new ProjectionReport(self::project($this->operations->plSums($companyId, $from, $to, $channel), $this->rules->rules($companyId)));
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
