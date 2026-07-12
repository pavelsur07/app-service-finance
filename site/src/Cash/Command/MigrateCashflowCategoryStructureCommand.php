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
    description: 'Проверяет или переводит категории ДДС компаний на системную CF_* структуру',
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
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить изменения; без флага команда работает в read-only режиме');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getOption('company-id');
        $companyId = is_string($companyId) && '' !== trim($companyId) ? trim($companyId) : null;
        $execute = true === $input->getOption('execute');

        if (null !== $companyId && !Uuid::isValid($companyId)) {
            $io->error('Опция --company-id должна содержать UUID.');

            return Command::INVALID;
        }

        $companyIds = $this->migrator->findCompanyIds($companyId);
        if ([] === $companyIds) {
            $io->error(null === $companyId ? 'Компании не найдены.' : 'Компания не найдена.');

            return Command::FAILURE;
        }

        $io->title($execute ? 'Перенос системных категорий ДДС' : 'Аудит системных категорий ДДС (read-only)');

        $table = [];
        $conflictCount = 0;
        $processedCount = 0;
        foreach ($companyIds as $id) {
            $plan = $this->migrator->plan($id);
            $created = count(array_filter($plan['categories'], static fn (array $category): bool => $category['create']));
            $conflicts = count($plan['conflicts']);
            $conflictCount += $conflicts;

            if ($execute && 0 === $conflicts) {
                $this->migrator->execute($plan);
                ++$processedCount;
            }

            $table[] = [
                $id,
                (string) $created,
                (string) (count($plan['categories']) - $created),
                (string) count($plan['rootsToMove']),
                0 === $conflicts ? ($execute ? 'DONE' : 'READY') : 'CONFLICT',
            ];

            foreach ($plan['conflicts'] as $conflict) {
                $io->warning(sprintf('%s: %s', $id, $conflict));
            }
        }

        $io->table(['company_id', 'create', 'reuse', 'move roots', 'status'], $table);

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
