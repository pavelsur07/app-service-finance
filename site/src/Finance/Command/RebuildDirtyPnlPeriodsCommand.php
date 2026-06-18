<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Finance\Message\RebuildPnlPeriodMessage;
use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:finance:rebuild-dirty-pnl-periods',
    description: 'Dispatches async rebuild jobs for pending dirty P&L periods.',
)]
final class RebuildDirtyPnlPeriodsCommand extends Command
{
    public function __construct(
        private readonly PLDirtyPeriodRepository $dirtyPeriodRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max', null, InputOption::VALUE_REQUIRED, 'Maximum number of periods to dispatch.', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $max = max(1, min(200, (int) $input->getOption('max')));
        $periods = $this->dirtyPeriodRepository->findPending($max);

        foreach ($periods as $period) {
            if (!$period instanceof PLDirtyPeriod) {
                continue;
            }

            $this->messageBus->dispatch(new RebuildPnlPeriodMessage(
                companyId: $period->getCompanyId(),
                year: $period->getPeriodYear(),
                month: $period->getPeriodMonth(),
                shopRef: $period->getShopRef(),
            ));
        }

        $io->success(sprintf('Dispatched %d P&L rebuild jobs.', count($periods)));

        return Command::SUCCESS;
    }
}
