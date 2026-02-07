<?php

namespace App\Cash\Command\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Message\Import\CashFileImportMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:cash:import:dispatch')]
final class CashFileImportDispatchCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('jobId', InputArgument::REQUIRED, 'Cash file import job id');
        $this->addOption('force-queue', null, InputOption::VALUE_NONE, 'Force job status to queued');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (string) $input->getArgument('jobId');
        $job = $this->entityManager->find(CashFileImportJob::class, $jobId);

        if (true === $input->getOption('force-queue') && $job instanceof CashFileImportJob) {
            $job->setStatus(CashFileImportJob::STATUS_QUEUED);
            $job->setStartedAt(null);
            $job->setFinishedAt(null);
            $job->setErrorMessage(sprintf(
                'DBG:force_queue %s',
                (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ));
            $this->entityManager->flush();
        }

        $this->bus->dispatch(new CashFileImportMessage($jobId));

        if ($job instanceof CashFileImportJob) {
            $job->setErrorMessage(sprintf(
                'DBG:manual_dispatch %s',
                (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
            ));
            $this->entityManager->flush();
        }

        $output->writeln(sprintf('DISPATCHED %s', $jobId));

        return Command::SUCCESS;
    }
}
