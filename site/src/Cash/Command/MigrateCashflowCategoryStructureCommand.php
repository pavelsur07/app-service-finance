<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Service\Category\CashflowCategoryStructureMigrator;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cashflow-categories:migrate-system-structure',
    description: 'Проверяет или синхронизирует системную CF_* структуру без перемещения обычных root-категорий',
)]
final class MigrateCashflowCategoryStructureCommand extends Command
{
    public function __construct(private readonly CashflowCategoryStructureMigrator $migrator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Обработать только одну компанию')
            ->addOption('companies-with-accounts', null, InputOption::VALUE_NONE, 'Обработать только компании, у которых есть счета ДДС')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить изменения; без флага команда работает в read-only режиме');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getOption('company-id');
        $companyId = is_string($companyId) && '' !== trim($companyId) ? trim($companyId) : null;
        $companiesWithAccounts = true === $input->getOption('companies-with-accounts');
        $execute = true === $input->getOption('execute');

        if (null !== $companyId && !Uuid::isValid($companyId)) {
            $io->error('Опция --company-id должна содержать UUID.');

            return Command::INVALID;
        }

        if (null !== $companyId && $companiesWithAccounts) {
            $io->error('Опции --company-id и --companies-with-accounts нельзя использовать одновременно.');

            return Command::INVALID;
        }

        $companyIds = $companiesWithAccounts
            ? $this->migrator->findCompanyIdsWithMoneyAccounts()
            : $this->migrator->findCompanyIds($companyId);
        if ([] === $companyIds) {
            $io->error($companiesWithAccounts ? 'Компании со счетами ДДС не найдены.' : (null === $companyId ? 'Компании не найдены.' : 'Компания не найдена.'));

            return Command::FAILURE;
        }

        $io->title($execute ? 'Синхронизация системных категорий ДДС' : 'Аудит системных категорий ДДС (read-only)');

        $createdCount = 0;
        $reusedCount = 0;
        $warningCompanyCount = 0;
        $conflictCount = 0;
        $conflictCompanyCount = 0;
        $readyCompanyCount = 0;
        $processedCount = 0;
        foreach ($companyIds as $id) {
            $plan = $this->migrator->plan($id);
            $created = count(array_filter($plan['categories'], static fn (array $category): bool => $category['create']));
            $conflicts = count($plan['conflicts']);
            $createdCount += $created;
            $reusedCount += count($plan['categories']) - $created;
            $warningCompanyCount += [] === $plan['warnings'] ? 0 : 1;
            $conflictCount += $conflicts;
            $conflictCompanyCount += 0 === $conflicts ? 0 : 1;
            $readyCompanyCount += 0 === $conflicts ? 1 : 0;

            if ($execute && 0 === $conflicts) {
                try {
                    $this->migrator->execute($plan);
                    ++$processedCount;
                } catch (\Throwable) {
                    $io->error('Не удалось применить план одной компании; выполнение остановлено.');

                    return Command::FAILURE;
                }
            }
        }

        $io->table(
            ['Компаний в scope', $execute ? 'Обработано' : 'Без конфликтов', 'С конфликтами', 'Создать категорий', 'Переиспользовать', 'С legacy TECHNICAL root'],
            [[count($companyIds), $readyCompanyCount, $conflictCompanyCount, $createdCount, $reusedCount, $warningCompanyCount]],
        );

        if ($warningCompanyCount > 0) {
            $io->warning(sprintf(
                'Обычные root-категории с TECHNICAL найдены у компаний: %d. Они не изменяются автоматически.',
                $warningCompanyCount,
            ));
        }

        if ($conflictCount > 0) {
            $io->error(sprintf('Найдено конфликтов: %d. Компании с конфликтами не изменены.', $conflictCount));

            return Command::FAILURE;
        }

        if ($execute) {
            $io->success(sprintf('Обработано компаний: %d.', $processedCount));
        } else {
            $io->note('Изменения не применялись. Для выполнения используйте --execute после проверки отчёта.');
        }

        return Command::SUCCESS;
    }
}
