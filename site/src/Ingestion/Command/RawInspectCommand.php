<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:raw:inspect',
    description: 'Shows one stored ingestion raw record structure for a company.',
)]
final class RawInspectCommand extends Command
{
    public function __construct(
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('raw-record-id', InputArgument::REQUIRED, 'Raw record UUID.')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Sample rows to inspect, 1..20.', 3)
            ->addOption('with-values', null, InputOption::VALUE_NONE, 'Print truncated sample JSON values. By default only keys are shown.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $rawRecordId = trim((string) $input->getArgument('raw-record-id'));
            Assert::uuid($rawRecordId, 'Invalid raw record UUID.');

            $companyId = $this->requiredUuidOption($input, 'company-id');
            $limit = $this->intOption($input, 'limit', 1, 20);
            $withValues = (bool) $input->getOption('with-values');

            $record = $this->rawRecordRepository->findByIdAndCompany($rawRecordId, $companyId);
            if (null === $record) {
                throw new \RuntimeException('Raw record was not found for the requested company.');
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Ingestion raw record');
        $this->printMetadata($io, $record);
        $this->printRows($io, $record, $companyId, $limit, $withValues);

        return Command::SUCCESS;
    }

    private function printMetadata(SymfonyStyle $io, IngestRawRecord $record): void
    {
        $io->table(
            ['field', 'value'],
            [
                ['id', $record->getId()],
                ['companyId', $record->getCompanyId()],
                ['connectionRef', $record->getConnectionRef()],
                ['shopRef', $record->getShopRef()],
                ['source', $record->getSource()->value],
                ['resourceType', $record->getResourceType()],
                ['externalId', $record->getExternalId()],
                ['syncJobId', $record->getSyncJobId()],
                ['normalizationStatus', $record->getNormalizationStatus()->value],
                ['fetchedAt', $record->getFetchedAt()->format('Y-m-d H:i:s')],
                ['byteSize', (string) $record->getByteSize()],
                ['hash', $record->getHash()],
                ['storagePath', $record->getStoragePath()],
            ],
        );
    }

    private function printRows(SymfonyStyle $io, IngestRawRecord $record, string $companyId, int $limit, bool $withValues): void
    {
        $rows = [];
        $totalSeen = 0;

        foreach ($this->rawStorageFacade->read($record->getId(), $companyId) as $row) {
            ++$totalSeen;
            if (count($rows) >= $limit) {
                continue;
            }

            $keys = array_keys($row);
            sort($keys);

            $rows[] = [
                (string) $totalSeen,
                implode(', ', $keys),
                $withValues ? $this->truncate($this->json($row), 3000) : '',
            ];
        }

        $io->section('Rows');
        $io->writeln(sprintf('Read rows: %d', $totalSeen));
        if ([] === $rows) {
            $io->writeln('No rows found in storage.');

            return;
        }

        $io->table($withValues ? ['#', 'keys', 'json'] : ['#', 'keys'], array_map(
            static fn (array $row): array => $withValues ? $row : [$row[0], $row[1]],
            $rows,
        ));
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from %d to %d.', $name, $min, $max));
        }

        return $number;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }
}
