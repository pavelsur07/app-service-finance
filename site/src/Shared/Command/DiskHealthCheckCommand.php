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
 * inode (образы и build-кэш, которые на проде ничто не чистит). `df -h` этого не
 * показывает, поэтому inode проверяются отдельно.
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
        $threshold = (int) $input->getOption('threshold');

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
     * Процент считается из used/total, а не из колонки capacity: busybox и coreutils
     * округляют её по-разному. total = 0 — файловая система без фиксированного
     * числа inode (btrfs): показатель неприменим, это не сбой.
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
        if (count($lines) < 2 || count($columns) < 6 || !ctype_digit($columns[1]) || !ctype_digit($columns[2])) {
            throw new \RuntimeException(sprintf('Unexpected df %s output.', implode(' ', $arguments)));
        }

        $total = (int) $columns[1];
        if (0 === $total) {
            return null;
        }

        return (int) ceil((int) $columns[2] * 100 / $total);
    }

    private function format(?int $percent): string
    {
        return null === $percent ? 'n/a' : $percent.'%';
    }
}
