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
     * @return int число обновлённых строк (0 — ozonUuid не найден)
     */
    public function updateState(
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
                    SQL,
                [
                    'state' => $state,
                    'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                    'poll_attempts' => $pollAttempts,
                    'ozon_uuid' => $ozonUuid,
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
                SQL,
            [
                'state' => $state,
                'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                'poll_attempts' => $pollAttempts,
                'first_non_pending_at' => $firstNonPendingAt->format('Y-m-d H:i:s'),
                'ozon_uuid' => $ozonUuid,
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
     * @return int число обновлённых строк (0 — uuid не найден или уже финализирован)
     */
    public function markFinalized(string $ozonUuid, string $state, ?string $errorMessage = null): int
    {
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
                  AND finalized_at IS NULL
                SQL,
            [
                'state' => $state,
                'error_message' => $errorMessage,
                'ozon_uuid' => $ozonUuid,
            ],
        );
    }

    public function findByOzonUuid(string $ozonUuid): ?OzonAdPendingReport
    {
        return $this->findOneBy(['ozonUuid' => $ozonUuid]);
    }

    /**
     * Все in-flight записи (REQUESTED / NOT_STARTED / IN_PROGRESS) для конкретного job'а.
     * Пригодится для задачи 3 (resume on Messenger retry): handler при повторном
     * запуске получит список UUID, по которым надо продолжать polling вместо
     * нового POST /statistics.
     *
     * @return list<OzonAdPendingReport>
     */
    public function findInFlightByJob(string $jobId): array
    {
        /** @var list<OzonAdPendingReport> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.jobId = :jobId')
            ->andWhere('r.state IN (:inFlightStates)')
            ->andWhere('r.finalizedAt IS NULL')
            ->setParameter('jobId', $jobId)
            ->setParameter('inFlightStates', OzonAdPendingReportState::IN_FLIGHT_STATES)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
