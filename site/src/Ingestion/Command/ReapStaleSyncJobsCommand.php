<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Освобождает ресурсы, заблокированные зависшими задачами синхронизации.
 *
 * `SyncJobRepository::findLatestForResource()` считает активной любую задачу в
 * OPEN/RUNNING без ограничения по возрасту, а `StartIncrementalAction` бросает
 * на неё `ActiveBackfillExistsException`. Воркер, убитый по SIGKILL или OOM, не
 * выполняет `finally`, задача остаётся RUNNING, и загрузка ресурса прекращается
 * молча: `RunIncrementalCommand` посчитает это как skippedActive и вернёт успех.
 *
 * Без этой уборки состояние «заблокировано» недостижимо для исправления, а
 * недостижимое состояние — дефект пайплайна (CLAUDE.md, «Health-гейты»).
 */
#[AsCommand(
    name: 'app:ingestion:reap-stale-jobs',
    description: 'Fails ingestion sync jobs stuck in OPEN/RUNNING so their resource unblocks.',
)]
final class ReapStaleSyncJobsCommand extends Command
{
    use LockableTrait;

    private const DEFAULT_OLDER_THAN_HOURS = 6;
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly SyncJobRepository $syncJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum age without progress, in hours.',
                self::DEFAULT_OLDER_THAN_HOURS,
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs per run.', self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be failed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Another reap run is in progress, skipping.');

            return self::SUCCESS;
        }

        try {
            $hours = max(1, (int) $input->getOption('older-than-hours'));
            $limit = max(1, (int) $input->getOption('limit'));
            $dryRun = (bool) $input->getOption('dry-run');

            $olderThan = new \DateTimeImmutable(sprintf('-%d hours', $hours));
            $jobs = $this->syncJobRepository->findStaleActive($olderThan, $limit);

            $output->writeln(sprintf('stale jobs found: %d (older than %d h)', count($jobs), $hours));

            if ([] === $jobs || $dryRun) {
                $output->writeln('finish');

                return self::SUCCESS;
            }

            foreach ($jobs as $job) {
                $job->markFailed('stale_no_progress');

                // Класс проблемы, а не инцидент конкретного прогона: воркер мог
                // быть убит штатно при деплое. Поэтому warning, а не error.
                $this->logger->warning('Ingestion sync job reaped as stale.', [
                    'companyId' => $job->getCompanyId(),
                    'jobId' => $job->getId(),
                    'source' => $job->getSource()->value,
                    'resourceType' => $job->getResourceType(),
                    'shopRef' => $job->getShopRef(),
                ]);
            }

            $this->entityManager->flush();

            $output->writeln(sprintf('reaped: %d', count($jobs)));
            $output->writeln('finish');

            return self::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
