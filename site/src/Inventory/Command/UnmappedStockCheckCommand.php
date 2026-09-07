<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Infrastructure\Query\UnmappedStockVariantsQuery;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт: не появилось ли новых расхождений между выгрузкой остатков и каталогом.
 *
 * Маркетплейс может прислать остаток по варианту, которого нет в его же каталоге
 * карточек. Листинг тогда не находится, строка сохраняется с listing_id = NULL, и
 * узнать об этом можно только руками сходив в базу. Правила проекта требуют, чтобы
 * неизвестное значение из внешней системы деградировало в видимую очередь, а не в
 * NULL: NULL одновременно ломает данные и прячет факт поломки.
 *
 * Алертит команда только на ВПЕРВЫЕ появившиеся варианты. Накопленный остаток
 * печатается как метрика и на exit code не влияет — для него не существует
 * операции, переводящей его в хорошее состояние автоматически, а гейт на
 * недостижимом состоянии был бы вечно красным и обесценил бы канал.
 *
 * Охват равен охвату починки: только компании с активным подключением. У компании
 * без подключения загрузка не идёт, новых расхождений появиться не может.
 */
#[AsCommand(
    name: 'app:inventory:unmapped-stock-check',
    description: 'Read-only gate: no new stock variants missing from the marketplace catalog',
)]
final class UnmappedStockCheckCommand extends Command
{
    private const DEFAULT_WINDOW_DAYS = 2;

    /**
     * Верхняя граница окна. Смысла в большем нет, а без ограничения строка из
     * цифр произвольной длины насыщается до PHP_INT_MAX и ломает арифметику дат.
     */
    private const MAX_WINDOW_DAYS = 3650;

    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly UnmappedStockVariantsQuery $unmappedVariantsQuery,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'window-days',
            null,
            InputOption::VALUE_REQUIRED,
            'Окно, внутри которого расхождение считается новым',
            (string) self::DEFAULT_WINDOW_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        $windowDays = $this->parseWindowDays($input->getOption('window-days'));
        if (null === $windowDays) {
            $output->writeln(sprintf(
                'invalid input: --window-days must be an integer between 0 and %d.',
                self::MAX_WINDOW_DAYS,
            ));
            $output->writeln('finish');

            return self::INVALID;
        }

        $companyIds = $this->companiesWithActiveConnections();
        if ([] === $companyIds) {
            $output->writeln('active connections count: 0');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        $newSince = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $windowDays));
        $rows = $this->unmappedVariantsQuery->execute($companyIds, $newSince);

        $newVariants = 0;
        $standingVariants = 0;
        $standingRows = 0;

        foreach ($rows as $row) {
            $newVariants += $row['newVariants'];
            $standingVariants += $row['standingVariants'];
            $standingRows += $row['standingRows'];

            $output->writeln(sprintf(
                '%s company %s source %s: %d new, %d standing variants (%d rows), first seen %s, last seen %s',
                $row['newVariants'] > 0 ? 'NEW' : 'OK',
                $row['companyId'],
                $row['source'],
                $row['newVariants'],
                $row['standingVariants'],
                $row['standingRows'],
                $row['firstSeen'],
                $row['lastSeen'],
            ));
        }

        $output->writeln(sprintf('companies checked count: %d', count($companyIds)));
        $output->writeln(sprintf('new unmapped variants count: %d', $newVariants));
        $output->writeln(sprintf('standing unmapped variants count: %d / rows: %d', $standingVariants, $standingRows));
        $output->writeln(sprintf('new since: %s (window days: %d)', $newSince->format('Y-m-d'), $windowDays));
        $output->writeln('finish');

        if (0 === $newVariants) {
            return self::SUCCESS;
        }

        // Один агрегированный error со счётчиком, а не запись на каждый вариант:
        // появилось новое расхождение с каталогом, и разбирать его будет человек.
        $this->logger->error('New stock variants are missing from the marketplace catalog.', [
            'new_variants' => $newVariants,
            'standing_variants' => $standingVariants,
            'standing_rows' => $standingRows,
            'new_since' => $newSince->format('Y-m-d'),
            'window_days' => $windowDays,
        ]);

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function companiesWithActiveConnections(): array
    {
        $ids = [];
        foreach ([
            $this->marketplaceFacade->getActiveOzonSellerConnections(),
            $this->marketplaceFacade->getActiveWbSellerConnections(),
        ] as $connections) {
            foreach ($connections as $connection) {
                $companyId = (string) $connection['companyId'];
                if ('' !== $companyId) {
                    $ids[$companyId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function parseWindowDays(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $days = filter_var($value, \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_WINDOW_DAYS],
        ]);

        return false === $days ? null : $days;
    }
}
