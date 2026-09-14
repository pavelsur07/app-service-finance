<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class EncryptConnectionSecretsAction
{
    public function __construct(
        private MoySkladConnectionWriteRepository $repository,
        private EntityManagerInterface $em,
        private ConnectionTokenCodec $codec,
    ) {
    }

    /** @return array{scanned: int, pending: int, encrypted: int} */
    public function __invoke(string $companyId, bool $execute = false): array
    {
        Assert::uuid($companyId);
        $counts = ['scanned' => 0, 'pending' => 0, 'encrypted' => 0];
        $afterId = null;
        while ([] !== $batch = $this->repository->findBatchForCompany($companyId, $afterId)) {
            foreach ($batch as $connection) {
                $afterId = $connection->getId();
                ++$counts['scanned'];
                if (null === $connection->getAccessToken() && null === $connection->getRefreshToken()) {
                    continue;
                }
                ++$counts['pending'];
                if (!$execute) {
                    continue;
                }

                $version = $connection->getVersion();
                $db = $this->em->getConnection();
                $db->beginTransaction();
                try {
                    $this->em->refresh($connection);
                    $this->em->lock($connection, LockMode::OPTIMISTIC, $version);
                    $this->codec->encryptExisting($connection);
                    $this->em->flush();
                    $db->commit();
                    ++$counts['encrypted'];
                } catch (\Throwable $exception) {
                    if ($db->isTransactionActive()) {
                        $db->rollBack();
                    }
                    $this->em->clear();

                    throw $exception;
                }
            }
            $this->em->clear();
        }

        return $counts;
    }
}
