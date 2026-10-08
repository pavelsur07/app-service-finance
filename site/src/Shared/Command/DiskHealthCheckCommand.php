<?php

declare(strict_types=1);

namespace App\Shared\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Заполнение диска хоста по inode и по байтам.
 *
 * 19.09.2026 деплой молча не доезжал почти десять часов: `docker compose pull`
 * падал с «no space left on device», хотя байтов было свободно 6.9G — кончились
 * inode, а чистка образов стояла только в конце успешной выкатки. Теперь job
 * schema-ready чистит образы и build-кэш до первого pull, но место могут съесть и
 * не образы, а между деплоями никто не смотрит. `df -h` inode не показывает,
 * поэтому они проверяются отдельно.
 *
 * Корень контейнера — overlay, а statfs у overlay отдаёт файловую систему его
 * верхнего слоя, то есть каталог данных Docker на хосте: именно её заполняют образы.
 *
 * Read-only. Порог превышен → один агрегированный error со стабильным текстом и
 * exit 1. Починка под гейт — ручная чистка образов и build-кэша на хосте
 * (docs/maintenance/production-logging.md, раздел про диск).
 */
#[AsCommand(
    name: 'app:disk:healthcheck',
    description: 'Заполнение диска по inode и по байтам; выше порога — error и exit 1.',
)]
final class DiskHealthCheckCommand extends Command
{
    private const DEFAULT_THRESHOLD_PERCENT = 85;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $dfBinary = 'df',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Точка монтирования.', '/')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Порог заполнения, %.', (string) self::DEFAULT_THRESHOLD_PERCENT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getOption('path');
        $rawThreshold = (string) $input->getOption('threshold');
        $threshold = ctype_digit($rawThreshold) ? (int) $rawThreshold : 0;
        if ($threshold < 1 || $threshold > 100) {
            $output->writeln('<error>--threshold должен быть целым числом от 1 до 100.</error>');

            return Command::INVALID;
        }

        try {
            $usage = [
                'inodes' => $this->usedPercent(['-Pi', $path]),
                'bytes' => $this->usedPercent(['-Pk', $path]),
            ];
        } catch (\RuntimeException $exception) {
            $this->logger->error('Disk healthcheck FAILED', ['path' => $path, 'exception' => $exception]);
            $output->writeln(sprintf('<error>disk healthcheck FAILED: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $summary = sprintf(
            'inodes %s, bytes %s (порог %d%%)',
            $this->format($usage['inodes']),
            $this->format($usage['bytes']),
            $threshold,
        );

        $exceeded = array_keys(array_filter($usage, static fn (?int $percent): bool => null !== $percent && $percent >= $threshold));
        if ([] !== $exceeded) {
            $this->logger->error('Disk usage above threshold', [
                'path' => $path,
                'exceeded' => $exceeded,
                'inodes_used_percent' => $usage['inodes'],
                'bytes_used_percent' => $usage['bytes'],
                'threshold_percent' => $threshold,
            ]);
            $output->writeln(sprintf('<error>disk healthcheck FAILED %s: %s</error>', $path, $summary));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('disk healthcheck OK %s: %s', $path, $summary));

        return Command::SUCCESS;
    }

    /**
     * POSIX-вывод df: заголовок и одна строка «fs total used available capacity mount».
     * Процент — used / (used + available) с округлением вверх, как Use% у coreutils:
     * зарезервированные root блоки ext4 (5%) иначе занижали бы показатель, и гейт
     * расходился бы с `df -h`, по которому человек его перепроверяет. Колонку
     * capacity не берём: busybox округляет её до ближайшего. used + available = 0 —
     * файловая система без фиксированного числа inode (btrfs): показатель
     * неприменим, это не сбой.
     *
     * @param list<string> $arguments
     */
    private function usedPercent(array $arguments): ?int
    {
        $process = new Process([$this->dfBinary, ...$arguments]);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('df %s exited with code %d.', implode(' ', $arguments), (int) $process->getExitCode()));
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $columns = preg_split('/\s+/', trim((string) end($lines))) ?: [];
        if (count($lines) < 2 || count($columns) < 6 || !ctype_digit($columns[2]) || !ctype_digit($columns[3])) {
            // Строка df — имя устройства, счётчики и точка монтирования: секретов в ней нет.
            throw new \RuntimeException(sprintf('Unexpected df %s output: "%s".', implode(' ', $arguments), mb_substr((string) end($lines), 0, 200)));
        }

        $used = (int) $columns[2];
        $capacity = $used + (int) $columns[3];
        if (0 === $capacity) {
            return null;
        }

        return (int) ceil($used * 100 / $capacity);
    }

    private function format(?int $percent): string
    {
        return null === $percent ? 'n/a' : $percent.'%';
    }
}
