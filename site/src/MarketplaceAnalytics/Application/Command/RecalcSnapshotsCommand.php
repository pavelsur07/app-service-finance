<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Command;

use App\MarketplaceAnalytics\Domain\Service\SnapshotRecalcPolicy;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

#[AsCommand(name: 'app:marketplace-analytics:recalc-snapshots')]
final class RecalcSnapshotsCommand extends Command
{
    public function __construct(
        private readonly SnapshotRecalcPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('lookback-days', null, InputOption::VALUE_OPTIONAL, 'Number of days to look back', 7);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = $input->getOption('company-id');

        try {
            Assert::uuid($companyId);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>Invalid company-id</error>');

            return Command::FAILURE;
        }

        $lookbackDays = (int) $input->getOption('lookback-days');

        try {
            $this->policy->recalcBySchedule($companyId, $lookbackDays);
            $this->entityManager->flush();
            $output->writeln('<info>Done</info>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Recalc command failed', $e);
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
