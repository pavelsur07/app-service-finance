<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Action\ApplyDefaultCostMappingAction;
use App\Marketplace\Application\Action\PreviewDefaultCostMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewResult;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Базовый маппинг затрат в ОПиУ из консоли — то же, что кнопка
 * /marketplace/cost-pl-mapping/default/apply, для запуска через codex-console.
 *
 * Своей логики нет: предпросмотр и запись делают те же экшены, что у UI.
 * Существующие правила не перезаписываются, отсутствующая строка ОПиУ блокирует
 * применение целиком. Без --execute ничего не пишет.
 *
 * Примеры:
 *   php bin/console app:marketplace:cost-pl-mapping:apply-default --company-id=<UUID> --marketplace=ozon
 *   php bin/console app:marketplace:cost-pl-mapping:apply-default --company-id=<UUID> --marketplace=ozon --execute
 */
#[AsCommand(
    name: 'app:marketplace:cost-pl-mapping:apply-default',
    description: 'Применить базовый маппинг затрат маркетплейса в ОПиУ для одной компании (без --execute — только предпросмотр)',
)]
final class ApplyDefaultCostMappingConsoleCommand extends Command
{
    // Экшен пишет actorUserId только в лог; пользователя у консоли нет.
    private const ACTOR = 'console';

    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly PreviewDefaultCostMappingAction $previewAction,
        private readonly ApplyDefaultCostMappingAction $applyAction,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED, 'ozon или wildberries')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Записать правила; без флага — только предпросмотр');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $companyId = (string) $input->getOption('company-id');
        $marketplace = MarketplaceType::tryFrom((string) $input->getOption('marketplace'));
        $execute = (bool) $input->getOption('execute');

        try {
            Assert::uuid($companyId);
        } catch (\InvalidArgumentException) {
            $io->error('Некорректный --company-id, ожидается UUID.');

            return Command::INVALID;
        }

        if (!\in_array($marketplace, [MarketplaceType::OZON, MarketplaceType::WILDBERRIES], true)) {
            $io->error('--marketplace должен быть ozon или wildberries.');

            return Command::INVALID;
        }

        if (null === $this->companyFacade->findById($companyId)) {
            $io->error(sprintf('Компания %s не найдена.', $companyId));

            return Command::FAILURE;
        }

        $context = ['company_id' => $companyId, 'marketplace' => $marketplace->value, 'execute' => $execute];
        $this->logger->info('[DefaultCostMapping] console run started', $context);

        $preview = ($this->previewAction)(new PreviewDefaultCostMappingCommand($companyId, $marketplace->value));
        $this->renderPreview($io, $preview);

        if ($preview->hasBlockingIssues()) {
            $io->error('Применение заблокировано: у компании нет нужных категорий ОПиУ или они не LEAF_INPUT. Ничего не записано.');
            $this->logger->info('[DefaultCostMapping] console run blocked', $context + $preview->getSummary());

            return Command::FAILURE;
        }

        if (!$execute) {
            $io->note('Предпросмотр. Для записи повторить с --execute.');
            $this->logger->info('[DefaultCostMapping] console preview finished', $context + $preview->getSummary());

            return Command::SUCCESS;
        }

        $result = ($this->applyAction)(new ApplyDefaultCostMappingCommand($companyId, $marketplace->value, self::ACTOR));

        $io->success(sprintf(
            'Создано: %d, заполнено пустых: %d, пропущено: %d.',
            $result->getCreatedCount(),
            $result->getUpdatedCount(),
            $result->getSkippedCount(),
        ));
        $this->logger->info('[DefaultCostMapping] console run finished', $context + $result->getSummary());

        return Command::SUCCESS;
    }

    private function renderPreview(SymfonyStyle $io, DefaultCostMappingPreviewResult $preview): void
    {
        $io->table(['Статус', 'Правил'], array_map(
            static fn (string $status, int $count): array => [$status, $count],
            array_keys($preview->getSummary()),
            $preview->getSummary(),
        ));

        $rows = [];
        foreach ([
            DefaultCostMappingPreviewStatus::WILL_CREATE,
            DefaultCostMappingPreviewStatus::WILL_FILL_EMPTY,
            DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY,
            DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY,
        ] as $status) {
            foreach ($preview->getItemsByStatus($status) as $item) {
                $rows[] = [$status->value, $item->getCostCode(), $item->getPlCode()];
            }
        }

        if ([] !== $rows) {
            $io->table(['Статус', 'Категория затрат', 'Код ОПиУ'], $rows);
        }
    }
}
