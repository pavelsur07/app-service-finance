<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Action\ApplyDefaultSaleMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultSaleMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultSaleMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultSaleMappingCommand;
use App\Marketplace\Application\DTO\DefaultSaleMappingPreviewResult;
use App\Marketplace\Enum\DefaultSaleMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Базовый маппинг продаж и возвратов в ОПиУ из консоли — то же, что кнопка «Настроить
 * автоматически» на вкладке «Маппинг ОПиУ», для запуска через codex-console.
 *
 * Своей логики нет: предпросмотр и запись делают те же экшены, что у UI. Существующие правила не
 * трогаются, отсутствующая строка ОПиУ блокирует пару «компания × маркетплейс» целиком — остальные
 * пары это не останавливает. Без --execute ничего не пишет.
 *
 * Примеры:
 *   php bin/console app:marketplace:sale-mapping:apply-default --all
 *   php bin/console app:marketplace:sale-mapping:apply-default --all --execute
 *   php bin/console app:marketplace:sale-mapping:apply-default --company-id=<UUID> --marketplace=ozon --execute
 */
#[AsCommand(
    name: 'app:marketplace:sale-mapping:apply-default',
    description: 'Применить базовый маппинг продаж и возвратов в ОПиУ: одна компания или все активные SELLER-подключения (без --execute — только предпросмотр)',
)]
final class ApplyDefaultSaleMappingConsoleCommand extends Command
{
    // Экшен пишет actorUserId только в лог; пользователя у консоли нет.
    private const string ACTOR = 'console';

    private const array SUPPORTED_MARKETPLACES = [MarketplaceType::OZON, MarketplaceType::WILDBERRIES];

    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly PreviewDefaultSaleMappingAction $previewAction,
        private readonly ApplyDefaultSaleMappingAction $applyAction,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Все компании с активным SELLER-подключением')
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED, 'ozon или wildberries; без опции — маркетплейсы SELLER-подключений')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Записать правила; без флага — только предпросмотр');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = $input->getOption('company-id');
        $all = (bool) $input->getOption('all');
        $marketplaceOption = $input->getOption('marketplace');
        $execute = (bool) $input->getOption('execute');

        if ($all === (null !== $companyId)) {
            $io->error('Нужно ровно одно из --company-id или --all.');

            return Command::INVALID;
        }

        $marketplace = null;
        if (null !== $marketplaceOption) {
            $marketplace = MarketplaceType::tryFrom((string) $marketplaceOption);
            if (!\in_array($marketplace, self::SUPPORTED_MARKETPLACES, true)) {
                $io->error('--marketplace должен быть ozon или wildberries.');

                return Command::INVALID;
            }
        }

        if (null !== $companyId) {
            $companyId = (string) $companyId;
            try {
                Assert::uuid($companyId);
            } catch (\InvalidArgumentException) {
                $io->error('Некорректный --company-id, ожидается UUID.');

                return Command::INVALID;
            }

            if (null === $this->companyFacade->findById($companyId)) {
                $io->error(sprintf('Компания %s не найдена.', $companyId));

                return Command::FAILURE;
            }
        }

        $pairs = $this->pairs($companyId, $marketplace);
        if ([] === $pairs) {
            $io->warning('Нет активных SELLER-подключений Ozon или Wildberries для запуска. Ничего не сделано.');

            return Command::SUCCESS;
        }

        $context = ['company_id' => $companyId ?? 'all', 'marketplace' => $marketplace->value ?? 'connections', 'execute' => $execute];
        $this->logger->info('[DefaultSaleMapping] console run started', $context + ['pairs' => \count($pairs)]);

        $created = 0;
        $blocked = 0;
        $failed = 0;
        foreach ($pairs as [$pairCompanyId, $pairMarketplace]) {
            $io->section(sprintf('Компания %s, %s', $pairCompanyId, $pairMarketplace->value));

            $preview = ($this->previewAction)(new PreviewDefaultSaleMappingCommand($pairCompanyId, $pairMarketplace->value));
            $this->renderPreview($io, $preview);

            if ($preview->hasBlockingIssues()) {
                ++$blocked;
                $io->error('Заблокировано: у компании нет нужных статей ОПиУ или они не LEAF_INPUT. По этой паре ничего не записано.');

                continue;
            }

            if (!$execute) {
                continue;
            }

            try {
                $result = ($this->applyAction)(new ApplyDefaultSaleMappingCommand($pairCompanyId, $pairMarketplace->value, self::ACTOR));
            } catch (\DomainException $e) {
                // Статья ОПиУ исчезла или сменила тип между предпросмотром и записью: экшен
                // перепроверяет в транзакции и отказывает целиком.
                ++$failed;
                $io->error($e->getMessage());
                $this->logger->warning('[DefaultSaleMapping] console apply blocked', ['company_id' => $pairCompanyId, 'marketplace' => $pairMarketplace->value]);

                continue;
            }

            $created += $result->getCreatedCount();
            $io->success(sprintf('Создано: %d, пропущено: %d.', $result->getCreatedCount(), $result->getSkippedCount()));
        }

        $summary = ['pairs' => \count($pairs), 'created' => $created, 'blocked' => $blocked, 'failed' => $failed];
        $io->writeln(sprintf('Пар: %d, создано правил: %d, заблокировано: %d, сбоев записи: %d.', ...array_values($summary)));
        if (!$execute) {
            $io->note('Предпросмотр. Для записи повторить с --execute.');
        }
        $this->logger->info('[DefaultSaleMapping] console run finished', $context + $summary);

        return 0 === $blocked + $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Пары «компания × маркетплейс»: явный маркетплейс у одной компании — без проверки подключения,
     * как у кнопки; иначе — активные SELLER-подключения Ozon и Wildberries.
     *
     * @return list<array{0: string, 1: MarketplaceType}>
     */
    private function pairs(?string $companyId, ?MarketplaceType $marketplace): array
    {
        if (null !== $companyId && null !== $marketplace) {
            return [[$companyId, $marketplace]];
        }

        // SELLER-подключение у пары (компания, маркетплейс) одно — uniq_company_marketplace_type.
        $pairs = [];
        foreach ($this->connectionsQuery->execute() as $row) {
            $rowMarketplace = MarketplaceType::tryFrom((string) $row['marketplace']);
            if (!\in_array($rowMarketplace, self::SUPPORTED_MARKETPLACES, true)
                || (null !== $companyId && $companyId !== (string) $row['company_id'])
                || (null !== $marketplace && $marketplace !== $rowMarketplace)) {
                continue;
            }
            $pairs[] = [(string) $row['company_id'], $rowMarketplace];
        }

        return $pairs;
    }

    private function renderPreview(SymfonyStyle $io, DefaultSaleMappingPreviewResult $preview): void
    {
        $rows = [];
        foreach ($preview->getItems() as $item) {
            $rows[] = [$item->getStatus()->value, $item->getAmountSource()->value, $item->getPlCode(), $item->getMessage()];
        }

        if ([] === $rows) {
            $io->writeln('Базовых правил для маркетплейса нет.');

            return;
        }

        $io->table(['Статус', 'Источник суммы', 'Код ОПиУ', 'Пояснение'], $rows);
        $io->writeln(sprintf(
            'Будет создано: %d, уже настроено: %d.',
            $preview->getCountByStatus(DefaultSaleMappingPreviewStatus::WILL_CREATE),
            $preview->getCountByStatus(DefaultSaleMappingPreviewStatus::SKIPPED_EXISTING),
        ));
    }
}
