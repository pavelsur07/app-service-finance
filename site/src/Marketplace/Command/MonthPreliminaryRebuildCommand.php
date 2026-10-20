<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\Command\RebuildPreliminaryForPeriodCommand;
use App\Marketplace\Application\DTO\PreflightCheck;
use App\Marketplace\Application\MonthClosePreflightAction;
use App\Marketplace\Application\RebuildPreliminaryForPeriodAction;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная пересборка предварительного ОПиУ за текущий открытый месяц.
 *
 * Cron: 30 4 * * * php bin/console app:marketplace:month-preliminary-rebuild
 * (после Ozon daily sync 04:00 — данные за вчера к этому моменту уже подтянуты)
 *
 * Команда тонкая: получает все активные SELLER-подключения и для каждого
 * диспатчит RebuildPreliminaryForPeriodMessage. Вся бизнес-логика —
 * в RebuildPreliminaryForPeriodHandler / -Action.
 *
 * Ручной режим — один этап одной компании за любой месяц:
 *   --company-id=<uuid> --marketplace=wildberries --month=2026-09 --stage=sales_returns
 * Без --execute показывает preflight и что сделает пересборка, ничего не пишет.
 * С --execute вызывает RebuildPreliminaryForPeriodAction синхронно, без очереди,
 * и печатает итоговый статус этапа: причина пропуска иначе осталась бы только
 * в warning-логе воркера. Финально закрытый этап не трогается, как и в cron.
 *
 * Системный actorUserId — фиксированный UUID '00000000-0000-0000-0000-000000000001'.
 * Поле stageXxxClosedByUserId в MarketplaceMonthClose — guid nullable БЕЗ FK,
 * поэтому несуществующий пользователь не сломает закрытие.
 */
#[AsCommand(
    name: 'app:marketplace:month-preliminary-rebuild',
    description: 'Пересборка предварительного ОПиУ: без опций — текущий месяц всех подключений, с опциями — один этап за указанный месяц',
)]
final class MonthPreliminaryRebuildCommand extends Command
{
    use LockableTrait;

