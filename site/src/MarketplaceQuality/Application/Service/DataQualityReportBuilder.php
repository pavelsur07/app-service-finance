<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Application\Service;

use App\MarketplaceEconomics\Facade\MarketplaceEconomicsFacadeInterface;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceOzon\Facade\MarketplaceOzonFacadeInterface;
use App\MarketplaceQuality\Application\DTO\CabinetQuality;
use App\MarketplaceQuality\Application\DTO\DataQualityReport;
use App\MarketplaceQuality\Application\DTO\QualityProblem;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;

/**
 * R10 (`stages/stage-7.md` §7.3): звенья «факты → перевод → канон → витрина» компании по дням.
 * Факты считаются по переводчику (`total`), потому что `SourceFacts` payload не читает. Только Ozon;
 * WB добавится своим переводчиком.
 */
final readonly class DataQualityReportBuilder
{
    private const OZON = 'ozon';
    private const OZON_RESOURCE = 'ozon.finance.accrual-by-day';

    public function __construct(
        private SourceFactsFacadeInterface $sourceFacts,
        private MarketplaceOzonFacadeInterface $ozon,
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceEconomicsFacadeInterface $economics,
    ) {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): DataQualityReport
    {
        /** @var array<string, CabinetQuality> $cabinets */
        $cabinets = [];
        $cabinet = static function (string $ref) use (&$cabinets): CabinetQuality {
            return $cabinets[$ref] ??= new CabinetQuality(self::OZON, $ref);
        };
        $problems = [];
        [$fromKey, $toKey] = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        $translated = [];
        foreach ($this->ozon->dayStates($companyId, $from, $to) as $day) {
            $translated[$day->connectionRef.'|'.$day->date] = $day;
            $c = $cabinet($day->connectionRef);
            $c->lastTranslatedDay = max((string) $c->lastTranslatedDay, $day->date);
            $c->factsMinor += $day->totalMinor;
            $c->quarantineMinor += $day->quarantineMinor;
            if (0 !== $day->quarantineMinor) {
                $problems[] = new QualityProblem($day->connectionRef, $day->date, QualityProblem::QUARANTINE, $day->quarantineMinor);
            }
        }

        foreach ($this->sourceFacts->periods(self::OZON_RESOURCE, $companyId) as $period) {
            $c = $cabinet($period->connectionRef);
            $c->lastFactDay = max((string) $c->lastFactDay, $period->periodKey);
            if ($period->periodKey < $fromKey || $period->periodKey > $toKey) {
                continue;
            }
            $day = $translated[$period->connectionRef.'|'.$period->periodKey] ?? null;
            if (null === $day || $day->factsRevision !== $period->revision || !$day->currentRules) {
                ++$c->untranslatedDays;
                $problems[] = new QualityProblem($period->connectionRef, $period->periodKey, QualityProblem::UNTRANSLATED, null);
            }
        }

        $canon = [];
        foreach ($this->operations->daySums(self::OZON, $from, $to, $companyId) as $sum) {
            $canon[$sum->connectionRef.'|'.$sum->date] = $sum->amountMinor;
            $cabinet($sum->connectionRef)->canonMinor += $sum->amountMinor;
        }
        foreach ($canon + array_map(static fn (): int => 0, $translated) as $key => $amount) {
            $diff = ($canon[$key] ?? 0) - (isset($translated[$key]) ? $translated[$key]->canonMinor : 0);
            if (0 !== $diff) {
                [$ref, $date] = explode('|', $key);
                $problems[] = new QualityProblem($ref, $date, QualityProblem::TRANSLATION_CANON, $diff);
            }
        }

        $mart = [];
        foreach ($this->economics->daySums($companyId, $from, $to) as $day) {
            $mart[$day->connectionRef.'|'.$day->date] = $day->linesMinor;
            $c = $cabinet($day->connectionRef);
            $c->lastMartDay = max((string) $c->lastMartDay, $day->date);
            $c->martMinor += $day->linesMinor;
            $c->unallocatedMinor += abs($day->unallocatedMinor);
            $c->costsMinor += $day->costsMinor;
        }
        foreach ($canon + $mart as $key => $amount) {
            $diff = ($mart[$key] ?? 0) - ($canon[$key] ?? 0);
            if (0 !== $diff) {
                [$ref, $date] = explode('|', $key);
                $problems[] = new QualityProblem($ref, $date, QualityProblem::CANON_MART, $diff);
            }
        }

        foreach ($cabinets as $c) {
            foreach (['lastFactDay', 'lastTranslatedDay', 'lastMartDay'] as $field) {
                $c->{$field} = '' === $c->{$field} ? null : $c->{$field};
            }
        }
        usort($problems, static fn (QualityProblem $a, QualityProblem $b): int => strcmp($b->date, $a->date) ?: strcmp($a->connectionRef.$a->link, $b->connectionRef.$b->link));
        ksort($cabinets);

        return new DataQualityReport($from, $to, array_values($cabinets), $problems);
    }
}
