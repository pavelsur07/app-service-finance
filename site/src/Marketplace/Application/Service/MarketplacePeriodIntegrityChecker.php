<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Application\DTO\MonthCloseDayCoverage;
use App\Marketplace\Application\DTO\MonthCloseDayViolation;
use App\Marketplace\Application\Source\MarketplaceDataSourceInterface;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\WbReportDayCoverageQuery;
use App\Marketplace\Wildberries\Application\FinancialReport\WbFinancialReportPeriodResolver;

/**
 * Проверка целостности исходных данных закрываемого периода (R-04 / M-01).
 *
 * Только обнаруживает: ничего не повторяет, не переоткрывает и не меняет. Все выборки
 * ограничены компанией, маркетплейсом и периодом закрытия — состояние других периодов,
 * компаний и подключений на результат не влияет.
 */
final class MarketplacePeriodIntegrityChecker
{
    /**
     * Источники, для которых паритет «что агрегируется» и «что привязывается» не доказан:
     * выборка реализации Ozon берёт строки, лежащие ВНУТРИ периода, а привязка —
     * только с точным совпадением period_from/period_to. На частичном периоде строка
     * агрегировалась бы и не привязывалась, и инвариант отменял бы закрытие навсегда.
     * Реализация проверяется своими гейтами (ozon-realization-unapplied-check).
     */
    private const PARITY_UNVERIFIED_SOURCES = ['ozon_realization', 'ozon_realization_return'];

    public function __construct(
        private readonly WbReportDayCoverageQuery $coverageQuery,
        private readonly WbFinancialReportPeriodResolver $periodResolver,
        private readonly MonthCloseDayCoverageEvaluator $evaluator,
    ) {
    }

    /**
     * Готовность ожидаемых дней периода.
     *
     * Ожидаемые дни — дни закрываемого периода по «вчера» (МСК) по календарной модели WB-планировщика
     * (WbFinancialReportPeriodResolver). Сегодняшний и будущие дни не требуются, поэтому финальное
     * закрытие ещё идущего месяца проверяет только уже завершённые дни.
     *
     * Подневная загрузка планируется с начала текущего года, поэтому период, начавшийся раньше
     * (декабрь прошлого года, закрываемый в январе), проверяется только если по нему есть хотя бы
     * одна строка статуса — иначе дни, которых загрузчик никогда не планировал, считались бы
     * пропущенными навсегда.
     *
     * null — подневной модели нет и проверять нечего: маркетплейс не WB (у Ozon нет подневных
     * статусов), в периоде нет завершённых дней, либо период прошлых лет без статусов.
     */
    public function checkReportDays(
        string $companyId,
        MarketplaceType $marketplace,
        string $periodFrom,
        string $periodTo,
    ): ?MonthCloseDayCoverage {
        if (MarketplaceType::WILDBERRIES !== $marketplace) {
            return null;
        }

        $from = $periodFrom;
        $to = min($periodTo, $this->periodResolver->yesterday()->format('Y-m-d'));

        if ($from > $to) {
            return null;
        }

        $statuses = $this->coverageQuery->statusesByDay($companyId, $from, $to);

        if ($from < $this->periodResolver->currentYearStart()->format('Y-m-d') && [] === $statuses) {
            return null;
        }

        $expectedDays = $this->periodResolver->daysBetween(
            $this->periodResolver->normalizeBusinessDate($from),
            $this->periodResolver->normalizeBusinessDate($to),
        );
        $violations = $this->evaluator->evaluate($expectedDays, $statuses, []);

        // Легаси-документы нужны только для дней без готового статуса; в обычном случае запрос не делается.
        if ($this->hasUncoveredDays($violations)) {
            $violations = $this->evaluator->evaluate(
                $expectedDays,
                $statuses,
                $this->coverageQuery->legacyCompletedPeriods($companyId, $from, $to),
            );
        }

        return new MonthCloseDayCoverage(count($expectedDays), $violations);
    }

    /**
     * Строки, которые источники этапа всё ещё считают необработанными.
     *
     * Вызывается ПОСЛЕ markProcessed в той же транзакции. Источник отдаёт ровно те записи
     * (document_id IS NULL + условия включения в ОПиУ + режим preliminary), которые только
     * что были агрегированы в документ и привязаны к нему, поэтому на этой точке выборка
     * обязана быть пустой. Непустая — строка появилась или изменилась между привязкой и
     * проверкой либо привязка разошлась с агрегацией: документ и источник расходятся.
     *
     * Сырой `document_id IS NULL` здесь был бы ложной тревогой: часть строк законно остаётся
     * непривязанной (категории затрат с include_in_pl = false, продажи с нулевой суммой по
     * маппингу, оперативный режим без себестоимости). Исключения заданы теми же запросами,
     * что формируют документ, а не отдельной копией условий.
     *
     * @param iterable<MarketplaceDataSourceInterface> $sources
     *
     * @return list<array{source: string, entries: int}>
     */
    public function findStillUnprocessed(
        iterable $sources,
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        bool $preliminary,
    ): array {
        $remaining = [];

        foreach ($sources as $source) {
            if (\in_array($source->getSourceId(), self::PARITY_UNVERIFIED_SOURCES, true)) {
                continue;
            }

            $entries = $source->getUnprocessedEntries($companyId, $marketplace, $periodFrom, $periodTo, $preliminary);
            if ([] !== $entries) {
                $remaining[] = ['source' => $source->getSourceId(), 'entries' => count($entries)];
            }
        }

        return $remaining;
    }

    /**
     * @param list<MonthCloseDayViolation> $violations
     */
    private function hasUncoveredDays(array $violations): bool
    {
        foreach ($violations as $violation) {
            if (MonthCloseDayViolation::STATE_IN_PROGRESS !== $violation->state) {
                return true;
            }
        }

        return false;
    }
}
