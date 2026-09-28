<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\DTO\PreflightCheck;
use App\Marketplace\Application\DTO\PreflightResult;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\PreflightCostsQuery;
use App\Marketplace\Infrastructure\Query\PreflightSalesReturnsQuery;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Wildberries\Domain\WbCostCategory;

/**
 * Проверяет готовность данных перед закрытием этапа месяца.
 *
 * Синхронный — вызывается из Controller до dispatch Message.
 * Не использует ActiveCompanyService — companyId через Command.
 *
 * Результат: PreflightResult с перечнем проверок, ошибок и предупреждений.
 *
 * Уровни проверок COSTS:
 *   ERROR (блокирует):
 *     - уже есть обработанные затраты (аномалия — нужно переоткрыть)
 *     - нераспознанные затраты: категория вне каталога маркетплейса
 *       (Ozon — всегда, WB — пока по ней нет решения по ОПиУ)
 *     - затраты без решения по ОПиУ
 *   WARNING (не блокирует, предупреждение):
 *     - исключённые категории (include_in_pl = false)
 *     - нет затрат за период
 *
 * Перед оперативным закрытием (preliminary) нераспознанные затраты и затраты
 * без решения по ОПиУ — предупреждения: оперативный ОПиУ не должен замирать
 * на первой новой услуге маркетплейса.
 */
final class MonthClosePreflightAction
{
    public function __construct(
        private readonly PreflightSalesReturnsQuery $salesReturnsQuery,
        private readonly PreflightCostsQuery $costsQuery,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly CompanyFacade $companyFacade,
    ) {
    }

    public function __invoke(PreflightMonthCloseCommand $command): PreflightResult
    {
        $checks = match ($command->stage) {
            CloseStage::SALES_RETURNS => $this->checkSalesReturns($command),
            CloseStage::COSTS => $this->checkCosts($command),
        };

        $checks = array_merge(
            $this->checkCommon($command),
            $checks,
        );

        return new PreflightResult($checks);
    }

    // -------------------------------------------------------------------------
    // Общие проверки
    // -------------------------------------------------------------------------

    private function checkCommon(PreflightMonthCloseCommand $command): array
    {
        $checks = [];

        $company = $this->companyFacade->findById($command->companyId);
        $lockBefore = $company?->getFinanceLockBefore();

        if (null !== $lockBefore) {
            $periodEnd = (new \DateTimeImmutable(sprintf('%d-%02d-01', $command->year, $command->month)))
                ->modify('last day of this month');

            if ($periodEnd <= $lockBefore) {
                $checks[] = PreflightCheck::error(
                    'finance_lock',
                    'Период заблокирован',
                    sprintf('Период закрыт для редактирования (дата блокировки: %s)', $lockBefore->format('d.m.Y')),
                );
            } else {
                $checks[] = PreflightCheck::ok('finance_lock', 'Период заблокирован', 'Период открыт для редактирования');
            }
        }

        $marketplace = MarketplaceType::from($command->marketplace);
        $monthClose = $this->monthCloseRepository->findByPeriod(
            $command->companyId, $marketplace, $command->year, $command->month,
        );

        if (null !== $monthClose && $monthClose->isStageClosed($command->stage)) {
            $checks[] = PreflightCheck::error(
                'already_closed',
                'Этап закрыт',
                sprintf('Этап "%s" уже закрыт. Переоткройте период для повторного закрытия.', $command->stage->getLabel()),
            );
        } else {
            $checks[] = PreflightCheck::ok('already_closed', 'Этап закрыт', 'Этап ещё не закрыт');
        }

        return $checks;
    }

    // -------------------------------------------------------------------------
    // Этап: Продажи и возвраты
    // -------------------------------------------------------------------------

