<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Application\DTO\MappingCharacteristicPage;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class MappingCharacteristicsQuery
{
    private const GROUPED = <<<'SQL'
        SELECT item->>'id' COLLATE "C" AS id,
            MIN(COALESCE(NULLIF(item->>'name',''), item->>'id') COLLATE "C") AS name
        FROM moysklad_variants v CROSS JOIN LATERAL jsonb_array_elements(v.characteristics) item
        WHERE v.company_id=:company AND v.connection_id=:connection
            AND item->>'id' IS NOT NULL AND item->>'id'<>''
        GROUP BY item->>'id' COLLATE "C"
        SQL;

    public function __construct(private Connection $db)
    {
    }

    public function page(string $companyId, string $connectionId, string $q = '', int $page = 1): MappingCharacteristicPage
    {
        Assert::greaterThanEq($page, 1);
        $q = trim($q);
        Assert::lessThanEq(mb_strlen($q), 100);
        $params = ['company' => Uuid::fromString($companyId)->toString(), 'connection' => Uuid::fromString($connectionId)->toString()];
        $sql = ' FROM ('.self::GROUPED.') grouped';
        if ('' !== $q) {
            $sql .= ' WHERE LOWER(name COLLATE "default") LIKE :q OR LOWER(id COLLATE "default") LIKE :q';
            $params['q'] = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$sql, $params);
        if ($page > max(1, (int) ceil($total / 20))) {
            return new MappingCharacteristicPage([], $page, $total);
        }
        /** @var list<array{id:string,name:string}> $items */
        $items = $this->db->fetchAllAssociative('SELECT id, name'.$sql.' ORDER BY name, id LIMIT 20 OFFSET '.(($page - 1) * 20), $params);

        return new MappingCharacteristicPage($items, $page, $total);
    }

    /** @return array{id:string,name:string}|null */
    public function find(string $companyId, string $connectionId, string $characteristicId): ?array
    {
        Assert::maxLength($characteristicId, 255);
        /** @var array{id:string,name:string}|false $row */
        $row = $this->db->fetchAssociative('SELECT id, name FROM ('.self::GROUPED.') grouped WHERE id=:id',
            ['company' => Uuid::fromString($companyId)->toString(), 'connection' => Uuid::fromString($connectionId)->toString(), 'id' => $characteristicId]);

        return false === $row ? null : $row;
    }
}
