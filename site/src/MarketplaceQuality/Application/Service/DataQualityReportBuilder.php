<?php

declare(strict_types=1);

namespace App\MarketplaceQuality\Application\Service;

use App\MarketplaceEconomics\Facade\MarketplaceEconomicsFacadeInterface;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplaceOzon\Facade\MarketplaceOzonFacadeInterface;
use App\MarketplaceQuality\Application\DTO\CabinetQuality;
use App\MarketplaceQuality\Application\DTO\DataQualityReport;
use App\MarketplaceQuality\Application\DTO\QualityProblem;
use App\MarketplaceWb\Facade\MarketplaceWbFacadeInterface;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;

/**
 * R10 (`stages/stage-7.md` §7.3, `stage-8.md` §8.5): звенья «факты → перевод → канон → витрина» компании по
 * дням и каналам. Факты считаются по переводчику (`total`), потому что `SourceFacts` payload не читает. Факты WB —
 * отчёт о продажах: реклама без отчёта дня в перевод не идёт. Кабинет (`connection_ref`) принадлежит одному каналу.
 */
final readonly class DataQualityReportBuilder
{
    /** канал канона => ресурс фактов, которые переводятся в день */
    private const RESOURCES = ['ozon' => 'ozon.finance.accrual-by-day', 'wildberries' => 'wb.finance.sales-report.daily'];

    public function __construct(
        private SourceFactsFacadeInterface $sourceFacts,
        private MarketplaceOzonFacadeInterface $ozon,
        private MarketplaceWbFacadeInterface $wb,
        private MarketplaceOperationsFacadeInterface $operations,
        private MarketplaceEconomicsFacadeInterface $economics,
    ) {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): DataQualityReport
    {
        /** @var array<string, CabinetQuality> $cabinets */
        $cabinets = [];
        $problems = [];
        [$fromKey, $toKey] = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        $canon = [];
        foreach (self::RESOURCES as $channel => $resource) {
            $cabinet = static function (string $ref) use (&$cabinets, $channel): CabinetQuality {
                return $cabinets[$ref] ??= new CabinetQuality($channel, $ref);
            };
            $translated = [];
            $states = 'ozon' === $channel ? $this->ozon->dayStates($companyId, $from, $to) : $this->wb->dayStates($companyId, $from, $to);
            foreach ($states as $day) {
                $translated[$day->connectionRef.'|'.$day->date] = $day;
                $c = $cabinet($day->connectionRef);
                $c->lastTranslatedDay = max((string) $c->lastTranslatedDay, $day->date);
                $c->factsMinor += $day->totalMinor;
                $c->quarantineMinor += $day->quarantineMinor;
                if (0 !== $day->quarantineMinor) {
                    $problems[] = new QualityProblem($day->connectionRef, $day->date, QualityProblem::QUARANTINE, $day->quarantineMinor);
                }
            }

            foreach ($this->sourceFacts->periods($resource, $companyId) as $period) {
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

            $channelCanon = [];
            foreach ($this->operations->daySums($channel, $from, $to, $companyId) as $sum) {
                $channelCanon[$sum->connectionRef.'|'.$sum->date] = $sum->amountMinor;
                $cabinet($sum->connectionRef)->canonMinor += $sum->amountMinor;
            }
            foreach ($channelCanon + array_map(static fn (): int => 0, $translated) as $key => $amount) {
                $diff = ($channelCanon[$key] ?? 0) - (isset($translated[$key]) ? $translated[$key]->canonMinor : 0);
                if (0 !== $diff) {
                    [$ref, $date] = explode('|', $key);
                    $problems[] = new QualityProblem($ref, $date, QualityProblem::TRANSLATION_CANON, $diff);
                }
            }
            $canon += $channelCanon;
        }

        $mart = [];
        foreach ($this->economics->daySums($companyId, $from, $to) as $day) {
            $mart[$day->connectionRef.'|'.$day->date] = $day->linesMinor;
            // ponytail: кабинет только из витрины (нет ни фактов, ни канона) — канал неизвестен, показываем как Ozon
            $c = $cabinets[$day->connectionRef] ??= new CabinetQuality('ozon', $day->connectionRef);
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
        uasort($cabinets, static fn (CabinetQuality $a, CabinetQuality $b): int => strcmp($a->channel, $b->channel) ?: strcmp($a->connectionRef, $b->connectionRef));

        return new DataQualityReport($from, $to, array_values($cabinets), $problems);
    }
}
