<?php

declare(strict_types=1);

namespace App\Company\Application\ProjectDirection;

use App\Company\Entity\ProjectDirection;
use App\Company\Repository\ProjectDirectionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SaveProjectDirectionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectDirectionRepository $repository,
    ) {
    }

    /**
     * @return array{field: 'code'|'parent'|null, message: string}|null
     */
    public function __invoke(ProjectDirection $draft, bool $create, ?string $expectedRevision = null): ?array
    {
        $companyId = (string) $draft->getCompany()->getId();
        $id = (string) $draft->getId();

        return $this->entityManager->wrapInTransaction(function () use ($draft, $create, $companyId, $id, $expectedRevision): ?array {
            $this->repository->lockDirectory($companyId);
            $snapshot = $this->repository->readDirectory($companyId);
            if (!$create && $expectedRevision !== $snapshot['revision']) {
                return ['field' => null, 'message' => 'Справочник изменился. Откройте форму заново.'];
            }
            $projects = $snapshot['projects'];
            $parentId = $draft->getParent()?->getId();
            $row = [
                'id' => $id,
                'name' => $draft->getName(),
                'code' => $draft->getCode(),
                'description' => $draft->getDescription(),
                'sort' => $draft->getSort(),
                'parentId' => $parentId,
            ];
            if (!$create && !isset($projects[$id])) {
                return ['field' => null, 'message' => 'Проект больше не существует.'];
            }
            if ($row === ($projects[$id] ?? null)) {
                return null;
            }

            foreach ($projects as $otherId => $project) {
                if ($otherId !== $id && null !== $row['code'] && $project['code'] === $row['code']) {
                    return ['field' => 'code', 'message' => 'Этот код уже используется в справочнике.'];
                }
            }

            $seen = [$id => true];
            for ($ancestor = $parentId; null !== $ancestor; $ancestor = $projects[$ancestor]['parentId']) {
                if (!isset($projects[$ancestor]) || isset($seen[$ancestor])) {
                    return ['field' => 'parent', 'message' => 'Выберите существующего родителя вне ветки этого проекта.'];
                }
                $seen[$ancestor] = true;
            }

            if ($create) {
                $this->entityManager->persist($draft);
            } else {
                $project = $this->repository->findForDirectoryWrite($companyId, [$id])[$id];
                $project->setName($row['name'])
                    ->setCode($row['code'])
                    ->setDescription($row['description'])
                    ->setSort($row['sort'])
                    ->setParent($draft->getParent());
            }
            $this->repository->advanceDirectoryRevision($companyId);

            return null;
        });
    }
}