    private function checkSalesReturns(PreflightMonthCloseCommand $command): array
    {
        $checks = [];
        $periodFrom = sprintf('%d-%02d-01', $command->year, $command->month);
        $periodTo = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        $salesStats = $this->salesReturnsQuery->getSalesStats($command->companyId, $command->marketplace, $periodFrom, $periodTo);
        $salesTotal = (int) $salesStats['total'];
        $salesWithoutCost = (int) $salesStats['without_cost'];
        $salesAlreadyProcessed = (int) $salesStats['already_processed'];

        if (0 === $salesTotal) {
            $checks[] = PreflightCheck::warning('sales_count', 'Продажи за период', 'Нет продаж за выбранный период', $salesTotal);
        } elseif ($salesWithoutCost > 0) {
            $skus = $this->salesReturnsQuery->getSalesWithoutCostSkus($command->companyId, $command->marketplace, $periodFrom, $periodTo);
            $checks[] = PreflightCheck::error(
                'sales_without_cost',
                'Себестоимость продаж',
                sprintf('Продажи без себестоимости: %d шт. Запустите пересчёт себестоимости.', $salesWithoutCost),
                $salesWithoutCost,
                $skus,
            );
        } else {
            $checks[] = PreflightCheck::ok('sales_without_cost', 'Себестоимость продаж', sprintf('Все продажи имеют себестоимость (%d шт.)', $salesTotal), $salesTotal);
        }

        if ($salesAlreadyProcessed > 0) {
            $checks[] = PreflightCheck::error(
                'sales_already_processed',
                'Уже обработанные продажи',
                sprintf(
                    'Найдено %d записей в sales_report с document_id IS NOT NULL. Этап не был переоткрыт корректно. Переоткройте этап и повторите.',
                    $salesAlreadyProcessed,
                ),
                $salesAlreadyProcessed,
            );
        } else {
            $checks[] = PreflightCheck::ok('sales_already_processed', 'Уже обработанные продажи', 'Продажи готовы к обработке');
        }

        $returnsStats = $this->salesReturnsQuery->getReturnsStats($command->companyId, $command->marketplace, $periodFrom, $periodTo);
        $returnsTotal = (int) $returnsStats['total'];
        $returnsWithoutCost = (int) $returnsStats['without_cost'];
        $returnsAlreadyProcessed = (int) $returnsStats['already_processed'];

        if (0 === $returnsTotal) {
            $checks[] = PreflightCheck::ok('returns_without_cost', 'Себестоимость возвратов', 'Нет возвратов за период', 0);
        } elseif ($returnsWithoutCost > 0) {
            $skus = $this->salesReturnsQuery->getReturnsWithoutCostSkus($command->companyId, $command->marketplace, $periodFrom, $periodTo);
            $checks[] = PreflightCheck::error(
                'returns_without_cost',
                'Себестоимость возвратов',
                sprintf('Возвраты без себестоимости: %d шт. Запустите пересчёт себестоимости.', $returnsWithoutCost),
                $returnsWithoutCost,
                $skus,
            );
        } else {
            $checks[] = PreflightCheck::ok('returns_without_cost', 'Себестоимость возвратов', sprintf('Все возвраты имеют себестоимость (%d шт.)', $returnsTotal), $returnsTotal);
        }

        if ($returnsAlreadyProcessed > 0) {
            $checks[] = PreflightCheck::error(
                'returns_already_processed',
                'Уже обработанные возвраты',
                sprintf(
                    'Найдено %d записей в sales_report с document_id IS NOT NULL. Этап не был переоткрыт корректно. Переоткройте этап и повторите.',
                    $returnsAlreadyProcessed,
                ),
                $returnsAlreadyProcessed,
            );
        } else {
            $checks[] = PreflightCheck::ok('returns_already_processed', 'Уже обработанные возвраты', 'Возвраты готовы к обработке');
        }

        if ($command->marketplace === MarketplaceType::OZON->value) {
            $realizationLoaded = $this->salesReturnsQuery->isOzonRealizationLoaded($command->companyId, $command->year, $command->month);
            if (!$realizationLoaded) {
                $checks[] = PreflightCheck::warning('ozon_realization', 'Реализация Ozon', 'Реализация Ozon за этот месяц не загружена. Выручка с СПП не будет включена в ОПиУ.');
            } else {
                $checks[] = PreflightCheck::ok('ozon_realization', 'Реализация Ozon', 'Реализация Ozon загружена');
            }
        }

        return $checks;
    }

    // -------------------------------------------------------------------------
    // Этап: Затраты
    // -------------------------------------------------------------------------

