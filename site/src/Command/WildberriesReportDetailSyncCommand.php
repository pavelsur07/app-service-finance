<?php

namespace App\Command;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailImporter;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:wb:report-detail:sync',
    description: 'Синхронизация детализации фин. отчётов Wildberries (v5) с курсором rrd_id'
)]
class WildberriesReportDetailSyncCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly WildberriesReportDetailImporter $importer,
        private readonly WildberriesReportDetailRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'company',
                null,
                InputOption::VALUE_REQUIRED,
                'ID компании (GUID) для импорта'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company');
        if (!$companyId) {
            $io->error('Опция --company обязательна.');

            return Command::INVALID;
        }

        /** @var Company|null $company */
        $company = $this->registry->getRepository(Company::class)->find($companyId);
        if (!$company) {
            $io->error(sprintf('Компания с id=%s не найдена.', $companyId));

            return Command::FAILURE;
        }

        // Проверка наличия API-ключа WB
        if (!method_exists($company, 'getWildberriesApiKey') || !$company->getWildberriesApiKey()) {
            $io->error('Для компании не задан wildberriesApiKey.');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable('now');
        $dateTo = $now;

        // Выбор окна импорта
        if (!$this->repository->hasDetailsForCompany($company)) {
            // Первый запуск: 80 дней истории
            $dateFrom = $now->sub(new \DateInterval('P80D'));
        } else {
            $oldestOpen = $this->repository->findOldestOpenSaleDt($company);
            if ($oldestOpen instanceof \DateTimeImmutable) {
                $dateFrom = $oldestOpen;
            } else {
                $latest = $this->repository->findLatestSaleDt($company);
                if ($latest instanceof \DateTimeImmutable) {
                    $dateFrom = $latest->sub(new \DateInterval('P3D')); // перекрытие на 3 дня
                } else {
                    // На всякий случай fallback к 80 дням
                    $dateFrom = $now->sub(new \DateInterval('P80D'));
                }
            }
        }

        // Коррекция: dateFrom <= dateTo
        if ($dateFrom > $dateTo) {
            $dateFrom = $dateTo;
        }

        $io->writeln(sprintf(
            'Старт импорта детализации WB: company=%s, окно: [%s .. %s], period=daily',
            $company->getId(),
            $dateFrom->format(\DATE_ATOM),
            $dateTo->format(\DATE_ATOM)
        ));

        $processed = $this->importer->import($company, $dateFrom, $dateTo, 'daily');

        $io->success(sprintf(
            'Импорт завершён. Обработано записей: %d. Интервал: [%s .. %s].',
            $processed,
            $dateFrom->format(\DATE_ATOM),
            $dateTo->format(\DATE_ATOM)
        ));

        return Command::SUCCESS;
    }
}
