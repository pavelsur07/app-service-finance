<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\RecalculateAllocatedAmountsAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:recalculate-allocated-amounts',
    description: 'Пересчитывает распределённую сумму транзакций ДДС по живым документам ОПиУ; без --execute работает read-only',
)]
final class RecalculateAllocatedAmountsCommand extends Command
{
    public function __construct(
        private readonly RecalculateAllocatedAmountsAction $recalculate,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Сохранить пересчитанные суммы')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число транзакций из последнего dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getOption('company');
        $execute = true === $input->getOption('execute');
        $expectedCount = $input->getOption('expected-count');
        $expectedCount = is_string($expectedCount) && ctype_digit($expectedCount) ? (int) $expectedCount : null;

        if (!is_string($companyId) || !Uuid::isValid($companyId)) {
            $io->error('Укажите UUID компании в --company.');

            return Command::INVALID;
        }
        if ($execute && null === $expectedCount) {
            $io->error('Для --execute укажите число транзакций из dry-run в --expected-count.');

            return Command::INVALID;
        }

        try {
            $result = $execute
                ? $this->entityManager->wrapInTransaction(function () use ($companyId, $expectedCount): array {
                    $result = ($this->recalculate)($companyId, true, $expectedCount);
                    $this->entityManager->flush();

                    return $result;
                })
                : ($this->recalculate)($companyId, false);
        } catch (\DomainException $exception) {
            $io->error($exception->getMessage().' Изменений нет.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['mode' => $execute ? 'execute' : 'dry-run'],
            ['candidates' => $result['candidates']],
            ['stored_allocated_sum' => $result['stored_sum']],
            ['updated' => $result['updated']],
        );

        if ($execute) {
            $io->success(sprintf('Пересчитано транзакций: %d.', $result['updated']));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute и --expected-count из этого отчёта.');
        }

        return Command::SUCCESS;
    }
}
