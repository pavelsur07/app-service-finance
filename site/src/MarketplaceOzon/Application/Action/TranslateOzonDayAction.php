<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Application\Action;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\OperationInput;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceOzon\Domain\AccrualByDayTranslator;
use App\MarketplaceOzon\Domain\AccrualComponent;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\Shared\Domain\ValueObject\Money;
use App\SourceFacts\Application\DTO\SourcePeriod;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;

/**
 * Один день кабинета: факты by-day → канон + карантин + итоги дня. Транзакцией владеет
 * вызывающая команда; блокировку дня берёт `replaceDay`. Повтор даёт тот же результат.
 */
final readonly class TranslateOzonDayAction
{
    public const RESOURCE = 'ozon.finance.accrual-by-day';
    public const CHANNEL = 'ozon';

    public function __construct(
        private SourceFactsFacadeInterface $sourceFacts,
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceFacade $marketplace,
        private OzonTranslationStore $store,
        private AccrualByDayTranslator $translator,
    ) {
    }

    /**
     * @return array{operations: int, quarantined: int, unresolved: int}
     */
    public function __invoke(SourcePeriod $period): array
    {
        $date = $period->periodKey;
        $facts = $this->sourceFacts->activeFacts($period->companyId, $period->connectionRef, self::RESOURCE, $date);

        // ponytail: итоги дня в рублях — у кабинетов Ozon (РФ) другой валюты нет; Money::add
        // бросит на чужой валюте, день упадёт с ошибкой, а не посчитается неверно.
        $total = $canon = $quarantined = Money::fromMinor(0, 'RUB');
        $components = [];
        foreach ($facts as $fact) {
            foreach ($this->translator->translate($fact->sourceKey, $fact->payload) as $component) {
                $components[] = $component;
                if (!AccrualByDayTranslator::isInformational($component)) {
                    $total = $total->add($component->amount);
                }
            }
        }

        $serviceTypes = array_values(array_unique(array_map(
            static fn (AccrualComponent $c): string => $c->sourceType,
            array_filter($components, static fn (AccrualComponent $c): bool => null === $c->kind),
        )));
        $kinds = $this->store->resolveKinds($serviceTypes, $period->companyId, $date);

        $skus = array_values(array_unique(array_filter(array_map(static fn (AccrualComponent $c): ?string => $c->sku, $components))));
        $listings = [];
        foreach ($this->marketplace->findListingsByMarketplaceSkus($period->companyId, self::CHANNEL, $skus) as $sku => $found) {
            // ponytail: несколько листингов на один SKU Ozon — берём первый по id; считать, если встретится
            usort($found, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
            $listings[(string) $sku] = $found[0]['id'];
        }

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

            $listingId = null === $component->sku ? null : ($listings[$component->sku] ?? null);
            if (null !== $component->sku && null === $listingId) {
                ++$unresolved;
            }
            $operations[] = new OperationInput($kind, $component->sourceType, $component->amount, [$component->sourceKey], $listingId, $component->quantity);
            if (!AccrualByDayTranslator::isInformational($component)) {
                $canon = $canon->add($component->amount);
            }
        }

        $this->operations->replaceDay(new DayOperations(
            $period->companyId,
            self::CHANNEL,
            $period->connectionRef,
            new \DateTimeImmutable($date),
            AccrualByDayTranslator::RULES_VERSION,
            $operations,
        ));

        $this->store->saveDay($period->companyId, $period->connectionRef, $date, AccrualByDayTranslator::RULES_VERSION, [
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
}
