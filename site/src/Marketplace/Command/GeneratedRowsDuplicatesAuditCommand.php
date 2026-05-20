<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\MarketplaceGeneratedRowsDuplicateAuditQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketplace:generated-rows:duplicates-audit',
    description: 'Read-only аудит дублей generated rows перед добавлением unique keys',
)]
final class GeneratedRowsDuplicatesAuditCommand extends Command
{
    public function __construct(
        private readonly MarketplaceGeneratedRowsDuplicateAuditQuery $auditQuery,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Количество групп в деталях по каждой таблице', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        if ($limit <= 0) {
            $io->error('Опция --limit должна быть положительным целым числом.');

            return Command::FAILURE;
        }

        $salesCount = $this->auditQuery->countSalesDuplicateGroups();
        $returnsCount = $this->auditQuery->countReturnsDuplicateGroups();
        $costsCount = $this->auditQuery->countCostsDuplicateGroups();

        $salesRows = $this->auditQuery->findSalesDuplicateGroups($limit);
        $returnsRows = $this->auditQuery->findReturnsDuplicateGroups($limit);
        $costsRows = $this->auditQuery->findCostsDuplicateGroups($limit);

        $io->title('Marketplace generated rows duplicates audit (read-only)');
        $io->definitionList(
            ['Detail limit per table' => (string) $limit],
            ['Sales duplicate groups' => (string) $salesCount],
            ['Returns duplicate groups' => (string) $returnsCount],
            ['Costs duplicate groups' => (string) $costsCount],
        );

        if (($salesCount + $returnsCount + $costsCount) > 0) {
            $io->warning('Найдены дубли. Перед TASK-010 нужно выполнить отдельный cleanup, иначе unique migration упадёт.');
        }

        $this->renderSection($io, 'marketplace_sales', $salesRows);
        $this->renderSection($io, 'marketplace_returns', $returnsRows);
        $this->renderSection($io, 'marketplace_costs', $costsRows);

        $io->success('Аудит завершён. Команда выполняет только SELECT-запросы и не изменяет данные в БД.');

        return Command::SUCCESS;
    }

    private function renderSection(SymfonyStyle $io, string $tableName, array $rows): void
    {
        $io->section(sprintf('Details: %s', $tableName));

        if ($rows === []) {
            $io->text('No duplicate groups found.');

            return;
        }

        $tableRows = array_map(
            static function (array $row): array {
                return [
                    (string) $row['company_id'],
                    (string) $row['marketplace'],
                    (string) $row['external_id'],
                    (string) $row['duplicate_count'],
                    is_array($row['row_ids']) ? json_encode($row['row_ids'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $row['row_ids'],
                ];
            },
            $rows,
        );

        $io->table(
            ['company_id', 'marketplace', 'external_id', 'duplicate_count', 'row_ids'],
            $tableRows,
        );
    }
}
