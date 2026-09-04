<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Application\RequestWbInventorySnapshotAction;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:inventory:wb-daily-sync',
    description: 'Initiates daily Wildberries inventory snapshot pipeline for active SELLER connections',
)]
final class WbInventoryDailySyncCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly RequestWbInventorySnapshotAction $requestSnapshotAction,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('start');
            $output->writeln('Another inventory daily-sync is running, skipping.');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        try {
            $output->writeln('start');

            $connections = $this->marketplaceFacade->getActiveWbSellerConnections();
            $companyIds = array_values(array_unique(array_map(
                static fn (array $row): string => (string) ($row['companyId'] ?? ''),
                $connections,
            )));
            $companyIds = array_values(array_filter($companyIds, static fn (string $companyId): bool => '' !== $companyId));

            $output->writeln(sprintf('active connections count: %d / queued count: %d', count($connections), 0));
            $output->writeln(sprintf('unique companies count: %d', count($companyIds)));

            $queuedCount = 0;
            $skippedCount = 0;
            $errorsCount = 0;
            $failedCompanyIds = [];
            $lastError = null;

            foreach ($companyIds as $companyId) {
                try {
                    $result = ($this->requestSnapshotAction)(
                        $companyId,
                        SnapshotTriggerType::ScheduledNight,
                    );

                    $queuedCount += $result->queuedCount;
                    $skippedCount += $result->skippedCount;
                } catch (\Throwable $e) {
                    ++$errorsCount;
                    $failedCompanyIds[] = $companyId;
                    $lastError = $e->getMessage();
                    // Action только создаёт сессию и ставит сообщение в очередь — API WB
                    // здесь не вызывается, поэтому любое исключение неожиданно. Детали
                    // по компании — warning, один агрегированный error — после цикла.
                    $this->logger->warning('WB inventory daily sync failed for company.', [
                        'companyId' => $companyId,
                        'exceptionClass' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                    $output->writeln(sprintf('company %s error: %s', $companyId, $e->getMessage()));
                }
            }

            if ($errorsCount > 0) {
                $this->logger->error('WB inventory daily sync failed for some companies.', [
                    'failedCount' => $errorsCount,
                    'companyIds' => $failedCompanyIds,
                    'lastError' => $lastError,
                ]);
            }

            $output->writeln(sprintf('active connections count: %d / queued count: %d', count($connections), $queuedCount));
            $output->writeln(sprintf('unique companies count: %d', count($companyIds)));
            $output->writeln(sprintf('skipped count: %d', $skippedCount));
            $output->writeln(sprintf('errors count: %d', $errorsCount));
            $output->writeln('finish');

            // Ненулевой exit только когда прогон не дал ничего: тихий SUCCESS на
            // полностью упавшем запуске — это ровно тот режим отказа, из-за которого
            // WB-остатки молча стояли три недели. Частичный сбой оставляем SUCCESS,
            // иначе одно битое подключение делает cron вечно красным.
            return $errorsCount > 0 && 0 === $queuedCount ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('errors count: %d', 1));
            $output->writeln(sprintf('orchestration failure: %s', $e->getMessage()));
            $output->writeln('finish');

            return self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
