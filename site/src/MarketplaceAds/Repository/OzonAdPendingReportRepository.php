<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Репозиторий записей о запрошенных отчётах Ozon Performance.
 *
 * {@see create()} сохраняет запись сразу (persist + flush), чтобы UUID был в БД
 * ДО первой итерации polling'а — даже если handler упадёт на следующем шаге,
 * зомби-UUID останется видимым для диагностики и будущей resume-логики.
 *
 * {@see updateState()} и {@see markFinalized()} идут через raw DBAL UPDATE,
 * минуя UoW: они вызываются в горячем цикле polling'а, не должны триггерить
 * hydration и безопасны для одновременной работы нескольких воркеров
 * (на случай рестарта Messenger-job'а, когда два handler'а видят один и
 * тот же in-flight UUID).
 */
class OzonAdPendingReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonAdPendingReport::class);
    }

    /**
     * Создаёт запись о запрошенном отчёте со state = REQUESTED и сразу flush'ит.
     *
     * Flush обязателен здесь (не откладывается на вызывающий Action), потому
     * что create() вызывается из {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient::requestStatistics()}
     * в середине pipeline'а: последующие шаги (pollReport, downloadReport)
     * могут упасть, и без немедленного flush UUID не попадёт в БД.
     *
     * ВНИМАНИЕ: flush() здесь сбрасывает ВЕСЬ Doctrine UoW, а не только эту
     * entity. Вызывающий код не должен держать в UoW другие "грязные"
     * сущности на момент вызова create() — иначе они попадут в БД
     * непредвиденно. В контексте Messenger-handler'а это безопасно:
     * FetchOzonAdStatisticsHandler пишет через отдельные Repository с raw
     * DBAL и не накапливает UoW.
     *
     * @param list<string> $campaignIds
     */
    public function create(
        string $companyId,
        string $ozonUuid,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
        ?string $jobId,
    ): OzonAdPendingReport {
        $entity = new OzonAdPendingReport(
            companyId: $companyId,
            ozonUuid: $ozonUuid,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            campaignIds: $campaignIds,
            jobId: $jobId,
        );

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    /**
     * Обновляет state / lastCheckedAt / pollAttempts после очередной итерации polling.
     *
     * Если $firstNonPendingAt передан и ещё не установлен в БД — фиксируем.
     * Guard-условие `first_non_pending_at IS NULL` защищает от перезаписи
     * уже зафиксированного timestamp'а при повторных итерациях.
     *
     * Реализовано через raw DBAL: polling вызывается в цикле, persist/flush
     * здесь означал бы загрузку entity в UoW на каждой итерации.
     *
     * companyId в WHERE-clause — defense-in-depth против IDOR: даже если
     * ozon_uuid уникален сам по себе, принадлежность к company проверяем
     * на каждой операции записи.
     *
     * @return int число обновлённых строк (0 — ozonUuid не найден в этой company)
     */
    public function updateState(
        string $companyId,
        string $ozonUuid,
        string $state,
        \DateTimeImmutable $lastCheckedAt,
        int $pollAttempts,
        ?\DateTimeImmutable $firstNonPendingAt = null,
    ): int {
        $conn = $this->getEntityManager()->getConnection();

        if (null === $firstNonPendingAt) {
            return (int) $conn->executeStatement(
                <<<'SQL'
                    UPDATE marketplace_ad_pending_reports
                    SET state = :state,
                        last_checked_at = :last_checked_at,
                        poll_attempts = :poll_attempts,
                        updated_at = NOW()
                    WHERE ozon_uuid = :ozon_uuid
                      AND company_id = :company_id
                    SQL,
                [
                    'state' => $state,
                    'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                    'poll_attempts' => $pollAttempts,
                    'ozon_uuid' => $ozonUuid,
                    'company_id' => $companyId,
                ],
            );
        }

        return (int) $conn->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_pending_reports
                SET state = :state,
                    last_checked_at = :last_checked_at,
                    poll_attempts = :poll_attempts,
                    first_non_pending_at = COALESCE(first_non_pending_at, :first_non_pending_at),
                    updated_at = NOW()
                WHERE ozon_uuid = :ozon_uuid
                  AND company_id = :company_id
                SQL,
            [
                'state' => $state,
                'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                'poll_attempts' => $pollAttempts,
                'first_non_pending_at' => $firstNonPendingAt->format('Y-m-d H:i:s'),
                'ozon_uuid' => $ozonUuid,
                'company_id' => $companyId,
            ],
        );
    }

    /**
     * Терминализирует запись: выставляет state (OK/ERROR/ABANDONED), finalized_at,
     * optional errorMessage.
     *
     * Идемпотентно: guard `finalized_at IS NULL` не даст перезаписать уже
     * финализированную запись — параллельный воркер, который видел тот же
     * UUID и пришёл к OK позже, не стирает исходный ERROR/ABANDONED.
     *
     * companyId в WHERE-clause — defense-in-depth против IDOR (см. updateState).
     *
     * @return int число обновлённых строк (0 — uuid не найден в company, уже финализирован)
     */
    public function markFinalized(
        string $companyId,
        string $ozonUuid,
        string $state,
        ?string $errorMessage = null,
    ): int {
        if (!OzonAdPendingReportState::isTerminal($state)) {
            throw new \InvalidArgumentException(sprintf(
                'markFinalized принимает только терминальные state (OK/ERROR/ABANDONED), получено: %s',
                $state,
            ));
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_pending_reports
                SET state = :state,
                    error_message = :error_message,
                    finalized_at = NOW(),
                    updated_at = NOW()
                WHERE ozon_uuid = :ozon_uuid
                  AND company_id = :company_id
                  AND finalized_at IS NULL
                SQL,
            [
                'state' => $state,
                'error_message' => $errorMessage,
                'ozon_uuid' => $ozonUuid,
                'company_id' => $companyId,
            ],
        );
    }

    public function findByOzonUuid(string $companyId, string $ozonUuid): ?OzonAdPendingReport
    {
        return $this->findOneBy(['companyId' => $companyId, 'ozonUuid' => $ozonUuid]);
    }

    /**
     * Все in-flight записи (REQUESTED / NOT_STARTED / IN_PROGRESS) для конкретного job'а.
     * Пригодится для задачи 3 (resume on Messenger retry): handler при повторном
     * запуске получит список UUID, по которым надо продолжать polling вместо
     * нового POST /statistics.
     *
     * @return list<OzonAdPendingReport>
     */
    public function findInFlightByJob(string $companyId, string $jobId): array
    {
        /** @var list<OzonAdPendingReport> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.companyId = :companyId')
            ->andWhere('r.jobId = :jobId')
            ->andWhere('r.state IN (:inFlightStates)')
            ->andWhere('r.finalizedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('jobId', $jobId)
            ->setParameter('inFlightStates', OzonAdPendingReportState::IN_FLIGHT_STATES)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Unix-timestamp момента, когда POST /statistics был отправлен Ozon'у.
     *
     * Используется в resume-ветке {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient}:
     * если handler перезапущен Messenger'ом и продолжает polling существующего
     * UUID, NOT_STARTED-таймаут должен отсчитываться от исходного requestedAt,
     * а не от момента resume — иначе зависший в очереди отчёт получит новое
     * 5-минутное окно на каждом retry, и queue-full exception никогда не
     * сработает.
     *
     * requestedAt проставляется в конструкторе Entity и не nullable в схеме,
     * но тип возврата допускает null на случай будущих расширений — caller
     * должен быть готов использовать fallback (now).
     */
    public function getPollStartTime(OzonAdPendingReport $report): ?float
    {
        return (float) $report->getRequestedAt()->getTimestamp();
    }
}
