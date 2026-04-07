<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Переобработка затрат через process()-путь (DELETE + reinsert)
 * для документов, у которых marketplace_costs ссылаются на категории чужой компании.
 *
 * Примеры:
 *   php bin/console marketplace:costs:reprocess --dry-run
 *   php bin/console marketplace:costs:reprocess --company-id=<UUID>
 *   php bin/console marketplace:costs:reprocess
 */
#[AsCommand(
    name: 'marketplace:costs:reprocess',
    description: 'Переобработка затрат с неправильными категориями (DELETE + reinsert)',
)]
final class ReprocessMarketplaceCostsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MarketplaceSyncFacade $syncFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании (опционально)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать что будет обработано, без изменений');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company-id');
        $dryRun = $input->getOption('dry-run');

        if ($companyId !== null) {
            try {
                Assert::uuid($companyId);
            } catch (\InvalidArgumentException) {
                $io->error('Некорректный формат --company-id, ожидается UUID.');

                return Command::FAILURE;
            }
        }

        $io->title('Переобработка затрат с неправильными категориями' . ($dryRun ? ' [DRY-RUN]' : ''));

        $documents = $this->findDocumentsWithMismatchedCategories($companyId);

        if ($documents === []) {
            $io->success('Не найдено документов с неправильными категориями.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Найдено документов: %d', count($documents)));
        $io->newLine();

        $io->table(
            ['Raw Document ID', 'Company ID', 'Period From', 'Period To'],
            array_map(static fn(array $row): array => [
                $row['id'],
                $row['company_id'],
                $row['period_from'],
                $row['period_to'],
            ], $documents),
        );

        if ($dryRun) {
            $io->note('DRY-RUN: реальных изменений не будет. Уберите --dry-run для запуска.');

            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Будет переобработано %d документов. Продолжить?', count($documents)), false)) {
            return Command::SUCCESS;
        }

        $progressBar = new ProgressBar($output, count($documents));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% — %message%');
        $progressBar->setMessage('Начинаем...');
        $progressBar->start();

        $processed = 0;
        $failedDocs = [];

        foreach ($documents as $row) {
            $progressBar->setMessage($row['id']);

            try {
                $this->syncFacade->processCostsFromRaw($row['company_id'], $row['id']);
                $processed++;
            } catch (\Throwable $e) {
                $failedDocs[] = sprintf('%s: %s', $row['id'], $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Готово');
        $progressBar->finish();
        $io->newLine(2);

        $io->section('Итог');
        $io->text(sprintf('  Обработано: %d', $processed));

        if ($failedDocs !== []) {
            $io->text(sprintf('  Ошибок:     %d', count($failedDocs)));
            $io->newLine();
            $io->warning('Документы с ошибками:');
            $io->listing($failedDocs);
        }

        $io->success('Переобработка затрат завершена.');

        return $failedDocs !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int, array{id: string, company_id: string, period_from: string, period_to: string}>
     */
    private function findDocumentsWithMismatchedCategories(?string $companyId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT mrd.id, mrd.company_id, mrd.period_from, mrd.period_to
            FROM marketplace_raw_documents mrd
            WHERE mrd.processing_status = :status
              AND EXISTS (
                  SELECT 1
                  FROM marketplace_costs mc
                  JOIN marketplace_cost_categories mcc ON mc.category_id = mcc.id
                  WHERE mc.raw_document_id = mrd.id
                    AND mc.company_id != mcc.company_id
              )
            SQL;

        $params = ['status' => PipelineStatus::COMPLETED->value];

        if ($companyId !== null) {
            $sql .= ' AND mrd.company_id = :companyId';
            $params['companyId'] = $companyId;
        }

        $sql .= ' ORDER BY mrd.company_id, mrd.period_from';

        return $this->connection->fetchAllAssociative($sql, $params);
    }
}
