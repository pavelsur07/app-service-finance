<?php

namespace App\Analytics\Command;

use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\PeriodResolver;
use App\Cash\Enum\FiatCurrency;
use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Service\PLRegisterUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'analytics:dashboard:warmup',
    description: 'Warms analytics dashboard snapshot cache and optionally recalculates PL register.',
)]
final class AnalyticsDashboardWarmupCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly PeriodResolver $periodResolver,
        private readonly DashboardSnapshotService $dashboardSnapshotService,
        private readonly PLRegisterUpdater $plRegisterUpdater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company UUID or all', 'all')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Period preset (day|week|month)', 'month')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Custom period from date (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Custom period to date (YYYY-MM-DD)')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Cash currency (RUB|USD|EUR|KZT)', FiatCurrency::RUB->value)
            ->addOption('recalc-pl', null, InputOption::VALUE_REQUIRED, 'Recalculate PL register before warmup (0|1)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyOption = (string) $input->getOption('company');
        $preset = $this->nullableString($input->getOption('preset'));
        $from = $this->nullableString($input->getOption('from'));
        $to = $this->nullableString($input->getOption('to'));
        $cashCurrency = FiatCurrency::fromCode((string) $input->getOption('currency'));
        $recalcPl = '1' === (string) $input->getOption('recalc-pl');

        $period = $this->periodResolver->resolve(new SnapshotQuery($preset, $from, $to));
        $companies = $this->resolveCompanies($companyOption);

        if ([] === $companies) {
            $output->writeln('<comment>No companies found for warmup.</comment>');

            return Command::SUCCESS;
        }

        foreach ($companies as $company) {
            if ($recalcPl) {
                $this->plRegisterUpdater->recalcRange($company, $period->getFrom(), $period->getTo());
            }

            $snapshot = $this->dashboardSnapshotService->getSnapshot($company, $period, $cashCurrency);
            $context = $snapshot->toArray()['context'];
            $companyId = (string) $company->getId();
            $cacheKey = $this->dashboardSnapshotService->buildCacheKey($company, $period, $cashCurrency);

            $output->writeln(sprintf(
                'company_id=%s cash_currency=%s cache_key=%s last_updated_at=%s',
                $companyId,
                $cashCurrency->value,
                $cacheKey,
                (string) ($context['last_updated_at'] ?? ''),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Company>
     */
    private function resolveCompanies(string $companyOption): array
    {
        if ('all' === $companyOption) {
            /** @var list<Company> $companies */
            $companies = $this->companyRepository->findAll();

            return $companies;
        }

        $company = $this->companyRepository->find($companyOption);
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException(sprintf('Company "%s" not found.', $companyOption));
        }

        return [$company];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }
}
