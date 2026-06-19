<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:ingestion:normalize-pending',
    description: 'Dispatches normalization messages for stale pending ingestion raw records.',
)]
final class NormalizePendingRawRecordsCommand extends Command
{
    public function __construct(
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum raw records to dispatch.', 50)
            ->addOption('threshold-minutes', null, InputOption::VALUE_REQUIRED, 'Minimum pending age in minutes.', 15);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, min(200, (int) $input->getOption('limit')));
        $thresholdMinutes = max(1, (int) $input->getOption('threshold-minutes'));
        $olderThan = new \DateTimeImmutable(sprintf('-%d minutes', $thresholdMinutes));

        try {
            $records = $this->rawRecordRepository->findStuckPending($olderThan, $limit);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to find stale pending ingestion raw records.', [
                'thresholdMinutes' => $thresholdMinutes,
                'limit' => $limit,
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            $io->error('Failed to find stale pending ingestion raw records.');

            return Command::FAILURE;
        }

        if ([] === $records) {
            $this->logger->debug('No stale pending ingestion raw records found.', [
                'thresholdMinutes' => $thresholdMinutes,
                'limit' => $limit,
            ]);

            return Command::SUCCESS;
        }

        $rawRecordIds = [];
        $dispatched = 0;
        foreach ($records as $record) {
            $rawRecordIds[] = $record->getId();

            try {
                $this->messageBus->dispatch(new NormalizeRawRecordMessage(
                    rawRecordId: $record->getId(),
                    companyId: $record->getCompanyId(),
                ));
                ++$dispatched;
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to dispatch stale pending ingestion raw record normalization.', [
                    'companyId' => $record->getCompanyId(),
                    'rawRecordId' => $record->getId(),
                    'exceptionClass' => $exception::class,
                    'errorMessage' => $exception->getMessage(),
                ]);
            }
        }

        $this->logger->info('Dispatched stale pending ingestion raw records for normalization.', [
            'count' => $dispatched,
            'found' => count($records),
            'rawRecordIds' => $rawRecordIds,
            'thresholdMinutes' => $thresholdMinutes,
            'limit' => $limit,
        ]);
        $io->success(sprintf('Dispatched %d stale pending raw record normalization messages.', $dispatched));

        return Command::SUCCESS;
    }
}
