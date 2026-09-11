<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\MonthCloseAdvisoryLockQuery;
use App\Marketplace\Infrastructure\Query\UnlinkDocumentRowsQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Решает, можно ли заменить строки перезагруженного дня, и готовит их к замене.
 *
 * Зачем. Скользящее окно by-day существует ради правок Ozon задним числом, а
 * замена не трогает строки с проставленным `document_id`. Для окончательно
 * закрытого периода это и требуется. Но текущий месяц закрывается предварительно
 * каждую ночь, и к утру его строки тоже несут `document_id`: замер на проде
 * 11.09.2026 показал, что из 915 продаж за 08.09 привязано 787, из 4710 затрат —
 * 1702. Правка по ним не доезжала вовсе.
 *
 * Две границы, обе жёсткие:
 *
 * 1. Заблокированный период (`Company::financeLockBefore`) не меняется вообще:
 *    ни привязка не снимается, ни строки не удаляются. Ту же границу держит
 *    `ReopenMonthStageAction`, и обойти её замена не вправе.
 * 2. Привязка снимается только у ПРЕДВАРИТЕЛЬНО закрытых этапов —
 *    `settings.last_close_was_preliminary[stage]`. Флаг ведётся per-stage ровно
 *    затем, чтобы предварительность одного этапа не разблокировала финальное
 *    закрытие соседнего в том же месяце.
 *
 * Сам документ ОПиУ не трогается и остаётся со своими цифрами до ночного
 * пересбора (`app:marketplace:month-preliminary-rebuild`). Размен осознанный:
 * предварительный отчёт расходится с источником до ближайшего пересбора, зато
 * перестаёт расходиться с Ozon навсегда.
 */
final readonly class ByDayRowReplacement
{
    public function __construct(
        private MarketplaceMonthCloseRepository $monthCloseRepository,
        private CompanyFacade $companyFacade,
        private UnlinkDocumentRowsQuery $unlinkQuery,
        private MonthCloseAdvisoryLockQuery $lockQuery,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Продажи и возвраты закрываются одним этапом, затраты — другим, и
     * предварительность у них своя: поэтому методы разные, а не один с
     * параметром-таблицей.
     *
     * @return bool можно ли заменять строки этого дня
     */
    public function prepareSales(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_sales', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function prepareReturns(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_returns', $companyId, $marketplace, $day, $rawDocId, CloseStage::SALES_RETURNS);
    }

    public function prepareCosts(string $companyId, MarketplaceType $marketplace, \DateTimeImmutable $day, string $rawDocId): bool
    {
        return $this->prepare('marketplace_costs', $companyId, $marketplace, $day, $rawDocId, CloseStage::COSTS);
    }

    private function prepare(
        string $table,
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $day,
        string $rawDocId,
        CloseStage $stage,
    ): bool {
        $year = (int) $day->format('Y');
        $month = (int) $day->format('n');

        // Замок берётся первым — до чтения и блокировки периода, и состояния
        // этапа: иначе параллельное закрытие месяца успело бы собрать документ
        // по строкам, которые замена тут же перезапишет.
        $this->lockQuery->lock($companyId, $marketplace, $year, $month);

        if ($this->isDayLocked($companyId, $day)) {
            $this->logger->warning('[Ozon by-day] replacement skipped: finance period is locked', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'day' => $day->format('Y-m-d'),
            ]);

            return false;
        }

        $monthClose = $this->monthCloseRepository->findByPeriod($companyId, $marketplace, $year, $month);
        $status = $monthClose?->getStageStatus($stage) ?? MonthCloseStageStatus::PENDING;

        // Этап не закрыт: привязок к ОПиУ нет, отбирать нечего и защищать
        // нечего. Сюда попадает и первичная загрузка дня — в том числе
        // последнего дня прошлого месяца, который крон забирает первого числа.
        // Отказать здесь значило бы вовсе не завести его продажи, возвраты и
        // затраты: шаг конвейера всё равно отчитался бы успехом.
        //
        // Проверяется именно статус, а не флаг предварительности: настоящий
        // reopenStage() переводит этап в REOPENED и очищает список документов,
        // но флаг предварительности не сбрасывает.
        if (MonthCloseStageStatus::CLOSED !== $status) {
            return true;
        }

        // Окончательно закрытый этап неизменен так же, как заблокированный
        // период: иначе в него попала бы строка с ранее не виденным
        // external_id, которой нет в итоговом документе ОПиУ.
        if (!$monthClose?->isStageLastCloseWasPreliminary($stage)) {
            $this->logger->warning('[Ozon by-day] replacement skipped: stage is closed finally', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'stage' => $stage->value,
                'period' => sprintf('%d-%02d', $year, $month),
            ]);

            return false;
        }

        // Привязку снимаем только в текущем месяце: его предварительное закрытие
        // пересобирается ночью в любом случае, поэтому снятая привязка
        // восстановится сама. У прошлых месяцев такого пересбора нет — их
        // документ остался бы расходиться с источником, а чинить это надо
        // вместе с семантикой опустевшего этапа. Отдельная работа.
        //
        // Но обработку прошлого месяца при этом НЕ запрещаем. Крон забирает
        // вчерашний день, а прошлый месяц в проде всегда закрыт предварительно:
        // первого числа отказ означал бы, что последний день месяца не завёлся
        // вовсе — и так каждый месяц. Строки, не привязанные к ОПиУ, заменяются
        // как обычно; привязанные остаются нетронутыми, то есть правка Ozon по
        // ним не доедет. Это ровно то поведение, что было до этой задачи.
        if (!$this->isCurrentMonth($day)) {
            $this->logger->info('[Ozon by-day] past preliminary month: rows processed, links kept', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'stage' => $stage->value,
                'period' => sprintf('%d-%02d', $year, $month),
            ]);

            return true;
        }

        // array_values: список приходит из JSON-поля, и его ключи не обязаны
        // быть последовательными.
        $documentIds = array_values($monthClose->getStagePLDocumentIds($stage));

        // Пустой список id — не «привязок нет», а закрытие, у которого id
        // документов не сохранены: такие встречаются у старых и повреждённых.
        // Снять привязку у их строк нельзя: переоткрытие потом не найдёт, какой
        // документ ОПиУ удалять, и рядом с новым останется старый — двойной
        // счёт. Замена отказывается, состояние видно в логе.
        if ([] === $documentIds) {
            $this->logger->warning('[Ozon by-day] replacement skipped: preliminary close has no stored document ids', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'stage' => $stage->value,
                'period' => sprintf('%d-%02d', $year, $month),
            ]);

            return false;
        }

        $unlinked = $this->unlinkQuery->execute($table, $companyId, $rawDocId, $documentIds);

        if ($unlinked > 0) {
            $this->logger->info('[Ozon by-day] preliminary rows unlinked before replacement', [
                'company_id' => $companyId,
                'raw_document_id' => $rawDocId,
                'stage' => $stage->value,
                'rows' => $unlinked,
            ]);
        }

        return true;
    }

    private function isCurrentMonth(\DateTimeImmutable $day): bool
    {
        return $day->format('Y-m') === $this->clock->now()->format('Y-m');
    }

    private function isDayLocked(string $companyId, \DateTimeImmutable $day): bool
    {
        $lockBefore = $this->companyFacade->findById($companyId)?->getFinanceLockBefore();

        return null !== $lockBefore && $day <= $lockBefore;
    }
}