    public const SYSTEM_ACTOR_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly CompanyFacade $companyFacade,
        private readonly MonthClosePreflightAction $preflightAction,
        private readonly RebuildPreliminaryForPeriodAction $rebuildAction,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Ручной режим: компания')
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED, 'Ручной режим: ozon|wildberries')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Ручной режим: месяц YYYY-MM')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Ручной режим: sales_returns|costs')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Ручной режим: выполнить пересборку (без опции — только предпросмотр)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $manual = array_filter(
            ['company-id', 'marketplace', 'month', 'stage'],
            static fn (string $name): bool => null !== $input->getOption($name),
        );
        if ([] === $manual && $input->getOption('execute')) {
            $io->error('--execute работает только в ручном режиме: --company-id, --marketplace, --month, --stage.');

            return Command::INVALID;
        }

        if (!$this->lock()) {
            $io->warning('Команда уже запущена другим процессом — пропускаем.');

            return Command::SUCCESS;
        }

        try {
            return [] === $manual
                ? $this->rebuildCurrentMonth($io)
                : $this->rebuildStage($input, $io);
        } finally {
            $this->release();
        }
    }

    private function rebuildCurrentMonth(SymfonyStyle $io): int
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $connections = $this->connectionsQuery->execute();

        if (empty($connections)) {
            $io->info('Нет активных SELLER-подключений для пересборки.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        foreach ($connections as $row) {
            $companyId = (string) $row['company_id'];
            $marketplace = (string) $row['marketplace'];

            try {
                $this->messageBus->dispatch(new RebuildPreliminaryForPeriodMessage(
                    companyId: $companyId,
                    marketplace: $marketplace,
                    year: $year,
                    month: $month,
                    actorUserId: self::SYSTEM_ACTOR_USER_ID,
                ));

                ++$dispatched;

                $this->logger->info('[PreliminaryRebuild] Dispatched', [
                    'company_id' => $companyId,
                    'marketplace' => $marketplace,
                    'year' => $year,
                    'month' => $month,
                ]);
            } catch (\Throwable $e) {
                // Сбой одного диспатча не должен прерывать остальные.
                ++$failed;

                $this->logger->error('[PreliminaryRebuild] Dispatch failed', [
                    'company_id' => $companyId,
                    'marketplace' => $marketplace,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->success(sprintf(
            'Отправлено %d задач предзакрытия (ошибок: %d), период: %d-%02d.',
            $dispatched,
            $failed,
            $year,
            $month,
        ));

        return Command::SUCCESS;
    }

    private function rebuildStage(InputInterface $input, SymfonyStyle $io): int
    {
        $companyId = (string) $input->getOption('company-id');
        $marketplace = MarketplaceType::tryFrom((string) $input->getOption('marketplace'));
        $stage = CloseStage::tryFrom((string) $input->getOption('stage'));
        $monthOption = (string) $input->getOption('month');

        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)
            || !\in_array($marketplace, [MarketplaceType::OZON, MarketplaceType::WILDBERRIES], true)
            || null === $stage
            || 1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthOption, $period)
        ) {
            $io->error('Ручной режим требует все опции: --company-id=<uuid> --marketplace=ozon|wildberries --month=YYYY-MM --stage=sales_returns|costs.');

            return Command::INVALID;
        }

        $year = (int) $period[1];
        $month = (int) $period[2];
        $periodStart = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        if ($periodStart > new \DateTimeImmutable('first day of this month midnight')) {
            $io->error(sprintf('Месяц %s ещё не начался.', $monthOption));

            return Command::INVALID;
        }

        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            $io->error(sprintf('Компания %s не найдена.', $companyId));

            return Command::FAILURE;
        }

        $io->title(sprintf('%s · %s · %s · этап «%s»', $company->getName(), $marketplace->value, $monthOption, $stage->getLabel()));

        $monthClose = $this->monthCloseRepository->findByPeriod($companyId, $marketplace, $year, $month);
        $status = $monthClose?->getStageStatus($stage) ?? MonthCloseStageStatus::PENDING;
        $wasPreliminary = $monthClose?->isStageLastCloseWasPreliminary($stage) ?? false;
        $io->writeln(sprintf('Сейчас: %s', $this->describeStatus($status, $wasPreliminary)));

        $preflight = ($this->preflightAction)(new PreflightMonthCloseCommand(
            companyId: $companyId,
            marketplace: $marketplace->value,
            year: $year,
            month: $month,
            stage: $stage,
            preliminary: true,
        ));
        $io->table(
            ['Проверка', 'Итог', 'Сообщение'],
            array_map(static fn (PreflightCheck $check): array => [
                $check->key,
                $check->passed ? 'ok' : ($check->blocking ? 'ОШИБКА' : 'предупреждение'),
                $check->message,
            ], $preflight->checks),
        );

        if (MonthCloseStageStatus::CLOSED === $status && !$wasPreliminary) {
            $io->warning('Этап закрыт окончательно — пересборка его не трогает. Переоткрыть можно только на экране «Закрытие месяца».');

            return Command::FAILURE;
        }

        if (!$input->getOption('execute')) {
            // Закрытый предварительно этап пересборка переоткрывает: ошибки
            // «уже закрыт/обработано» для него ожидаемы и не блокируют.
            $expected = MonthCloseStageStatus::CLOSED !== $status ? [] : match ($stage) {
                CloseStage::SALES_RETURNS => ['already_closed', 'sales_already_processed', 'returns_already_processed'],
                CloseStage::COSTS => ['already_closed', 'costs_already_processed'],
            };
            $blocking = array_filter(
                $preflight->getErrors(),
                static fn (PreflightCheck $check): bool => !\in_array($check->key, $expected, true),
            );

            if ([] !== $blocking) {
                $io->warning('Предпросмотр. Есть блокирующие ошибки — с --execute этап не закроется.');

                return Command::FAILURE;
            }

            $io->note(MonthCloseStageStatus::CLOSED === $status
                ? 'Предпросмотр. С --execute этап будет переоткрыт и закрыт заново предварительно.'
                : 'Предпросмотр. С --execute этап будет закрыт предварительно.');

            return Command::SUCCESS;
        }

        $this->logger->info('[PreliminaryRebuild] Manual run', [
            'company_id' => $companyId,
            'marketplace' => $marketplace->value,
            'year' => $year,
            'month' => $month,
            'stage' => $stage->value,
        ]);

        try {
            ($this->rebuildAction)(new RebuildPreliminaryForPeriodCommand(
                companyId: $companyId,
                marketplace: $marketplace->value,
                year: $year,
                month: $month,
                actorUserId: self::SYSTEM_ACTOR_USER_ID,
                stages: [$stage->value],
            ));
        } catch (\DomainException $e) {
            $io->error(sprintf('Пересборка прервана: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $this->entityManager->clear();
        $after = $this->monthCloseRepository->findByPeriod($companyId, $marketplace, $year, $month);
        $afterStatus = $after?->getStageStatus($stage) ?? MonthCloseStageStatus::PENDING;
        $afterPreliminary = $after?->isStageLastCloseWasPreliminary($stage) ?? false;
        $io->writeln(sprintf('После: %s, документов ОПиУ: %d', $this->describeStatus($afterStatus, $afterPreliminary), \count($after?->getStagePLDocumentIds($stage) ?? [])));

        if (MonthCloseStageStatus::CLOSED !== $afterStatus) {
            $io->error('Этап не закрыт: см. ошибки проверок выше.');

            return Command::FAILURE;
        }

        $io->success('Этап закрыт предварительно.');

        return Command::SUCCESS;
    }

    private function describeStatus(MonthCloseStageStatus $status, bool $wasPreliminary): string
    {
        return match (true) {
            MonthCloseStageStatus::CLOSED === $status && $wasPreliminary => 'закрыт предварительно',
            MonthCloseStageStatus::CLOSED === $status => 'закрыт окончательно',
            default => $status->value,
        };
    }
}
