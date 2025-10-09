<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Repository\Wildberries\WildberriesSaleRepository;
use App\Service\Wildberries\WildberriesSalesImporter;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:wildberries:sync-sales', description: 'Импортирует продажи Wildberries для компании')]
class WildberriesSalesSyncCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companyRepository,
        private readonly WildberriesSaleRepository $saleRepository,
        private readonly WildberriesSalesImporter $salesImporter,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'ID компании для обновления');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company');
        if ('' === $companyId) {
            $output->writeln('<error>Необходимо указать компанию через --company</error>');

            return Command::FAILURE;
        }

        /** @var Company|null $company */
        $company = $this->companyRepository->find($companyId);
        if (!$company) {
            $output->writeln(sprintf('<error>Компания с идентификатором %s не найдена</error>', $companyId));

            return Command::FAILURE;
        }

        $apiKey = $company->getWildberriesApiKey();
        if (!$apiKey) {
            $output->writeln('<comment>Для компании не задан Wildberries API ключ, импорт пропущен</comment>');

            return Command::SUCCESS;
        }

        $dateTo = new DateTimeImmutable('now');
        if (!$this->saleRepository->hasSalesForCompany($company)) {
            $dateFrom = $dateTo->sub(new DateInterval('P80D'));
            $this->logger->info('Wildberries initial sync period calculated', [
                'companyId' => $company->getId(),
                'dateFrom' => $dateFrom->format(DateTimeImmutable::ATOM),
                'dateTo' => $dateTo->format(DateTimeImmutable::ATOM),
            ]);
        } else {
            $oldestOpen = $this->saleRepository->findOldestOpenSoldAt($company);
            if ($oldestOpen instanceof DateTimeImmutable) {
                $dateFrom = $oldestOpen;
            } else {
                $latestSoldAt = $this->saleRepository->findLatestSoldAt($company);
                if ($latestSoldAt instanceof DateTimeImmutable) {
                    $dateFrom = $latestSoldAt->sub(new DateInterval('P3D'));
                } else {
                    $dateFrom = $dateTo->sub(new DateInterval('P3D'));
                }
            }

            if ($dateFrom > $dateTo) {
                $dateFrom = $dateTo;
            }

            $this->logger->info('Wildberries incremental sync period calculated', [
                'companyId' => $company->getId(),
                'dateFrom' => $dateFrom->format(DateTimeImmutable::ATOM),
                'dateTo' => $dateTo->format(DateTimeImmutable::ATOM),
            ]);
        }

        $processed = $this->salesImporter->import($company, $dateFrom, $dateTo);
        $output->writeln(sprintf(
            'Импорт завершён: %d записей (%s — %s)',
            $processed,
            $dateFrom->format('Y-m-d H:i'),
            $dateTo->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }
}
