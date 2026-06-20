<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Infrastructure\Api\Ozon\OzonAccrualClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'app:ingestion:ozon-accrual:probe',
    description: 'Reads Ozon accrual endpoints without writing anything to storage.',
)]
final class OzonAccrualProbeCommand extends Command
{
    public function __construct(private readonly OzonAccrualClientInterface $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID.')
            ->addOption('connection-ref', null, InputOption::VALUE_REQUIRED, 'Marketplace connection UUID.')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'Endpoint: postings, by-day, or types.', 'postings')
            ->addOption('posting-number', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ozon posting number. Repeat up to 200 times for postings endpoint.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date YYYY-MM-DD. Required for by-day.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date YYYY-MM-DD. Required for by-day.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Sample rows to show, 1..20.', 5)
            ->addOption('with-values', null, InputOption::VALUE_NONE, 'Print truncated sample JSON values. By default only keys are shown.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $companyId = $this->requiredUuidOption($input, 'company-id');
            $connectionRef = $this->requiredUuidOption($input, 'connection-ref');
            $endpoint = $this->endpoint($input);
            $limit = $this->intOption($input, 'limit', 1, 20);

            $page = match ($endpoint) {
                'postings' => $this->client->fetchPostings($companyId, $connectionRef, $this->postingNumbers($input)),
                'by-day' => $this->fetchByDay($input, $companyId, $connectionRef),
                'types' => $this->client->fetchTypes($companyId, $connectionRef),
            };
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Ozon accrual probe');
        $io->table(
            ['endpoint', 'rows', 'hasMore', 'nextPageToken'],
            [[
                $endpoint,
                (string) count($page->rows),
                $page->hasMore ? 'yes' : 'no',
                $page->nextPageToken ?? '',
            ]],
        );

        if ([] !== $page->metadata) {
            $io->section('API metadata');
            $io->writeln($this->json($page->metadata));
        }

        $this->printSamples($io, $page, $limit, (bool) $input->getOption('with-values'));

        return Command::SUCCESS;
    }

    private function requiredUuidOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));
        Assert::uuid($value, sprintf('Invalid --%s UUID.', $name));

        return $value;
    }

    private function endpoint(InputInterface $input): string
    {
        $value = trim((string) $input->getOption('endpoint'));
        if (!in_array($value, ['postings', 'by-day', 'types'], true)) {
            throw new \InvalidArgumentException('The --endpoint option must be one of: postings, by-day, types.');
        }

        return $value;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function requiredDateWindow(InputInterface $input): array
    {
        $from = $this->requiredDateOption($input, 'from')->setTime(0, 0);
        $to = $this->requiredDateOption($input, 'to')->setTime(23, 59, 59);
        if ($from > $to) {
            throw new \InvalidArgumentException('The --from date cannot be later than --to.');
        }

        return [$from, $to];
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

    private function fetchByDay(InputInterface $input, string $companyId, string $connectionRef): OzonRawPage
    {
        [$from, $to] = $this->requiredDateWindow($input);
        $rows = [];
        $metadata = [];

        foreach ($this->eachDay($from, $to) as $date) {
            $page = $this->client->fetchByDay($companyId, $connectionRef, $date);
            if ([] !== $page->rows) {
                array_push($rows, ...$page->rows);
            }

            $metadata[] = [
                'date' => $date->format('Y-m-d'),
                'metadata' => $page->metadata,
            ];
        }

        return new OzonRawPage(rows: $rows, hasMore: false, metadata: ['days' => $metadata]);
    }

    /**
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function eachDay(\DateTimeImmutable $from, \DateTimeImmutable $to): \Generator
    {
        for ($date = $from->setTime(0, 0); $date <= $to; $date = $date->modify('+1 day')) {
            yield $date;
        }
    }

    /**
     * @return non-empty-list<string>
     */
    private function postingNumbers(InputInterface $input): array
    {
        $values = $input->getOption('posting-number');
        if (!is_array($values)) {
            $values = [$values];
        }

        $postingNumbers = [];
        foreach ($values as $value) {
            $postingNumber = trim((string) $value);
            if ('' === $postingNumber) {
                continue;
            }

            $postingNumbers[$postingNumber] = $postingNumber;
        }

        $postingNumbers = array_values($postingNumbers);
        if ([] === $postingNumbers) {
            throw new \InvalidArgumentException('The --posting-number option is required for postings endpoint.');
        }

        if (count($postingNumbers) > 200) {
            throw new \InvalidArgumentException('The postings endpoint supports at most 200 --posting-number values.');
        }

        return $postingNumbers;
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

    private function printSamples(SymfonyStyle $io, OzonRawPage $page, int $limit, bool $withValues): void
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

    private function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).'...';
    }
}
