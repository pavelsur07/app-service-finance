<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Contract\SecretRotatorInterface;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\ValueObject\KeyVersionCount;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Ротация ключа шифрования секретов всех модулей (`SecretRotatorInterface` по тегу):
 * перешифровывает значения на неактивных версиях ключа активной версией.
 *
 * По умолчанию dry-run: распределение по версиям и наличие ключа каждой версии.
 * `--execute`: порции по 100, каждая — своя транзакция; сбой откатывает порцию, готовые
 * порции остаются, повторный запуск продолжает. Нет ключа для версии в БД — код 1 до
 * первой записи. В вывод и лог попадают только модули, версии и счётчики.
 *
 * Порядок смены ключа — docs/maintenance/encryption-key-rotation.md.
 */
#[AsCommand(
    name: 'app:security:rotate-keys',
    description: 'Ротация версий ключа шифрования секретов всех модулей (dry-run по умолчанию).',
)]
final class RotateEncryptionKeysCommand extends Command
{
    private const BATCH_SIZE = 100;

    /** @var array<string, SecretRotatorInterface> */
    private readonly array $rotators;

    /**
     * @param iterable<SecretRotatorInterface> $rotators
     */
    public function __construct(
        #[AutowireIterator(SecretRotatorInterface::TAG)]
        iterable $rotators,
        private readonly SecretKeyProviderInterface $keyProvider,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        $byModule = [];
        foreach ($rotators as $rotator) {
            $byModule[$rotator->module()] = $rotator;
        }
        ksort($byModule);
        $this->rotators = $byModule;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Выполнить ротацию (без флага — только отчёт)')
            ->addOption('module', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Только эти модули: '.implode(', ', array_keys($this->rotators)));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $modules */
        $modules = $input->getOption('module');
        $unknown = array_diff($modules, array_keys($this->rotators));
        if ([] !== $unknown) {
            $io->error(sprintf('Неизвестный модуль: %s. Есть: %s.', implode(', ', $unknown), implode(', ', array_keys($this->rotators))));

            return Command::INVALID;
        }
        $rotators = [] === $modules ? $this->rotators : array_intersect_key($this->rotators, array_flip($modules));

        $activeVersion = $this->keyProvider->getActiveKeyVersion();
        $io->writeln(sprintf('Активная версия ключа: %s', $activeVersion));

        $counts = $this->countAll($rotators);
        $missingVersions = $this->renderDistribution($io, $counts, $activeVersion);
        if ([] !== $missingVersions) {
            $io->error(sprintf(
                'Нет ключа для версий: %s. Вернуть их в APP_ENCRYPTION_KEYS_JSON — без ключа эти секреты не прочитать.',
                implode(', ', $missingVersions),
            ));

            return Command::FAILURE;
        }

        $pending = $this->pending($counts, $activeVersion);
        if (0 === $pending) {
            $io->success('Все зашифрованные секреты уже на активной версии ключа. Делать нечего.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('execute')) {
            $io->warning(sprintf(
                'DRY-RUN: %d секретов требуют перешифровки на версию %s. Запустите с --execute для ротации.',
                $pending,
                $activeVersion,
            ));

            return Command::SUCCESS;
        }

        $io->note(sprintf('Перешифровываем %d секретов на версию %s...', $pending, $activeVersion));

        $rotatedByModule = [];
        foreach ($rotators as $module => $rotator) {
            $rotatedByModule[$module] = 0;
            while (true) {
                try {
                    $batch = $this->connection->transactional(
                        static fn () => $rotator->rotateBatch($activeVersion, self::BATCH_SIZE),
                    );
                } catch (DecryptionException|EncryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
                    $this->logger->error('Encryption key rotation failed', [
                        'module' => $module,
                        'active_key_version' => $activeVersion,
                        'rotated' => $rotatedByModule,
                        'exception_class' => $exception::class,
                    ]);
                    $io->error(sprintf('%s: порция откатилась (%s: %s). Готовые порции сохранены.', $module, $exception::class, $exception->getMessage()));

                    return Command::FAILURE;
                }

                if (0 === $batch->selected) {
                    break;
                }
                if (0 === $batch->rotated) {
                    // Все значения порции изменились параллельно и снова попали бы в выборку.
                    $io->error(sprintf('%s: порция из %d секретов не перешифрована — значения меняются параллельно, повторите позже.', $module, $batch->selected));

                    return Command::FAILURE;
                }

                $rotatedByModule[$module] += $batch->rotated;
                $io->writeln(sprintf('  %s: перешифровано %d', $module, $rotatedByModule[$module]));
            }
        }

        $remaining = $this->pending($this->countAll($rotators), $activeVersion);
        $this->logger->info('Encryption key rotation finished', [
            'active_key_version' => $activeVersion,
            'rotated' => $rotatedByModule,
            'remaining' => $remaining,
        ]);

        if (0 !== $remaining) {
            $io->error(sprintf('После ротации осталось %d секретов на старых версиях — запустите ещё раз.', $remaining));

            return Command::FAILURE;
        }

        $io->success(sprintf('Ротация завершена: перешифровано %d секретов на версию %s.', array_sum($rotatedByModule), $activeVersion));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, SecretRotatorInterface> $rotators
     *
     * @return list<KeyVersionCount>
     */
    private function countAll(array $rotators): array
    {
        $counts = [];
        foreach ($rotators as $rotator) {
            array_push($counts, ...$rotator->countByKeyVersion());
        }

        return $counts;
    }

    /**
     * @param list<KeyVersionCount> $counts
     *
     * @return list<string> версии с записями, для которых нет ключа
     */
    private function renderDistribution(SymfonyStyle $io, array $counts, string $activeVersion): array
    {
        $keyAvailable = [];
        $rows = [];
        foreach ($counts as $count) {
            $version = $count->keyVersion;
            if (null !== $version && !isset($keyAvailable[$version])) {
                $keyAvailable[$version] = $this->hasKey($version);
            }

            $rows[] = [
                $count->module,
                $count->field,
                $version ?? '(без шифра)',
                $count->count,
                null === $version ? '—' : ($keyAvailable[$version] ? 'есть' : 'НЕТ'),
            ];
        }

        $io->table(['module', 'field', 'key_version', 'records', 'key'], $rows);

        if (!isset($keyAvailable[$activeVersion]) && !$this->hasKey($activeVersion)) {
            $keyAvailable[$activeVersion] = false;
        }

        return array_map('strval', array_keys(array_filter($keyAvailable, static fn (bool $available): bool => !$available)));
    }

    private function hasKey(string $version): bool
    {
        try {
            $this->keyProvider->getKeyByVersion($version);

            return true;
        } catch (MissingEncryptionKeyException) {
            return false;
        }
    }

    /**
     * @param list<KeyVersionCount> $counts
     */
    private function pending(array $counts, string $activeVersion): int
    {
        $pending = 0;
        foreach ($counts as $count) {
            if (null !== $count->keyVersion && $count->keyVersion !== $activeVersion) {
                $pending += $count->count;
            }
        }

        return $pending;
    }
}
