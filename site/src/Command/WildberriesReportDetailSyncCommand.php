<?php

namespace App\Command;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Marketplace\Wildberries\Service\WildberriesReportDetailImporter;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

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
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Начало интервала (YYYY-MM-DD)'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Конец интервала (YYYY-MM-DD)'
            )
            ->addOption(
                'period',
                null,
                InputOption::VALUE_REQUIRED,
                'Период агрегации (daily|weekly)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company');
        $period = strtolower((string) ($input->getOption('period') ?? 'daily'));
        if (!in_array($period, ['daily', 'weekly'], true)) {
            $io->error('Опция --period должна быть daily или weekly.');

            return Command::INVALID;
        }

        $fromOption = $input->getOption('from');
        $toOption = $input->getOption('to');
        if ((null !== $fromOption && null === $toOption) || (null === $fromOption && null !== $toOption)) {
            $io->error('Опции --from и --to должны передаваться вместе.');

            return Command::INVALID;
        }

        try {
            $manualFrom = $this->parseDateOption($fromOption, 'from', false);
            $manualTo = $this->parseDateOption($toOption, 'to', true);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($manualFrom && $manualTo && $manualFrom > $manualTo) {
            $io->error('Опция --from должна быть меньше или равна --to.');

            return Command::INVALID;
        }

        $companyRepository = $this->registry->getRepository(Company::class);

        $companies = [];
        if ($companyId) {
            /** @var Company|null $company */
            $company = $companyRepository->find($companyId);
            if (!$company) {
                $io->error(sprintf('Компания с id=%s не найдена.', $companyId));

                return Command::FAILURE;
            }

            $companies = [$company];
        } else {
            if (!method_exists($companyRepository, 'createQueryBuilder')) {
                $io->error('Репозиторий Company не поддерживает построение запросов.');

                return Command::FAILURE;
            }

            $companies = $companyRepository
                ->createQueryBuilder('c')
                ->andWhere("c.wildberriesApiKey IS NOT NULL AND c.wildberriesApiKey <> ''")
                ->orderBy('c.name', 'ASC')
                ->getQuery()
                ->getResult();
        }

        if (!$companies) {
            $io->warning('Компании с Wildberries API ключом не найдены.');

            return Command::SUCCESS;
        }

        $hasErrors = false;
        foreach ($companies as $company) {
            \assert($company instanceof Company);

            if (!method_exists($company, 'getWildberriesApiKey') || !$company->getWildberriesApiKey()) {
                $message = sprintf('Пропуск компании %s: не задан wildberriesApiKey.', (string) $company->getId());
                if ($companyId) {
                    $io->error($message);

                    return Command::FAILURE;
                }

                $io->warning($message);

                continue;
            }

            if ($manualFrom && $manualTo) {
                $dateFrom = $manualFrom;
                $dateTo = $manualTo;
            } else {
                [$dateFrom, $dateTo] = $this->calculateAutoWindow($company);
            }

            $io->section(sprintf(
                'Старт импорта WB детализации: company=%s (%s)',
                $company->getId(),
                $company->getName() ?? 'без названия'
            ));
            $io->text(sprintf(
                'Интервал: [%s .. %s], period=%s',
                $dateFrom->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                $dateTo->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                $period
            ));

            try {
                $processed = $this->importer->import($company, $dateFrom, $dateTo, $period);
                $io->success(sprintf('Импорт завершён. Обработано записей: %d.', $processed));
            } catch (Throwable $exception) {
                $hasErrors = true;
                $io->error(sprintf('Ошибка импорта: %s', $exception->getMessage()));
            }
        }

        if ($hasErrors) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function parseDateOption(?string $value, string $optionName, bool $endOfDay): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date) {
            throw new InvalidArgumentException(sprintf('Некорректное значение опции --%s: %s', $optionName, $value));
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
    }

    /**
     * Авто-подбор окна импорта по данным в БД.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function calculateAutoWindow(Company $company): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dateTo = $now;

        if (!$this->repository->hasDetailsForCompany($company)) {
            $dateFrom = $now->sub(new \DateInterval('P80D'));
        } else {
            $oldestOpen = $this->repository->findOldestOpenSaleDt($company);
            if ($oldestOpen instanceof \DateTimeImmutable) {
                $dateFrom = $oldestOpen;
            } else {
                $latest = $this->repository->findLatestSaleDt($company);
                if ($latest instanceof \DateTimeImmutable) {
                    $dateFrom = $latest->sub(new \DateInterval('P3D'));
                } else {
                    $dateFrom = $now->sub(new \DateInterval('P80D'));
                }
            }
        }

        if ($dateFrom > $dateTo) {
            $dateFrom = $dateTo;
        }

        return [$dateFrom, $dateTo];
    }
}
