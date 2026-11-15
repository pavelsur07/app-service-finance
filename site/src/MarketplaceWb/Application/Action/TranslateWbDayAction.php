<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Application\Action;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\OperationInput;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceWb\Domain\SalesReportTranslator;
use App\MarketplaceWb\Domain\WbComponent;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\Shared\Domain\ValueObject\Money;
use App\SourceFacts\Application\DTO\SourcePeriod;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;

/**
 * Один день кабинета WB: строки отчёта → канон + карантин + итоги дня. Транзакцией владеет
 * вызывающая команда; блокировку дня берёт `replaceDay`. Повтор даёт тот же результат.
 */
final readonly class TranslateWbDayAction
{
    public const RESOURCE = 'wb.finance.sales-report.daily';
    /** Канал канона = `MarketplaceType::WILDBERRIES->value`. */
    public const CHANNEL = 'wildberries';

    public function __construct(
        private SourceFactsFacadeInterface $sourceFacts,
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceFacade $marketplace,
        private WbTranslationStore $store,
        private SalesReportTranslator $translator,
    ) {
    }

    /**
     * @return array{operations: int, quarantined: int, unresolved: int}
     */
    public function __invoke(SourcePeriod $period): array
    {
        $date = $period->periodKey;
        $facts = $this->sourceFacts->activeFacts($period->companyId, $period->connectionRef, self::RESOURCE, $date);

        // ponytail: итоги дня в рублях — у кабинетов WB (РФ) другой валюты нет; Money::add бросит на
        // чужой валюте, день упадёт с ошибкой, а не посчитается неверно.
        $total = $canon = $quarantined = Money::fromMinor(0, 'RUB');
        $components = [];
        foreach ($facts as $fact) {
            foreach ($this->translator->translate($fact->sourceKey, $fact->payload) as $component) {
                $components[] = $component;
                if (!SalesReportTranslator::isInformational($component)) {
                    $total = $total->add($component->amount);
                }
            }
        }

        $undecided = array_values(array_unique(array_map(
            static fn (WbComponent $c): string => $c->sourceType,
            array_filter($components, static fn (WbComponent $c): bool => null === $c->kind),
        )));
        $kinds = $this->store->resolveKinds($undecided, $period->companyId, $date);
        $listings = $this->listings($period->companyId, $components);

        $operations = [];
        $quarantine = [];
        $unresolved = 0;
        foreach ($components as $component) {
            $kind = $component->kind ?? $kinds[$component->sourceType] ?? null;
            if (null === $kind) {
                $row = $quarantine[$component->sourceType] ?? ['amountMinor' => 0, 'currency' => $component->amount->currency(), 'rows' => 0, 'refs' => []];
                $row['amountMinor'] += $component->amount->amountMinor();
                ++$row['rows'];
                $row['refs'][] = $component->sourceKey;
                $quarantine[$component->sourceType] = $row;
                $quarantined = $quarantined->add($component->amount);
                continue;
            }

            $listingId = null === $component->nmId ? null : self::listingFor($listings[$component->nmId] ?? [], (string) $component->size);
            if (null !== $component->nmId && null === $listingId) {
                ++$unresolved;
            }
            $operations[] = new OperationInput($kind, $component->sourceType, $component->amount, [$component->sourceKey], $listingId, $component->quantity);
            if (!SalesReportTranslator::isInformational($component)) {
                $canon = $canon->add($component->amount);
            }
        }

        $this->operations->replaceDay(new DayOperations(
            $period->companyId,
            self::CHANNEL,
            $period->connectionRef,
            new \DateTimeImmutable($date),
            SalesReportTranslator::RULES_VERSION,
            $operations,
        ));

        $this->store->saveDay($period->companyId, $period->connectionRef, $date, SalesReportTranslator::RULES_VERSION, [
            'revision' => $period->revision,
            'facts' => \count($facts),
            'totalMinor' => $total->amountMinor(),
            'canonMinor' => $canon->amountMinor(),
            'quarantineMinor' => $quarantined->amountMinor(),
            'currency' => $total->currency(),
            'unresolved' => $unresolved,
        ], $quarantine);

        return ['operations' => \count($operations), 'quarantined' => \count($quarantine), 'unresolved' => $unresolved];
    }

    /**
     * Листинг по размеру; размера нет, а у `nmId` один листинг — он; иначе без SKU.
     *
     * @param list<array{id: string, size: string}> $found
     */
    public static function listingFor(array $found, string $size): ?string
    {
        foreach ($found as $listing) {
            if ($listing['size'] === $size) {
                return $listing['id'];
            }
        }

        return 1 === \count($found) ? $found[0]['id'] : null;
    }

    /**
     * @param list<WbComponent> $components
     *
     * @return array<string, list<array{id: string, size: string}>> nmId → листинги
     */
    private function listings(string $companyId, array $components): array
    {
        $nmIds = array_values(array_unique(array_filter(array_map(static fn (WbComponent $c): ?string => $c->nmId, $components))));
        $listings = [];
        foreach ($this->marketplace->findListingsByMarketplaceSkus($companyId, self::CHANNEL, $nmIds) as $nmId => $found) {
            $listings[(string) $nmId] = array_map(static fn (array $l): array => ['id' => $l['id'], 'size' => $l['size']], $found);
        }

        return $listings;
    }
}
