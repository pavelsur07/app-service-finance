<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Application\Action\ApplyDefaultCostMappingAction;
use App\Marketplace\Application\Command\ApplyDefaultCostMappingCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ночной прогон базового маппинга затрат в ОПиУ по всем активным SELLER-подключениям.
 *
 * Новые категории затрат появляются при загрузке без правила маппинга, и до
 * ручного нажатия «Базовый маппинг» их затраты не попадают в ОПиУ. Прогон стоит
 * в cron перед пересборкой предварительного ОПиУ (04:45), поэтому категория,
 * появившаяся ночью, попадает в отчёт в то же утро.
 *
 * Применение частичное: правило, для которого у компании нет статьи ОПиУ из
 * шаблона, получает статью по образцу компании (DefaultCostMappingSiblingResolver),
 * а без единогласного образца пропускается (blocked); остальные применяются. Ручные и отключённые
 * правила не трогаются — это гарантирует ApplyDefaultCostMappingAction. Затраты,
 * которые так и остались вне ОПиУ, показывает гейт
 * app:marketplace:cost-pl-mapping:unmapped-check.
 */
#[AsCommand(
    name: 'app:marketplace:cost-pl-mapping:sync-default',
    description: 'Ночной частичный базовый маппинг затрат в ОПиУ для всех активных SELLER-подключений',
)]
final class SyncDefaultCostMappingCommand extends Command
{
    use LockableTrait;

    // Экшен пишет actorUserId только в лог; пользователя у cron нет.
    private const string ACTOR = 'cron';

    private const array SUPPORTED_MARKETPLACES = [MarketplaceType::OZON, MarketplaceType::WILDBERRIES];

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly ApplyDefaultCostMappingAction $applyAction,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Команда уже запущена другим процессом — пропускаем.');

            return Command::SUCCESS;
        }

        try {
            return $this->syncAll($output);
        } finally {
            $this->release();
        }
    }

    private function syncAll(OutputInterface $output): int
    {
        $this->logger->info('[DefaultCostMappingSync] started');

        // SELLER-подключение у пары (компания, маркетплейс) одно —
        // uniq_company_marketplace_type, — поэтому пары не повторяются.
        $pairs = [];
        foreach ($this->connectionsQuery->execute() as $row) {
            $marketplace = MarketplaceType::tryFrom((string) $row['marketplace']);
            if (null !== $marketplace && \in_array($marketplace, self::SUPPORTED_MARKETPLACES, true)) {
                $pairs[] = [(string) $row['company_id'], $marketplace];
            }
        }

        $created = 0;
        $updated = 0;
        $blocked = 0;
        $failures = [];

        foreach ($pairs as [$companyId, $marketplace]) {
            try {
                $result = ($this->applyAction)(new ApplyDefaultCostMappingCommand($companyId, $marketplace->value, self::ACTOR, partial: true));
            } catch (\Throwable $e) {
                // Сбой одной компании не должен оставлять без маппинга остальные.
                $failures[] = ['company_id' => $companyId, 'marketplace' => $marketplace->value, 'exception' => $e::class];
                $output->writeln(sprintf('FAILED company %s %s: %s', $companyId, $marketplace->value, $e::class));

                continue;
            }

            $created += $result->getCreatedCount();
            $updated += $result->getUpdatedCount();
            $blocked += $result->getBlockedCount();

            if ($result->getCreatedCount() + $result->getUpdatedCount() > 0) {
                $output->writeln(sprintf(
                    'company %s %s: создано %d, заполнено %d (%s); по образцу компании: %s',
                    $companyId,
                    $marketplace->value,
                    $result->getCreatedCount(),
                    $result->getUpdatedCount(),
                    implode(', ', [...$result->getCreatedCostCodes(), ...$result->getUpdatedCostCodes()]),
                    [] === $result->getInferredCostCodes() ? '—' : implode(', ', $result->getInferredCostCodes()),
                ));
            }
        }

        $summary = [
            'pairs' => \count($pairs),
            'created' => $created,
            'updated' => $updated,
            'blocked' => $blocked,
            'failed' => \count($failures),
        ];
        $output->writeln(sprintf(
            'checked pairs: %d, created: %d, filled: %d, template rules without P&L line in company: %d, failed: %d',
            ...array_values($summary),
        ));

        if ([] !== $failures) {
            $this->logger->error('[DefaultCostMappingSync] default cost mapping failed for some companies.', $summary + [
                'failures' => $failures,
            ]);

            return Command::FAILURE;
        }

        $this->logger->info('[DefaultCostMappingSync] finished', $summary);

        return Command::SUCCESS;
    }
}
