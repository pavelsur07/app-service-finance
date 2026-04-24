<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Repository;

use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * Репозиторий плана последовательной обработки рекламных батчей Ozon Performance.
 *
 * Dead code на момент Task-11.2: никто ещё не вызывает. Будет использован
 * cron-командами шагов Task-11.3+ (planner / poster / poller / finalizer).
 *
 * {@see self::findNextPlanned()} — точка входа scheduler-cron'а, берёт один
 * готовый PLANNED-батч через `FOR UPDATE SKIP LOCKED`: параллельные воркеры
 * не мешают друг другу (скипают уже захваченные строки), один тик гарантированно
 * обслуживает не более одного батча на worker'а.
 */
final class AdScheduledBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdScheduledBatch::class);
    }

    /**
     * Возвращает 1 PLANNED-батч, готовый к обработке, с `FOR UPDATE SKIP LOCKED`.
     *
     * Семантика:
     *  - несколько scheduler-воркеров могут вызывать метод одновременно;
     *  - каждый получит свою строку (либо null, если доступных нет);
     *  - блокировка держится до конца текущей транзакции вызывающего кода —
     *    вызывающий обязан обернуть findNextPlanned + state transition в одну
     *    транзакцию, иначе гарантии изоляции теряются.
     *
     * Порядок детерминированный — `scheduled_at ASC, batch_index ASC`:
     *  - primary по scheduled_at → более старые задачи берутся раньше;
     *  - secondary по batch_index → внутри одного тика батчи одного job'а
     *    идут по возрастанию (снижает реордеринг в логах).
     *
     * Предикат `scheduled_at <= NOW()` обязателен: retry/backoff-логика
     * (Task-11.3+) двигает `scheduled_at` в будущее через `setScheduledAt()`,
     * и без фильтра scheduler подхватит батч раньше запланированного момента,
     * сведя на нет throttling.
     *
     * Реализация через native SQL: Doctrine DQL не поддерживает `SKIP LOCKED`
     * (только `PESSIMISTIC_WRITE` → простой `FOR UPDATE`, который блокирует
     * воркер вместо того, чтобы пропустить строку).
     */
    public function findNextPlanned(): ?AdScheduledBatch
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(AdScheduledBatch::class, 'b');

        $sql = sprintf(
            'SELECT %s FROM marketplace_ad_scheduled_batches b '
            . 'WHERE b.state = :state '
            . '  AND b.scheduled_at <= NOW() '
            . 'ORDER BY b.scheduled_at ASC, b.batch_index ASC '
            . 'LIMIT 1 FOR UPDATE SKIP LOCKED',
            $rsm->generateSelectClause(['b' => 'b']),
        );

        $query = $this->getEntityManager()->createNativeQuery($sql, $rsm);
        $query->setParameter('state', AdScheduledBatchState::PLANNED->value);

        /** @var AdScheduledBatch|null $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }

    /**
     * Все IN_FLIGHT батчи для poller-cron'а.
     *
     * Order: `started_at ASC` (старые первыми, чтобы зависшие батчи не держали
     * слот дольше нужного). Батчи без `started_at` (теоретически не должны
     * существовать в IN_FLIGHT, но защита от рассинхрона) идут в конец через
     * `NULLS LAST`.
     *
     * @return list<AdScheduledBatch>
     */
    public function findAllInFlight(): array
    {
        /** @var list<AdScheduledBatch> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.state = :state')
            ->setParameter('state', AdScheduledBatchState::IN_FLIGHT)
            ->orderBy('b.startedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * IDOR-safe lookup по PK + companyId.
     *
     * Используется download-контроллером
     * {@see \App\MarketplaceAds\Controller\AdScheduledBatchDownloadController}:
     * подмена `{id}` в URL чужой компании возвращает null → 404. Паттерн
     * консистентен с `OzonAdPendingReportRepository::findByIdAndCompany`.
     */
    public function findByIdAndCompany(string $id, string $companyId): ?AdScheduledBatch
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->findOneBy([
            'id' => $id,
            'companyId' => $companyId,
        ]);
    }

    /**
     * Все батчи конкретного job'а (любого state).
     *
     * IDOR-guard: `companyId` в WHERE. Чужой `jobId`, случайно пробитый
     * из UI/API, вернёт пустой массив вместо утечки.
     *
     * @return list<AdScheduledBatch>
     */
    public function findByJobId(string $jobId, string $companyId): array
    {
        Assert::uuid($jobId);
        Assert::uuid($companyId);

        /** @var list<AdScheduledBatch> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.jobId = :jobId')
            ->andWhere('b.companyId = :companyId')
            ->setParameter('jobId', $jobId)
            ->setParameter('companyId', $companyId)
            ->orderBy('b.batchIndex', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Сводка состояний батчей job'а для finalizer-cron'а.
     *
     * Возвращает ассоциативный массив `[state => count]` с ключами из
     * {@see AdScheduledBatchState::value}. В результат попадают только те
     * состояния, для которых есть хотя бы 1 запись — пустые буксеты в
     * GROUP BY не появляются.
     *
     * Raw DBAL: finalizer работает частыми тиками, гидратация entity
     * избыточна (нужен только COUNT(*)).
     *
     * IDOR-guard: `company_id` в WHERE — даже если jobId принадлежит чужой
     * компании, получим пустую сводку.
     *
     * @return array<string, int>
     */
    public function countStatesForJob(string $jobId, string $companyId): array
    {
        Assert::uuid($jobId);
        Assert::uuid($companyId);

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT state, COUNT(*) AS cnt
                FROM marketplace_ad_scheduled_batches
                WHERE job_id = :job_id
                  AND company_id = :company_id
                GROUP BY state
                SQL,
            [
                'job_id' => $jobId,
                'company_id' => $companyId,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['state']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Persist без flush — консистентно с {@see AdLoadJobRepository::save()}.
     *
     * Flush делегируется вызывающему коду:
     *  - planner-cron (Task-11.3+) создаёт 20–30 батчей на job и делает один
     *    flush в конце цикла, а не N round-trips;
     *  - scheduler/poster/poller/finalizer мутируют батч в рамках одной
     *    транзакции с `findNextPlanned() FOR UPDATE SKIP LOCKED`, flush в
     *    конце транзакции.
     */
    public function save(AdScheduledBatch $batch): void
    {
        $this->getEntityManager()->persist($batch);
    }

    /**
     * Батчи job'а с заполненным `storage_path` — для UI «Открыть» (Task-11.8).
     *
     * Фильтр `storage_path IS NOT NULL` вынесен в SQL: показывать в списке
     * «нечего скачивать» записи без файла — баг UX.
     *
     * IDOR-guard: `companyId` обязателен, метод вызывается из HTTP-контекста
     * (Task-11.8 UI), подмена `jobId` не должна отдать чужие download-ссылки.
     *
     * @return list<AdScheduledBatch>
     */
    public function findDownloadableByJobId(string $jobId, string $companyId): array
    {
        Assert::uuid($jobId);
        Assert::uuid($companyId);

        /** @var list<AdScheduledBatch> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.jobId = :jobId')
            ->andWhere('b.companyId = :companyId')
            ->andWhere('b.storagePath IS NOT NULL')
            ->setParameter('jobId', $jobId)
            ->setParameter('companyId', $companyId)
            ->orderBy('b.batchIndex', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