    private function checkCosts(PreflightMonthCloseCommand $command): array
    {
        $checks = [];
        $periodFrom = sprintf('%d-%02d-01', $command->year, $command->month);
        $periodTo = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        $costsStats = $this->costsQuery->getCostsStats($command->companyId, $command->marketplace, $periodFrom, $periodTo);
        $total = (int) $costsStats['total'];
        $alreadyProcessed = (int) $costsStats['already_processed'];
        $excluded = (int) $costsStats['excluded_from_pl'];
        $netAmountForPl = $costsStats['net_amount_for_pl'] ?? '0';

        // Проверка 1: нет затрат за период (предупреждение)
        if (0 === $total) {
            $checks[] = PreflightCheck::warning('costs_count', 'Затраты за период', 'Нет затрат за выбранный период', 0);
        } else {
            $checks[] = PreflightCheck::ok('costs_count', 'Затраты за период', sprintf('Затрат за период: %d шт.', $total), $total);
        }

        // Проверка 2: уже обработанные затраты (БЛОКИРУЕТ — аномалия)
        if ($alreadyProcessed > 0) {
            $checks[] = PreflightCheck::error(
                'costs_already_processed',
                'Уже обработанные затраты',
                sprintf(
                    'Найдено %d затрат с document_id IS NOT NULL. Этап не был переоткрыт корректно. Переоткройте этап и повторите.',
                    $alreadyProcessed,
                ),
                $alreadyProcessed,
            );
        } else {
            $checks[] = PreflightCheck::ok('costs_already_processed', 'Уже обработанные затраты', 'Все затраты готовы к обработке');
        }

        // Проверка 3: нераспознанные затраты (БЛОКИРУЕТ финальное закрытие)
        $checks[] = $this->checkUnrecognizedCosts($command, $periodFrom, $periodTo);

        // Проверка 4: затраты без решения по ОПиУ (БЛОКИРУЕТ финальное закрытие)
        $withoutDecision = (int) $costsStats['without_pl_decision'];
        if ($withoutDecision > 0) {
            $categoriesWithoutDecision = $this->costsQuery->getCategoriesWithoutMapping(
                $command->companyId, $command->marketplace, $periodFrom, $periodTo,
            );
            $message = sprintf('Затрат без маппинга к ОПиУ: %d шт. Они не попадут в ОПиУ. Настройте маппинг в разделе "Себестоимость" или исключите категорию из ОПиУ.', $withoutDecision);
            $checks[] = $command->preliminary
                ? PreflightCheck::warning('costs_without_mapping', 'Маппинг затрат к ОПиУ', $message, $withoutDecision, details: $categoriesWithoutDecision)
                : PreflightCheck::error('costs_without_mapping', 'Маппинг затрат к ОПиУ', $message, $withoutDecision, $categoriesWithoutDecision);
        } else {
            $checks[] = PreflightCheck::ok('costs_without_mapping', 'Маппинг затрат к ОПиУ', 'Все затраты имеют маппинг к ОПиУ');
        }

        // Проверка 5: исключённые категории (информационно)
        if ($excluded > 0) {
            $checks[] = PreflightCheck::warning(
                'costs_excluded',
                'Исключённые затраты',
                sprintf('Затрат исключено из ОПиУ (include_in_pl = false): %d шт.', $excluded),
                $excluded,
            );
        }

        // Проверка 6: контрольная сумма — информационно, для snapshot
        if ($total > 0) {
            $checks[] = PreflightCheck::ok(
                'costs_control_sum',
                'Контрольная сумма',
                sprintf('Сумма затрат для ОПиУ (нетто): %s руб.', number_format((float) $netAmountForPl, 2, '.', ' ')),
                $netAmountForPl,
            );
        }

        return $checks;
    }

    /**
     * Нераспознанная затрата — категория вне каталога маркетплейса: новая
     * услуга Ozon (`ozon_unknown_<type_id>`), удержание WB, не описанное в
     * WbCostCategory, либо легаси-корзина `ozon_other_service`: код в каталоге
     * есть, но за ним нет ни одной услуги — туда легаси-путь сваливал неизвестные.
     *
     * Ozon блокирует всегда: неизвестная услуга требует правки каталога.
     * WB — только пока по категории нет решения по ОПиУ: удержания WB
     * приходят свободным текстом, и замапленная пользователем категория
     * уже решена.
     */
    private function checkUnrecognizedCosts(PreflightMonthCloseCommand $command, string $periodFrom, string $periodTo): PreflightCheck
    {
        $key = 'costs_unknown_service_names';
        $label = 'Нераспознанные операции';

        [$knownCodes, $blocksWhenDecided] = match (MarketplaceType::tryFrom($command->marketplace)) {
            MarketplaceType::OZON => [OzonCostCategory::recognizedCodes(), true],
            MarketplaceType::WILDBERRIES => [array_keys(WbCostCategory::byCode()), false],
            default => [null, false],
        };

        if (null === $knownCodes) {
            return PreflightCheck::ok($key, $label, 'Каталог услуг для маркетплейса не ведётся');
        }

        $rows = $this->costsQuery->getUnrecognizedCosts(
            $command->companyId, $command->marketplace, $periodFrom, $periodTo, $knownCodes,
        );
        if ([] === $rows) {
            return PreflightCheck::ok($key, $label, 'Все операции распознаны');
        }

        $total = array_sum(array_column($rows, 'count'));
        $blocking = array_sum(array_map(
            static fn (array $row): int => $blocksWhenDecided || !$row['decided'] ? $row['count'] : 0,
            $rows,
        ));

        if ($blocking > 0 && !$command->preliminary) {
            $message = $blocksWhenDecided
                ? 'Найдено %d операций с услугами, которых нет в каталоге OzonCostCategory. Добавьте их в каталог и переобработайте затраты.'
                : 'Найдено %d операций вне каталога WbCostCategory без маппинга к ОПиУ. Настройте маппинг в разделе "Себестоимость" или добавьте их в каталог.';

            return PreflightCheck::error($key, $label, sprintf($message, $blocking), $blocking, $rows);
        }

        // Ozon вне каталога оперативное закрытие не берёт (PreliminaryCostFilter).
        $warning = $blocksWhenDecided
            ? 'Найдено %d операций, которых нет в каталоге услуг маркетплейса. В оперативный ОПиУ они не попадут.'
            : 'Найдено %d операций, которых нет в каталоге услуг маркетплейса.';

        return PreflightCheck::warning(
            $key,
            $label,
            sprintf($warning, $total),
            $total,
            details: $rows,
        );
    }
}
