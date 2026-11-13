<?php

declare(strict_types=1);

namespace App\Cash\Command\Import;

use App\Cash\Application\Import\File\DispatchCashFileImportJobAction;
use App\Cash\Infrastructure\Lock\CashImportDeliveryLock;
use App\Cash\Infrastructure\Messenger\ImportQueueProbeInterface;
use App\Cash\Infrastructure\Query\CashFileImportDeliveryQuery;
use App\Cash\Infrastructure\Query\CashIntegrityCompaniesQuery;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cash:import:recover-delivery', description: 'Повторяет отсутствующую доставку queued-заданий старше 120 секунд; по умолчанию dry-run')]
final class RecoverCashImportDeliveryCommand extends Command
{
    public function __construct(
        private readonly CashIntegrityCompaniesQuery $companies,
        private readonly CashFileImportDeliveryQuery $jobs,
        private readonly CashImportDeliveryLock $lock,
        private readonly ImportQueueProbeInterface $queue,
        private readonly DispatchCashFileImportJobAction $dispatch,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('companyId', InputArgument::OPTIONAL, 'UUID компании; без аргумента — все компании с подходящими заданиями')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Повторить отправку отсутствующих сообщений');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getArgument('companyId');
        if (null !== $companyId && (!is_string($companyId) || !Uuid::isValid($companyId))) {
            $io->error('companyId должен быть UUID.');

            return self::INVALID;
        }
        $execute = true === $input->getOption('execute');
        $before = new \DateTimeImmutable('-120 seconds');
        $companies = null === $companyId ? $this->companies->withQueuedImportJobsBefore($before) : [$companyId];
        sort($companies, \SORT_STRING);
        $locked = [];
        $candidates = [];
        $missing = 0;
        $sent = 0;
        try {
            // Acquire every lease before the shared snapshot; concurrent runs cannot send from stale snapshots.
            foreach ($companies as $company) {
                if (!$this->lock->tryAcquire($company)) {
                    $io->note('Компания занята другим восстановителем: '.$company);
                    continue;
                }
                $locked[] = $company;
                $cursor = null;
                do {
                    $page = $this->jobs->queuedPage($company, $before, $cursor);
                    foreach ($page as $id) {
                        $candidates[$id] = $company;
                        $cursor = $id;
                    }
                } while (100 === count($page));
            }
            if ([] !== $candidates) {
                try {
                    $present = array_fill_keys($this->queue->findQueued(array_keys($candidates)), true);
                } catch (\RuntimeException $exception) {
                    $this->logger->warning('Cash import recovery queue probe unavailable', ['exception_class' => $exception::class]);
                    $io->error('Очередь недоступна для проверки. Повторная доставка не выполнялась.');

                    return self::FAILURE;
                }
                foreach ($candidates as $id => $company) {
                    if (isset($present[$id]) || !$this->jobs->isQueuedForDelivery($company, $id, $before)) {
                        continue;
                    }
                    ++$missing;
                    if ($execute) {
                        if (!($this->dispatch)($company, $id)) {
                            $io->error('Доставка прервана: очередь недоступна. Сохранённые queued-задания доступны следующему запуску.');

                            return self::FAILURE;
                        }
                        ++$sent;
                    }
                }
            }
        } finally {
            foreach (array_reverse($locked) as $company) {
                $this->lock->release($company);
            }
        }
        $io->definitionList(['mode' => $execute ? 'execute' : 'dry-run'], ['candidates' => count($candidates)], ['missing' => $missing], ['dispatched' => $sent]);

        return self::SUCCESS;
    }
}
