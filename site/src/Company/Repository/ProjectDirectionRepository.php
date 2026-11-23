<?php

declare(strict_types=1);

namespace App\Company\Repository;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<ProjectDirection>
 *
 * @phpstan-type DirectorySnapshot array{revision: ?string, projects: array<string, ProjectRow>, systemProjects: array<string, string>}
 * @phpstan-type ProjectRow array{id: string, name: string, code: ?string, description: ?string, sort: int, parentId: ?string}
 */
class ProjectDirectionRepository extends ServiceEntityRepository
{
    private const DEFAULT_PROJECT_NAME = 'Общий';
    private const LEGACY_DEFAULT_PROJECT_NAME = 'Основной';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectDirection::class);
    }

    public function findDefaultForCompany(Company $company): ?ProjectDirection
    {
        $project = $this->findOneBy([
            'company' => $company,
            'systemCode' => ProjectDirection::CODE_GENERAL,
        ]);

        $project ??= $this->findOneBy([
            'company' => $company,
            'name' => self::DEFAULT_PROJECT_NAME,
        ]);

        return $project ?? $this->findOneBy([
            'company' => $company,
            'name' => self::LEGACY_DEFAULT_PROJECT_NAME,
        ]);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?ProjectDirection
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('project')
            ->andWhere('project.id = :id')
            ->andWhere('IDENTITY(project.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ProjectDirection>
     */
    public function findByIdsAndCompanyId(array $ids, string $companyId): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ProjectDirection> $projects */
        $projects = $this->createQueryBuilder('project')
            ->andWhere('project.id IN (:ids)')
            ->andWhere('IDENTITY(project.company) = :companyId')
            ->setParameter('ids', $ids)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();

        return $projects;
    }

    /** @return DirectorySnapshot */
    public function readDirectory(string $companyId): array
    {
        // Один снимок PostgreSQL для ревизии и строк, в том числе для пустого справочника.
        /** @var list<array{revision: ?string, id: ?string, name: string, code: ?string, description: ?string, sort: int|string, parent_id: ?string, system_code: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.project_direction_revision AS revision, p.id, p.name, p.code, p.description, p.sort, p.parent_id, p.system_code
               FROM companies c LEFT JOIN project_directions p ON p.company_id = c.id
              WHERE c.id = :companyId ORDER BY p.id',
            ['companyId' => $companyId],
        );
        $projects = $systemProjects = [];
        foreach ($rows as $row) {
            if (null !== $row['id']) {
                $projects[$row['id']] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'description' => $row['description'],
                    'sort' => (int) $row['sort'],
                    'parentId' => $row['parent_id'],
                ];
                if (null !== $row['system_code']) {
                    $systemProjects[$row['system_code']] = $row['id'];
                }
            }
        }

        return ['revision' => $rows[0]['revision'] ?? null, 'projects' => $projects, 'systemProjects' => $systemProjects];
    }

    public function lockDirectory(string $companyId): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'project_directions:'.$companyId],
        );
    }

    public function getDirectoryRevision(string $companyId): ?string
    {
        /** @var string|false|null $revision */
        $revision = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT project_direction_revision FROM companies WHERE id = :companyId',
            ['companyId' => $companyId],
        );

        return $revision ?: null;
    }

    public function advanceDirectoryRevision(string $companyId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE companies SET project_direction_revision = :revision WHERE id = :companyId',
            ['revision' => Uuid::uuid7()->toString(), 'companyId' => $companyId],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function findConflictingIds(string $companyId, array $ids): array
    {
        // Проверяем глобальный PK, возвращая только переданные UUID без данных других компаний.
        $conflicts = [];
        foreach (array_chunk($ids, 1000) as $batch) {
            /** @var list<string> $batchConflicts */
            $batchConflicts = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT id FROM project_directions WHERE company_id <> :companyId AND id IN (:ids)',
                ['companyId' => $companyId, 'ids' => $batch],
                ['ids' => ArrayParameterType::STRING],
            );
            array_push($conflicts, ...$batchConflicts);
        }

        return $conflicts;
    }

    /** @param list<string> $ids */
    public function clearCodesForImport(string $companyId, array $ids): void
    {
        if ([] !== $ids) {
            $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE project_directions SET code = NULL WHERE company_id = :companyId AND id IN (:ids)',
                ['companyId' => $companyId, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );
        }
    }

    /** @param list<ProjectRow> $projects */
    public function insertForImport(string $companyId, array $projects): void
    {
        if ([] !== $projects) {
            $this->getEntityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO project_directions (id, company_id, name)
                    SELECT incoming.id, :companyId, incoming.name
                      FROM jsonb_to_recordset(CAST(:projects AS jsonb)) AS incoming(id UUID, name TEXT)
                     ORDER BY incoming.id
                    SQL,
                ['companyId' => $companyId, 'projects' => json_encode($projects, \JSON_THROW_ON_ERROR)],
            );
        }
    }

    /** @param list<ProjectRow> $projects */
    public function updateFromImport(string $companyId, array $projects): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE project_directions AS project
                   SET name = incoming.name, code = incoming.code, description = incoming.description,
                       sort = incoming.sort, parent_id = incoming."parentId"
                  FROM jsonb_to_recordset(CAST(:projects AS jsonb))
                       AS incoming(id UUID, name TEXT, code TEXT, description TEXT, sort INTEGER, "parentId" UUID)
                 WHERE project.company_id = :companyId AND project.id = incoming.id
                SQL,
            ['companyId' => $companyId, 'projects' => json_encode($projects, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, ProjectDirection>
     */
    public function findForDirectoryWrite(string $companyId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('p', 'p.id')
            ->andWhere('IDENTITY(p.company) = :companyId')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('companyId', $companyId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * @return ProjectDirection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->findTreeByCompany($company);
    }

    /**
     * @return ProjectDirection[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->andWhere('d.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('d.sort', \SortDirection::Ascending)
            ->addOrderBy('d.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ProjectDirection[]
     */
    public function findTreeByCompany(Company $company): array
    {
        $allDirections = $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getResult();

        $childrenMap = [];
        $roots = [];
        foreach ($allDirections as $direction) {
            if (null === $direction->getParent()) {
                $roots[] = $direction;
            } else {
                $childrenMap[$direction->getParent()->getId()][] = $direction;
            }
        }

        usort(
            $roots,
            static fn (ProjectDirection $a, ProjectDirection $b) => [$a->getSort(), $a->getName()] <=> [$b->getSort(), $b->getName()]
        );

        foreach ($childrenMap as &$children) {
            usort(
                $children,
                static fn (ProjectDirection $a, ProjectDirection $b) => $a->getSort() <=> $b->getSort()
            );
        }
        unset($children);

        $result = [];
        foreach ($roots as $root) {
            $this->collectTree($root, $result, $childrenMap);
        }

        return $result;
    }

    private function collectTree(ProjectDirection $node, array &$result, array $childrenMap): void
    {
        $result[] = $node;
        $nodeId = $node->getId();
        if (isset($childrenMap[$nodeId])) {
            foreach ($childrenMap[$nodeId] as $child) {
                $this->collectTree($child, $result, $childrenMap);
            }
        }
    }

    /**
     * @return ProjectDirection[]
     */
    public function collectSelfAndDescendants(ProjectDirection $root): array
    {
        $result = [$root];

        foreach ($root->getChildren() as $child) {
            $result = array_merge($result, $this->collectSelfAndDescendants($child));
        }

        return $result;
    }
}
