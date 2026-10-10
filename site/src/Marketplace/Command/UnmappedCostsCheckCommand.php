<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\UnmappedCostsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт «затраты вне ОПиУ»: у активных SELLER-подключений нет затрат текущего и
 * прошлого месяца, которые не попадут в ОПиУ из-за отсутствующего маппинга.
 *
 * Стоит после ночного app:marketplace:cost-pl-mapping:sync-default и краснит
 * только то, что тот не смог починить: у компании нет ни статьи ОПиУ из шаблона,
 * ни единогласного образца среди её правил того же типа, или в шаблоне нет
 * правила для кода. Починка — человек назначает статью в
 * «Маппинге затрат в ОПиУ» (или заводит статью, и следующей ночью её подхватит
 * прогон); после этого затраты перестают подходить под условие, и гейт
 * зеленеет сам, не дожидаясь пересборки ОПиУ.
 *
 * Окно — с 1-го числа прошлого месяца (МСК): прошлый месяц ещё может быть не
 * закрыт окончательно, а более старые затраты попадают в ОПиУ только ручным
 * закрытием, которое и так блокирует preflight.
 */
#[AsCommand(
    name: 'app:marketplace:cost-pl-mapping:unmapped-check',
    description: 'Read-only gate: у активных SELLER-подключений нет затрат текущего и прошлого месяца без маппинга в ОПиУ',
)]
final class UnmappedCostsCheckCommand extends Command
{
    private const array MARKETPLACES = [MarketplaceType::OZON, MarketplaceType::WILDBERRIES];
    private const int MAX_LOGGED_CODES = 20;

    public function __construct(
        private readonly UnmappedCostsQuery $query,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        $fromDay = new \DateTimeImmutable('first day of previous month', new \DateTimeZone('Europe/Moscow'));
        $fromDay = $fromDay->setTime(0, 0);
        $output->writeln(sprintf('window: from %s', $fromDay->format('Y-m-d')));

        $totalRows = 0;
        $totalAmount = '0';
        $rowsByCode = [];
        $companies = [];

        foreach (self::MARKETPLACES as $marketplace) {
            foreach ($this->query->findUnmapped($marketplace->value, $fromDay) as $row) {
                $totalRows += $row['rows_count'];
                $totalAmount = bcadd($totalAmount, $row['amount'], 2);
                $key = $marketplace->value.':'.$row['code'];
                $rowsByCode[$key] = ($rowsByCode[$key] ?? 0) + $row['rows_count'];
                $companies[$row['company_id']] = true;

                $output->writeln(sprintf(
                    'UNMAPPED company %s %s «%s»: %d строк, %s ₽ (%s - %s)',
                    $row['company_id'],
                    $marketplace->value,
                    $row['code'],
                    $row['rows_count'],
                    $row['amount'],
                    $row['first_day'],
                    $row['last_day'],
                ));
            }
        }

        $output->writeln(sprintf('unmapped rows count: %d', $totalRows));
        $output->writeln(sprintf('affected companies count: %d', \count($companies)));
        $output->writeln('finish');

        if (0 === $totalRows) {
            return Command::SUCCESS;
        }

        arsort($rowsByCode);

        // Один агрегированный error: пока статью не назначили, гейт краснеет каждое
        // утро одним событием, а не потоком по компаниям и категориям.
        $this->logger->error('Marketplace costs are left out of P&L: no cost-to-P&L mapping.', [
            'from_day' => $fromDay->format('Y-m-d'),
            'affected_companies' => \count($companies),
            'unmapped_rows' => $totalRows,
            'unmapped_amount' => $totalAmount,
            'codes' => \array_slice($rowsByCode, 0, self::MAX_LOGGED_CODES, true),
        ]);

        return Command::FAILURE;
    }
}
