<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\MarketplaceRawDocumentDuplicateAuditQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:marketplace:raw-documents:duplicates-audit',
    description: 'Read-only аудит дублей marketplace_raw_documents перед добавлением unique key',
)]
final class RawDocumentsDuplicatesAuditCommand extends Command
{
    public function __construct(
        private readonly MarketplaceRawDocumentDuplicateAuditQuery $duplicateAuditQuery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = $this->duplicateAuditQuery->findDuplicateGroups();

        $io->title('Marketplace raw documents duplicates audit (read-only)');

        if ($rows === []) {
            $io->success('Конфликтов не найдено: дублей по будущему unique key нет среди активных raw documents.');

            return Command::SUCCESS;
        }

        $tableRows = array_map(
            static function (array $row): array {
                return [
                    (string) $row['company_id'],
                    (string) $row['marketplace'],
                    (string) $row['document_type'],
                    (string) $row['api_endpoint'],
                    (string) $row['period_from'],
                    (string) $row['period_to'],
                    (string) $row['duplicate_count'],
                    is_array($row['raw_document_ids']) ? json_encode($row['raw_document_ids'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $row['raw_document_ids'],
                ];
            },
            $rows,
        );

        $io->table(
            ['company_id', 'marketplace', 'document_type', 'api_endpoint', 'period_from', 'period_to', 'duplicate_count', 'raw_document_ids'],
            $tableRows,
        );

        $io->warning(sprintf('Найдено %d конфликтующих групп(ы). Команда не изменяет данные в БД.', count($rows)));

        return Command::SUCCESS;
    }
}
