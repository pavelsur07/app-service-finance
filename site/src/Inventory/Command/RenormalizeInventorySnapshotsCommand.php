<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Application\NormalizeInventorySnapshotAction;
use App\Inventory\Infrastructure\Query\SessionsToRenormalizeQuery;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Разовая пере-нормализация снапшотов остатков за диапазон дат.
 *
 * Зачем: маппинг «строка остатка → листинг» замораживается в момент нормализации,
 * потому что ключ upsert включает snapshot_date. Листинг, появившийся в каталоге
 * позже, вчерашние строки уже не чинит, и они остаются unmapped навсегда — а
 * аналитика Маркетплейса строится именно на listing_id.
 *
 * Команда не пересобирает данные заново: она переиспользует уже сохранённый raw и
 * прогоняет по нему тот же нормализатор. Внешние API не вызываются, количества
 * берутся из того же ответа маркетплейса, поэтому меняются только поля маппинга.
 *
 * Нормализация выполняется СИНХРОННО, а не через очередь. Между выборкой «последней
 * сессии дня» и фактическим upsert не должно быть окна: ключ upsert не содержит
 * сессию, поэтому отложенная обработка старой сессии могла бы затереть строки,
 * записанные более новой. Синхронный проход убирает это окно.
 *
 * По той же причине диапазон обязан заканчиваться строго раньше сегодняшнего дня:
 * новая сессия всегда пишется текущей датой, поэтому для прошедших дней набор
 * сессий зафиксирован и после выборки не меняется.
 *
 * Повторный запуск допустим и безопасен: он снова обработает те сессии, где строки
 * остались непривязанными, и приведёт их к тому же результату. Данные не дублируются
 * (уникальный ключ + upsert), но и объём работы повторный запуск не сокращает.
 *
 * Без --execute команда только считает объём и ничего не меняет.
 */
#[AsCommand(
    name: 'app:inventory:renormalize-snapshots',
    description: 'Re-runs normalization for stock snapshot sessions that still have unmapped rows',
)]
final class RenormalizeInventorySnapshotsCommand extends Command
{
    public function __construct(
        private readonly SessionsToRenormalizeQuery $sessionsQuery,
        private readonly NormalizeInventorySnapshotAction $normalizeAction,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Начало диапазона, YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Конец диапазона включительно, YYYY-MM-DD')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Ограничить источником: ozon | wildberries')
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Ограничить одной компанией, UUID')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Выполнить нормализацию. Без флага — только подсчёт');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        try {
            $from = $this->parseDate($input->getOption('from'), 'from');
            $to = $this->parseDate($input->getOption('to'), 'to');
            $source = $this->parseSource($input->getOption('source'));
            $companyId = $this->parseCompany($input->getOption('company'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('invalid input: %s', $e->getMessage()));
            $output->writeln('finish');

            return self::INVALID;
        }

        if ($from > $to) {
            $output->writeln('invalid input: --from must not be later than --to.');
            $output->writeln('finish');

            return self::INVALID;
        }

        // Для прошедших дней набор сессий зафиксирован: новая сессия всегда пишется
        // текущей датой. Включив сегодня, мы допустили бы гонку с ночной загрузкой.
        $today = new \DateTimeImmutable('today');
        if ($to >= $today) {
            $output->writeln(sprintf(
                'invalid input: --to must be earlier than today (%s); the current day is still being written.',
                $today->format('Y-m-d'),
            ));
            $output->writeln('finish');

            return self::INVALID;
        }

        $sessions = $this->sessionsQuery->execute($from, $to, $source, $companyId);
        $execute = (bool) $input->getOption('execute');

        $unmappedRows = 0;
        $totalRows = 0;
        foreach ($sessions as $session) {
            $unmappedRows += $session['unmappedRows'];
            $totalRows += $session['totalRows'];

            $output->writeln(sprintf(
                '%s %s company %s session %s: %d unmapped of %d rows',
                $session['snapshotDate'],
                $session['source'],
                $session['companyId'],
                $session['snapshotSessionId'],
                $session['unmappedRows'],
                $session['totalRows'],
            ));
        }

        $output->writeln(sprintf('range: %s .. %s', $from->format('Y-m-d'), $to->format('Y-m-d')));
        $output->writeln(sprintf('sessions count: %d', count($sessions)));
        $output->writeln(sprintf('unmapped rows count: %d / total rows count: %d', $unmappedRows, $totalRows));

        if (!$execute) {
            $output->writeln('dry run: nothing changed, pass --execute to apply');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        $processed = 0;
        $errors = 0;
        foreach ($sessions as $session) {
            $source = MarketplaceType::tryFrom($session['source']);
            if (null === $source) {
                ++$errors;
                $output->writeln(sprintf('session %s error: unsupported source %s', $session['snapshotSessionId'], $session['source']));

                continue;
            }

            try {
                ($this->normalizeAction)($session['companyId'], $session['snapshotSessionId'], $source);
                ++$processed;
            } catch (\Throwable $e) {
                ++$errors;
                $output->writeln(sprintf('session %s error: %s', $session['snapshotSessionId'], $e->getMessage()));
            }
        }

        $output->writeln(sprintf('processed count: %d', $processed));
        $output->writeln(sprintf('errors count: %d', $errors));
        $output->writeln('finish');

        return 0 === $errors ? self::SUCCESS : self::FAILURE;
    }

    private function parseDate(mixed $value, string $option): \DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('--%s is required.', $option));
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('--%s must be a valid YYYY-MM-DD date.', $option));
        }

        return $date;
    }

    private function parseSource(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!is_string($value) || null === MarketplaceType::tryFrom($value)) {
            throw new \InvalidArgumentException('--source must be one of: ozon, wildberries.');
        }

        return $value;
    }

    private function parseCompany(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!is_string($value) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new \InvalidArgumentException('--company must be a UUID.');
        }

        return $value;
    }
}
