<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportClientInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportPage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:wb-finance:probe',
    description: 'Reads one Wildberries finance report page without writing anything to storage.',
)]
final class WbFinanceProbeCommand extends Command
{
    public function __construct(private readonly WbFinanceReportClientInterface $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('connection-ref', null, InputOption::VALUE_REQUIRED, 'Marketplace connection UUID.')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Report date YYYY-MM-DD.')
            ->addOption('rrd-id', null, InputOption::VALUE_REQUIRED, 'Page cursor rrdId.', 0)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'API page limit, 1..100000.', 100000)
            ->addOption('sample-limit', null, InputOption::VALUE_REQUIRED, 'Sample rows to show, 1..20.', 5)
            ->addOption('with-values', null, InputOption::VALUE_NONE, 'Print truncated sample JSON values. By default only keys are shown.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            $connectionRef = $this->requiredUuidOption($input, 'connection-ref');
            $date = $this->requiredDateOption($input, 'date');
            $rrdId = $this->intOption($input, 'rrd-id', 0, PHP_INT_MAX);
            $limit = $this->intOption($input, 'limit', 1, 100000);
            $sampleLimit = $this->intOption($input, 'sample-limit', 1, 20);

            $page = $this->client->fetchDetailedDayPage($companyId, $connectionRef, $date, $rrdId, $limit);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Wildberries finance report probe');
        $io->table(
            ['date', 'rrdId', 'rows', 'hasMore', 'nextRrdId'],
            [[
                $date->format('Y-m-d'),
                (string) $rrdId,
                (string) count($page->rows),
                $page->hasMore ? 'yes' : 'no',
                null !== $page->nextRrdId ? (string) $page->nextRrdId : '',
            ]],
        );

        if ([] !== $page->metadata) {
            $io->section('API metadata');
            $io->writeln($this->json($page->metadata));
        }

        $this->printSamples($io, $page, $sampleLimit, (bool) $input->getOption('with-values'));

        return Command::SUCCESS;
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function requiredDateOption(InputInterface $input, string $name): \DateTimeImmutable
    {
        $value = trim((string) $input->getOption($name));
        Assert::notEmpty($value, sprintf('The --%s option is required.', $name));

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a YYYY-MM-DD date.', $name));
        }

        return $date;
    }

    private function intOption(InputInterface $input, string $name, int $min, int $max): int
    {
        $value = (string) $input->getOption($name);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer.', $name));
        }

        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be between %d and %d.', $name, $min, $max));
        }

        return $number;
    }

    private function printSamples(SymfonyStyle $io, WbFinanceReportPage $page, int $limit, bool $withValues): void
    {
        if ([] === $page->rows) {
            $io->note('Endpoint returned no rows.');

            return;
        }

        $rows = [];
        foreach (array_slice($page->rows, 0, $limit) as $index => $row) {
            $keys = array_keys($row);
            sort($keys);

            $rows[] = [
                (string) ($index + 1),
                implode(', ', $keys),
                $withValues ? $this->truncate($this->json($row), 2000) : '',
            ];
        }

        $io->section('Sample rows');
        $io->table($withValues ? ['#', 'keys', 'json'] : ['#', 'keys'], array_map(
            static fn (array $row): array => $withValues ? $row : [$row[0], $row[1]],
            $rows,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 3).'...';
    }
}
