<?php

declare(strict_types=1);

namespace App\OneC\Infrastructure\Query;

use App\OneC\Application\DTO\PackagePart;
use App\OneC\Application\DTO\PackageSummary;
use App\OneC\Entity\OneCConnection;
use App\OneC\Entity\OneCOrganization;
use App\OneC\Exception\ConnectionNotFoundException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Подключение принадлежит пользователю: каждый запрос сужен владельцем.
 */
final readonly class ConnectionQuery
{
    private const MAX_PACKAGES = 50;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<OneCConnection> */
    public function listOwned(string $ownerId): array
    {
        return $this->em->getRepository(OneCConnection::class)->findBy(['ownerId' => $ownerId], ['name' => 'ASC']);
    }

    public function getOwned(string $ownerId, string $connectionId): OneCConnection
    {
        return $this->owned($ownerId, $connectionId, LockMode::NONE);
    }

    /** Для смены токена и статуса: внутри транзакции вызывающего. */
    public function lockOwned(string $ownerId, string $connectionId): OneCConnection
    {
        return $this->owned($ownerId, $connectionId, LockMode::PESSIMISTIC_WRITE);
    }

    /** @return list<OneCOrganization> */
    public function organizations(OneCConnection $connection): array
    {
        return $this->em->getRepository(OneCOrganization::class)->findBy(['connection' => $connection], ['present' => 'DESC', 'name' => 'ASC']);
    }

    /**
     * Последние выгрузки со всеми их частями.
     *
     * @return list<PackageSummary>
     */
    public function packages(OneCConnection $connection): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, package_id, part, parts_total, kind, period_from, period_to, warnings, document_count, byte_size, received_at
             FROM onec_package WHERE connection_id = ? AND package_id IN (
                 SELECT package_id FROM onec_package WHERE connection_id = ? GROUP BY package_id ORDER BY MAX(received_at) DESC LIMIT '.self::MAX_PACKAGES.'
             ) ORDER BY received_at DESC, part',
            [$connection->id, $connection->id],
        );
        $groups = [];
        foreach ($rows as $row) {
            $group = $groups[$row['package_id']] ??= ['row' => $row, 'parts' => [], 'warnings' => []];
            $group['parts'][] = new PackagePart($row['id'], $row['part'], $row['document_count'], $row['byte_size'], new \DateTimeImmutable($row['received_at']));
            foreach (json_decode($row['warnings'], true, flags: \JSON_THROW_ON_ERROR) as $warning) {
                $group['warnings'][] = \is_string($warning) ? $warning : json_encode($warning, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            }
            $groups[$row['package_id']] = $group;
        }

        return array_values(array_map(static function (array $group): PackageSummary {
            $row = $group['row'];
            usort($group['parts'], static fn (PackagePart $a, PackagePart $b): int => $a->part <=> $b->part);

            return new PackageSummary($row['package_id'], $row['kind'], $row['period_from'], $row['period_to'], $row['parts_total'], $group['parts'], array_values(array_unique($group['warnings'])));
        }, $groups));
    }

    /**
     * Сырая часть: владельцу подключения или администратору ($ownerId = null).
     *
     * @return array{path: string, filename: string}|null
     */
    public function rawPart(string $partId, ?string $ownerId): ?array
    {
        if (!Uuid::isValid($partId)) {
            return null;
        }
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT p.storage_path, p.package_id, p.part FROM onec_package p JOIN onec_connection c ON c.id = p.connection_id
             WHERE p.id = ? AND (CAST(? AS uuid) IS NULL OR c.owner_id = ?)',
            [$partId, $ownerId, $ownerId],
        );

        return false === $row ? null : ['path' => $row['storage_path'], 'filename' => \sprintf('onec-%s-%d.json', $row['package_id'], $row['part'])];
    }

    private function owned(string $ownerId, string $connectionId, LockMode $lock): OneCConnection
    {
        $connection = Uuid::isValid($connectionId)
            ? $this->em->createQueryBuilder()->select('c')->from(OneCConnection::class, 'c')
                ->where('c.id = :id AND c.ownerId = :owner')->setParameter('id', $connectionId)->setParameter('owner', $ownerId)
                ->getQuery()->setLockMode($lock)->getOneOrNullResult()
            : null;
        if (!$connection instanceof OneCConnection) {
            throw new ConnectionNotFoundException('Подключение не найдено.');
        }

        return $connection;
    }
}
