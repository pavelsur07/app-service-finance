<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

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

    /**
     * Обновляет поля scheduling (last_checked_at, next_poll_at, poll_attempts)
     * без изменения state / error_message / finalized_at. Используется
     * poll-cron'ом, когда Ozon ещё не отдал нового состояния, но мы
     * зафиксировали попытку и перепланировали следующий тик.
     *
     * Guard `finalized_at IS NULL` — идемпотентно: не обновит уже терминальную
     * запись, даже если параллельный воркер успел её финализировать.
     *
     * companyId в WHERE — defense-in-depth против IDOR (см. updateState).
     *
     * @return int число обновлённых строк (0 — uuid не найден или уже финализирован)
     */
    public function updateSchedule(
        string $companyId,
        string $ozonUuid,
        \DateTimeImmutable $lastCheckedAt,
        \DateTimeImmutable $nextPollAt,
        int $pollAttempts,
    ): int {
        Assert::uuid($companyId);

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_ad_pending_reports
                SET last_checked_at = :last_checked_at,
                    next_poll_at    = :next_poll_at,
                    poll_attempts   = :poll_attempts,
                    updated_at      = NOW()
                WHERE ozon_uuid = :ozon_uuid
                  AND company_id = :company_id
                  AND finalized_at IS NULL
                SQL,
            [
                'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                'next_poll_at' => $nextPollAt->format('Y-m-d H:i:s'),
                'poll_attempts' => $pollAttempts,
                'ozon_uuid' => $ozonUuid,
                'company_id' => $companyId,
            ],
        );
    }

    /**
     * Обновляет state + scheduling одним UPDATE'ом. Нужно poll-cron'у, когда
     * Ozon отдал новое non-terminal state и одновременно надо перепланировать
     * следующий опрос — делать это в два запроса бессмысленно и создаёт гонку
     * с параллельным markFinalized.
     *
     * Отдельный метод (а не extension updateState), чтобы не ломать существующих
     * вызывающих updateState() из старого synchronous polling pipeline.
     *
     * $nextPollAt=null — запланируем "null" в БД (значит «не опрашивать через
     * scheduling», актуально когда Ozon перешёл в OK и дальнейший polling не нужен).
     *
     * companyId в WHERE — IDOR defense-in-depth. Guard `finalized_at IS NULL`
     * — идемпотентность против гонки с markFinalized().
     *
     * @return int число обновлённых строк (0 — uuid не найден или уже финализирован)
     */
    public function updateStateWithSchedule(
        string $companyId,
        string $ozonUuid,
        string $state,
        \DateTimeImmutable $lastCheckedAt,
        ?\DateTimeImmutable $nextPollAt,
        int $pollAttempts,
        ?\DateTimeImmutable $firstNonPendingAt = null,
    ): int {
        Assert::uuid($companyId);

        $sql = <<<'SQL'
            UPDATE marketplace_ad_pending_reports
            SET state = :state,
                last_checked_at = :last_checked_at,
                next_poll_at    = :next_poll_at,
                poll_attempts   = :poll_attempts,
                first_non_pending_at = COALESCE(first_non_pending_at, :first_non_pending_at),
                updated_at      = NOW()
            WHERE ozon_uuid = :ozon_uuid
              AND company_id = :company_id
              AND finalized_at IS NULL
            SQL;

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            [
                'state' => $state,
                'last_checked_at' => $lastCheckedAt->format('Y-m-d H:i:s'),
                'next_poll_at' => $nextPollAt?->format('Y-m-d H:i:s'),
                'poll_attempts' => $pollAttempts,
                'first_non_pending_at' => $firstNonPendingAt?->format('Y-m-d H:i:s'),
                'ozon_uuid' => $ozonUuid,
                'company_id' => $companyId,
            ],
        );
    }

    /**
     * Distinct companyIds, у которых есть хоть одна in-flight запись, готовая
     * к опросу: finalized_at IS NULL AND (next_poll_at IS NULL OR next_poll_at <= :now).
     *
     * next_poll_at IS NULL покрывает два кейса: (1) legacy-строки до step 2,
     * (2) свежесозданные REQUESTED ещё не расписанные командой. Оба кейса
     * = «опросить на ближайшем тике».
     *
     * Raw DBAL: нужен только distinct company_id, гидратация entity избыточна.
     * Partial index idx_ad_pending_report_next_poll (WHERE finalized_at IS NULL)
     * обеспечивает быструю часть `next_poll_at <= :now`.
     *
     * @return list<string>
     */
    public function findCompanyIdsWithDueReports(\DateTimeImmutable $now): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT company_id
                FROM marketplace_ad_pending_reports
                WHERE finalized_at IS NULL
                  AND (next_poll_at IS NULL OR next_poll_at <= :now)
                SQL,
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        return array_map(static fn ($v): string => (string) $v, $rows);
    }

    public function findByOzonUuid(string $companyId, string $ozonUuid): ?OzonAdPendingReport
    {
        return $this->findOneBy(['companyId' => $companyId, 'ozonUuid' => $ozonUuid]);
    }

    /**
     * IDOR-safe lookup по PK + companyId.
     *
     * Используется async-download handler'ом (step 4): Messenger-payload
     * несёт pending report ID и companyId; handler resolve'ит entity по
     * обоим полям, чтобы чужая company никаким способом (подмена id в
     * очереди, повтор retry после смены владельца) не получила доступ к
     * pending-отчёту.
     */
    public function findByIdAndCompany(string $id, string $companyId): ?OzonAdPendingReport
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
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
     * Все in-flight (не финализированные) записи для company, отсортированные
     * по requestedAt ASC — свежие воркеры сначала обрабатывают самые старые.
     *
     * "In-flight" определяется строго по `finalized_at IS NULL` — это единственный
     * источник правды. Фильтр по state умышленно не добавлен: это дублировало бы
     * логику терминализации и могло бы разъехаться с реальным состоянием записи.
     *
     * Используется будущей poll-cron командой (шаг 3 из redesign-плана) для
     * bulk-запроса `GET /api/client/statistics/list` по всем активным UUID
     * одной компании за один проход.
     *
     * @return list<OzonAdPendingReport>
     */
    public function findInFlightByCompany(string $companyId): array
    {
        Assert::uuid($companyId);

        /** @var list<OzonAdPendingReport> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.companyId = :companyId')
            ->andWhere('r.finalizedAt IS NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Счётчик in-flight pending reports конкретной company.
     * Используется backpressure-гейтом в RequestOzonAdBatchHandler:
     * если >= 3 (лимит Ozon «активных отчётов» на аккаунт) — не делаем POST,
     * откладываем Message в очередь.
     *
     * Отличие от findInFlightByCompany(): возвращает только COUNT без hydration,
     * вызывается на каждом сообщении, должен быть быстрым.
     */
    public function countInFlightByCompany(string $companyId): int
    {
        Assert::uuid($companyId);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM marketplace_ad_pending_reports
                WHERE company_id = :company_id
                  AND finalized_at IS NULL
                SQL,
            ['company_id' => $companyId],
        );
    }
}
