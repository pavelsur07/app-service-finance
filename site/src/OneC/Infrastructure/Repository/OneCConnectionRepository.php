<?php

declare(strict_types=1);

namespace App\OneC\Infrastructure\Repository;

use App\OneC\Application\DTO\PackagePart;
use App\OneC\Application\DTO\PackageSummary;
use App\OneC\Entity\OneCConnection;
use App\OneC\Entity\OneCOrganization;
use App\OneC\Exception\ConnectionNotFoundException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Подключение принадлежит компании: каждый запрос сужен компанией.
 */
final readonly class OneCConnectionRepository
{
    private const MAX_PACKAGES = 20;
    private const MAX_PARTS_SHOWN = 100;
    private const MAX_WARNINGS_SHOWN = 20;
    private const MAX_WARNING_LENGTH = 500;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<OneCConnection> */
    public function listForCompany(string $companyId): array
    {
        return $this->em->getRepository(OneCConnection::class)->findBy(['companyId' => $companyId], ['name' => 'ASC']);
    }

    public function getForCompany(string $connectionId, string $companyId): OneCConnection
    {
        return $this->forCompany($connectionId, $companyId, LockMode::NONE);
    }

    /** Для смены токена и статуса: внутри транзакции вызывающего. */
    public function lockForCompany(string $connectionId, string $companyId): OneCConnection
    {
        return $this->forCompany($connectionId, $companyId, LockMode::PESSIMISTIC_WRITE);
    }

    /** @return list<OneCOrganization> */
    public function organizations(OneCConnection $connection): array
    {
        return $this->em->getRepository(OneCOrganization::class)->findBy(['connection' => $connection], ['present' => 'DESC', 'name' => 'ASC']);
    }

    public function organization(OneCConnection $connection, string $guid): ?OneCOrganization
    {
        return Uuid::isValid($guid) ? $this->em->find(OneCOrganization::class, ['connection' => $connection->id, 'guid' => Uuid::fromString($guid)->toString()]) : null;
    }

    /**
     * Последние выгрузки: число частей считает SQL, частей и предупреждений
     * в выборке не больше MAX_PARTS_SHOWN / MAX_WARNINGS_SHOWN на выгрузку.
     *
     * @return list<PackageSummary>
     */
    public function packages(OneCConnection $connection): array
    {
        $db = $this->em->getConnection();
        $recent = 'SELECT package_id FROM onec_package WHERE connection_id = :c GROUP BY package_id ORDER BY MAX(received_at) DESC LIMIT '.self::MAX_PACKAGES;
        $rows = $db->fetchAllAssociative(
            'SELECT id, package_id, part, parts_total, kind, period_from, period_to, document_count, byte_size, received_at, received
             FROM (SELECT id, package_id, part, parts_total, kind, period_from, period_to, document_count, byte_size, received_at,
                     ROW_NUMBER() OVER (PARTITION BY package_id ORDER BY part) AS n, COUNT(*) OVER (PARTITION BY package_id) AS received
                   FROM onec_package WHERE connection_id = :c AND package_id IN ('.$recent.')) p
             WHERE n <= '.self::MAX_PARTS_SHOWN.' ORDER BY received_at DESC, part',
            ['c' => $connection->id],
        );
        $warnings = [];
        foreach ($db->fetchAllAssociative(
            'SELECT package_id, warning FROM (
                 SELECT p.package_id, left(CASE jsonb_typeof(w) WHEN \'string\' THEN w #>> \'{}\' ELSE w::text END, '.self::MAX_WARNING_LENGTH.') AS warning,
                     ROW_NUMBER() OVER (PARTITION BY p.package_id ORDER BY p.part) AS n
                 FROM onec_package p CROSS JOIN LATERAL jsonb_array_elements(p.warnings) w
                 WHERE p.connection_id = :c AND p.package_id IN ('.$recent.')) x
             WHERE n <= '.self::MAX_WARNINGS_SHOWN,
            ['c' => $connection->id],
        ) as $row) {
            $warnings[$row['package_id']][$row['warning']] = true;
        }
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['package_id']] ??= ['row' => $row, 'parts' => []];
            $groups[$row['package_id']]['parts'][] = new PackagePart($row['id'], $row['part'], $row['document_count'], $row['byte_size'], new \DateTimeImmutable($row['received_at']));
        }

        return array_values(array_map(static function (array $group) use ($warnings): PackageSummary {
            $row = $group['row'];
            usort($group['parts'], static fn (PackagePart $a, PackagePart $b): int => $a->part <=> $b->part);

            return new PackageSummary($row['package_id'], $row['kind'], $row['period_from'], $row['period_to'], $row['parts_total'], $row['received'], $group['parts'], array_keys($warnings[$row['package_id']] ?? []));
        }, $groups));
    }

    /**
     * Сырая часть подключения компании.
     *
     * @return array{path: string, filename: string}|null
     */
    public function findRawPart(string $partId, string $companyId): ?array
    {
        return $this->rawPart($partId, $companyId);
    }

    /**
     * @companyScopeExempt админка под ROLE_ADMIN (firewall admin) скачивает сырьё любой компании для разбора обращений
     *
     * @return array{path: string, filename: string}|null
     */
    public function findRawPartAnyCompany(string $partId): ?array
    {
        return $this->rawPart($partId, null);
    }

    /** @return array{path: string, filename: string}|null */
    private function rawPart(string $partId, ?string $companyId): ?array
    {
        if (!Uuid::isValid($partId)) {
            return null;
        }
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT p.storage_path, p.package_id, p.part FROM onec_package p JOIN onec_connection c ON c.id = p.connection_id
             WHERE p.id = ? AND (CAST(? AS uuid) IS NULL OR c.company_id = ?)',
            [$partId, $companyId, $companyId],
        );

        return false === $row ? null : ['path' => $row['storage_path'], 'filename' => \sprintf('onec-%s-%d.json', $row['package_id'], $row['part'])];
    }

    private function forCompany(string $connectionId, string $companyId, LockMode $lock): OneCConnection
    {
        $connection = Uuid::isValid($connectionId)
            ? $this->em->createQueryBuilder()->select('c')->from(OneCConnection::class, 'c')
                ->where('c.id = :id AND c.companyId = :company')->setParameter('id', $connectionId)->setParameter('company', $companyId)
                ->getQuery()->setLockMode($lock)->getOneOrNullResult()
            : null;
        if (!$connection instanceof OneCConnection) {
            throw new ConnectionNotFoundException('Подключение не найдено.');
        }

        return $connection;
    }
}
