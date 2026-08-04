<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill: шифрует plaintext api_key существующих подключений маркетплейсов
 * (заполняет api_key_encrypted + api_key_key_version). Plaintext-колонку
 * не трогает — чтение и откат остаются безопасными (expand/contract).
 *
 * По умолчанию dry-run (только подсчёт). Реальное шифрование — с --execute.
 * Идемпотентно: обрабатывает только строки без encrypted-пары.
 * Секреты в вывод не попадают: печатаются только идентификаторы и счётчики.
 */
#[AsCommand(
    name: 'app:marketplace:encrypt-connection-keys',
    description: 'Backfill шифрования api_key подключений маркетплейсов (dry-run по умолчанию).',
)]
final class EncryptConnectionKeysCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnectionApiKeyCodec $codec,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Выполнить шифрование (без флага — только подсчёт)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');

        $pending = $this->countPending();

        if (0 === $pending) {
            $io->success('Все подключения уже имеют зашифрованный api_key. Делать нечего.');

            return Command::SUCCESS;
        }

        if (!$execute) {
            $io->warning(sprintf(
                'DRY-RUN: %d подключений с plaintext api_key без encrypted-пары. Запустите с --execute для шифрования.',
                $pending,
            ));

            return Command::SUCCESS;
        }

        $io->note(sprintf('Шифруем api_key для %d подключений...', $pending));

        $processed = 0;
        $repository = $this->em->getRepository(MarketplaceConnection::class);

        while (true) {
            /** @var MarketplaceConnection[] $batch */
            $batch = $repository->createQueryBuilder('c')
                ->andWhere('c.apiKeyEncrypted IS NULL')
                ->setMaxResults(self::BATCH_SIZE)
                ->getQuery()
                ->getResult();

            if ([] === $batch) {
                break;
            }

            foreach ($batch as $connection) {
                $this->codec->encryptExisting($connection);
                ++$processed;
            }

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  обработано: %d / %d', $processed, $pending));
        }

        $remaining = $this->countPending();
        if (0 !== $remaining) {
            $io->error(sprintf('После backfill осталось %d необработанных подключений — проверьте логи.', $remaining));

            return Command::FAILURE;
        }

        $io->success(sprintf('Backfill завершён: зашифровано %d подключений.', $processed));

        return Command::SUCCESS;
    }

    private function countPending(): int
    {
        return (int) $this->em->getRepository(MarketplaceConnection::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.apiKeyEncrypted IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
