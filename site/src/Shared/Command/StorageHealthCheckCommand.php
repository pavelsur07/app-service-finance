<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Service\Storage\ObjectStorageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Синтетическая проверка живости объектного хранилища: write → read → verify → delete
 * крошечного probe-объекта, с замером латентности.
 *
 * Запускается кроном (supercronic). При сбое — logger->error → GlitchTip алерт, exit 1.
 * Проактивно ловит недоступность S3 / протухшие ключи / деградацию латентности до того,
 * как это увидит пользователь.
 */
#[AsCommand(
    name: 'app:storage:healthcheck',
    description: 'Синтетическая проверка живости объектного хранилища (write→read→delete).',
)]
final class StorageHealthCheckCommand extends Command
{
    public function __construct(
        private readonly ObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = sprintf('_healthcheck/probe-%s.txt', bin2hex(random_bytes(8)));
        $payload = 'probe-'.bin2hex(random_bytes(8));
        $startedAt = microtime(true);

        try {
            $this->storage->write($key, $payload);
            $readBack = $this->storage->read($key);
            $this->storage->delete($key);

            if ($readBack !== $payload) {
                throw new \RuntimeException('Read-back payload mismatch — повреждение данных или чужой объект.');
            }
        } catch (\Throwable $exception) {
            $durationMs = $this->elapsedMs($startedAt);
            $this->logger->error('Object storage healthcheck FAILED', [
                'key' => $key,
                'duration_ms' => $durationMs,
                'exception' => $exception,
            ]);
            $output->writeln(sprintf('<error>storage healthcheck FAILED (%d ms): %s</error>', $durationMs, $exception->getMessage()));

            // Best-effort уборка probe-объекта, если write прошёл, а read — нет. Не маскируем исходную ошибку.
            try {
                $this->storage->delete($key);
            } catch (\Throwable) {
            }

            return Command::FAILURE;
        }

        $output->writeln(sprintf('storage healthcheck OK (%d ms)', $this->elapsedMs($startedAt)));

        return Command::SUCCESS;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
