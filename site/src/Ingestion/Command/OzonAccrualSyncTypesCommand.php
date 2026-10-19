<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\SyncOzonAccrualTypesAction;
use App\Ingestion\Infrastructure\Query\OzonAccrualTypesRawVersionQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Раскладывает уже загруженные версии справочника `accrual/types` в таблицу.
 *
 * В штатной работе таблицу обновляет нормализация новой версии. Команда нужна
 * для первичного заполнения после выкладки и для ручной проверки: неизменная
 * версия Ozon новой сырой записи не создаёт, а значит, и нормализации не будет.
 * Версии применяются от старой к новой, так что итог совпадает с последней.
 */
#[AsCommand(
    name: 'app:ingestion:ozon-accrual:sync-types',
    description: 'Syncs stored Ozon accrual types dictionary versions into ingest_ozon_accrual_types.',
)]
final class OzonAccrualSyncTypesCommand extends Command
{
    public function __construct(
        private readonly OzonAccrualTypesRawVersionQuery $versionQuery,
        private readonly SyncOzonAccrualTypesAction $syncAction,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Only the latest version stored for this company UUID.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $companyId = trim((string) $input->getOption('company-id'));
        $companyId = '' !== $companyId ? $companyId : null;
        if (null !== $companyId) {
            try {
                Assert::uuid($companyId, 'Invalid --company-id UUID.');
            } catch (\InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }
        }

        $io->title('Ozon accrual types dictionary sync');

        $versions = $this->versionQuery->latestPerCompany($companyId);
        if ([] === $versions) {
            $io->warning('No normalized Ozon accrual types versions found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($versions as $version) {
            $result = ($this->syncAction)($version->companyId, $version->rawRecordId, $dryRun);
            $rows[] = [
                $version->companyId,
                $version->rawRecordId,
                $version->seenAt->format('Y-m-d H:i'),
                $result->rows,
                $this->typeIds($result->createdTypeIds),
                $this->typeIds($result->updatedTypeIds),
                $this->typeIds($result->removedTypeIds),
                $result->unchanged,
                $result->stale ? 'stale' : ($result->empty ? 'empty' : 'ok'),
            ];
        }

        $io->table(
            ['companyId', 'rawRecordId', 'fetchedAt', 'rows', 'created', 'updated', 'removed', 'unchanged', 'status'],
            $rows,
        );

        if ($dryRun) {
            $io->note('Dry-run only. ingest_ozon_accrual_types was not changed. Each version is compared with the current table, not with the versions above it, so on first fill every row shows the full dictionary as created.');
        } else {
            $io->success('Ozon accrual types dictionary synced.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<int> $typeIds
     */
    private function typeIds(array $typeIds): string
    {
        if ([] === $typeIds) {
            return '0';
        }

        $shown = array_slice($typeIds, 0, 10);

        return sprintf('%d: %s%s', count($typeIds), implode(',', $shown), count($typeIds) > count($shown) ? ',…' : '');
    }
}
