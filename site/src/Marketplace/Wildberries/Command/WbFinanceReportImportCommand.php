<?php

namespace App\Marketplace\Wildberries\Command;

use App\Entity\Company;
use App\Marketplace\Wildberries\Message\WbFinanceReportImportMessage;
use App\Repository\CompanyRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'wb:finance:import',
    description: 'Schedule Wildberries finance report import tasks',
)]
final class WbFinanceReportImportCommand extends Command
{
    private const WINDOW_DAYS = 10;
    private const MAX_SPAN_DAYS = 80;

    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('companyId', null, InputOption::VALUE_OPTIONAL, 'Company identifier (UUID)')
            ->addOption('dateFrom', null, InputOption::VALUE_OPTIONAL, 'Start date (YYYY-MM-DD)')
            ->addOption('dateTo', null, InputOption::VALUE_OPTIONAL, 'End date (YYYY-MM-DD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $this->normalizeOption($input->getOption('companyId'));
        $dateFromOption = $this->normalizeOption($input->getOption('dateFrom'));
        $dateToOption = $this->normalizeOption($input->getOption('dateTo'));

        if (!$companyId && !$dateFromOption && !$dateToOption) {
            return $this->dispatchForAllCompanies($io);
        }

        if (!$companyId) {
            $io->error('Option --companyId is required when specifying a custom date range.');

            return Command::INVALID;
        }

        $company = $this->companies->find($companyId);
        if (!$company instanceof Company) {
            $io->error(sprintf('Company with id %s not found.', $companyId));

            return Command::FAILURE;
        }

        try {
            $dateTo = $dateToOption ? $this->parseDate($dateToOption, '--dateTo') : $this->todayUtc();
            $dateFrom = $dateFromOption ? $this->parseDate($dateFromOption, '--dateFrom') : $dateTo->sub(new \DateInterval('P'.(self::WINDOW_DAYS - 1).'D'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($dateFrom > $dateTo) {
            $io->error('--dateFrom must not be later than --dateTo.');

            return Command::INVALID;
        }

        $spanDays = $dateTo->diff($dateFrom)->days ?? 0;

        if ($spanDays > self::WINDOW_DAYS - 1) {
            $earliestAllowed = $dateTo->sub(new \DateInterval('P'.(self::MAX_SPAN_DAYS - 1).'D'));
            if ($dateFrom < $earliestAllowed) {
                $dateFrom = $earliestAllowed;
            }

            $windows = $this->splitIntoWindows($dateFrom, $dateTo);
            foreach ($windows as [$windowFrom, $windowTo]) {
                $this->dispatchWindow($io, $company, $windowFrom, $windowTo);
            }
        } else {
            $this->dispatchWindow($io, $company, $dateFrom, $dateTo);
        }

        $io->success('Import tasks have been enqueued.');

        return Command::SUCCESS;
    }

    private function dispatchForAllCompanies(SymfonyStyle $io): int
    {
        $companies = $this->companies->findAllWithWildberriesCredentials();
        if (0 === \count($companies)) {
            $io->warning('No companies with Wildberries API key found.');

            return Command::SUCCESS;
        }

        $dateTo = $this->todayUtc();
        $dateFrom = $dateTo->sub(new \DateInterval('P'.(self::WINDOW_DAYS - 1).'D'));

        foreach ($companies as $company) {
            if (!$company instanceof Company) {
                continue;
            }

            $this->dispatchWindow($io, $company, $dateFrom, $dateTo);
        }

        $io->success(sprintf('Scheduled imports for %d companies.', \count($companies)));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function splitIntoWindows(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $windows = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $windowEnd = $cursor->add(new \DateInterval('P'.(self::WINDOW_DAYS - 1).'D'));
            if ($windowEnd > $to) {
                $windowEnd = $to;
            }

            $windows[] = [$cursor, $windowEnd];
            $cursor = $windowEnd->add(new \DateInterval('P1D'));
        }

        return $windows;
    }

    private function dispatchWindow(SymfonyStyle $io, Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $message = new WbFinanceReportImportMessage(
            (string) $company->getId(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            0,
            Uuid::uuid4()->toString(),
        );

        $this->bus->dispatch($message);

        $io->text(sprintf(
            'Queued import: company=%s [%s … %s] importId=%s',
            (string) $company->getId(),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $message->getImportId(),
        ));
    }

    private function parseDate(string $value, string $optionName): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw new \InvalidArgumentException(sprintf('Invalid value for %s: %s', $optionName, $value));
        }

        return $date;
    }

    private function todayUtc(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0, 0, 0);
    }

    private function normalizeOption(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
