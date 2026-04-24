<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ежедневная точка инициации cron-driven Ozon Performance pipeline (Task-13b).
 *
 * Создаёт один {@see AdLoadJob} за вчерашний день (UTC) для каждой компании
 * с активным Ozon Performance подключением и планирует для него batch'и
 * через {@see AdBatchPlanner}. Дальше работу подхватывают cron'ы
 * scheduler → poller → finalizer → auto-extract → worker (Task-11.5/6/7,
 * Task-13a): без ручных действий до появления AdDocument'ов.
 *
 * Запуск: cron 30 4 * * * в scheduler-контейнере (`TZ=Europe/Moscow`) —
 * фактическое время 04:30 MSK. Дата отчёта — "вчера" в UTC: для MSK-ночи
 * это всё тот же вчерашний календарный день, а у Ozon Performance данные
 * за D закрыты после полуночи UTC следующего дня.
 *
 * {@see LockableTrait} — защита от наложения: если предыдущий тик не
 * завершился (медленный планировщик при большом количестве компаний),
 * новый просто выходит с SUCCESS. Идемпотентность по содержимому
 * дополнительно гарантируется
 * {@see AdLoadJobRepositoryInterface::existsByDateRange()}: повторный
 * ручной запуск в тот же день — все компании skipped.
 *
 * Заменяет устаревший {@see OzonAdDailySyncCommand} (старый Messenger
 * event-driven pipeline): тот остаётся в коде до Task-14, но cron-entry
 * удалён, чтобы не создавать дубликаты job'ов.
 */
#[AsCommand(
    name: 'app:marketplace-ads:daily-sync',
    description: 'Создаёт AdLoadJob за вчера для всех компаний с Ozon Performance подключением',
)]
final class AdDailySyncCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly ActiveOzonPerformanceConnectionsQuery $connectionsQuery,
        private readonly AdBatchPlanner $planner,
        private readonly AdLoadJobRepositoryInterface $jobRepo,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('<comment>Another daily-sync is running, skipping.</comment>');

            return self::SUCCESS;
        }

        try {
            // Вчера в UTC: модуль хранит dateFrom/dateTo как date_immutable
            // (без TZ), а Ozon Performance закрывает данные за D после
            // полуночи UTC следующего дня — это корректная «отчётная дата».
            $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))
                ->setTime(0, 0);

            $companyIds = $this->connectionsQuery->getCompanyIds();

            if ([] === $companyIds) {
                $output->writeln('<info>No companies with active Ozon Performance connection.</info>');

                return self::SUCCESS;
            }

            $output->writeln(sprintf(
                'Daily sync for %d companies, date=%s',
                count($companyIds),
                $yesterday->format('Y-m-d'),
            ));

            $created = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($companyIds as $companyId) {
                try {
                    // Идемпотентность: повторный запуск в тот же день не
                    // создаёт второй job за вчера (по (company, marketplace,
                    // dateFrom, dateTo)), даже если первый оказался FAILED.
                    if ($this->jobRepo->existsByDateRange(
                        $companyId,
                        MarketplaceType::OZON->value,
                        $yesterday,
                        $yesterday,
                    )) {
                        ++$skipped;
                        $this->logger->info('Daily sync: job for yesterday already exists, skip', [
                            'companyId' => $companyId,
                            'date' => $yesterday->format('Y-m-d'),
                        ]);

                        continue;
                    }

                    $job = new AdLoadJob(
                        companyId: $companyId,
                        marketplace: MarketplaceType::OZON,
                        dateFrom: $yesterday,
                        dateTo: $yesterday,
                    );
                    // Job обязан существовать в БД до вызова Planner'а —
                    // AdScheduledBatch ссылается на job_id через FK.
                    $this->em->persist($job);
                    $this->em->flush();

                    try {
                        $batchCount = $this->planner->planBatchesForJob(
                            $job->getId(),
                            $companyId,
                            $yesterday,
                            $yesterday,
                        );
                    } catch (\Throwable $e) {
                        // Планировщик бросает, например, при пустом списке
                        // SKU-кампаний в Ozon. Фиксируем job как FAILED,
                        // чтобы existsByDateRange() в следующий запуск
                        // видел его и не создавал дубликат, а оператор
                        // видел причину в «Истории загрузок».
                        $job->markFailed('Planning error: '.$e->getMessage());
                        $this->em->flush();

                        throw $e;
                    }

                    // Finalizer сканирует именно RUNNING-jobs; без перехода
                    // из PENDING job застрял бы без финализации.
                    $job->markRunning();
                    $this->em->flush();

                    ++$created;
                    $this->logger->info('Daily sync: job created', [
                        'companyId' => $companyId,
                        'jobId' => $job->getId(),
                        'batchCount' => $batchCount,
                        'date' => $yesterday->format('Y-m-d'),
                    ]);
                } catch (\Throwable $e) {
                    // Per-company isolation: сбой у одной компании
                    // (например, Ozon вернул пустой список кампаний или
                    // упал transient) не прерывает обработку остальных.
                    ++$failed;
                    $this->logger->error('Daily sync: company failed', [
                        'companyId' => $companyId,
                        'error' => $e->getMessage(),
                        'exception' => $e::class,
                    ]);
                }
            }

            $output->writeln(sprintf(
                'Totals: created=%d skipped=%d failed=%d',
                $created,
                $skipped,
                $failed,
            ));

            return self::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
