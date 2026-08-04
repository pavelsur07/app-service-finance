<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ротация ключей шифрования api_key подключений маркетплейсов:
 * перешифровывает строки с key_version != активной версии на активную.
 * Строки без encrypted-пары (legacy plaintext) НЕ затрагиваются —
 * ими занимается app:marketplace:encrypt-connection-keys.
 *
 * По умолчанию dry-run (распределение по версиям + сколько требует ротации).
 * Идемпотентна. В вывод попадают только версии и счётчики, никакого ключевого
 * материала и значений api_key.
 *
 * Порядок применения см. docs/maintenance/encryption-key-rotation.md.
 */
#[AsCommand(
    name: 'app:marketplace:rotate-connection-keys',
    description: 'Ротация версий ключей шифрования api_key подключений (dry-run по умолчанию).',
)]
final class RotateConnectionKeysCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnectionApiKeyCodec $codec,
        private readonly SecretKeyProviderInterface $secretKeyProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Выполнить ротацию (без флага — только отчёт)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $activeVersion = $this->secretKeyProvider->getActiveKeyVersion();

        $io->writeln(sprintf('Активная версия ключа: %s', $activeVersion));

        /** @var array<array{version: string|null, cnt: int|string}> $distribution */
        $distribution = $this->em->getRepository(MarketplaceConnection::class)
            ->createQueryBuilder('c')
            ->select('c.apiKeyKeyVersion AS version, COUNT(c.id) AS cnt')
            ->groupBy('c.apiKeyKeyVersion')
            ->getQuery()
            ->getArrayResult();

        $io->table(
            ['key_version', 'connections'],
            array_map(
                static fn (array $row): array => [$row['version'] ?? '(нет encrypted-пары)', (int) $row['cnt']],
                $distribution,
            ),
        );

        $pending = $this->countPending($activeVersion);

        if (0 === $pending) {
            $io->success('Все зашифрованные подключения уже на активной версии ключа. Делать нечего.');

            return Command::SUCCESS;
        }

        if (!$execute) {
            $io->warning(sprintf(
                'DRY-RUN: %d подключений требуют перешифровки на версию %s. Запустите с --execute для ротации.',
                $pending,
                $activeVersion,
            ));

            return Command::SUCCESS;
        }

        $io->note(sprintf('Перешифровываем %d подключений на версию %s...', $pending, $activeVersion));

        $processed = 0;
        $repository = $this->em->getRepository(MarketplaceConnection::class);

        while (true) {
            /** @var MarketplaceConnection[] $batch */
            $batch = $repository->createQueryBuilder('c')
                ->andWhere('c.apiKeyEncrypted IS NOT NULL')
                ->andWhere('c.apiKeyKeyVersion != :active')
                ->setParameter('active', $activeVersion)
                ->setMaxResults(self::BATCH_SIZE)
                ->getQuery()
                ->getResult();

            if ([] === $batch) {
                break;
            }

            foreach ($batch as $connection) {
                if ($this->codec->rotateIfNeeded($connection)) {
                    ++$processed;
                }
            }

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  обработано: %d', $processed));
        }

        $remaining = $this->countPending($activeVersion);
        if (0 !== $remaining) {
            $io->error(sprintf('После ротации осталось %d подключений на старых версиях — проверьте логи.', $remaining));

            return Command::FAILURE;
        }

        $io->success(sprintf('Ротация завершена: перешифровано %d подключений на версию %s.', $processed, $activeVersion));

        return Command::SUCCESS;
    }

    private function countPending(string $activeVersion): int
    {
        return (int) $this->em->getRepository(MarketplaceConnection::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.apiKeyEncrypted IS NOT NULL')
            ->andWhere('c.apiKeyKeyVersion != :active')
            ->setParameter('active', $activeVersion)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
